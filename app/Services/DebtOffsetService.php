<?php

namespace App\Services;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\DebtOffset;
use App\Models\SupplierDebtTransaction;

class DebtOffsetService
{
    /**
     * Tự động đối trừ công nợ giữa KH và NCC cho cùng 1 người.
     * Gọi sau mỗi lần thay đổi debt_amount hoặc supplier_debt_amount.
     *
     * @return array|null  Thông tin đối trừ hoặc null nếu không cần
     */
    public static function offsetDebts(Customer $person): ?array
    {
        return static::doOffset($person, true, null);
    }

    /**
     * Cấn bằng công nợ thủ công - user chỉ định số tiền.
     *
     * @param  Customer  $person
     * @param  float     $amount  Số tiền cấn bằng (phải <= min(receivable, payable))
     * @param  string|null $note  Ghi chú
     * @return array|null
     */
    public static function manualOffset(Customer $person, float $amount, ?string $note = null): ?array
    {
        return static::doOffset($person, false, $note, $amount);
    }

    /**
     * Hủy cấn bằng - tạo bút toán đảo.
     *
     * @param  DebtOffset  $debtOffset
     * @param  string|null $reason  Lý do hủy
     * @return array
     */
    public static function cancelOffset(DebtOffset $debtOffset, ?string $reason = null): array
    {
        $person = $debtOffset->customer;
        $amount = (float) $debtOffset->amount;

        // Đảo ngược: tăng lại cả 2 bên
        $person->debt_amount += $amount;
        $person->supplier_debt_amount += $amount;
        $person->save();

        $code = 'HDTCN' . date('ymdHis') . rand(10, 99);

        // CashFlow đảo (payment = chi ra = tăng nợ phải thu lại)
        CashFlow::create([
            'code' => $code,
            'type' => 'payment',
            'amount' => $amount,
            'time' => now(),
            'category' => 'Hủy đối trừ công nợ',
            'target_type' => 'Khách hàng',
            'target_id' => $person->id,
            'target_name' => $person->name,
            'reference_type' => 'DebtOffsetCancel',
            'reference_code' => $debtOffset->code,
            'description' => "Hủy đối trừ công nợ {$debtOffset->code}: {$person->name} - " . number_format($amount) . '₫',
        ]);

        // SupplierDebtTransaction đảo (tăng nợ phải trả lại)
        SupplierDebtTransaction::create([
            'supplier_id' => $person->id,
            'code' => $code,
            'type' => 'offset',
            'amount' => $amount,
            'debt_remain' => $person->supplier_debt_amount,
            'note' => "Hủy đối trừ công nợ {$debtOffset->code}: {$person->name}",
            'user_id' => auth()->id(),
        ]);

        // Đánh dấu cancelled
        $debtOffset->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by' => auth()->id(),
            'cancel_reason' => $reason,
        ]);

        return [
            'cancelled_amount' => $amount,
            'remaining_customer_debt' => $person->debt_amount,
            'remaining_supplier_debt' => $person->supplier_debt_amount,
        ];
    }

    /**
     * Logic chung cho cả auto và manual offset.
     */
    private static function doOffset(Customer $person, bool $isAuto, ?string $note, float $forceAmount = 0): ?array
    {
        $person->refresh();

        if (!$person->is_customer || !$person->is_supplier) {
            return null;
        }

        $customerDebt = (float) $person->debt_amount;
        $supplierDebt = (float) $person->supplier_debt_amount;

        if ($customerDebt <= 0 || $supplierDebt <= 0) {
            return null;
        }

        $maxOffset = min($customerDebt, $supplierDebt);
        $offsetAmount = $forceAmount > 0 ? min($forceAmount, $maxOffset) : $maxOffset;

        if ($offsetAmount <= 0) {
            return null;
        }

        $receivableBefore = $customerDebt;
        $payableBefore = $supplierDebt;

        $person->debt_amount -= $offsetAmount;
        $person->supplier_debt_amount -= $offsetAmount;
        $person->save();

        $code = 'DTCN' . date('ymdHis') . rand(10, 99);

        // Tạo DebtOffset record
        DebtOffset::create([
            'code' => $code,
            'customer_id' => $person->id,
            'amount' => $offsetAmount,
            'receivable_before' => $receivableBefore,
            'payable_before' => $payableBefore,
            'receivable_after' => $person->debt_amount,
            'payable_after' => $person->supplier_debt_amount,
            'is_auto' => $isAuto,
            'note' => $note ?? ($isAuto ? 'Đối trừ tự động' : 'Cấn bằng công nợ thủ công'),
            'user_id' => auth()->id(),
            'status' => 'active',
        ]);

        // CashFlow cho phía KH (giảm nợ phải thu)
        CashFlow::create([
            'code' => $code,
            'type' => 'receipt',
            'amount' => $offsetAmount,
            'time' => now(),
            'category' => 'Đối trừ công nợ',
            'target_type' => 'Khách hàng',
            'target_id' => $person->id,
            'target_name' => $person->name,
            'reference_type' => 'DebtOffset',
            'reference_code' => $code,
            'description' => ($isAuto ? 'Tự động đối trừ' : 'Cấn bằng thủ công') . " NCC↔KH: {$person->name} - " . number_format($offsetAmount) . '₫',
        ]);

        // SupplierDebtTransaction cho phía NCC (giảm nợ phải trả)
        SupplierDebtTransaction::create([
            'supplier_id' => $person->id,
            'code' => $code,
            'type' => 'offset',
            'amount' => -$offsetAmount,
            'debt_remain' => $person->supplier_debt_amount,
            'note' => ($isAuto ? 'Tự động đối trừ' : 'Cấn bằng thủ công') . " KH↔NCC: {$person->name}",
            'user_id' => auth()->id(),
        ]);

        return [
            'offset_amount' => $offsetAmount,
            'remaining_customer_debt' => $person->debt_amount,
            'remaining_supplier_debt' => $person->supplier_debt_amount,
            'code' => $code,
        ];
    }
}
