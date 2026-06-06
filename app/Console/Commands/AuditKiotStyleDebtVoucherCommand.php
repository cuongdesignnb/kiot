<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\PartnerDebtLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * STEP 10 — Read-only audit of KiotViet-style debt vouchers.
 *
 * Classifies each timeline row of a customer/supplier as a real voucher
 * vs a virtual fallback, records its click target, and flags risks
 * (missing real voucher / missing click target). It NEVER writes data —
 * it only reads via PartnerDebtLedgerService.
 */
class AuditKiotStyleDebtVoucherCommand extends Command
{
    protected $signature = 'debt:audit-kiot-vouchers
        {--dry-run : Required. This command is read-only.}
        {--customer-code= : Customer code}
        {--supplier-code= : Supplier code}
        {--export-json= : Write the JSON report to this path}
        {--export-md= : Write the Markdown report to this path}';

    protected $description = 'Read-only audit: real vs virtual-fallback debt vouchers and their click targets.';

    public function handle(): int
    {
        if (!$this->option('dry-run')) {
            $this->error('This command is read-only. Please pass --dry-run. No data was modified.');
            return self::FAILURE;
        }

        $code = $this->option('customer-code') ?: $this->option('supplier-code');
        if (!$code) {
            $this->error('Provide --customer-code or --supplier-code.');
            return self::FAILURE;
        }

        $partner = Customer::where('code', $code)->first();
        if (!$partner) {
            $this->error("Partner not found: {$code}");
            return self::FAILURE;
        }

        $service = app(PartnerDebtLedgerService::class);
        $isSupplierView = (bool) $this->option('supplier-code');

        $ledger = $isSupplierView
            ? $service->buildSupplierPayableLedger($partner)
            : $service->buildCustomerNetLedger($partner);

        $rows = collect($ledger['entries'])->map(function ($e) {
            $e = is_array($e) ? $e : (array) $e;
            $isReal = (bool) ($e['is_real_voucher'] ?? false);
            $isFallback = (bool) ($e['is_virtual_fallback'] ?? false);
            $modal = $e['detail_modal_type'] ?? null;
            $clickable = ($e['detail_available'] ?? false) && $modal && $modal !== 'none';

            $mismatch = (bool) ($e['receipt_allocation_mismatch'] ?? false);

            $risk = 'ok';
            if ($mismatch) {
                $risk = 'receipt_allocation_mismatch';
            } elseif ($isFallback && $clickable) {
                $risk = 'clickable_fallback'; // STEP 10B — must never happen
            } elseif ($isFallback) {
                $risk = 'virtual_fallback';
            } elseif (($e['event_kind'] ?? '') === 'invoice_payment' && !$isReal) {
                $risk = 'missing_real_voucher';
            } elseif (!$clickable && !($e['is_virtual_opening'] ?? false)) {
                $risk = 'missing_click_target';
            }

            return [
                'code' => $e['code'] ?? '—',
                'type' => $e['display_type'] ?? $e['type'] ?? '—',
                'amount' => (float) ($e['amount'] ?? 0),
                'event_kind' => $e['event_kind'] ?? '',
                'reference_code' => $e['reference_code'] ?? null,
                'is_real_voucher' => $isReal,
                'is_virtual_fallback' => $isFallback,
                'clickable' => $clickable,
                'receipt_allocation_mismatch' => $mismatch,
                'click_modal' => $modal ?: 'none',
                'click_ref' => $e['detail_reference_code'] ?? null,
                'risk' => $risk,
            ];
        })->values();

        // STEP 10B — invoice → real receipt groups (customer view).
        $invoiceReceiptGroups = $rows
            ->where('event_kind', 'invoice_payment')
            ->where('is_real_voucher', true)
            ->groupBy('reference_code')
            ->map(function ($group, $invoiceCode) {
                return [
                    'invoice_code' => $invoiceCode,
                    'real_receipt_count' => $group->count(),
                    'real_receipt_total' => (float) $group->sum('amount'),
                    'receipt_codes' => $group->pluck('code')->all(),
                    'is_mismatch' => (bool) $group->contains('receipt_allocation_mismatch', true),
                ];
            })->values();

        $fallbackRows = $rows->where('is_virtual_fallback', true);

        $summary = [
            'partner_code' => $partner->code,
            'partner_name' => $partner->name,
            'view' => $isSupplierView ? 'supplier' : 'customer',
            'total_rows' => $rows->count(),
            'real_vouchers' => $rows->where('is_real_voucher', true)->count(),
            'virtual_fallbacks' => $rows->where('is_virtual_fallback', true)->count(),
            'fallback_rows' => $fallbackRows->count(),
            'non_clickable_fallback_rows' => $fallbackRows->where('clickable', false)->count(),
            'clickable_fallback_rows' => $fallbackRows->where('clickable', true)->count(),
            'receipt_allocation_mismatches' => $rows->where('receipt_allocation_mismatch', true)->count(),
            'missing_real_voucher' => $rows->where('risk', 'missing_real_voucher')->count(),
            'missing_click_target' => $rows->where('risk', 'missing_click_target')->count(),
        ];

        $this->info("Audit ({$summary['view']}): {$partner->name} ({$partner->code})");
        $this->table(
            ['Code', 'Type', 'Amount', 'Real', 'Fallback', 'Click', 'Ref', 'Risk'],
            $rows->take(60)->map(fn ($r) => [
                $r['code'], $r['type'], number_format($r['amount']),
                $r['is_real_voucher'] ? 'Y' : '', $r['is_virtual_fallback'] ? 'Y' : '',
                $r['click_modal'], $r['click_ref'] ?? '', $r['risk'],
            ])->all()
        );
        foreach ($summary as $k => $v) {
            $this->line(str_pad($k, 24) . ': ' . $v);
        }

        $report = ['summary' => $summary, 'invoice_receipt_groups' => $invoiceReceiptGroups->all(), 'rows' => $rows->all()];

        if ($path = $this->option('export-json')) {
            File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("JSON written: {$path}");
        }
        if ($path = $this->option('export-md')) {
            $md = "# Kiot-style debt voucher audit — {$partner->name} ({$partner->code})\n\n";
            foreach ($summary as $k => $v) $md .= "- **{$k}**: {$v}\n";
            $md .= "\n| Code | Type | Amount | Real | Fallback | Click | Risk |\n|---|---|--:|:--:|:--:|---|---|\n";
            foreach ($rows as $r) {
                $md .= "| {$r['code']} | {$r['type']} | " . number_format($r['amount']) . ' | '
                    . ($r['is_real_voucher'] ? '✓' : '') . ' | ' . ($r['is_virtual_fallback'] ? '✓' : '') . ' | '
                    . $r['click_modal'] . ' | ' . $r['risk'] . " |\n";
            }
            File::put($path, $md);
            $this->info("Markdown written: {$path}");
        }

        $this->warn('Read-only audit complete. No data was modified.');
        return self::SUCCESS;
    }
}
