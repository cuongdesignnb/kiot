<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\PartnerMerge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PartnerMergeService
{
    public function preview(Customer $source, Customer $target): array
    {
        $this->assertMergeable($source, $target);

        $debtAfter = (float) $source->debt_amount + (float) $target->debt_amount;
        $supplierDebtAfter = (float) $source->supplier_debt_amount
            + (float) $target->supplier_debt_amount;
        $totalSpentAfter = (float) $source->total_spent + (float) $target->total_spent;
        $totalReturnsAfter = (float) $source->total_returns + (float) $target->total_returns;
        $totalBoughtAfter = (float) $source->total_bought + (float) $target->total_bought;

        return [
            'before' => [
                'source' => $this->partnerSnapshot($source),
                'target' => $this->partnerSnapshot($target),
            ],
            'after' => [
                'debt_amount' => $debtAfter,
                'supplier_debt_amount' => $supplierDebtAfter,
                'total_spent' => $totalSpentAfter,
                'total_returns' => $totalReturnsAfter,
                'total_bought' => $totalBoughtAfter,
                'customer_net_position' => $debtAfter - $supplierDebtAfter,
                'supplier_net_position' => $supplierDebtAfter - $debtAfter,
            ],
            'marker' => [
                'ref_code' => $this->markerCode($source->id, $target->id),
                'amount' => 0.0,
                'type' => 'merge_marker',
                'source_layer' => 'reference',
                'is_reference_only' => true,
                'affects_debt_balance' => false,
            ],
        ];
    }

    public function merge(Customer $source, Customer $target): array
    {
        $this->assertMergeable($source, $target);

        return DB::transaction(function () use ($source, $target) {
            $partners = Customer::query()
                ->whereIn('id', [$source->id, $target->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $lockedSource = $partners->get($source->id);
            $lockedTarget = $partners->get($target->id);

            if (!$lockedSource || !$lockedTarget) {
                throw ValidationException::withMessages([
                    'merge_with_id' => 'Không tìm thấy đối tác để gộp.',
                ]);
            }
            $this->assertMergeable($lockedSource, $lockedTarget);

            $markerCode = $this->markerCode($lockedSource->id, $lockedTarget->id);
            if (PartnerMerge::where('ref_code', $markerCode)->exists()) {
                throw ValidationException::withMessages([
                    'merge_with_id' => 'Đối tác đã được gộp.',
                ]);
            }

            $preview = $this->preview($lockedSource, $lockedTarget);
            PartnerMerge::create([
                'ref_code' => $markerCode,
                'source_partner_id' => $lockedSource->id,
                'target_partner_id' => $lockedTarget->id,
                'source_debt_amount' => $lockedSource->debt_amount,
                'source_supplier_debt_amount' => $lockedSource->supplier_debt_amount,
                'target_debt_amount_before' => $lockedTarget->debt_amount,
                'target_supplier_debt_amount_before' => $lockedTarget->supplier_debt_amount,
                'source_total_spent_before' => $lockedSource->total_spent,
                'source_total_returns_before' => $lockedSource->total_returns,
                'source_total_bought_before' => $lockedSource->total_bought,
                'target_total_spent_before' => $lockedTarget->total_spent,
                'target_total_returns_before' => $lockedTarget->total_returns,
                'target_total_bought_before' => $lockedTarget->total_bought,
                'target_debt_amount_after' => $preview['after']['debt_amount'],
                'target_supplier_debt_amount_after' => $preview['after']['supplier_debt_amount'],
                'target_total_spent_after' => $preview['after']['total_spent'],
                'target_total_returns_after' => $preview['after']['total_returns'],
                'target_total_bought_after' => $preview['after']['total_bought'],
                'merged_by' => auth()->id(),
                'merged_at' => now(),
            ]);

            $this->transferRelations($lockedSource->id, $lockedTarget->id, $lockedTarget->name);

            $lockedTarget->forceFill([
                'debt_amount' => $preview['after']['debt_amount'],
                'supplier_debt_amount' => $preview['after']['supplier_debt_amount'],
                'total_spent' => $preview['after']['total_spent'],
                'total_returns' => $preview['after']['total_returns'],
                'total_bought' => $preview['after']['total_bought'],
                'is_customer' => (bool) ($lockedTarget->is_customer || $lockedSource->is_customer),
                'is_supplier' => (bool) ($lockedTarget->is_supplier || $lockedSource->is_supplier),
            ])->save();

            CustomerDebt::firstOrCreate(
                [
                    'customer_id' => $lockedTarget->id,
                    'ref_code' => $markerCode,
                    'type' => 'merge_marker',
                ],
                [
                    'amount' => 0,
                    'debt_total' => (float) $lockedTarget->debt_amount,
                    'note' => "Gộp hồ sơ {$lockedSource->code} vào {$lockedTarget->code}",
                    'created_by' => auth()->id(),
                    'recorded_at' => now(),
                ]
            );

            $lockedSource->forceFill([
                'debt_amount' => 0,
                'supplier_debt_amount' => 0,
                'total_spent' => 0,
                'total_returns' => 0,
                'total_bought' => 0,
                'status' => 'inactive',
                'merged_into_id' => $lockedTarget->id,
                'merged_at' => now(),
            ])->save();

            ActivityLog::log(
                'partner_merge',
                "Gộp đối tác {$lockedSource->code} vào {$lockedTarget->code}",
                $lockedTarget,
                [
                    'source_partner_id' => $lockedSource->id,
                    'marker_code' => $markerCode,
                    'before' => $preview['before'],
                    'after' => $preview['after'],
                ]
            );

            return $preview;
        });
    }

    private function assertMergeable(Customer $source, Customer $target): void
    {
        if ($source->id === $target->id) {
            throw ValidationException::withMessages([
                'merge_with_id' => 'Không thể gộp đối tác với chính mình.',
            ]);
        }
        if ($source->merged_into_id) {
            throw ValidationException::withMessages([
                'merge_with_id' => 'Đối tác nguồn đã được gộp.',
            ]);
        }
        if ($target->merged_into_id || $target->status === 'inactive') {
            throw ValidationException::withMessages([
                'merge_with_id' => 'Đối tác đích không còn hoạt động.',
            ]);
        }
    }

    private function transferRelations(int $sourceId, int $targetId, string $targetName): void
    {
        $relations = [
            ['invoices', 'customer_id'],
            ['orders', 'customer_id'],
            ['returns', 'customer_id'],
            ['purchases', 'supplier_id'],
            ['purchase_orders', 'supplier_id'],
            ['purchase_returns', 'supplier_id'],
            ['customer_debts', 'customer_id'],
            ['supplier_debt_transactions', 'supplier_id'],
            ['debt_offsets', 'customer_id'],
            ['customer_payment_allocations', 'customer_id'],
            ['customer_payment_discounts', 'customer_id'],
            ['customer_payment_discount_allocations', 'customer_id'],
            ['customer_delivery_addresses', 'customer_id'],
            ['promotion_usages', 'customer_id'],
            ['tasks', 'customer_id'],
            ['waybills', 'customer_id'],
        ];

        foreach ($relations as [$table, $column]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                DB::table($table)->where($column, $sourceId)->update([$column => $targetId]);
            }
        }

        CashFlow::withTrashed()
            ->where('target_id', $sourceId)
            ->whereIn('target_type', [
                'customer',
                'supplier',
                'Khách hàng',
                'Nhà cung cấp',
                'Khach hang',
                'Nha cung cap',
            ])
            ->update(['target_id' => $targetId, 'target_name' => $targetName]);

        foreach ($relations as [$table, $column]) {
            if (
                Schema::hasTable($table)
                && Schema::hasColumn($table, $column)
                && DB::table($table)->where($column, $sourceId)->exists()
            ) {
                throw ValidationException::withMessages([
                    'merge_with_id' => "Không thể chuyển hết dữ liệu liên quan trong bảng {$table}.",
                ]);
            }
        }
    }

    private function partnerSnapshot(Customer $partner): array
    {
        return [
            'id' => $partner->id,
            'code' => $partner->code,
            'name' => $partner->name,
            'debt_amount' => (float) $partner->debt_amount,
            'supplier_debt_amount' => (float) $partner->supplier_debt_amount,
            'total_spent' => (float) $partner->total_spent,
            'total_returns' => (float) $partner->total_returns,
            'total_bought' => (float) $partner->total_bought,
            'document_counts' => [
                'invoices' => $partner->invoices()->count(),
                'orders' => $partner->orders()->count(),
                'returns' => $partner->returns()->count(),
                'purchases' => $partner->purchases()->count(),
            ],
        ];
    }

    private function markerCode(int $sourceId, int $targetId): string
    {
        return "MERGE-PARTNER-{$sourceId}-TO-{$targetId}";
    }
}
