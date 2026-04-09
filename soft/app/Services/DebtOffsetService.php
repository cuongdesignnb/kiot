<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\CustomerDebt;
use App\Models\SupplierDebt;
use App\Models\DebtOffset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DebtOffsetService
{
    /**
     * Cấn bằng công nợ 2 chiều KH ↔ NCC
     *
     * Logic:
     * - receivable = KH nợ mình (total_debt của customer)
     * - payable    = Mình nợ NCC (total_debt của supplier)
     * - offsetAmount = min(receivable, payable) hoặc custom amount
     * - Tạo CustomerDebt(TYPE_OFFSET, -offsetAmount) → giảm nợ KH
     * - Tạo SupplierDebt(type=offset, -offsetAmount) → giảm nợ NCC
     * - Tạo DebtOffset record ghi lại phiên cấn bằng
     */
    public function executeOffset(
        int $customerId,
        ?float $customAmount = null,
        ?string $note = null,
        bool $isAuto = false
    ): DebtOffset {
        return DB::transaction(function () use ($customerId, $customAmount, $note, $isAuto) {

            // 1. Lock both sides
            $customer = Customer::lockForUpdate()->findOrFail($customerId);

            if (!$customer->linked_supplier_id) {
                throw new \Exception('Khách hàng chưa liên kết với nhà cung cấp nào.');
            }

            $supplier = Supplier::lockForUpdate()->findOrFail($customer->linked_supplier_id);

            // 2. Calculate offset amounts (abs() vì DB có thể lưu âm)
            $receivable = abs($customer->total_debt ?? 0); // KH nợ mình
            $payable    = abs($supplier->total_debt ?? 0);  // Mình nợ NCC

            if ($receivable <= 0 && $payable <= 0) {
                throw new \Exception('Không có công nợ nào để cấn bằng.');
            }

            $maxOffset = min($receivable, $payable);

            if ($maxOffset <= 0) {
                throw new \Exception('Một bên không có nợ, không thể cấn bằng. Phải thu: ' .
                    number_format($receivable) . ', Phải trả: ' . number_format($payable));
            }

            // Determine amount to offset
            $offsetAmount = $customAmount ? min(abs($customAmount), $maxOffset) : $maxOffset;

            if ($offsetAmount <= 0) {
                throw new \Exception('Số tiền cấn bằng phải lớn hơn 0.');
            }

            $offsetCode = DebtOffset::generateCode();

            // 3. Create CustomerDebt (offset = giảm nợ KH)
            CustomerDebt::createDebtRecord(
                customerId: $customerId,
                amount: -$offsetAmount,
                type: CustomerDebt::TYPE_OFFSET,
                refCode: $offsetCode,
                note: $note ?? "Cấn bằng công nợ với NCC {$supplier->name} ({$supplier->code})"
            );

            // 4. Create SupplierDebt (offset = giảm nợ NCC)
            SupplierDebt::createDebtRecord(
                supplierId: $supplier->id,
                amount: -$offsetAmount,
                type: 'offset',
                refCode: $offsetCode,
                note: $note ?? "Cấn bằng công nợ với KH {$customer->name} ({$customer->code})"
            );

            // 5. Reload fresh data after debt records updated totals
            $customer->refresh();
            $supplier->refresh();

            // 6. Create DebtOffset record
            $debtOffset = DebtOffset::create([
                'code' => $offsetCode,
                'customer_id' => $customerId,
                'amount' => $offsetAmount,
                'receivable_before' => $receivable,
                'payable_before' => $payable,
                'receivable_after' => $customer->total_debt,
                'payable_after' => $supplier->total_debt,
                'is_auto' => $isAuto,
                'note' => $note,
                'user_id' => auth()->id(),
                'status' => 'active',
            ]);

            Log::info('Debt offset executed', [
                'offset_code' => $offsetCode,
                'customer_id' => $customerId,
                'supplier_id' => $supplier->id,
                'amount' => $offsetAmount,
                'receivable' => "{$receivable} → {$customer->total_debt}",
                'payable' => "{$payable} → {$supplier->total_debt}",
            ]);

            return $debtOffset;
        });
    }

    /**
     * Hủy phiên cấn bằng (reverse offset)
     */
    public function cancelOffset(int $offsetId, string $reason): DebtOffset
    {
        return DB::transaction(function () use ($offsetId, $reason) {
            $offset = DebtOffset::lockForUpdate()->findOrFail($offsetId);

            if ($offset->status === 'cancelled') {
                throw new \Exception('Phiên cấn bằng này đã bị hủy trước đó.');
            }

            $customer = Customer::lockForUpdate()->findOrFail($offset->customer_id);
            $supplier = Supplier::lockForUpdate()->findOrFail($customer->linked_supplier_id);

            $reverseCode = 'REV-' . $offset->code;

            // Reverse: tăng lại nợ KH
            CustomerDebt::createDebtRecord(
                customerId: $customer->id,
                amount: $offset->amount,  // positive → tăng nợ lại
                type: CustomerDebt::TYPE_ADJUSTMENT,
                refCode: $reverseCode,
                note: "Hủy cấn bằng {$offset->code}: {$reason}"
            );

            // Reverse: tăng lại nợ NCC
            SupplierDebt::createDebtRecord(
                supplierId: $supplier->id,
                amount: $offset->amount,  // positive → tăng nợ lại
                type: 'adjustment',
                refCode: $reverseCode,
                note: "Hủy cấn bằng {$offset->code}: {$reason}"
            );

            $offset->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancel_reason' => $reason,
            ]);

            Log::info('Debt offset cancelled', [
                'offset_code' => $offset->code,
                'amount' => $offset->amount,
                'reason' => $reason,
            ]);

            return $offset->fresh();
        });
    }

    /**
     * Lấy lịch sử cấn bằng của KH
     */
    public function getHistory(int $customerId)
    {
        return DebtOffset::where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->with(['user:id,name', 'cancelledByUser:id,name'])
            ->get();
    }

    /**
     * Tự động cấn bằng khi phát sinh giao dịch mới
     * Gọi sau khi tạo CustomerDebt hoặc SupplierDebt
     *
     * @param string $side 'customer' hoặc 'supplier'  
     * @param int $entityId ID của customer hoặc supplier vừa phát sinh nợ
     */
    public static function tryAutoOffset(string $side, int $entityId): void
    {
        try {
            if ($side === 'customer') {
                $customer = Customer::find($entityId);
                if (!$customer || !$customer->linked_supplier_id) return;
                $customerId = $customer->id;
            } else {
                $supplier = Supplier::find($entityId);
                if (!$supplier || !$supplier->linked_customer_id) return;
                $customerId = $supplier->linked_customer_id;
            }

            // Kiểm tra cả 2 bên đều có nợ
            $customer = Customer::find($customerId);
            if (!$customer || !$customer->linked_supplier_id) return;
            
            $supplier = Supplier::find($customer->linked_supplier_id);
            if (!$supplier) return;

            $receivable = abs($customer->total_debt ?? 0);
            $payable    = abs($supplier->total_debt ?? 0);
            $maxOffset  = min($receivable, $payable);

            // Chỉ tự cấn bằng khi cả 2 bên đều > 0
            if ($maxOffset <= 0) return;

            // Thực hiện cấn bằng tự động
            $service = new self();
            $service->executeOffset(
                customerId: $customerId,
                customAmount: null, // cấn bằng tối đa
                note: 'Tự động cấn bằng khi phát sinh giao dịch',
                isAuto: true
            );

            Log::info('Auto debt offset triggered', [
                'side' => $side,
                'entity_id' => $entityId,
                'customer_id' => $customerId,
                'amount' => $maxOffset,
            ]);
        } catch (\Exception $e) {
            // Không throw — auto-offset lỗi không ảnh hưởng giao dịch chính
            Log::warning('Auto offset failed (non-blocking)', [
                'side' => $side,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
