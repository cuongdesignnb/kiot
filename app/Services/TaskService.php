<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskComment;
use App\Models\TaskPart;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\User;
use App\Models\ActivityLog;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskStatusChangedNotification;
use App\Notifications\TaskCommentNotification;
use Illuminate\Support\Facades\DB;

class TaskService
{
    /**
     * Helper: resolve employee/user name from user ID.
     */
    protected function resolveActorName(?int $userId): string
    {
        if (!$userId) {
            $user = auth()->user();
            $userId = $user?->id;
        }
        if (!$userId) return 'Hệ thống';

        $user = User::find($userId);
        if (!$user) return 'Hệ thống';

        // Try to find linked employee name first
        $employee = \App\Models\Employee::where('user_id', $userId)->first();
        return $employee?->name ?? $user->name ?? 'Hệ thống';
    }

    /**
     * Helper: build machine description for activity logs.
     */
    protected function buildMachineInfo(Task $task): string
    {
        $machineName = $task->product?->name ?? '';
        $serialNumber = $task->serialImei?->serial_number ?? '';

        if ($machineName && $serialNumber) {
            return "máy {$machineName} (SN: {$serialNumber})";
        } elseif ($machineName) {
            return "máy {$machineName}";
        } elseif ($serialNumber) {
            return "máy SN: {$serialNumber}";
        }
        return "phiếu {$task->code}";
    }

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

            $actorName = $this->resolveActorName($data['created_by'] ?? null);
            ActivityLog::log('task_create', "NV {$actorName} tạo công việc {$task->code}: {$task->title}", $task, [
                'task_code' => $task->code,
                'title' => $task->title,
                'employee' => $actorName,
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

        // Prevent duplicate: check if serial already has active repair task
        $existingTask = Task::where('serial_imei_id', $serial->id)
            ->where('type', Task::TYPE_REPAIR)
            ->whereNotIn('status', [Task::STATUS_COMPLETED, Task::STATUS_CANCELLED])
            ->first();

        if ($existingTask) {
            throw new \Illuminate\Validation\ValidationException(
                \Illuminate\Support\Facades\Validator::make([], []),
                new \Illuminate\Http\JsonResponse([
                    'message' => "Serial {$serial->serial_number} đang có phiếu sửa chữa {$existingTask->code} chưa hoàn thành. Không thể tạo thêm.",
                    'errors' => ['serial_imei_id' => ["Serial này đang trong phiếu sửa chữa {$existingTask->code}."]]
                ], 422)
            );
        }

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

        $actorName = $this->resolveActorName($data['created_by'] ?? null);
        $machineInfo = $this->buildMachineInfo($task);
        ActivityLog::log('task_create', "NV {$actorName} tạo phiếu sửa chữa {$task->code} cho {$machineInfo}", $task, [
            'task_code' => $task->code,
            'employee' => $actorName,
            'product' => $task->product?->name,
            'serial' => $serial->serial_number,
        ], $data['created_by'] ?? null);

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
        $task = $this->changeStatus($task, Task::STATUS_COMPLETED, $completedBy);

        $actorName = $this->resolveActorName($completedBy);
        $machineInfo = $this->buildMachineInfo($task);
        ActivityLog::log('task_complete', "NV {$actorName} hoàn thành công việc {$task->code} — {$machineInfo}", $task, [
            'task_code' => $task->code,
            'employee' => $actorName,
            'serial' => $task->serialImei?->serial_number,
            'product' => $task->product?->name,
        ], $completedBy);

        return $task;
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

            // ── Hoàn trả tất cả linh kiện về kho ──
            $parts = $task->parts()->get();
            foreach ($parts as $part) {
                if (($part->direction ?? 'export') === 'export') {
                    // Linh kiện đã lắp vào → trả lại tồn kho
                    Product::where('id', $part->product_id)->increment('stock_quantity', $part->quantity);

                    // Trừ giá vốn đã cộng vào serial/product
                    if ($task->serial_imei_id) {
                        $serial = $task->serialImei;
                        if ($serial) {
                            $serial->cost_price = max(0, (float) $serial->cost_price - (float) $part->total_cost);
                            $serial->save();
                        }
                    } elseif ($task->product_id) {
                        $repairedProduct = Product::find($task->product_id);
                        if ($repairedProduct) {
                            $repairedProduct->cost_price = max(0, (float) $repairedProduct->cost_price - (float) $part->total_cost);
                            $repairedProduct->save();
                        }
                    }
                } elseif ($part->direction === 'import') {
                    // Linh kiện bóc ra từ máy → trừ lại tồn kho (hoàn nguyên)
                    Product::where('id', $part->product_id)->decrement('stock_quantity', $part->quantity);

                    // Cộng lại giá vốn đã trừ từ serial/product
                    if ($task->serial_imei_id) {
                        $serial = $task->serialImei;
                        if ($serial) {
                            $serial->cost_price = (float) $serial->cost_price + (float) $part->total_cost;
                            $serial->save();
                        }
                    } elseif ($task->product_id) {
                        $repairedProduct = Product::find($task->product_id);
                        if ($repairedProduct) {
                            $repairedProduct->cost_price = (float) $repairedProduct->cost_price + (float) $part->total_cost;
                            $repairedProduct->save();
                        }
                    }
                }
            }

            // Xoá tất cả part records
            $task->parts()->delete();
            $task->recalculateCosts();

            $actorName = $this->resolveActorName($cancelledBy);
            $machineInfo = $this->buildMachineInfo($task);
            ActivityLog::log('task_cancel', "NV {$actorName} huỷ công việc {$task->code} — {$machineInfo}", $task, [
                'task_code' => $task->code,
                'employee' => $actorName,
                'serial' => $task->serialImei?->serial_number,
                'product' => $task->product?->name,
            ], $cancelledBy);

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
    public function addPart(Task $task, int $productId, int $quantity = 1, ?string $notes = null, ?int $exportedBy = null, bool $allowNegative = false): TaskPart
    {
        return DB::transaction(function () use ($task, $productId, $quantity, $notes, $exportedBy, $allowNegative) {
            $product = Product::findOrFail($productId);

            if (!$allowNegative && $product->stock_quantity < $quantity) {
                throw new \RuntimeException("Tồn kho linh kiện \"{$product->name}\" không đủ (còn {$product->stock_quantity}, cần {$quantity}). Tích chọn \"Cho phép lắp khi hết hàng\" để lắp âm kho.");
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

            $productName = $product->name;
            $actorName = $this->resolveActorName($exportedBy);
            $machineInfo = $this->buildMachineInfo($task);
            ActivityLog::log('part_install', "NV {$actorName} lắp {$quantity}x {$productName} vào {$machineInfo} — phiếu {$task->code}", $task, [
                'task_code' => $task->code,
                'employee' => $actorName,
                'linh_kien' => $productName,
                'so_luong' => $quantity,
                'may' => $task->product?->name,
                'serial' => $task->serialImei?->serial_number,
                'gia_von' => $totalCost,
            ], $exportedBy);

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

            $productName = $part->product?->name ?? 'N/A';
            $qty = $part->quantity;
            $taskCode = $task->code;
            $actorName = $this->resolveActorName(null);
            $machineInfo = $this->buildMachineInfo($task);

            $part->delete();
            $task->recalculateCosts();

            ActivityLog::log('part_remove', "NV {$actorName} gỡ {$qty}x {$productName} khỏi {$machineInfo} — phiếu {$taskCode}", $task, [
                'task_code' => $taskCode,
                'employee' => $actorName,
                'linh_kien' => $productName,
                'so_luong' => $qty,
                'may' => $task->product?->name,
                'serial' => $task->serialImei?->serial_number,
            ]);
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

            $actorName = $this->resolveActorName($exportedBy);
            $machineInfo = $this->buildMachineInfo($task);
            ActivityLog::log('part_disassemble', "NV {$actorName} bóc {$quantity}x {$product->name} từ {$machineInfo} — phiếu {$task->code}", $task, [
                'task_code' => $task->code,
                'employee' => $actorName,
                'linh_kien' => $product->name,
                'so_luong' => $quantity,
                'may' => $task->product?->name,
                'serial' => $task->serialImei?->serial_number,
                'gia_von' => $totalCost,
            ], $exportedBy);

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
     * Tính hiệu suất NV trong kỳ (chi tiết).
     */
    public function getEmployeePerformance(int $employeeId, string $from, string $to): array
    {
        // Get all tasks assigned to this employee in the period via assignments table
        $taskIds = TaskAssignment::where('employee_id', $employeeId)
            ->whereBetween('assigned_at', [$from, $to . ' 23:59:59'])
            ->pluck('task_id')
            ->unique();

        $tasks = Task::with(['product:id,name,sku', 'serialImei:id,serial_number,product_id'])
            ->whereIn('id', $taskIds)
            ->get();

        $total = $tasks->count();
        $completed = $tasks->where('status', Task::STATUS_COMPLETED)->count();
        $inProgress = $tasks->where('status', Task::STATUS_IN_PROGRESS)->count();
        $pending = $tasks->where('status', Task::STATUS_PENDING)->count();
        $cancelled = $tasks->where('status', Task::STATUS_CANCELLED)->count();

        $activeTasks = $tasks->whereIn('status', [Task::STATUS_IN_PROGRESS, Task::STATUS_PENDING]);
        $avgProgress = $activeTasks->count() > 0
            ? round($activeTasks->avg('progress'), 1)
            : ($completed > 0 ? 100 : 0);

        $now = now();
        $overdue = $tasks->filter(function ($t) use ($now) {
            return $t->deadline
                && $t->status !== Task::STATUS_COMPLETED
                && $t->status !== Task::STATUS_CANCELLED
                && \Carbon\Carbon::parse($t->deadline)->lt($now);
        })->count();

        $rate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        $tier = \App\Models\RepairPerformanceTier::getTierForPercent($rate);

        // Detail list for expandable view
        $taskDetails = $tasks->map(function ($t) {
            return [
                'id' => $t->id,
                'code' => $t->code,
                'title' => $t->title,
                'type' => $t->type,
                'status' => $t->status,
                'progress' => $t->progress ?? 0,
                'deadline' => $t->deadline,
                'completed_at' => $t->completed_at,
                'product_name' => $t->product?->name,
                'serial_number' => $t->serialImei?->serial_number,
            ];
        })->values();

        return [
            'total'           => $total,
            'assigned'        => $total,
            'completed'       => $completed,
            'in_progress'     => $inProgress,
            'pending'         => $pending,
            'cancelled'       => $cancelled,
            'avg_progress'    => $avgProgress,
            'overdue'         => $overdue,
            'completion_rate' => $rate,
            'tier'            => $tier,
            'salary_percent'  => $tier?->salary_percent ?? 100,
            'tasks'           => $taskDetails,
        ];
    }
}
