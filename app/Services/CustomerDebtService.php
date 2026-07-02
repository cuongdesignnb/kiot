<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderReturn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * RR-06: Service ghi ledger công nợ khách hàng.
 *
 * Mỗi method:
 *   1. Lock Customer cho update.
 *   2. Cộng/trừ debt_amount theo signed amount.
 *   3. Tạo CustomerDebt row với debt_total = customers.debt_amount sau update.
 *   4. Trả CustomerDebt model (hoặc null nếu amount=0).
 *
 * Convention type:
 *   - 'sale'        : amount > 0 (bán nợ)
 *   - 'return'      : amount < 0 (trả hàng giảm nợ)
 *   - 'payment'     : amount < 0 (KH thanh toán)
 *   - 'adjustment'  : signed (manual / reversal)
 *
 * Có thể được gọi bên trong hoặc ngoài DB::transaction.
 */
class CustomerDebtService
{
    /**
     * Bán hàng nợ — increase debt_amount + ghi ledger type='sale'.
     *
     * @param int $customerId
     * @param float $amount Positive sale receivable amount.
     * @param Model|null $reference Invoice | Order | OrderReturn | null
     * @param string|null $note
     * @param array $meta Có thể chứa 'ref_code', 'order_id', 'order_return_id'
     * @return CustomerDebt|null null nếu amount=0
     */
    public function recordSale(int $customerId, float $amount, ?Model $reference = null, ?string $note = null, array $meta = []): ?CustomerDebt
    {
        $this->assertNonNegative($amount, 'recordSale');

        return $this->record($customerId, $amount, 'sale', $reference, $note, $meta);
    }

    /**
     * Trả hàng — decrease debt_amount + ghi ledger type='return'.
     */
    public function recordReturn(int $customerId, float $amount, ?Model $reference = null, ?string $note = null, array $meta = []): ?CustomerDebt
    {
        return $this->record($customerId, -abs($amount), 'return', $reference, $note, $meta);
    }

    /**
     * KH thanh toán — decrease debt_amount + ghi ledger type='payment'.
     */
    public function recordPayment(int $customerId, float $amount, ?Model $reference = null, ?string $note = null, array $meta = []): ?CustomerDebt
    {
        return $this->record($customerId, -abs($amount), 'payment', $reference, $note, $meta);
    }

    /**
     * Đảo công nợ do hủy hóa đơn — decrease debt_amount + ghi ledger type='adjustment' (signed âm).
     */
    public function recordSaleReversal(int $customerId, float $amount, ?Model $reference = null, ?string $note = null, array $meta = []): ?CustomerDebt
    {
        $this->assertNonNegative($amount, 'recordSaleReversal');

        return $this->record($customerId, -$amount, 'adjustment', $reference, $note, $meta);
    }

    public function recordInvoiceBalanceEffect(
        int $customerId,
        float $signedAmount,
        ?Model $reference = null,
        ?string $note = null,
        array $meta = []
    ): ?CustomerDebt {
        return $this->record($customerId, $signedAmount, 'sale', $reference, $note, $meta);
    }

    public function recordInvoiceBalanceReversal(
        int $customerId,
        float $originalSignedAmount,
        ?Model $reference = null,
        ?string $note = null,
        array $meta = []
    ): ?CustomerDebt {
        return $this->record(
            $customerId,
            -$originalSignedAmount,
            'adjustment',
            $reference,
            $note,
            $meta
        );
    }

    /**
     * Adjustment thủ công — signed amount giữ nguyên.
     */
    public function recordAdjustment(int $customerId, float $signedAmount, ?string $note = null, array $meta = []): ?CustomerDebt
    {
        return $this->record(
            $customerId,
            $signedAmount,
            $meta['type'] ?? 'adjustment',
            null,
            $note,
            $meta
        );
    }

    /**
     * Core record — tất cả method đều gọi vào đây.
     */
    private function record(
        int $customerId,
        float $signedAmount,
        string $type,
        ?Model $reference,
        ?string $note,
        array $meta
    ): ?CustomerDebt {
        if (abs($signedAmount) < 0.01) {
            return null;
        }

        return DB::transaction(function () use ($customerId, $signedAmount, $type, $reference, $note, $meta) {
            $customer = Customer::lockForUpdate()->find($customerId);
            if (!$customer) {
                return null;
            }

            $customer->debt_amount = (float) $customer->debt_amount + $signedAmount;
            $customer->save();

            // Resolve ref_code + order_id + order_return_id từ reference + meta
            $refCode = $meta['ref_code'] ?? null;
            $orderId = $meta['order_id'] ?? null;
            $orderReturnId = $meta['order_return_id'] ?? null;

            if ($reference instanceof Invoice) {
                $refCode = $refCode ?? $reference->code;
            } elseif ($reference instanceof Order) {
                $refCode = $refCode ?? $reference->code;
                $orderId = $orderId ?? $reference->id;
            } elseif ($reference instanceof OrderReturn) {
                $refCode = $refCode ?? $reference->code;
                $orderReturnId = $orderReturnId ?? $reference->id;
            }

            return CustomerDebt::create([
                'customer_id'     => $customer->id,
                'order_id'        => $orderId,
                'order_return_id' => $orderReturnId,
                'ref_code'        => $refCode,
                'amount'          => $signedAmount,
                'debt_total'      => (float) $customer->debt_amount,
                'type'            => $type,
                'note'            => $note,
                'created_by'      => auth()->id(),
                'recorded_at'     => now(),
            ]);
        });
    }

    private function assertNonNegative(float $amount, string $method): void
    {
        if ($amount < 0) {
            throw new InvalidArgumentException(
                "{$method} does not accept negative amounts. Use the signed invoice balance method."
            );
        }
    }
}
