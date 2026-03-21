<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskComment;
use App\Models\TaskPart;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskStatusChangedNotification;
use App\Notifications\TaskCommentNotification;
use Illuminate\Support\Facades\DB;

class TaskService
{
    /**
     * Tạo công việc mới (general hoặc repair).
     */
    public function createTask(array $data): Task
    {
        return DB::transaction(function () use ($data) {
            $type = $data['type'] ?? Task::TYPE_GENERAL;

            // Repair flow — cần serial
            if ($type === Task::TYPE_REPAIR) {
                return $this->createRepairTask($data);
            }

            // General flow
            $task = Task::create([
                'code'              => Task::generateCode(Task::TYPE_GENERAL),
                'type'              => Task::TYPE_GENERAL,
                'title'             => $data['title'],
                'category_id'       => $data['category_id'] ?? null,
                'issue_description' => $data['description'] ?? null,
                'priority'          => $data['priority'] ?? Task::PRIORITY_NORMAL,
                'status'            => Task::STATUS_PENDING,
                'branch_id'         => $data['branch_id'] ?? null,
                'notes'             => $data['notes'] ?? null,
                'deadline'          => $data['deadline'] ?? null,
                'created_by'        => $data['created_by'] ?? null,
            ]);

            return $task;
        });
    }

    /**
     * Tạo phiếu sửa chữa (repair task) — giữ nguyên logic cũ.
     */
    protected function createRepairTask(array $data): Task
    {
        $serial = SerialImei::findOrFail($data['serial_imei_id']);

        $task = Task::create([
            'code'              => Task::generateCode(Task::TYPE_REPAIR),
            'type'              => Task::TYPE_REPAIR,
            'title'             => $data['title'] ?? null,
            'category_id'       => $data['category_id'] ?? null,
            'product_id'        => $serial->product_id,
            'serial_imei_id'    => $serial->id,
            'original_cost'     => $serial->cost_price ?: ($serial->product->cost_price ?? 0),
            'parts_cost'        => 0,
            'total_cost'        => $serial->cost_price ?: ($serial->product->cost_price ?? 0),
            'issue_description' => $data['issue_description'] ?? $data['description'] ?? null,
            'priority'          => $data['priority'] ?? Task::PRIORITY_NORMAL,
            'status'            => Task::STATUS_PENDING,
            'branch_id'         => $data['branch_id'] ?? null,
            'notes'             => $data['notes'] ?? null,
            'deadline'          => $data['deadline'] ?? null,
            'created_by'        => $data['created_by'] ?? null,
        ]);

        // Update title to code if not set
        if (!$task->title) {
            $task->update(['title' => $task->code]);
        }

        // Snapshot cost_price if serial doesn't have one
        if (!$serial->cost_price) {
            $serial->cost_price = $serial->product->cost_price ?? 0;
        }
        $serial->repair_status = 'not_started';
        $serial->save();

        return $task;
    }

    /**
     * Giao công việc cho nhiều nhân viên.
     */
    public function assignEmployees(Task $task, array $employeeIds, ?int $assignedBy = null): Task
    {
        return DB::transaction(function () use ($task, $employeeIds, $assignedBy) {
            $assigner = $assignedBy ? User::find($assignedBy) : null;
            $assignerName = $assigner?->name ?? 'Hệ thống';

            foreach ($employeeIds as $employeeId) {
                // Skip if already assigned
                if ($task->assignments()->where('employee_id', $employeeId)->exists()) {
                    continue;
                }

                TaskAssignment::create([
                    'task_id'     => $task->id,
                    'employee_id' => $employeeId,
                    'assigned_by' => $assignedBy,
                    'status'      => TaskAssignment::STATUS_PENDING,
                    'assigned_at' => now(),
                ]);

                // Send notification to employee's linked user
                $employee = \App\Models\Employee::find($employeeId);
                if ($employee?->user_id) {
                    $user = User::find($employee->user_id);
                    $user?->notify(new TaskAssignedNotification($task, $assignerName));
                }
            }

            // Update legacy single-assign field (first employee)
            if (!$task->assigned_employee_id && count($employeeIds) > 0) {
                $task->update([
                    'assigned_employee_id' => $employeeIds[0],
                    'assigned_at'          => now(),
                ]);
            }

            // KHÔNG tự động chuyển in_progress — phải đợi nhân viên accept

            return $task->fresh('assignments.employee');
        });
    }

    /**
     * Nhân viên nhận/từ chối công việc.
     */
    public function respondToAssignment(TaskAssignment $assignment, string $status, ?string $notes = null): TaskAssignment
    {
        $assignment->update([
            'status'       => $status,
            'responded_at' => now(),
            'notes'        => $notes,
        ]);

        $task = $assignment->task;

        if ($status === TaskAssignment::STATUS_ACCEPTED) {
            // Khi có ít nhất 1 người nhận → chuyển task sang in_progress
            if ($task->status === Task::STATUS_PENDING) {
                $this->changeStatus($task, Task::STATUS_IN_PROGRESS, null);
            }
        } elseif ($status === TaskAssignment::STATUS_REJECTED) {
            // Nếu TẤT CẢ đều reject → thông báo, giữ pending
            $allResponded = $task->assignments()
                ->where('status', '!=', TaskAssignment::STATUS_PENDING)
                ->count();
            $totalAssigned = $task->assignments()->count();
            $anyAccepted = $task->assignments()
                ->where('status', TaskAssignment::STATUS_ACCEPTED)
                ->exists();

            if ($allResponded === $totalAssigned && !$anyAccepted) {
                // Tất cả đã từ chối — task vẫn pending, cần giao lại
                // Gửi notification cho người tạo
                if ($task->created_by) {
                    $creator = User::find($task->created_by);
                    $creator?->notify(new TaskStatusChangedNotification(
                        $task, $task->status, $task->status,
                        'Tất cả nhân viên đã từ chối — cần giao lại'
                    ));
                }
            }
        }

        return $assignment;
    }

    /**
     * Thay đổi trạng thái công việc.
     */
    public function changeStatus(Task $task, string $newStatus, ?int $changedBy = null): Task
    {
        $oldStatus = $task->status;

        $updates = ['status' => $newStatus];

        if ($newStatus === Task::STATUS_COMPLETED) {
            $updates['completed_at'] = now();
            $updates['progress'] = 100;
        }
        if ($newStatus === Task::STATUS_CANCELLED) {
            $updates['cancelled_at'] = now();
        }

        $task->update($updates);

        // Repair-specific: update serial status
        if ($task->is_repair && $task->serial_imei_id) {
            if ($newStatus === Task::STATUS_COMPLETED) {
                $task->serialImei?->update(['repair_status' => 'ready']);
            } elseif ($newStatus === Task::STATUS_IN_PROGRESS) {
                $task->serialImei?->update(['repair_status' => 'repairing']);
            }
        }

        // Notify assigned employees about status change
        if ($oldStatus !== $newStatus) {
            $changerName = $changedBy ? (User::find($changedBy)?->name ?? 'Hệ thống') : 'Hệ thống';
            $notification = new TaskStatusChangedNotification($task, $oldStatus, $newStatus, $changerName);

            foreach ($task->assignments as $assignment) {
                if ($assignment->employee?->user_id) {
                    User::find($assignment->employee->user_id)?->notify($notification);
                }
            }
        }

        return $task->fresh();
    }

    /**
     * Đánh dấu hoàn thành.
     */
    public function markCompleted(Task $task, ?int $completedBy = null): Task
    {
        return $this->changeStatus($task, Task::STATUS_COMPLETED, $completedBy);
    }

    /**
     * Huỷ công việc — reset serial về trạng thái sẵn bán.
     */
    public function cancelTask(Task $task, ?int $cancelledBy = null): Task
    {
        return DB::transaction(function () use ($task, $cancelledBy) {
            // Đổi trạng thái sang cancelled
            $task = $this->changeStatus($task, Task::STATUS_CANCELLED, $cancelledBy);

            // Reset repair_status serial về null (sẵn bán)
            if ($task->is_repair && $task->serial_imei_id) {
                $task->serialImei?->update(['repair_status' => null]);
            }

            return $task;
        });
    }

    /**
     * Cập nhật tiến độ.
     */
    public function updateProgress(Task $task, int $progress): Task
    {
        $task->update(['progress' => min(100, max(0, $progress))]);
        return $task;
    }

    /**
     * Xuất linh kiện (chỉ cho repair task).
     */
    public function addPart(Task $task, int $productId, int $quantity = 1, ?string $notes = null, ?int $exportedBy = null): TaskPart
    {
        return DB::transaction(function () use ($task, $productId, $quantity, $notes, $exportedBy) {
            $product = Product::findOrFail($productId);

            if ($product->stock_quantity < $quantity) {
                throw new \RuntimeException("Tồn kho linh kiện \"{$product->name}\" không đủ (còn {$product->stock_quantity}, cần {$quantity}).");
            }

            $unitCost = $product->cost_price ?? 0;
            $totalCost = $unitCost * $quantity;

            $part = TaskPart::create([
                'task_id'    => $task->id,
                'product_id' => $productId,
                'quantity'   => $quantity,
                'unit_cost'  => $unitCost,
                'total_cost' => $totalCost,
                'exported_by' => $exportedBy,
                'notes'      => $notes,
                'direction'  => 'export',
            ]);

            $product->decrement('stock_quantity', $quantity);
            $task->recalculateCosts();

            // Cộng giá vốn vào đúng nơi (repair only)
            if ($task->serial_imei_id) {
                // Sản phẩm có serial → cộng vào giá vốn serial cụ thể đó, KHÔNG cộng vào product chung
                $serial = $task->serialImei;
                $serial->cost_price = (float) $serial->cost_price + $totalCost;
                $serial->save();
            } elseif ($task->product_id) {
                // Sản phẩm không theo dõi serial → cộng vào giá vốn product chung
                $repairedProduct = Product::find($task->product_id);
                if ($repairedProduct) {
                    $repairedProduct->cost_price = (float) $repairedProduct->cost_price + $totalCost;
                    $repairedProduct->save();
                }
            }

            return $part;
        });
    }

    /**
     * Gỡ linh kiện.
     */
    public function removePart(TaskPart $part): void
    {
        DB::transaction(function () use ($part) {
            $task = $part->task;

            Product::where('id', $part->product_id)->increment('stock_quantity', $part->quantity);

            if ($task->serial_imei_id) {
                // Sản phẩm có serial → trừ từ giá vốn serial cụ thể
                $serial = $task->serialImei;
                $serial->cost_price = max(0, (float) $serial->cost_price - (float) $part->total_cost);
                $serial->save();
            } elseif ($task->product_id) {
                // Sản phẩm không theo dõi serial → trừ từ giá vốn product chung
                $repairedProduct = Product::find($task->product_id);
                if ($repairedProduct) {
                    $repairedProduct->cost_price = max(0, (float) $repairedProduct->cost_price - (float) $part->total_cost);
                    $repairedProduct->save();
                }
            }

            $part->delete();
            $task->recalculateCosts();
        });
    }

    /**
     * Bóc linh kiện từ máy — nhập vào tồn kho.
     */
    public function disassemblePart(Task $task, int $productId, int $quantity = 1, ?float $unitCost = null, ?string $notes = null, ?int $exportedBy = null): TaskPart
    {
        return DB::transaction(function () use ($task, $productId, $quantity, $unitCost, $notes, $exportedBy) {
            $product = Product::findOrFail($productId);

            // Giá mặc định = giá vốn bình quân hiện tại, có thể sửa
            $cost = $unitCost ?? ($product->cost_price ?? 0);
            $totalCost = $cost * $quantity;

            $part = TaskPart::create([
                'task_id'    => $task->id,
                'product_id' => $productId,
                'quantity'   => $quantity,
                'unit_cost'  => $cost,
                'total_cost' => $totalCost,
                'exported_by' => $exportedBy,
                'notes'      => $notes,
                'direction'  => 'import',
            ]);

            // Cộng tồn kho linh kiện
            $product->increment('stock_quantity', $quantity);

            // Trừ giá vốn máy
            if ($task->serial_imei_id) {
                $serial = $task->serialImei;
                $serial->cost_price = max(0, (float) $serial->cost_price - $totalCost);
                $serial->save();
            } elseif ($task->product_id) {
                $repairedProduct = Product::find($task->product_id);
                if ($repairedProduct) {
                    $repairedProduct->cost_price = max(0, (float) $repairedProduct->cost_price - $totalCost);
                    $repairedProduct->save();
                }
            }

            $task->recalculateCosts();

            return $part;
        });
    }

    /**
     * Thêm bình luận.
     */
    public function addComment(Task $task, int $userId, string $body): TaskComment
    {
        $comment = TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $userId,
            'body'    => $body,
        ]);

        $user = User::find($userId);
        $notification = new TaskCommentNotification($task, $user?->name ?? 'Ai đó', $body);

        // Notify all assigned employees except the commenter
        foreach ($task->assignments as $assignment) {
            if ($assignment->employee?->user_id && $assignment->employee->user_id !== $userId) {
                User::find($assignment->employee->user_id)?->notify($notification);
            }
        }

        // Notify creator if different from commenter
        if ($task->created_by && $task->created_by !== $userId) {
            User::find($task->created_by)?->notify($notification);
        }

        return $comment->load('user:id,name');
    }

    /**
     * Tính % hoàn thành của NV trong kỳ.
     */
    public function getEmployeePerformance(int $employeeId, string $from, string $to): array
    {
        $assigned = Task::where('assigned_employee_id', $employeeId)
            ->whereBetween('assigned_at', [$from, $to])
            ->count();

        $completed = Task::where('assigned_employee_id', $employeeId)
            ->where('status', Task::STATUS_COMPLETED)
            ->whereBetween('completed_at', [$from, $to])
            ->count();

        $rate = $assigned > 0 ? round(($completed / $assigned) * 100, 1) : 0;

        $tier = \App\Models\RepairPerformanceTier::getTierForPercent($rate);

        return [
            'assigned'        => $assigned,
            'completed'       => $completed,
            'completion_rate'  => $rate,
            'tier'            => $tier,
            'salary_percent'  => $tier?->salary_percent ?? 100,
        ];
    }
}
