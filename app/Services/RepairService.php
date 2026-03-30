<?php

namespace App\Services;

use App\Models\DeviceRepair;
use App\Models\DeviceRepairPart;
use App\Models\Product;
use App\Models\SerialImei;
use Illuminate\Support\Facades\DB;

class RepairService
{
    /**
     * Tạo phiếu sửa chữa.
     */
    public function createRepair(array $data): DeviceRepair
    {
        return DB::transaction(function () use ($data) {
            $serial = SerialImei::findOrFail($data['serial_imei_id']);

            $repair = DeviceRepair::create([
                'code'                 => DeviceRepair::generateCode(),
                'product_id'           => $serial->product_id,
                'serial_imei_id'       => $serial->id,
                'original_cost'        => $serial->cost_price ?: ($serial->product->cost_price ?? 0),
                'parts_cost'           => 0,
                'total_cost'           => $serial->cost_price ?: ($serial->product->cost_price ?? 0),
                'issue_description'    => $data['issue_description'] ?? null,
                'status'               => DeviceRepair::STATUS_PENDING,
                'branch_id'            => $data['branch_id'] ?? null,
                'notes'                => $data['notes'] ?? null,
                'deadline'             => $data['deadline'] ?? null,
                'created_by'           => $data['created_by'] ?? null,
            ]);

            // Nếu serial chưa có cost_price → snapshot từ product
            if (!$serial->cost_price) {
                $serial->cost_price = $serial->product->cost_price ?? 0;
            }
            $serial->repair_status = 'not_started';
            $serial->save();

            return $repair;
        });
    }

    /**
     * Giao phiếu cho nhân viên.
     */
    public function assignEmployee(DeviceRepair $repair, int $employeeId): DeviceRepair
    {
        return DB::transaction(function () use ($repair, $employeeId) {
            $repair->update([
                'assigned_employee_id' => $employeeId,
                'assigned_at'          => now(),
                'status'               => DeviceRepair::STATUS_IN_PROGRESS,
            ]);

            $repair->serialImei->update(['repair_status' => 'repairing']);

            return $repair->fresh();
        });
    }

    /**
     * Xuất linh kiện lắp máy — logic cốt lõi:
     * 1) Lấy giá vốn BQ linh kiện
     * 2) Tạo record repair_part
     * 3) Trừ tồn kho linh kiện
     * 4) Cộng vào repair.parts_cost & total_cost
     * 5) Cộng vào serial.cost_price
     */
    public function addPart(DeviceRepair $repair, int $productId, int $quantity = 1, ?string $notes = null, ?int $exportedBy = null): DeviceRepairPart
    {
        return DB::transaction(function () use ($repair, $productId, $quantity, $notes, $exportedBy) {
            $product = Product::findOrFail($productId);

            if ($product->stock_quantity < $quantity) {
                throw new \RuntimeException("Tồn kho linh kiện \"{$product->name}\" không đủ (còn {$product->stock_quantity}, cần {$quantity}).");
            }

            $unitCost = $product->cost_price ?? 0;
            $totalCost = $unitCost * $quantity;

            // Tạo record linh kiện
            $part = DeviceRepairPart::create([
                'device_repair_id' => $repair->id,
                'product_id'       => $productId,
                'quantity'         => $quantity,
                'unit_cost'        => $unitCost,
                'total_cost'       => $totalCost,
                'exported_by'      => $exportedBy,
                'notes'            => $notes,
            ]);

            // Trừ tồn kho linh kiện
            $product->decrement('stock_quantity', $quantity);

            // Cập nhật chi phí phiếu sửa
            $repair->recalculateCosts();

            // Đồng bộ giá vốn serial = repair.total_cost (luôn chính xác)
            $serial = $repair->serialImei;
            if ($serial) {
                $serial->cost_price = max(0, (float) $repair->total_cost);
                $serial->save();
            }

            return $part;
        });
    }

    /**
     * Gỡ linh kiện (reverse).
     */
    public function removePart(DeviceRepairPart $part): void
    {
        DB::transaction(function () use ($part) {
            $repair = $part->deviceRepair;

            // Cộng lại tồn kho
            Product::where('id', $part->product_id)->increment('stock_quantity', $part->quantity);

            $part->delete();

            // Recalc repair costs
            $repair->recalculateCosts();

            // Đồng bộ giá vốn serial = repair.total_cost (luôn chính xác)
            $serial = $repair->serialImei;
            if ($serial) {
                $serial->cost_price = max(0, (float) $repair->total_cost);
                $serial->save();
            }
        });
    }

    /**
     * Đánh dấu hoàn thành.
     */
    public function markCompleted(DeviceRepair $repair): DeviceRepair
    {
        return DB::transaction(function () use ($repair) {
            $repair->update([
                'status'       => DeviceRepair::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            $repair->serialImei->update(['repair_status' => 'ready']);

            return $repair->fresh();
        });
    }

    /**
     * Tính % hoàn thành của NV trong kỳ.
     */
    public function getEmployeePerformance(int $employeeId, string $from, string $to): array
    {
        $assigned = DeviceRepair::where('assigned_employee_id', $employeeId)
            ->whereBetween('assigned_at', [$from, $to])
            ->count();

        $completed = DeviceRepair::where('assigned_employee_id', $employeeId)
            ->where('status', DeviceRepair::STATUS_COMPLETED)
            ->whereBetween('completed_at', [$from, $to])
            ->count();

        $rate = $assigned > 0 ? round(($completed / $assigned) * 100, 1) : 0;

        $tier = \App\Models\RepairPerformanceTier::getTierForPercent($rate);

        return [
            'assigned'       => $assigned,
            'completed'      => $completed,
            'completion_rate' => $rate,
            'tier'           => $tier,
            'salary_percent' => $tier?->salary_percent ?? 100,
        ];
    }
}
