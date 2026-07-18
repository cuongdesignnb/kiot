<?php

namespace App\Services\Debt;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyOrphanFinancialReferenceService
{
    private const SOURCES = [
        'cash_flows' => [
            'partner_column' => 'target_id',
            'fields' => [
                'id', 'code', 'type', 'amount', 'target_type', 'target_id',
                'reference_type', 'reference_id', 'reference_code', 'time',
                'status', 'deleted_at',
            ],
        ],
        'invoices' => [
            'partner_column' => 'customer_id',
            'fields' => ['id', 'code', 'status', 'total', 'customer_paid', 'transaction_date'],
        ],
        'returns' => [
            'partner_column' => 'customer_id',
            'fields' => ['id', 'code', 'status', 'total', 'paid_to_customer', 'invoice_id'],
        ],
        'customer_debts' => [
            'partner_column' => 'customer_id',
            'fields' => ['id', 'ref_code', 'type', 'amount', 'debt_total', 'recorded_at'],
        ],
        'purchases' => [
            'partner_column' => 'supplier_id',
            'fields' => ['id', 'code', 'status', 'total_amount', 'paid_amount', 'debt_amount', 'purchase_date'],
        ],
        'purchase_returns' => [
            'partner_column' => 'supplier_id',
            'fields' => ['id', 'code', 'status', 'total_amount', 'refund_amount', 'purchase_id', 'return_date'],
        ],
        'supplier_debt_transactions' => [
            'partner_column' => 'supplier_id',
            'fields' => ['id', 'code', 'type', 'amount', 'debt_remain', 'reference_type', 'reference_id'],
        ],
        'debt_offsets' => [
            'partner_column' => 'customer_id',
            'fields' => ['id', 'code', 'amount', 'status', 'cancelled_at'],
        ],
        'partner_debt_operation_participants' => [
            'partner_column' => 'partner_id',
            'fields' => [
                'id', 'operation_id', 'participant_role', 'effect_role',
                'customer_delta', 'supplier_delta',
            ],
        ],
    ];

    public function snapshot(int $partnerId): array
    {
        $entries = collect();

        foreach (self::SOURCES as $table => $config) {
            $partnerColumn = $config['partner_column'];
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $partnerColumn)) {
                continue;
            }

            $fields = collect($config['fields'])
                ->filter(fn (string $field): bool => Schema::hasColumn($table, $field))
                ->values()
                ->all();
            if (! in_array('id', $fields, true)) {
                continue;
            }

            DB::table($table)->where($partnerColumn, $partnerId)->orderBy('id')
                ->get($fields)
                ->each(function (object $row) use ($entries, $table): void {
                    $payload = collect((array) $row)
                        ->map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value)
                        ->all();
                    $entries->push([
                        'source_table' => $table,
                        'source_id' => (int) $payload['id'],
                        'event_identity' => $table.'|'.$payload['id'],
                        'payload' => $payload,
                    ]);
                });
        }

        $entries = $entries->sortBy([
            ['source_table', 'asc'],
            ['source_id', 'asc'],
        ])->values()->all();
        $snapshot = [
            'partner_id' => $partnerId,
            'customer_exists' => Schema::hasTable('customers')
                && DB::table('customers')->where('id', $partnerId)->exists(),
            'source_count' => count($entries),
            'sources' => $entries,
            'affects_canonical_balance' => false,
            'affects_any_partner_balance' => false,
        ];
        $snapshot['evidence_hash'] = $this->evidenceHash($snapshot);

        return $snapshot;
    }

    public function evidenceHash(array $snapshot): string
    {
        return hash('sha256', json_encode([
            'partner_id' => (int) ($snapshot['partner_id'] ?? 0),
            'customer_exists' => (bool) ($snapshot['customer_exists'] ?? false),
            'sources' => array_values((array) ($snapshot['sources'] ?? [])),
            'affects_canonical_balance' => false,
            'affects_any_partner_balance' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}
