<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;

/**
 * Read-only invoice-edit policy hints for UI clients.
 *
 * InvoiceUpdateService remains the enforcement point. This service only exposes
 * the same policy inputs required for a client to collect the required audit
 * reasons before it submits an update.
 */
class InvoiceEditPolicyService
{
    public function hints(Invoice $invoice, ?User $user): array
    {
        $orderChangeTime = (int) Setting::get('order_change_time', 24);
        $lockReference = $invoice->lock_started_at ?? $invoice->created_at;
        $lockAgeHours = $lockReference
            ? (float) Carbon::parse($lockReference)->floatDiffInHours(now())
            : 0.0;
        // Keep the enforcement boundary identical to InvoiceUpdateService's
        // previous lock comparison. lock_age_hours remains fractional only for
        // display; it must not make the UI infer a different lock state.
        $isTimeLocked = $lockReference
            ? Carbon::parse($lockReference)->diffInHours(now()) > $orderChangeTime
            : false;

        return [
            'is_time_locked' => $isTimeLocked,
            'lock_age_hours' => round($lockAgeHours, 2),
            'order_change_time_hours' => $orderChangeTime,
            'can_override_time_lock' => (bool) $user?->hasPermission('invoices.override_time_lock'),
            'can_change_transaction_date' => (bool) $user?->hasPermission('invoices.change_transaction_date'),
            'original_transaction_date' => $invoice->transaction_date?->toIso8601String(),
            'is_einvoice_blocked' => $this->isEinvoiceBlocked($invoice),
        ];
    }

    public function isEinvoiceBlocked(Invoice $invoice): bool
    {
        return (bool) Setting::get('block_edit_cancel_einvoice', false)
            && ! empty($invoice->einvoice_code);
    }
}
