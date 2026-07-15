<?php

namespace App\Services\Debt;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

class PartnerDebtInvariantChecker
{
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

        return array_merge($canonical, [
            'partner_id' => (int) $partner->id,
            'partner_code' => (string) ($partner->code ?? ''),
            'partner_name' => (string) ($partner->name ?? ''),
            'role' => (string) ($audit['role'] ?? $this->role($partner)),
            'drift_detected' => $classification !== 'OK',
            'difference' => $this->maxDifference($audit),
            'root_cause' => $classification,
            'classification_flags' => $flags,
            'risk_level' => (string) ($audit['risk_level'] ?? 'CRITICAL'),
            'audit_error' => $audit['audit_error'] ?? null,
        ]);
    }

    public function scan(array $partnerIds = [], ?int $limit = null): array
    {
        $query = Customer::query()
            ->whereNull('merged_into_id')
            ->where(function (Builder $builder): void {
                $builder->where('is_customer', true)
                    ->orWhere('is_supplier', true)
                    ->orWhere('debt_amount', '!=', 0)
                    ->orWhere('supplier_debt_amount', '!=', 0);
            })
            ->orderBy('id');

        if ($partnerIds !== []) {
            $query->whereKey($partnerIds);
        }

        $rows = [];
        foreach ($query->lazyById(25) as $partner) {
            if ($limit !== null && count($rows) >= $limit) {
                break;
            }
            $rows[] = $this->check($partner);
        }

        $collection = collect($rows);

        return [
            'checked_at' => now()->toIso8601String(),
            'total_checked' => $collection->count(),
            'matched' => $collection->where('drift_detected', false)->count(),
            'drift_detected' => $collection->where('drift_detected', true)->count(),
            'audit_errors' => $collection->filter(fn (array $row): bool => $row['audit_error'] !== null)->count(),
            'rows' => $rows,
        ];
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
