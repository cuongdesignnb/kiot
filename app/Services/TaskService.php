<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskComment;
use App\Models\TaskPart;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\User;
use App\Models\Warranty;
use App\Models\ActivityLog;
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
     *
     * HOTFIX 24.16B — wrapped in DB::transaction so SerialImei::lockForUpdate()
     * inside updateInternalRepairSerialStatus() actually holds the row lock
     * across the task + serial + recompute writes. Without an outer
     * transaction, auto-commit releases the lock right after the SELECT and
     * two concurrent repair completions could race on the same serial.
     */
    public function changeStatus(Task $task, string $newStatus, ?int $changedBy = null): Task
    {
        return DB::transaction(function () use ($task, $newStatus, $changedBy) {
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

            // HOTFIX 24.16 — keep `repair_status` and physical `status` in sync.
            // The previous version only touched `repair_status`, which left
            // serials stuck at `status=dismantled` + `repair_status=ready`
            // after a repair finished — the UI then incorrectly badged them
            // as "Sẵn bán".
            if ($task->is_repair && $task->serial_imei_id) {
                $this->updateInternalRepairSerialStatus($task, $newStatus);
            }

            // Notify assigned employees about status change.
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
        });
    }

    /**
     * HOTFIX 24.16 — sync serial's physical `status` and `repair_status`
     * when an internal repair task transitions.
     *
     * Refuses to touch a serial that has already left stock (sold, returned
     * to supplier) or whose disassembly has not been rolled back, so the
     * machine isn't silently brought back to in_stock when the body is
     * actually gone.
     */
    private function updateInternalRepairSerialStatus(Task $task, string $newStatus): void
    {
        if (!$task->is_repair || !$task->serial_imei_id) {
            return;
        }

        $serial = SerialImei::lockForUpdate()->find($task->serial_imei_id);
        if (!$serial) {
            return;
        }

        if ($newStatus === Task::STATUS_IN_PROGRESS) {
            // Đang sửa: chỉ đổi repair_status; giữ status vật lý.
            if ($serial->status === 'in_stock') {
                $serial->repair_status = 'repairing';
                $serial->save();
            }
            return;
        }

        if ($newStatus !== Task::STATUS_COMPLETED) {
            return;
        }

        $serial->repair_status = 'ready';

        // Đã rời kho thật → tuyệt đối không hồi về in_stock.
        $hasLeftStock = !empty($serial->invoice_id)
            || !empty($serial->sold_at)
            || !empty($serial->purchase_return_id);

        if ($hasLeftStock) {
            $serial->save();
            return;
        }

        // HOTFIX 24.35 — nghiệp vụ chốt: completed task = máy phải về sẵn bán.
        // Không còn gate task_parts.direction='import': vì khi task đã được
        // operator bấm Hoàn thành, hệ thống coi như máy đã lắp lại xong.
        // task_parts vẫn được giữ để audit trail (giá vốn / linh kiện tiêu
        // hao đã ghi nhận); chỉ trạng thái vật lý của serial được hồi về.
        if (in_array($serial->status, ['in_stock', 'dismantled'], true)) {
            $serial->status = 'in_stock';
        }

        $serial->save();

        // Nếu status đổi giữa in_stock/dismantled thì stock_quantity của
        // product cần recompute để khớp với count sellable serials.
        if ($serial->wasChanged('status') && $serial->product) {
            $serial->product->recomputeFromSerials();
        }
    }

    /**
     * HOTFIX 24.18 — operator-triggered "đã lắp lại xong" restore.
     *
     * HOTFIX 24.16 only restores status during a Task::changeStatus
     * transition, which means a serial that was already stuck at
     * `dismantled + ready` BEFORE that hotfix shipped (or whose
     * repair task was never re-completed) stays stuck forever. The
     * UI then shows the contradiction the tester reported on
     * `MTXNBZJ1WK`: badge says "Sẵn bán" (because repair_status=ready)
     * while the right-hand column still reads "dismantled", and the
     * product stock stays at 0.
     *
     * This helper is the explicit "hoàn nguyên / đã lắp lại" lever.
     * It refuses to act unless the serial is genuinely back to a
     * sellable shape:
     *   - currently dismantled,
     *   - repair_status already 'ready' (so we're not bypassing a
     *     mid-repair task),
     *   - never sold (invoice_id / sold_at null),
     *   - never returned to supplier (purchase_return_id null),
     *   - no live disassembly task on this serial still holding a
     *     direction='import' part that has output stock in flight.
     *
     * On success: bumps the serial to in_stock + recomputes the
     * product's stock_quantity from the sellable-serial count. Never
     * mutates inventory_total_cost (moving-avg cost stays put — the
     * machine had its cost re-credited when the disassembly was
     * rolled back, if it was; otherwise the existing cost stands).
     *
     * Returns a short summary the API layer can echo back so the
     * operator UI knows whether anything actually changed.
     *
     * @return array{restored: bool, reason?: string, serial: SerialImei}
     */
    public function restoreReassembledSerial(int $serialId, ?int $userId = null): array
    {
        return DB::transaction(function () use ($serialId, $userId) {
            /** @var SerialImei|null $serial */
            $serial = SerialImei::lockForUpdate()->find($serialId);
            if (!$serial) {
                throw new \RuntimeException('Không tìm thấy serial cần hoàn nguyên.');
            }

            // Already sellable — no-op, but surface it to the caller
            // so the operator doesn't think the request failed.
            if ($serial->status === 'in_stock' && $serial->repair_status === 'ready') {
                return ['restored' => false, 'reason' => 'already_in_stock', 'serial' => $serial];
            }

            if ($serial->status !== 'dismantled') {
                throw new \RuntimeException(
                    'Serial "' . $serial->serial_number . '" hiện ở trạng thái "' .
                    $serial->status . '" — chỉ phục hồi được serial đang dismantled.'
                );
            }
            if ($serial->repair_status !== 'ready') {
                throw new \RuntimeException(
                    'Phiếu sửa serial "' . $serial->serial_number . '" chưa hoàn tất (repair_status=' .
                    ($serial->repair_status ?? 'null') . ') — không thể hoàn nguyên.'
                );
            }
            if (!empty($serial->invoice_id) || !empty($serial->sold_at)) {
                throw new \RuntimeException('Serial đã bán, không thể hoàn nguyên về kho.');
            }
            if (!empty($serial->purchase_return_id)) {
                throw new \RuntimeException('Serial đã trả NCC, không thể hoàn nguyên về kho.');
            }

            // Active import parts: any open (non-completed, non-cancelled)
            // repair task that still has a direction='import' part is
            // physical evidence the machine is still missing pieces.
            // Completed/cancelled tasks no longer hold the serial open —
            // those import parts have already been settled into inventory
            // and the operator is now telling us the body has been put
            // back together.
            $activeImport = TaskPart::query()
                ->where('direction', 'import')
                ->whereHas('task', function ($q) use ($serialId) {
                    $q->where('serial_imei_id', $serialId)
                      ->whereNotIn('status', [Task::STATUS_COMPLETED, Task::STATUS_CANCELLED]);
                })
                ->exists();
            if ($activeImport) {
                throw new \RuntimeException(
                    'Vẫn còn phiếu sửa đang mở với linh kiện đã bóc — đóng/hoàn tất phiếu trước khi hoàn nguyên.'
                );
            }

            $serial->status = 'in_stock';
            $serial->save();

            if ($serial->product) {
                $serial->product->recomputeFromSerials();
            }

            ActivityLog::create([
                'user_id'      => $userId,
                'action'       => 'serial_imei.restore_reassembled',
                'subject_type' => SerialImei::class,
                'subject_id'   => $serial->id,
                'description'  => 'Hoàn nguyên serial sau khi lắp lại: ' . $serial->serial_number,
            ]);

            return ['restored' => true, 'serial' => $serial->refresh()];
        });
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
     *
     * @param array|null $serialIds  Bắt buộc nếu product has_serial=true.
     */
    public function addPart(Task $task, int $productId, int $quantity = 1, ?string $notes = null, ?int $exportedBy = null, ?array $serialIds = null): TaskPart
    {
        return DB::transaction(function () use ($task, $productId, $quantity, $notes, $exportedBy, $serialIds) {
            $product = Product::findOrFail($productId);

            // ── Serial validation cho linh kiện has_serial ──
            if ($product->has_serial) {
                $this->validatePartSerials($product, $quantity, $serialIds);
            }

            if ($product->stock_quantity < $quantity) {
                throw new \RuntimeException("Tồn kho linh kiện \"{$product->name}\" không đủ (còn {$product->stock_quantity}, cần {$quantity}).");
            }

            $unitCost = $product->cost_price ?? 0;

            // Nếu has_serial, tính cost từ từng serial thực tế
            if ($product->has_serial && !empty($serialIds)) {
                $serials = SerialImei::whereIn('id', $serialIds)->get();
                $totalCost = $serials->sum('cost_price');
                $unitCost = $quantity > 0 ? round($totalCost / $quantity) : 0;
            } else {
                $totalCost = $unitCost * $quantity;
            }

            $part = TaskPart::create([
                'task_id'     => $task->id,
                'product_id'  => $productId,
                'quantity'    => $quantity,
                'unit_cost'   => $unitCost,
                'total_cost'  => $totalCost,
                'exported_by' => $exportedBy,
                'notes'       => $notes,
                'direction'   => 'export',
                'serial_ids'  => $product->has_serial ? $serialIds : null,
            ]);

            // Trừ tồn kho linh kiện qua CostingService (cập nhật inventory_total_cost)
            MovingAvgCostingService::applySale($product, $quantity);
            $product->refresh();

            // Đánh dấu serial linh kiện đã dùng
            if ($product->has_serial && !empty($serialIds)) {
                SerialImei::whereIn('id', $serialIds)->update(['status' => 'used_for_repair']);
                $product->recomputeFromSerials();
            }

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

            // Step 24.0: audit log part install
            ActivityLog::log(
                ActivityLog::ACTION_PART_INSTALL,
                "Lắp linh kiện {$product->name} (x{$quantity}) vào phiếu {$task->code}",
                $task,
                [
                    'task_part_id' => $part->id,
                    'product_id'   => $product->id,
                    'quantity'     => $quantity,
                    'unit_cost'    => (float) $unitCost,
                    'total_cost'   => (float) $totalCost,
                    'serial_ids'   => $product->has_serial ? $serialIds : null,
                ]
            );

            return $part;
        });
    }

    /**
     * Validate serial IDs cho linh kiện has_serial trước khi xuất.
     */
    protected function validatePartSerials(Product $product, int $quantity, ?array $serialIds): void
    {
        if (empty($serialIds)) {
            throw new \RuntimeException("Sản phẩm \"{$product->name}\" có Serial/IMEI — cần chọn đủ {$quantity} serial.");
        }

        if (count($serialIds) !== $quantity) {
            throw new \RuntimeException("Số Serial/IMEI đã chọn (" . count($serialIds) . ") không khớp số lượng ({$quantity}).");
        }

        // Duplicate check
        if (count($serialIds) !== count(array_unique($serialIds))) {
            throw new \RuntimeException("Serial/IMEI bị trùng trong danh sách.");
        }

        $serials = SerialImei::whereIn('id', $serialIds)->get();

        if ($serials->count() !== count($serialIds)) {
            throw new \RuntimeException("Một hoặc nhiều Serial/IMEI không tồn tại.");
        }

        foreach ($serials as $s) {
            if ((int) $s->product_id !== (int) $product->id) {
                throw new \RuntimeException("Serial {$s->serial_number} không thuộc sản phẩm \"{$product->name}\".");
            }
            if ($s->status !== 'in_stock') {
                throw new \RuntimeException("Serial {$s->serial_number} không còn trong kho (status: {$s->status}).");
            }
        }
    }

    /**
     * Gỡ linh kiện. Step 23.8E: chặn remove cho direction='import' (output từ
     * disassembly) — cần policy rollback riêng (xóa serial output, decrement
     * stock, revert cost). Hiện chưa hỗ trợ → block để tránh inconsistency.
     */
    public function removePart(TaskPart $part): void
    {
        if ($part->direction === 'import') {
            throw new \RuntimeException(
                'Không thể gỡ linh kiện đã bóc tách (direction=import). '
                . 'Cần thao tác rollback riêng để xóa serial output đã tạo.'
            );
        }

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

                // Hoàn serial linh kiện về in_stock
                if (!empty($part->serial_ids) && $product->has_serial) {
                    SerialImei::whereIn('id', $part->serial_ids)
                        ->where('status', 'used_for_repair')
                        ->update(['status' => 'in_stock']);
                    $product->recomputeFromSerials();
                }
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

            $partSnapshot = [
                'task_part_id' => $part->id,
                'product_id'   => $part->product_id,
                'quantity'     => (int) $part->quantity,
                'unit_cost'    => (float) $part->unit_cost,
                'total_cost'   => (float) $part->total_cost,
                'serial_ids'   => $part->serial_ids,
            ];
            $part->delete();
            $task->recalculateCosts();

            // Step 24.0: audit log part remove (export direction only — import bị block ở guard trên)
            ActivityLog::log(
                ActivityLog::ACTION_PART_REMOVE,
                "Gỡ linh kiện khỏi phiếu {$task->code}",
                $task,
                $partSnapshot
            );
        });
    }

    /**
     * Bóc linh kiện từ máy — nhập vào tồn kho. (Step 23.8E hardening)
     *
     * Rules:
     *   - Chỉ áp dụng cho internal repair (task.external = false, có serial_imei_id).
     *   - Task không completed/cancelled.
     *   - Cost cap: tổng output cost ≤ task.original_cost + sum(export parts) - sum(prior import parts).
     *   - Output có serial: bắt buộc serial_numbers (count khớp qty, không trùng, chưa tồn tại DB).
     *   - Output không serial: không nhận serial_numbers.
     *   - Sau bóc tách thành công, set serial máy gốc status='dismantled' (idempotent).
     *
     * @param array<string>|null $serialNumbers Bắt buộc nếu output product has_serial=true.
     */
    public function disassemblePart(
        Task $task,
        int $productId,
        int $quantity = 1,
        ?float $unitCost = null,
        ?string $notes = null,
        ?int $exportedBy = null,
        ?array $serialNumbers = null
    ): TaskPart {
        return DB::transaction(function () use ($task, $productId, $quantity, $unitCost, $notes, $exportedBy, $serialNumbers) {
            // ── Guards ──
            if ($task->external) {
                throw new \RuntimeException('Chỉ hỗ trợ bóc tách với phiếu sửa chữa nội bộ.');
            }
            if ($task->type !== Task::TYPE_REPAIR) {
                throw new \RuntimeException('Task không phải phiếu sửa chữa.');
            }
            if (!$task->serial_imei_id || !$task->serialImei) {
                throw new \RuntimeException('Phiếu sửa chữa nội bộ phải có serial máy gốc.');
            }
            if (in_array($task->status, [Task::STATUS_COMPLETED, Task::STATUS_CANCELLED], true)) {
                throw new \RuntimeException('Phiếu đã hoàn thành/hủy, không thể bóc tách.');
            }
            if ($quantity < 1) {
                throw new \RuntimeException('Số lượng bóc tách phải >= 1.');
            }
            if ($unitCost !== null && $unitCost < 0) {
                throw new \RuntimeException('Đơn giá bóc tách không được âm.');
            }

            $product = Product::findOrFail($productId);
            $cost = $unitCost ?? (float) ($product->cost_price ?? 0);
            $totalCost = $cost * $quantity;

            // ── Cost cap ──
            $originalCost = (float) $task->original_cost;
            $exportTotal  = (float) $task->parts()->where('direction', 'export')->sum('total_cost');
            $importTotal  = (float) $task->parts()->where('direction', 'import')->sum('total_cost');
            $available    = $originalCost + $exportTotal - $importTotal;

            if ($totalCost > $available + 0.01) {
                throw new \RuntimeException(sprintf(
                    'Tổng giá vốn linh kiện bóc tách (%s) vượt giá vốn khả dụng của máy (%s).',
                    number_format($totalCost),
                    number_format(max(0, $available))
                ));
            }

            // ── Validate serial output ──
            if ($product->has_serial) {
                if (empty($serialNumbers)) {
                    throw new \RuntimeException("Linh kiện \"{$product->name}\" có Serial/IMEI cần nhập đủ {$quantity} serial.");
                }
                if (count($serialNumbers) !== $quantity) {
                    throw new \RuntimeException(
                        'Số serial bóc tách (' . count($serialNumbers) . ") không khớp số lượng ({$quantity})."
                    );
                }
                if (count($serialNumbers) !== count(array_unique($serialNumbers))) {
                    throw new \RuntimeException('Serial output bị trùng trong danh sách.');
                }
                $existing = SerialImei::whereIn('serial_number', $serialNumbers)->pluck('serial_number')->all();
                if (!empty($existing)) {
                    throw new \RuntimeException('Serial output đã tồn tại: ' . implode(', ', $existing));
                }
            } elseif (!empty($serialNumbers)) {
                throw new \RuntimeException("Linh kiện \"{$product->name}\" không phải hàng Serial/IMEI — không được gửi serial_numbers.");
            }

            // ── Create task_part (import) ──
            $createdSerialIds = [];
            if ($product->has_serial) {
                foreach ($serialNumbers as $sn) {
                    $newSerial = SerialImei::create([
                        'product_id'    => $product->id,
                        'serial_number' => $sn,
                        'status'        => 'in_stock',
                        'cost_price'    => $cost,
                    ]);
                    $createdSerialIds[] = $newSerial->id;
                }
            }

            $part = TaskPart::create([
                'task_id'     => $task->id,
                'product_id'  => $productId,
                'quantity'    => $quantity,
                'unit_cost'   => $cost,
                'total_cost'  => $totalCost,
                'exported_by' => $exportedBy,
                'notes'       => $notes,
                'direction'   => 'import',
                'serial_ids'  => $product->has_serial ? $createdSerialIds : null,
            ]);

            // ── Tăng tồn linh kiện output ──
            MovingAvgCostingService::applyPurchase($product, $quantity, $cost);
            $product->refresh();

            // Recompute từ serials nếu has_serial
            if ($product->has_serial) {
                $product->recomputeFromSerials();
            }

            // Stock movement
            StockMovementService::record(
                $product,
                StockMovementService::TYPE_REPAIR_IN,
                $quantity,
                $cost,
                $task,
                ['note' => 'Bóc linh kiện từ máy — nhập kho']
            );

            // ── Trừ giá vốn máy + đánh dấu serial gốc dismantled ──
            $deltaCost = -$totalCost;
            $serial = $task->serialImei;
            $serial->cost_price = max(0, (float) $serial->cost_price + $deltaCost);

            // Idempotent: chỉ set dismantled lần đầu để tránh double event
            if ($serial->status !== 'dismantled') {
                $serial->status = 'dismantled';
            }
            $serial->save();

            if ($serial->product) {
                MovingAvgCostingService::applyRepairAdjustment($serial->product, $deltaCost);
                // Sau khi serial input chuyển dismantled (không còn in_stock), recompute để
                // sync stock_quantity cho product gốc theo serial thực tế.
                $serial->product->recomputeFromSerials();
            }

            $task->recalculateCosts();

            // Step 24.0: audit log disassembly
            ActivityLog::log(
                ActivityLog::ACTION_PART_DISASSEMBLE,
                "Bóc linh kiện {$product->name} (x{$quantity}) từ phiếu {$task->code}",
                $task,
                [
                    'task_part_id'      => $part->id,
                    'output_product_id' => $product->id,
                    'quantity'          => $quantity,
                    'unit_cost'         => (float) $cost,
                    'total_cost'        => (float) $totalCost,
                    'output_serial_ids' => $createdSerialIds,
                    'input_serial_id'   => $task->serial_imei_id,
                ]
            );

            return $part;
        });
    }

    /**
     * HOTFIX 24.11B — Rollback a disassembled part (direction='import').
     *
     * removePart() intentionally blocks direction='import' because the
     * disassembly flow created a TaskPart, bumped output stock, created
     * output serials, debited the source machine's cost, marked the source
     * serial 'dismantled', and wrote a TYPE_REPAIR_IN movement. A safe
     * rollback must reverse all of those — atomically — and refuse if any
     * of the output stock/serials has already been consumed downstream.
     *
     * Guards (fail-fast, no mutation on guard failure):
     *   - part.direction must be 'import'
     *   - task must be a repair, not completed/cancelled
     *   - output product must exist; output stock must be enough
     *   - if has_serial: every stored serial_id must still belong to the
     *     output product and be status='in_stock'
     */
    public function rollbackDisassembledPart(TaskPart $part, ?int $userId = null): void
    {
        if ($part->direction !== 'import') {
            throw new \RuntimeException(
                'Chỉ hoàn tác được linh kiện đã bóc tách (direction=import). Linh kiện lắp dùng nút "Gỡ".'
            );
        }

        $task = $part->task;
        if (!$task) {
            throw new \RuntimeException('Không tìm thấy phiếu sửa chữa của linh kiện này.');
        }
        if (in_array($task->status, [Task::STATUS_COMPLETED, Task::STATUS_CANCELLED], true)) {
            throw new \RuntimeException('Phiếu đã hoàn thành/hủy, không thể hoàn tác.');
        }
        if ($task->type !== Task::TYPE_REPAIR) {
            throw new \RuntimeException('Task không phải phiếu sửa chữa.');
        }

        $product = Product::find($part->product_id);
        if (!$product) {
            throw new \RuntimeException('Không tìm thấy sản phẩm linh kiện output.');
        }

        $quantity = (int) $part->quantity;
        $unitCost = (float) ($part->unit_cost ?? 0);
        $totalCost = (float) $part->total_cost;

        if ($quantity < 1) {
            throw new \RuntimeException('Số lượng linh kiện không hợp lệ.');
        }

        // ── Pre-flight: serial sanity (no mutation yet) ────────────────
        $serialIds = is_array($part->serial_ids) ? $part->serial_ids : [];
        if ($product->has_serial) {
            if (count($serialIds) !== $quantity) {
                throw new \RuntimeException('Dữ liệu serial output không khớp số lượng linh kiện đã bóc.');
            }
            $serials = SerialImei::whereIn('id', $serialIds)
                ->where('product_id', $product->id)
                ->get();
            if ($serials->count() !== $quantity) {
                throw new \RuntimeException('Một hoặc nhiều serial output đã bị xóa hoặc đổi sản phẩm — không thể hoàn tác.');
            }
            foreach ($serials as $s) {
                if ($s->status !== 'in_stock') {
                    throw new \RuntimeException(
                        'Serial output "' . $s->serial_number . '" hiện ở trạng thái "' . $s->status .
                        '" — không thể hoàn tác (đã phát sinh giao dịch khác).'
                    );
                }
            }
        }

        // ── Pre-flight: non-serial stock sanity ────────────────────────
        $product->refresh();
        if (!$product->has_serial && (float) $product->stock_quantity < $quantity) {
            throw new \RuntimeException(
                'Tồn kho linh kiện hiện không đủ để hoàn tác (cần ' . $quantity . ', còn ' .
                (int) $product->stock_quantity . ').'
            );
        }

        DB::transaction(function () use ($part, $task, $product, $quantity, $unitCost, $totalCost, $serialIds, $userId) {
            // ── 1. Reverse output stock ──────────────────────────────
            // Use applySaleReturn-style adjustment in reverse: decrement
            // qty at the snapshot cost so inventory_total_cost balances.
            MovingAvgCostingService::applySale($product, $quantity);
            $product->refresh();

            // ── 2. Delete output serials (only after the in_stock guard) ─
            if ($product->has_serial && !empty($serialIds)) {
                SerialImei::whereIn('id', $serialIds)
                    ->where('product_id', $product->id)
                    ->where('status', 'in_stock')
                    ->delete();
                $product->refresh();
                $product->recomputeFromSerials();
            }

            // ── 3. Stock movement (reverse) ──────────────────────────
            StockMovementService::record(
                $product,
                StockMovementService::TYPE_REPAIR_OUT,
                $quantity,
                $unitCost,
                $task,
                ['note' => 'Hoàn tác bóc linh kiện — xuất ngược khỏi kho']
            );

            // ── 4. Restore machine serial cost ───────────────────────
            $serial = $task->serialImei;
            if ($serial) {
                $serial->cost_price = max(0, (float) $serial->cost_price + $totalCost);
                $serial->save();

                if ($serial->product) {
                    MovingAvgCostingService::applyRepairAdjustment($serial->product, $totalCost);
                }
            } elseif ($task->product_id) {
                $repairedProduct = Product::find($task->product_id);
                if ($repairedProduct) {
                    MovingAvgCostingService::applyRepairAdjustment($repairedProduct, $totalCost);
                }
            }

            // ── 5. Restore machine serial status if no other import parts ─
            $remainingImportTotal = (float) $task->parts()
                ->where('id', '!=', $part->id)
                ->where('direction', 'import')
                ->sum('total_cost');

            if ($serial && $remainingImportTotal <= 0 && $serial->status === 'dismantled') {
                $serial->status = 'in_stock';
                $serial->save();
                if ($serial->product) {
                    $serial->product->recomputeFromSerials();
                }
            }

            // ── 6. Snapshot + delete TaskPart + recalc ──────────────
            $snapshot = [
                'task_part_id'      => $part->id,
                'output_product_id' => $part->product_id,
                'quantity'          => $quantity,
                'unit_cost'         => $unitCost,
                'total_cost'        => $totalCost,
                'output_serial_ids' => $serialIds,
                'input_serial_id'   => $task->serial_imei_id,
            ];
            $part->delete();
            $task->recalculateCosts();

            // ── 7. Audit log ────────────────────────────────────────
            ActivityLog::log(
                ActivityLog::ACTION_PART_DISASSEMBLE_ROLLBACK,
                "Hoàn tác bóc linh kiện {$product->name} (x{$quantity}) khỏi phiếu {$task->code}",
                $task,
                $snapshot
            );
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

    /**
     * Hoàn thành sửa chữa khách ngoài — tạo invoice stock-neutral + cashflow + công nợ.
     *
     * KHÔNG trừ tồn kho (đã trừ ở addPart).
     * KHÔNG đổi serial status (đã mark ở addPart).
     * Idempotent: reject nếu task.invoice_id đã có.
     *
     * Step 23.8D: chấp nhận `warranty_policy` để miễn công/linh kiện/toàn bộ
     * nếu task đã được attach warranty còn hạn.
     */
    public function completeExternalRepair(Task $task, array $data): Task
    {
        return DB::transaction(function () use ($task, $data) {
            // ── Guards ──
            if (!$task->external) {
                throw new \RuntimeException('Chỉ phiếu sửa chữa khách ngoài mới dùng chức năng này.');
            }
            if ($task->type !== Task::TYPE_REPAIR) {
                throw new \RuntimeException('Task không phải phiếu sửa chữa.');
            }
            if ($task->status === Task::STATUS_CANCELLED) {
                throw new \RuntimeException('Phiếu sửa chữa đã hủy, không thể hoàn thành.');
            }
            if ($task->invoice_id) {
                throw new \RuntimeException('Phiếu sửa chữa đã hoàn thành và có hóa đơn.');
            }

            $laborFee    = (float) ($data['labor_fee'] ?? 0);
            $partPrices  = $data['part_prices'] ?? [];
            $policy      = $data['warranty_policy'] ?? Task::WARRANTY_POLICY_NONE;

            if (!in_array($policy, Task::WARRANTY_POLICIES, true)) {
                throw new \RuntimeException("Chính sách bảo hành không hợp lệ: {$policy}.");
            }

            // ── Validate policy vs warranty validity ──
            $warranty = $task->warranty_id ? Warranty::find($task->warranty_id) : null;
            $warrantyValid = $warranty && $this->isWarrantyValid($warranty);

            if ($policy !== Task::WARRANTY_POLICY_NONE && !$warrantyValid) {
                throw new \RuntimeException(
                    'Không thể áp chính sách bảo hành: phiếu chưa gắn bảo hành hoặc bảo hành đã hết hạn.'
                );
            }

            // ── Snapshot giá bán linh kiện + tính parts_total (gross, trước khi áp policy) ──
            $partsTotal = 0;
            $exportParts = $task->parts()->where('direction', 'export')->get();
            foreach ($exportParts as $part) {
                $salePrice = isset($partPrices[$part->id])
                    ? (float) $partPrices[$part->id]
                    : (float) ($part->product?->retail_price ?? 0);
                $part->sale_price = $salePrice;
                $part->save();
                $partsTotal += $salePrice * (int) $part->quantity;
            }

            $grossLabor = $laborFee;
            $grossParts = $partsTotal;
            $grossTotal = $grossLabor + $grossParts;

            // ── Áp warranty policy (chỉ ảnh hưởng doanh thu/khách phải trả, không đụng kho) ──
            $coveredLabor = 0.0;
            $coveredParts = 0.0;
            if ($policy === Task::WARRANTY_POLICY_FREE_LABOR) {
                $coveredLabor = $grossLabor;
            } elseif ($policy === Task::WARRANTY_POLICY_FREE_PARTS) {
                $coveredParts = $grossParts;
            } elseif ($policy === Task::WARRANTY_POLICY_FULL_FREE) {
                $coveredLabor = $grossLabor;
                $coveredParts = $grossParts;
            }
            $coveredAmount = $coveredLabor + $coveredParts;

            $payableLabor = $grossLabor - $coveredLabor;
            $payableParts = $grossParts - $coveredParts;
            $totalAmount  = $payableLabor + $payableParts;

            $paidAmount  = min((float) ($data['paid_amount'] ?? 0), $totalAmount);
            $debtAmount  = $totalAmount - $paidAmount;

            // ── Validate debt requires customer ──
            if ($debtAmount > 0.01 && !$task->customer_id) {
                throw new \RuntimeException('Phiếu sửa chữa còn nợ phải có khách hàng (customer_id).');
            }

            // ── Create stock-neutral invoice ──
            $invoice = \App\Models\Invoice::create([
                'code'            => 'SC-HD' . date('YmdHis') . rand(10, 99),
                'customer_id'     => $task->customer_id,
                'branch_id'       => $task->branch_id,
                'status'          => 'Hoàn thành',
                'source_type'     => 'repair',
                'subtotal'        => $totalAmount,
                'discount'        => 0,
                'total'           => $totalAmount,
                'customer_paid'   => $paidAmount,
                'note'            => "Hóa đơn sửa chữa {$task->code}" . ($data['note'] ?? ''),
                'created_by_name' => auth()->user()?->name ?? 'Hệ thống',
                'payment_method'  => $data['payment_method'] ?? 'cash',
            ]);

            // ── Invoice items: labor fee (sau policy) ──
            if ($grossLabor > 0) {
                $laborNote = "Task {$task->code}";
                if ($coveredLabor > 0) {
                    $laborNote .= ' — Miễn công theo bảo hành';
                }
                $invoice->items()->create([
                    'product_id'  => null,
                    'quantity'    => 1,
                    'price'       => $payableLabor,
                    'cost_price'  => 0,
                    'discount'    => 0,
                    'subtotal'    => $payableLabor,
                    'description' => 'Tiền công sửa chữa',
                    'note'        => $laborNote,
                ]);
            }

            // ── Invoice items: parts (NO stock deduction; price=0 nếu free_parts) ──
            $partsCovered = ($policy === Task::WARRANTY_POLICY_FREE_PARTS || $policy === Task::WARRANTY_POLICY_FULL_FREE);
            foreach ($exportParts as $part) {
                $salePrice = $partsCovered ? 0.0 : (float) $part->sale_price;
                $qty       = (int) $part->quantity;
                $partNote  = "Task {$task->code}";
                if ($partsCovered) {
                    $partNote .= ' — Miễn linh kiện theo bảo hành';
                }
                $invoice->items()->create([
                    'product_id'  => $part->product_id,
                    'quantity'    => $qty,
                    'price'       => $salePrice,
                    'cost_price'  => (float) $part->unit_cost,
                    'discount'    => 0,
                    'subtotal'    => $salePrice * $qty,
                    'description' => 'Linh kiện sửa chữa',
                    'note'        => $partNote,
                ]);
            }

            // ── CashFlow receipt ──
            if ($paidAmount > 0.01) {
                $customerName = $task->customer_name
                    ?? ($task->customer_id ? \App\Models\Customer::find($task->customer_id)?->name : null)
                    ?? 'Khách sửa chữa';

                \App\Models\CashFlow::create([
                    'code'           => 'PT' . date('YmdHis') . rand(10, 99),
                    'type'           => 'receipt',
                    'amount'         => $paidAmount,
                    'time'           => now(),
                    'category'       => 'Thu tiền sửa chữa',
                    'target_type'    => 'Khách hàng',
                    'target_id'      => $task->customer_id,
                    'target_name'    => $customerName,
                    'reference_type' => 'Invoice',
                    'reference_code' => $invoice->code,
                    'payment_method' => $data['payment_method'] ?? 'cash',
                    'description'    => "Thu tiền sửa chữa {$task->code} - {$customerName}",
                ]);
            }

            // ── Customer debt ──
            if ($debtAmount > 0.01 && $task->customer_id) {
                app(CustomerDebtService::class)->recordSale(
                    $task->customer_id,
                    $debtAmount,
                    $invoice,
                    "Nợ sửa chữa {$task->code}"
                );
            }

            // ── Update task ──
            $task->update([
                'status'                  => Task::STATUS_COMPLETED,
                'sub_status'              => 'completed',
                'completed_at'            => now(),
                'invoice_id'              => $invoice->id,
                'labor_fee'               => $grossLabor,
                'parts_total'             => $grossParts,
                'total_amount'            => $totalAmount,
                'paid_amount'             => $paidAmount,
                'debt_amount'             => $debtAmount,
                'warranty_policy'         => $policy,
                'warranty_covered_amount' => $coveredAmount,
                'customer_payable_amount' => $totalAmount,
                'progress'                => 100,
            ]);

            // Step 24.0: audit log repair complete
            ActivityLog::log(
                ActivityLog::ACTION_TASK_COMPLETE,
                "Hoàn thành sửa chữa {$task->code}",
                $task,
                [
                    'invoice_id'      => $invoice->id,
                    'total_amount'    => (float) $totalAmount,
                    'paid_amount'    => (float) $paidAmount,
                    'debt_amount'    => (float) $debtAmount,
                    'warranty_policy' => $policy,
                    'covered_amount'  => (float) $coveredAmount,
                ]
            );

            return $task->fresh();
        });
    }

    /**
     * Step 23.8D: Gắn bảo hành vào phiếu sửa chữa khách ngoài.
     *
     * Rules:
     *   - task.external = true.
     *   - task.type = repair.
     *   - task.status không phải completed/cancelled.
     *   - Nếu warranty.serial_imei có và task có serial reference → phải khớp.
     *   - KHÔNG tự đổi trạng thái warranty record gốc.
     *   - Cho phép attach cả warranty hết hạn (để lưu lịch sử) — chỉ chặn việc áp policy
     *     ở completeExternalRepair.
     */
    public function attachWarranty(Task $task, Warranty $warranty): Task
    {
        return DB::transaction(function () use ($task, $warranty) {
            if (!$task->external) {
                throw new \RuntimeException('Chỉ phiếu sửa chữa khách ngoài mới gắn được bảo hành.');
            }
            if ($task->type !== Task::TYPE_REPAIR) {
                throw new \RuntimeException('Task không phải phiếu sửa chữa.');
            }
            if (in_array($task->status, [Task::STATUS_COMPLETED, Task::STATUS_CANCELLED], true)) {
                throw new \RuntimeException('Phiếu đã hoàn thành/hủy, không thể gắn bảo hành.');
            }

            // Serial mismatch guard — task có thể giữ serial khách dưới dạng issue_description/notes
            // nên ta dùng serialImei->serial_number nếu có; còn không dùng task.notes/issue
            // làm hint mềm. Ưu tiên data structured nhất.
            if ($warranty->serial_imei) {
                $taskSerial = null;
                if ($task->serialImei) {
                    $taskSerial = $task->serialImei->serial_number;
                }
                if ($taskSerial && $taskSerial !== $warranty->serial_imei) {
                    throw new \RuntimeException(
                        "Serial bảo hành ({$warranty->serial_imei}) không khớp serial trên phiếu ({$taskSerial})."
                    );
                }
            }

            $task->update(['warranty_id' => $warranty->id]);

            // Step 24.0: audit log warranty attach
            ActivityLog::log(
                ActivityLog::ACTION_TASK_WARRANTY_ATTACH,
                "Gắn bảo hành {$warranty->invoice_code} vào phiếu sửa chữa {$task->code}",
                $task,
                [
                    'warranty_id'    => $warranty->id,
                    'invoice_code'   => $warranty->invoice_code,
                    'serial_imei'    => $warranty->serial_imei,
                ]
            );

            return $task->fresh();
        });
    }

    /**
     * Step 23.8D: Warranty còn hạn nếu warranty_end_date >= today.
     * warranty_end_date null → coi như không xác định, không tự miễn phí.
     */
    public function isWarrantyValid(Warranty $warranty): bool
    {
        if (!$warranty->warranty_end_date) {
            return false;
        }
        return $warranty->warranty_end_date->endOfDay()->gte(now());
    }
}
