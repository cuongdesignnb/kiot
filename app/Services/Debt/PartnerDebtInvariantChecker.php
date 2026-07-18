<?php

namespace App\Services\Debt;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PartnerDebtInvariantChecker
{
    public const STATUS_OK = 'OK';

    public const STATUS_DRIFT = 'DRIFT_DETECTED';

    public const STATUS_INSUFFICIENT = 'INSUFFICIENT_EVIDENCE';

    public const STATUS_TECHNICAL = 'TECHNICAL_WARNING';

    public const STATUS_ERROR = 'CHECK_ERROR';

    private const MATERIAL_FLAGS = [
        'CUSTOMER_STORED_VS_DOCUMENT',
        'CUSTOMER_STORED_VS_LEDGER',
        'CUSTOMER_DOCUMENT_VS_LEDGER',
        'SUPPLIER_STORED_VS_DOCUMENT',
        'SUPPLIER_STORED_VS_LEDGER',
        'SUPPLIER_DOCUMENT_VS_LEDGER',
        'DUAL_ROLE_NET_MISMATCH',
        'DUAL_ROLE_SCREEN_ASYMMETRY',
        'DUPLICATE_REAL_AND_FALLBACK',
        'DUPLICATE_CUSTOMER_RECEIPT',
        'DUPLICATE_SUPPLIER_PAYMENT',
        'RETURN_REFUND_DUPLICATE',
        'PURCHASE_RETURN_REFUND_MISMATCH',
        'CANCEL_REVERSAL_MISSING',
        'INVOICE_RECEIPT_ALLOCATION_MISMATCH',
        'PURCHASE_PAYMENT_ALLOCATION_MISMATCH',
    ];

    private const INSUFFICIENT_FLAGS = [
        'VIRTUAL_OPENING_REQUIRED',
        'STORED_BALANCE_NO_HISTORY',
        'HAS_DOCUMENTS_NO_LEDGER',
        'HAS_LEDGER_NO_DOCUMENTS',
        'INVOICE_RECEIPT_ALLOCATION_EVIDENCE_MISSING',
        'PURCHASE_PAYMENT_ALLOCATION_EVIDENCE_MISSING',
    ];

    private const TECHNICAL_FLAGS = [
        'VIRTUAL_DISPLAY_ALIGNMENT_ONLY',
        'TARGET_TYPE_ALIAS_SUSPECT',
        'TECHNICAL_LEDGER_EXCLUDED',
    ];

    public function __construct(
        private readonly CanonicalPartnerDebtService $canonicalDebt,
        private readonly PartnerDebtParityAuditService $parityAudit,
    ) {}

    public function check(Customer $partner): array
    {
        $canonical = $this->canonicalDebt->calculate($partner);
        $audit = $this->parityAudit->audit($partner);
        $classification = (string) ($audit['primary_classification'] ?? 'AUDIT_ERROR');
        $flags = array_values((array) ($audit['classification_flags'] ?? [$classification]));
        $status = $this->status($flags, $audit['audit_error'] ?? null);

        return array_merge($canonical, [
            'partner_id' => (int) $partner->id,
            'partner_code' => (string) ($partner->code ?? ''),
            'partner_name' => (string) ($partner->name ?? ''),
            'role' => (string) ($audit['role'] ?? $this->role($partner)),
            'invariant_status' => $status,
            'drift_detected' => $status === self::STATUS_DRIFT,
            'technical_warning' => $status === self::STATUS_TECHNICAL,
            'difference' => $this->maxDifference($audit),
            'root_cause' => $this->rootCause($flags, $classification, $status),
            'classification_flags' => $flags,
            'risk_level' => (string) ($audit['risk_level'] ?? 'CRITICAL'),
            'audit_error' => $audit['audit_error'] ?? null,
        ]);
    }

    public function scan(
        array $partnerIds = [],
        ?int $limit = null,
        string $role = 'all',
        string $status = 'all',
        bool $benchmark = false,
        bool $allPartners = false,
    ): array {
        $queryCount = 0;
        if ($benchmark) {
            DB::listen(function () use (&$queryCount): void {
                $queryCount++;
            });
        }
        $startedAt = hrtime(true);
        $slowestPartnerRuntimeMs = 0.0;
        $query = Customer::query()->orderBy('id');
        if (! $allPartners) {
            $query->whereNull('merged_into_id')
                ->where(function (Builder $builder): void {
                    $builder->where('is_customer', true)
                        ->orWhere('is_supplier', true)
                        ->orWhere('debt_amount', '!=', 0)
                        ->orWhere('supplier_debt_amount', '!=', 0);
                });
        }

        if ($partnerIds !== []) {
            $query->whereKey($partnerIds);
        }
        if ($role === 'customer') {
            $query->where('is_customer', true);
        } elseif ($role === 'supplier') {
            $query->where('is_supplier', true);
        } elseif ($role === 'dual') {
            $query->where('is_customer', true)->where('is_supplier', true);
        }
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $rows = [];
        foreach ($query->lazyById(25) as $partner) {
            if ($limit !== null && count($rows) >= $limit) {
                break;
            }
            $partnerStartedAt = hrtime(true);
            $rows[] = $this->check($partner);
            $slowestPartnerRuntimeMs = max(
                $slowestPartnerRuntimeMs,
                (hrtime(true) - $partnerStartedAt) / 1_000_000,
            );
        }

        $collection = collect($rows);
        $totalChecked = $collection->count();
        $runtimeMs = (hrtime(true) - $startedAt) / 1_000_000;

        return [
            'checked_at' => now()->toIso8601String(),
            'total_checked' => $totalChecked,
            'matched' => $collection->where('invariant_status', self::STATUS_OK)->count(),
            'drift_detected' => $collection->where('invariant_status', self::STATUS_DRIFT)->count(),
            'insufficient_evidence' => $collection->where('invariant_status', self::STATUS_INSUFFICIENT)->count(),
            'technical_warnings' => $collection->where('invariant_status', self::STATUS_TECHNICAL)->count(),
            'audit_errors' => $collection->where('invariant_status', self::STATUS_ERROR)->count(),
            'benchmark' => $benchmark ? [
                'query_count' => $queryCount,
                'queries_per_partner' => $totalChecked > 0 ? $queryCount / $totalChecked : 0.0,
                'runtime_ms' => $runtimeMs,
                'peak_memory_mb' => memory_get_peak_usage(true) / 1024 / 1024,
                'slowest_partner_runtime_ms' => $slowestPartnerRuntimeMs,
            ] : null,
            'rows' => $rows,
        ];
    }

    private function status(array $flags, mixed $auditError): string
    {
        if ($auditError !== null || in_array('AUDIT_ERROR', $flags, true)) {
            return self::STATUS_ERROR;
        }
        if (array_intersect(self::MATERIAL_FLAGS, $flags) !== []) {
            return self::STATUS_DRIFT;
        }
        if (array_intersect(self::INSUFFICIENT_FLAGS, $flags) !== []) {
            return self::STATUS_INSUFFICIENT;
        }
        if (array_intersect(self::TECHNICAL_FLAGS, $flags) !== []) {
            return self::STATUS_TECHNICAL;
        }

        return self::STATUS_OK;
    }

    private function rootCause(array $flags, string $classification, string $status): string
    {
        $candidates = match ($status) {
            self::STATUS_DRIFT => self::MATERIAL_FLAGS,
            self::STATUS_INSUFFICIENT => self::INSUFFICIENT_FLAGS,
            self::STATUS_TECHNICAL => self::TECHNICAL_FLAGS,
            self::STATUS_ERROR => ['AUDIT_ERROR'],
            default => ['OK'],
        };

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $flags, true)) {
                return $candidate;
            }
        }

        return $classification;
    }

    private function maxDifference(array $audit): float
    {
        return max(array_map(
            fn (string $key): float => abs((float) ($audit[$key] ?? 0)),
            [
                'customer_stored_vs_document_raw',
                'customer_stored_vs_ledger',
                'customer_document_vs_ledger',
                'supplier_stored_vs_document_raw',
                'supplier_stored_vs_ledger',
                'supplier_document_vs_ledger',
                'dual_role_screen_symmetry_difference',
            ],
        ));
    }

    private function role(Customer $partner): string
    {
        $customer = (bool) ($partner->is_customer ?? false);
        $supplier = (bool) ($partner->is_supplier ?? false);

        return $customer && $supplier ? 'dual_role' : ($supplier ? 'supplier_only' : 'customer_only');
    }
}
