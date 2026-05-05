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
use App\Services\MovingAvgCostingService;
use App\Services\StockMovementService;
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

            // External repair — no serial/stock required
            if ($type === Task::TYPE_REPAIR && !empty($data['external'])) {
                return $this->createExternalRepair($data);
            }

            // Internal repair flow — needs serial in_stock
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
     * Tạo phiếu sửa chữa khách ngoài — không yêu cầu serial nội bộ.
     */
    protected function createExternalRepair(array $data): Task
    {
        $task = Task::create([
            'code'              => Task::generateCode(Task::TYPE_REPAIR),
            'type'              => Task::TYPE_REPAIR,
            'external'          => true,
            'title'             => $data['title'] ?? null,
            'category_id'       => $data['category_id'] ?? null,
            'product_id'        => $data['product_id'] ?? null,
            'issue_description' => $data['issue_description'] ?? $data['description'] ?? null,
            'priority'          => $data['priority'] ?? Task::PRIORITY_NORMAL,
            'status'            => Task::STATUS_PENDING,
            'sub_status'        => 'received',
            'customer_id'       => $data['customer_id'] ?? null,
            'customer_name'     => $data['customer_name'] ?? null,
            'customer_phone'    => $data['customer_phone'] ?? null,
            'received_at'       => $data['received_at'] ?? now(),
            'branch_id'         => $data['branch_id'] ?? null,
            'notes'             => $data['notes'] ?? null,
            'deadline'          => $data['deadline'] ?? null,
            'created_by'        => $data['created_by'] ?? null,
            'original_cost'     => 0,
            'parts_cost'        => 0,
            'total_cost'        => 0,
            'labor_fee'         => 0,
            'parts_total'       => 0,
            'total_amount'      => 0,
            'paid_amount'       => 0,
            'debt_amount'       => 0,
        ]);

        if (!$task->title) {
            $task->update(['title' => $task->code]);
        }

        // Snapshot customer name/phone if customer_id provided
        if (!empty($data['customer_id']) && empty($data['customer_name'])) {
            $customer = \App\Models\Customer::find($data['customer_id']);
            if ($customer) {
                $task->update([
                    'customer_name'  => $customer->name,
                    'customer_phone' => $customer->phone,
                ]);
            }
        }

        return $task;
    }

    /**
     * Tạo phiếu sửa chữa (repair task) — giữ nguyên logic cũ.
     */
    protected function createRepairTask(array $data): Task
    {
        $serial = SerialImei::findOrFail($data['serial_imei_id']);

        // ── Validation 1: Serial phải còn trong kho (in_stock), không được đã bán ──
        if ($serial->status !== 'in_stock') {
            $statusLabels = [
                'sold' => 'đã bán',
                'returned' => 'đã trả',
                'damaged' => 'hỏng',
                'transferred' => 'đã chuyển kho',
            ];
            $label = $statusLabels[$serial->status] ?? $serial->status;
            throw new \InvalidArgumentException("Serial {$serial->serial_number} {$label}, không thể tạo phiếu sửa chữa.");
        }

        // ── Validation 2: Serial không được có task đang active (pending/in_progress) ──
        $activeTask = Task::where('serial_imei_id', $serial->id)
            ->whereIn('status', [Task::STATUS_PENDING, 'in_progress'])
            ->first();
        if ($activeTask) {
            throw new \InvalidArgumentException(
                "Serial {$serial->serial_number} đang có phiếu {$activeTask->code} (trạng thái: {$activeTask->status}). Phải hoàn thành hoặc hủy phiếu cũ trước."
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

            // Trừ tồn kho linh kiện qua CostingService (cập nhật inventory_total_cost)
            MovingAvgCostingService::applySale($product, $quantity);
            $product->refresh();

            // Ghi StockMovement cho linh kiện xuất
            StockMovementService::record(
                $product,
                StockMovementService::TYPE_REPAIR_OUT,
                $quantity,
                $unitCost,
                $task
            );

            $task->recalculateCosts();

            // Cộng giá vốn vào đúng nơi (repair only)
            if ($task->serial_imei_id) {
                // Sản phẩm có serial → cộng vào giá vốn serial cụ thể đó
                $serial = $task->serialImei;
                $serial->cost_price = (float) $serial->cost_price + $totalCost;
                $serial->save();

                // BQ DI ĐỘNG: nếu serial in_stock, tăng inventory_total_cost
                if ($serial->status === 'in_stock' && $serial->product) {
                    MovingAvgCostingService::applyRepairAdjustment($serial->product, (float) $totalCost);
                }
            } elseif ($task->product_id) {
                // Hàng không serial → BQ DI ĐỘNG: cộng vào inventory_total_cost
                $repairedProduct = Product::find($task->product_id);
                if ($repairedProduct) {
                    MovingAvgCostingService::applyRepairAdjustment($repairedProduct, (float) $totalCost);
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
            $product = Product::find($part->product_id);

            if ($product) {
                // Hoàn tồn kho linh kiện qua CostingService
                $restoreCost = (float) ($part->unit_cost ?? $product->cost_price ?? 0);
                MovingAvgCostingService::applyPurchase($product, (int) $part->quantity, $restoreCost);
                $product->refresh();

                // Ghi StockMovement cho linh kiện hoàn
                StockMovementService::record(
                    $product,
                    StockMovementService::TYPE_REPAIR_IN,
                    (int) $part->quantity,
                    $restoreCost,
                    $task,
                    ['note' => 'Hoàn linh kiện — gỡ khỏi phiếu sửa chữa']
                );
            }

            $deltaCost = -(float) $part->total_cost;

            if ($task->serial_imei_id) {
                // Sản phẩm có serial → trừ từ giá vốn serial cụ thể
                $serial = $task->serialImei;
                $serial->cost_price = max(0, (float) $serial->cost_price + $deltaCost);
                $serial->save();

                if ($serial->status === 'in_stock' && $serial->product) {
                    MovingAvgCostingService::applyRepairAdjustment($serial->product, $deltaCost);
                }
            } elseif ($task->product_id) {
                $repairedProduct = Product::find($task->product_id);
                if ($repairedProduct) {
                    MovingAvgCostingService::applyRepairAdjustment($repairedProduct, $deltaCost);
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

            // Cộng tồn kho linh kiện qua CostingService
            MovingAvgCostingService::applyPurchase($product, $quantity, $cost);
            $product->refresh();

            // Ghi StockMovement cho linh kiện thu hồi
            StockMovementService::record(
                $product,
                StockMovementService::TYPE_REPAIR_IN,
                $quantity,
                $cost,
                $task,
                ['note' => 'Bóc linh kiện từ máy — nhập kho']
            );

            // Trừ giá vốn máy
            $deltaCost = -$totalCost;

            if ($task->serial_imei_id) {
                $serial = $task->serialImei;
                $serial->cost_price = max(0, (float) $serial->cost_price + $deltaCost);
                $serial->save();

                if ($serial->status === 'in_stock' && $serial->product) {
                    MovingAvgCostingService::applyRepairAdjustment($serial->product, $deltaCost);
                }
            } elseif ($task->product_id) {
                $repairedProduct = Product::find($task->product_id);
                if ($repairedProduct) {
                    MovingAvgCostingService::applyRepairAdjustment($repairedProduct, $deltaCost);
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
        // Lấy tất cả task được giao cho NV trong khoảng thời gian (qua task_assignments)
        $taskIds = \App\Models\TaskAssignment::where('employee_id', $employeeId)
            ->whereHas('task', fn($q) => $q->whereBetween('created_at', [$from, $to . ' 23:59:59']))
            ->pluck('task_id');

        $tasks = Task::whereIn('id', $taskIds)->get();

        $total = $tasks->count();
        $completed = $tasks->where('status', Task::STATUS_COMPLETED)->count();
        $inProgress = $tasks->where('status', 'in_progress')->count();
        $pending = $tasks->where('status', 'pending')->count();
        $cancelled = $tasks->where('status', 'cancelled')->count();

        $rate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

        $tier = \App\Models\RepairPerformanceTier::getTierForPercent($rate);

        return [
            'total'           => $total,
            'assigned'        => $total, // backward compat
            'completed'       => $completed,
            'in_progress'     => $inProgress,
            'pending'         => $pending,
            'cancelled'       => $cancelled,
            'completion_rate' => $rate,
            'tier'            => $tier,
            'salary_percent'  => $tier?->salary_percent ?? 100,
        ];
    }
}
