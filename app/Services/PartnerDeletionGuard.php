<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PartnerDeletionGuard
{
    public const BLOCKED_MESSAGE = 'Không thể xóa đối tác đã có chứng từ, lịch sử tài chính hoặc số liệu lũy kế. Hãy dùng "Ngừng hoạt động" hoặc "Gộp đối tác" để bảo toàn truy vết.';

    /** @var array<string, array{0: string, 1: string}> */
    private const DIRECT_REFERENCES = [
        'invoice_count' => ['invoices', 'customer_id'],
        'order_count' => ['orders', 'customer_id'],
        'return_count' => ['returns', 'customer_id'],
        'purchase_count' => ['purchases', 'supplier_id'],
        'purchase_order_count' => ['purchase_orders', 'supplier_id'],
        'purchase_return_count' => ['purchase_returns', 'supplier_id'],
        'customer_debt_count' => ['customer_debts', 'customer_id'],
        'supplier_debt_transaction_count' => ['supplier_debt_transactions', 'supplier_id'],
        'debt_offset_count' => ['debt_offsets', 'customer_id'],
        'customer_payment_allocation_count' => ['customer_payment_allocations', 'customer_id'],
        'supplier_payment_allocation_count' => ['supplier_payment_allocations', 'supplier_id'],
        'customer_payment_discount_count' => ['customer_payment_discounts', 'customer_id'],
        'customer_payment_discount_allocation_count' => ['customer_payment_discount_allocations', 'customer_id'],
        'delivery_address_count' => ['customer_delivery_addresses', 'customer_id'],
        'promotion_usage_count' => ['promotion_usages', 'customer_id'],
        'task_count' => ['tasks', 'customer_id'],
        'waybill_count' => ['waybills', 'customer_id'],
        'debt_operation_count' => ['partner_debt_operations', 'partner_id'],
        'debt_operation_participant_count' => ['partner_debt_operation_participants', 'partner_id'],
        'opening_balance_count' => ['partner_debt_opening_balances', 'partner_id'],
        'integrity_incident_count' => ['partner_debt_integrity_incidents', 'partner_id'],
        'merge_source_count' => ['partner_merges', 'source_partner_id'],
        'merge_target_count' => ['partner_merges', 'target_partner_id'],
    ];

    private const CASH_FLOW_TARGET_TYPES = [
        'customer',
        'supplier',
        'Khách hàng',
        'Nhà cung cấp',
        'Khach hang',
        'Nha cung cap',
    ];

    /** @var array<string, string> */
    private const CUMULATIVE_FIELDS = [
        'debt_amount' => 'customer_debt_amount_nonzero',
        'supplier_debt_amount' => 'supplier_debt_amount_nonzero',
        'total_spent' => 'total_spent_nonzero',
        'total_returns' => 'total_returns_nonzero',
        'total_bought' => 'total_bought_nonzero',
    ];

    public function inspect(Customer $partner): array
    {
        $counts = [];
        foreach (self::DIRECT_REFERENCES as $key => [$table, $column]) {
            $counts[$key] = $this->referenceCount($table, $column, (int) $partner->id);
        }
        $counts['cash_flow_count'] = $this->cashFlowCount((int) $partner->id);

        $reasons = [];
        foreach ($counts as $key => $count) {
            if ($count > 0) {
                $reasons[] = $key.'_nonzero';
            }
        }

        foreach (self::CUMULATIVE_FIELDS as $field => $reason) {
            if (abs((float) ($partner->{$field} ?? 0)) > 0.00001) {
                $reasons[] = $reason;
            }
        }

        if ($partner->merged_into_id !== null) {
            $reasons[] = 'merged_into_partner';
        }

        return array_merge([
            'id' => (int) $partner->id,
            'code' => (string) $partner->code,
        ], $counts, [
            'blocked' => $reasons !== [],
            'reasons' => array_values(array_unique($reasons)),
        ]);
    }

    public function delete(Customer $partner, string $source): array
    {
        $context = $this->auditContext($partner, $source);
        $inspection = $this->inspect($partner);

        if ($inspection['blocked']) {
            $this->audit(ActivityLog::ACTION_PARTNER_DELETE_BLOCKED, $partner, $context, 'blocked', $inspection['reasons']);

            return ['deleted' => false, 'reasons' => $inspection['reasons']];
        }

        $result = DB::transaction(function () use ($partner, $context): array {
            $locked = Customer::query()->whereKey($partner->id)->lockForUpdate()->first();
            if (! $locked) {
                return ['deleted' => false, 'partner' => $partner, 'reasons' => ['partner_not_found']];
            }

            $inspection = $this->inspect($locked);
            if ($inspection['blocked']) {
                return ['deleted' => false, 'partner' => $locked, 'reasons' => $inspection['reasons']];
            }

            $locked->delete();
            $this->audit(ActivityLog::ACTION_PARTNER_DELETE, $locked, $context, 'success', []);

            return ['deleted' => true, 'partner' => $locked, 'reasons' => []];
        });

        if (! $result['deleted']) {
            $this->audit(
                ActivityLog::ACTION_PARTNER_DELETE_BLOCKED,
                $result['partner'],
                $context,
                'blocked',
                $result['reasons'],
            );
        }

        return ['deleted' => $result['deleted'], 'reasons' => $result['reasons']];
    }

    private function referenceCount(string $table, string $column, int $partnerId): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        return DB::table($table)->where($column, $partnerId)->count();
    }

    private function cashFlowCount(int $partnerId): int
    {
        if (! Schema::hasTable('cash_flows') || ! Schema::hasColumn('cash_flows', 'target_id')) {
            return 0;
        }

        $query = DB::table('cash_flows')->where('target_id', $partnerId);
        if (Schema::hasColumn('cash_flows', 'target_type')) {
            $query->where(function ($targetQuery) {
                $targetQuery->whereNull('target_type')
                    ->orWhereIn('target_type', self::CASH_FLOW_TARGET_TYPES);
            });
        }

        return $query->count();
    }

    private function auditContext(Customer $partner, string $source): array
    {
        $request = request();

        return [
            'actor_user_id' => auth()->id(),
            'request_id' => $request?->header('X-Request-ID') ?: (string) Str::uuid(),
            'route' => $request?->route()?->getName(),
            'ip' => $request?->ip(),
            'source' => $source,
            'partner_id' => (int) $partner->id,
            'partner_code' => (string) $partner->code,
        ];
    }

    private function audit(
        string $action,
        Customer $partner,
        array $context,
        string $result,
        array $reasons,
    ): void {
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        ActivityLog::log(
            $action,
            $result === 'success'
                ? 'Xóa đối tác chưa phát sinh dữ liệu thành công'
                : 'Chặn xóa đối tác có lịch sử hoặc số liệu tài chính',
            $partner,
            array_merge($context, [
                'result' => $result,
                'reasons' => $reasons,
                'created_at' => now()->toIso8601String(),
            ]),
        );
    }
}
