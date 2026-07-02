<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * STEP 24.6E — Canonical return total calculator.
 *
 * Single source of truth for:
 *   subtotal − discount − fee_amount = total_refund
 *
 * Where fee_amount is resolved from (fee_type, fee_value):
 *   amount  → fee_amount = fee_value
 *   percent → fee_amount = round((subtotal - discount) * fee_value / 100)
 *
 * Why this lives in PHP, not JS: backend MUST recompute the total from
 * the raw fee inputs so the frontend cannot send a tampered total.
 */
class ReturnTotalCalculator
{
    /**
     * @param array{
     *   items?: array<int, array{qty:int|float, price:int|float, discount?:int|float}>,
     *   subtotal?: int|float,
     *   discount?: int|float,
     *   fee_type?: string|null,
     *   fee_value?: int|float|null,
     *   fee?: int|float|null,
     *   paid_to_customer?: int|float|null
     * } $payload
     *
     * @return array{
     *   subtotal: float,
     *   discount: float,
     *   fee_type: string,
     *   fee_value: float,
     *   fee_amount: float,
     *   total_refund: float,
     *   paid_to_customer: float
     * }
     *
     * @throws ValidationException
     */
    public function calculate(array $payload): array
    {
        // 1. Subtotal: prefer recomputing from items so the frontend cannot lie.
        $subtotal = 0.0;
        if (!empty($payload['items']) && is_array($payload['items'])) {
            foreach ($payload['items'] as $line) {
                $qty = (float) ($line['qty'] ?? 0);
                $price = (float) ($line['price'] ?? 0);
                $itemDiscount = (float) ($line['discount'] ?? 0);
                $subtotal += max(0.0, $qty * $price - $itemDiscount);
            }
        } else {
            $subtotal = (float) ($payload['subtotal'] ?? 0);
        }
        $subtotal = max(0.0, $subtotal);

        // 2. Discount cannot exceed subtotal.
        $discount = max(0.0, (float) ($payload['discount'] ?? 0));
        if ($discount > $subtotal) {
            $discount = $subtotal;
        }

        // 3. Resolve fee_type. Legacy payloads (no fee_type) default to 'amount'.
        $feeTypeRaw = $payload['fee_type'] ?? null;
        $feeType = in_array($feeTypeRaw, ['amount', 'percent'], true) ? $feeTypeRaw : 'amount';

        // 4. fee_value: explicit, or fall back to legacy `fee` column for amount mode.
        $feeValue = (float) ($payload['fee_value'] ?? $payload['fee'] ?? 0);

        if ($feeValue < 0) {
            throw ValidationException::withMessages([
                'fee_value' => 'Phí trả hàng không được âm.',
            ]);
        }

        if ($feeType === 'percent' && $feeValue > 100) {
            throw ValidationException::withMessages([
                'fee_value' => 'Phí trả hàng theo phần trăm không được vượt 100%.',
            ]);
        }

        // 5. Resolve fee_amount in VND.
        $base = max(0.0, $subtotal - $discount);
        $feeAmount = $feeType === 'percent'
            ? round($base * $feeValue / 100, 2)
            : round($feeValue, 2);

        // Cap fee_amount so total never goes negative.
        if ($feeAmount > $base) {
            $feeAmount = $base;
        }

        // 6. Total refund = subtotal − discount − fee_amount.
        $totalRefund = max(0.0, $subtotal - $discount - $feeAmount);

        // 7. paid_to_customer ≤ total_refund.
        $paid = (float) ($payload['paid_to_customer'] ?? $totalRefund);
        if ($paid < 0) {
            throw ValidationException::withMessages([
                'paid_to_customer' => 'Tiền trả khách không được âm.',
            ]);
        }
        if ($paid > $totalRefund) {
            throw ValidationException::withMessages([
                'paid_to_customer' => 'Tiền trả khách (' . number_format($paid) . ') không được vượt số tiền cần trả khách (' . number_format($totalRefund) . ').',
            ]);
        }

        return [
            'subtotal'          => $subtotal,
            'discount'          => $discount,
            'fee_type'          => $feeType,
            'fee_value'         => $feeValue,
            'fee_amount'        => $feeAmount,
            'total_refund'      => $totalRefund,
            'paid_to_customer'  => $paid,
        ];
    }
}
