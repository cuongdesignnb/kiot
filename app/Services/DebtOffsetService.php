<?php

namespace App\Services;

use App\Models\CashFlow;
use App\Models\Customer;
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
        // Reload fresh data
        $person->refresh();

        // Chỉ đối trừ khi người này vừa là KH vừa là NCC
        if (!$person->is_customer || !$person->is_supplier) {
            return null;
        }

        $customerDebt = (float) $person->debt_amount;       // KH nợ mình
        $supplierDebt = (float) $person->supplier_debt_amount; // Mình nợ NCC

        // Chỉ đối trừ khi cả 2 bên đều > 0
        if ($customerDebt <= 0 || $supplierDebt <= 0) {
            return null;
        }

        $offsetAmount = min($customerDebt, $supplierDebt);

        // Cập nhật công nợ
        $person->debt_amount -= $offsetAmount;
        $person->supplier_debt_amount -= $offsetAmount;
        $person->save();

        $code = 'DTCN' . date('ymdHis') . rand(10, 99);

        // Tạo bản ghi CashFlow cho phía KH (giảm nợ phải thu)
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
            'description' => "Đối trừ công nợ NCC↔KH: {$person->name} - " . number_format($offsetAmount) . '₫',
        ]);

        // Tạo bản ghi SupplierDebtTransaction cho phía NCC (giảm nợ phải trả)
        SupplierDebtTransaction::create([
            'supplier_id' => $person->id,
            'code' => $code,
            'type' => 'offset',
            'amount' => -$offsetAmount,
            'debt_remain' => $person->supplier_debt_amount,
            'note' => "Đối trừ công nợ KH↔NCC: {$person->name}",
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
