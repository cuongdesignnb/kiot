<?php

namespace App\Services\Debt;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PartnerDebtPopulationService
{
    public const EXCLUDED_CSV_COLUMNS = [
        'partner_id',
        'partner_code',
        'deleted_at',
        'status',
        'is_customer',
        'is_supplier',
        'stored_customer_debt',
        'stored_supplier_debt',
        'document_count',
        'exclusion_reason',
    ];

    /**
     * Reconcile the customer projection table with every persisted partner
     * reference used by the canonical debt reducer. This is read-only.
     *
     * @param  array<int>  $scannedPartnerIds
     */
    public function reconcile(array $scannedPartnerIds, ?int $expectedPopulation = null): array
    {
        $customerRows = DB::table('customers')
            ->select($this->customerColumns())
            ->orderBy('id')
            ->get()
            ->keyBy(fn (object $row): int => (int) $row->id);

        $sources = [];
        foreach ($customerRows->keys() as $partnerId) {
            $sources[(int) $partnerId] = ['customers'];
        }
        foreach ($this->financialSourceQueries() as $source => $query) {
            foreach ($query->distinct()->pluck('partner_id') as $partnerId) {
                $partnerId = (int) $partnerId;
                if ($partnerId <= 0) {
                    continue;
                }
                $sources[$partnerId] ??= [];
                $sources[$partnerId][] = $source;
            }
        }
        ksort($sources, SORT_NUMERIC);
        foreach ($sources as &$partnerSources) {
            $partnerSources = array_values(array_unique($partnerSources));
            sort($partnerSources);
        }
        unset($partnerSources);

        $scanned = array_fill_keys(array_map('intval', $scannedPartnerIds), true);
        $excluded = [];
        $unscannable = [];
        foreach ($sources as $partnerId => $partnerSources) {
            $customer = $customerRows->get($partnerId);
            if ($customer === null) {
                $unscannable[] = [
                    'partner_id' => $partnerId,
                    'reason' => 'FINANCIAL_REFERENCE_WITHOUT_PARTNER_ROW',
                    'sources' => array_values(array_diff($partnerSources, ['customers'])),
                ];

                continue;
            }
            if (isset($scanned[$partnerId])) {
                continue;
            }

            $historySources = array_values(array_diff($partnerSources, ['customers']));
            $customerDebt = (float) ($customer->debt_amount ?? 0);
            $supplierDebt = (float) ($customer->supplier_debt_amount ?? 0);
            if (abs($customerDebt) <= PartnerDebtParityAuditService::TOLERANCE
                && abs($supplierDebt) <= PartnerDebtParityAuditService::TOLERANCE
                && $historySources === []) {
                $excluded[] = [
                    'partner_id' => $partnerId,
                    'partner_code' => (string) ($customer->code ?? ''),
                    'deleted_at' => $customer->deleted_at ?? null,
                    'status' => (string) ($customer->status ?? ''),
                    'is_customer' => (bool) ($customer->is_customer ?? false),
                    'is_supplier' => (bool) ($customer->is_supplier ?? false),
                    'stored_customer_debt' => $customerDebt,
                    'stored_supplier_debt' => $supplierDebt,
                    'document_count' => 0,
                    'exclusion_reason' => 'ZERO_STORED_BALANCE_AND_NO_FINANCIAL_HISTORY_OR_LEDGER',
                ];

                continue;
            }

            $unscannable[] = [
                'partner_id' => $partnerId,
                'partner_code' => (string) ($customer->code ?? ''),
                'reason' => 'AUDIT_DID_NOT_SCAN_MATERIAL_PARTNER',
                'sources' => $historySources,
                'stored_customer_debt' => $customerDebt,
                'stored_supplier_debt' => $supplierDebt,
            ];
        }

        $hasDeletedAt = Schema::hasColumn('customers', 'deleted_at');
        $totalWithTrashed = $customerRows->count();
        $totalWithoutTrashed = $hasDeletedAt
            ? $customerRows->filter(fn (object $row): bool => $row->deleted_at === null)->count()
            : $totalWithTrashed;
        $financialIds = collect($sources)->filter(
            fn (array $partnerSources): bool => array_diff($partnerSources, ['customers']) !== []
        )->keys();
        $nonzeroStored = $customerRows->filter(
            fn (object $row): bool => abs((float) ($row->debt_amount ?? 0)) > PartnerDebtParityAuditService::TOLERANCE
                || abs((float) ($row->supplier_debt_amount ?? 0)) > PartnerDebtParityAuditService::TOLERANCE
        )->count();

        $databaseIsLatest = $expectedPopulation === null
            ? null
            : $totalWithTrashed === $expectedPopulation;
        $coverageMatches = count($scanned) + count($excluded) === $totalWithTrashed;
        $reconciliationPass = $coverageMatches
            && $unscannable === []
            && $databaseIsLatest !== false;

        return [
            'summary' => [
                'total_customers_without_trashed' => $totalWithoutTrashed,
                'total_customers_with_trashed' => $totalWithTrashed,
                'total_partner_source_union' => count($sources),
                'total_with_financial_history' => $financialIds->count(),
                'total_with_nonzero_stored_balance' => $nonzeroStored,
                'total_scanned' => count($scanned),
                'total_excluded' => count($excluded),
                'total_unscannable' => count($unscannable),
                'expected_population' => $expectedPopulation,
                'expected_customer_gap' => $expectedPopulation === null ? null : $expectedPopulation - $totalWithTrashed,
                'expected_union_gap' => $expectedPopulation === null ? null : $expectedPopulation - count($sources),
                'database_is_latest' => $databaseIsLatest,
                'population_reconciliation_pass' => $reconciliationPass,
            ],
            'source_population' => collect($sources)->map(
                fn (array $partnerSources, int $partnerId): array => [
                    'partner_id' => $partnerId,
                    'sources' => $partnerSources,
                ]
            )->values()->all(),
            'excluded' => $excluded,
            'unscannable' => $unscannable,
        ];
    }

    /** @return array<string, Builder> */
    private function financialSourceQueries(): array
    {
        $queries = [];
        $this->addColumnSource($queries, 'invoices', 'customer_id');
        $this->addColumnSource($queries, 'returns', 'customer_id');
        $this->addColumnSource($queries, 'customer_debts', 'customer_id');
        $this->addColumnSource($queries, 'customer_payment_allocations', 'customer_id');
        $this->addColumnSource($queries, 'customer_payment_discounts', 'customer_id');
        $this->addColumnSource($queries, 'customer_payment_discount_allocations', 'customer_id');
        $this->addColumnSource($queries, 'purchases', 'supplier_id');
        $this->addColumnSource($queries, 'purchase_returns', 'supplier_id');
        $this->addColumnSource($queries, 'supplier_debt_transactions', 'supplier_id');
        $this->addColumnSource($queries, 'supplier_payment_allocations', 'supplier_id');
        $this->addColumnSource($queries, 'debt_offsets', 'customer_id');
        $this->addColumnSource($queries, 'partner_merges', 'source_partner_id', 'partner_merges:source');
        $this->addColumnSource($queries, 'partner_merges', 'target_partner_id', 'partner_merges:target');
        $this->addColumnSource($queries, 'partner_debt_operations', 'partner_id');
        $this->addColumnSource($queries, 'partner_debt_operation_participants', 'partner_id');
        $this->addColumnSource($queries, 'partner_debt_opening_balances', 'partner_id');
        $this->addColumnSource($queries, 'partner_debt_integrity_incidents', 'partner_id');

        if (Schema::hasTable('activity_logs')
            && Schema::hasColumn('activity_logs', 'subject_id')
            && Schema::hasColumn('activity_logs', 'subject_type')) {
            $queries['activity_logs:partner'] = DB::table('activity_logs')
                ->selectRaw('subject_id AS partner_id')
                ->whereNotNull('subject_id')
                ->where('subject_id', '>', 0)
                ->whereIn('subject_type', [
                    'App\\Models\\Customer', 'App\\Models\\Partner', 'Customer', 'Partner',
                ]);
        }

        if (Schema::hasTable('cash_flows')
            && Schema::hasColumn('cash_flows', 'target_id')
            && Schema::hasColumn('cash_flows', 'target_type')) {
            $queries['cash_flows'] = DB::table('cash_flows')
                ->selectRaw('target_id AS partner_id')
                ->whereNotNull('target_id')
                ->where('target_id', '>', 0)
                ->where(function (Builder $query): void {
                    $query->whereIn('target_type', [
                        'Khách hàng', 'Khach hang', 'Customer', 'customer',
                        'Nhà cung cấp', 'Nha cung cap', 'Supplier', 'supplier',
                    ]);
                    if (Schema::hasColumn('cash_flows', 'reference_type')) {
                        $query->orWhereIn('reference_type', [
                            'SupplierPayment', 'DebtPayment', 'DebtAdjustment',
                            'DebtOffset', 'DebtOffsetCancel',
                        ]);
                    }
                });
        }

        return $queries;
    }

    /** @param array<string, Builder> $queries */
    private function addColumnSource(
        array &$queries,
        string $table,
        string $column,
        ?string $source = null,
    ): void {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $queries[$source ?? $table] = DB::table($table)
            ->selectRaw("{$column} AS partner_id")
            ->whereNotNull($column)
            ->where($column, '>', 0);
    }

    /** @return array<string> */
    private function customerColumns(): array
    {
        $columns = [
            'id', 'code', 'status', 'is_customer', 'is_supplier',
            'debt_amount', 'supplier_debt_amount',
        ];
        if (Schema::hasColumn('customers', 'deleted_at')) {
            $columns[] = 'deleted_at';
        }

        return $columns;
    }
}
