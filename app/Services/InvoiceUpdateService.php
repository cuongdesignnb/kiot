<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\Setting;
use App\Models\Warranty;
use App\Services\Debt\PartnerDebtMutationCoordinator;
use App\Support\Customers\CustomerGroupSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * STEP 24.3 — Invoice Update Engine with Impact Safety.
 *
 * Responsibilities:
 *   1. Build a change plan comparing old invoice vs new payload.
 *   2. Execute date-only updates (no stock/cost/debt mutation).
 *   3. Execute commercial-only updates without replaying stock or serials.
 *   4. Execute inventory-impact updates (full reverse + re-apply within DB transaction).
 *   4. Enforce time lock, permission override, e-invoice block.
 */
class InvoiceUpdateService
{
    public function __construct(private readonly PartnerDebtMutationCoordinator $coordinator) {}

    /**
     * Build change plan: detect what changed between current invoice and payload.
     */
    public function buildChangePlan(Invoice $invoice, array $payload): array
    {
        $invoice->loadMissing('items');

        $plan = [
            'date_changed' => false,
            'header_changed' => false,
            'financial_changed' => false,
            'items_changed' => false,
            'serial_changed' => false,
            'customer_changed' => false,
            'seller_changed' => false,
            'payment_changed' => false,
            'product_changed' => false,
            'quantity_changed' => false,
            'inventory_identity_changed' => false,
            'commercial_changed' => false,
            'metadata_changed' => false,
        ];

        // 1. Date changed
        if (array_key_exists('transaction_date', $payload) && $payload['transaction_date'] !== null) {
            $oldTxDate = $invoice->transaction_date
                ? Carbon::parse($invoice->transaction_date)
                : Carbon::parse($invoice->created_at);
            $newTxDate = Carbon::parse($payload['transaction_date']);

            if (! $oldTxDate->copy()->seconds(0)->equalTo($newTxDate->copy()->seconds(0))) {
                $plan['date_changed'] = true;
            }
        }

        // 2. Header changed
        $headerFields = [
            'branch_id', 'note', 'sales_channel', 'price_book_name', 'is_delivery',
            'delivery_partner', 'receiver_name', 'receiver_phone', 'receiver_address',
            'receiver_ward', 'receiver_district', 'receiver_city', 'delivery_note',
            'weight', 'length', 'width', 'height', 'delivery_service', 'expected_delivery_date',
        ];
        foreach ($headerFields as $field) {
            if (array_key_exists($field, $payload)) {
                $old = (string) ($invoice->$field ?? '');
                $new = (string) ($payload[$field] ?? '');
                if ($old !== $new) {
                    $plan['header_changed'] = true;
                    break;
                }
            }
        }
        $plan['metadata_changed'] = $plan['header_changed'];

        // Payment method belongs to the commercial/payment contract, not inventory.
        if (array_key_exists('payment_method', $payload)
            && (string) ($invoice->payment_method ?? '') !== (string) ($payload['payment_method'] ?? '')) {
            $plan['payment_changed'] = true;
        }

        // 3. Customer changed
        $oldCustId = $invoice->customer_id;
        $newCustId = $payload['customer_id'] ?? $oldCustId;
        if ((int) $oldCustId !== (int) $newCustId) {
            $plan['customer_changed'] = true;
        }

        $newSellerId = $payload['seller_employee_id'] ?? $payload['created_by'] ?? $invoice->created_by;
        if ((int) ($invoice->created_by ?? 0) !== (int) ($newSellerId ?? 0)
            || (array_key_exists('seller_name', $payload)
                && (string) ($invoice->seller_name ?? '') !== (string) ($payload['seller_name'] ?? ''))) {
            $plan['seller_changed'] = true;
        }

        // 4. Financial changed
        $financialFields = ['subtotal', 'discount', 'total', 'customer_paid', 'delivery_fee'];
        foreach ($financialFields as $field) {
            if (array_key_exists($field, $payload)) {
                $old = round((float) ($invoice->$field ?? 0), 2);
                $new = round((float) ($payload[$field] ?? 0), 2);
                if (abs($old - $new) >= 0.01) {
                    $plan['financial_changed'] = true;
                    break;
                }
            }
        }

        // Commercial item comparison is deliberately separate from inventory identity.
        $oldItems = $invoice->items->map(fn ($i) => [
            'product_id' => (int) $i->product_id,
            'price' => round((float) $i->price, 2),
            'discount' => round((float) ($i->discount ?? 0), 2),
            'note' => (string) ($i->note ?? ''),
        ])->sort(fn (array $a, array $b) => json_encode($a) <=> json_encode($b))->values()->toArray();

        $newItems = collect($payload['items'] ?? [])->map(fn ($i) => [
            'product_id' => (int) $i['product_id'],
            'price' => round((float) $i['price'], 2),
            'discount' => round((float) ($i['discount'] ?? 0), 2),
            'note' => (string) ($i['note'] ?? ''),
        ])->sort(fn (array $a, array $b) => json_encode($a) <=> json_encode($b))->values()->toArray();

        $plan['items_changed'] = $oldItems !== $newItems;

        $oldIdentity = $this->inventoryIdentityCanonical($invoice);
        $newIdentity = $this->payloadInventoryIdentityCanonical($payload['items'] ?? []);
        $plan['inventory_identity_changed'] = $oldIdentity !== $newIdentity;
        // Backward-compatible signal for legacy callers/tests. New routing uses
        // inventory_identity_changed, so a price-only edit still never replays stock.
        $plan['items_changed'] = $plan['items_changed'] || $plan['inventory_identity_changed'];

        $oldProductIds = collect($oldIdentity)->pluck('product_id')->sort()->values()->all();
        $newProductIds = collect($newIdentity)->pluck('product_id')->sort()->values()->all();
        $plan['product_changed'] = $oldProductIds !== $newProductIds;

        $oldQuantities = collect($oldIdentity)->map(fn (array $item) => [
            'product_id' => $item['product_id'], 'quantity' => $item['quantity'],
        ])->sort(fn (array $a, array $b) => json_encode($a) <=> json_encode($b))->values()->all();
        $newQuantities = collect($newIdentity)->map(fn (array $item) => [
            'product_id' => $item['product_id'], 'quantity' => $item['quantity'],
        ])->sort(fn (array $a, array $b) => json_encode($a) <=> json_encode($b))->values()->all();
        $plan['quantity_changed'] = $oldQuantities !== $newQuantities;

        // Serial state is always sourced from persisted sale records, never trusted from UI alone.
        $oldSerialIds = SerialImei::where('invoice_id', $invoice->id)
            ->where('status', 'sold')->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $newSerialIds = collect($payload['items'] ?? [])
            ->pluck('serial_ids')->flatten()->filter()->map(fn ($v) => (int) $v)
            ->unique()->sort()->values()->all();
        if ($oldSerialIds !== $newSerialIds) {
            $plan['serial_changed'] = true;
        }

        $plan['commercial_changed'] = $plan['items_changed']
            || $plan['financial_changed']
            || $plan['payment_changed']
            || $plan['customer_changed']
            || $plan['seller_changed'];

        // Derived. Keep legacy keys for callers/tests, but never use items_changed as
        // an inventory signal: price/discount/note changes are commercial only.
        $plan['only_date_changed'] = $plan['date_changed']
            && ! $plan['header_changed']
            && ! $plan['commercial_changed']
            && ! $plan['inventory_identity_changed'];
        $plan['only_commercial_changed'] = ! $plan['inventory_identity_changed']
            && ($plan['commercial_changed'] || $plan['metadata_changed']);
        $plan['requires_inventory_replay'] = $plan['inventory_identity_changed'];
        $plan['content_changed'] = $plan['requires_inventory_replay']
            || $plan['commercial_changed'];

        return $plan;
    }

    /**
     * Validate time lock, permissions, e-invoice block.
     * Returns null if OK, or error string.
     */
    public function validateLockAndPermissions(Invoice $invoice, array $payload, array $context): ?string
    {
        // E-invoice block — absolute, even with override
        if (Setting::get('block_edit_cancel_einvoice', false) && ! empty($invoice->einvoice_code)) {
            return 'Không thể sửa hóa đơn đã xuất hóa đơn điện tử.';
        }

        $changePlan = $this->buildChangePlan($invoice, $payload);
        $orderChangeTime = Setting::get('order_change_time', 24);
        $lockRef = $invoice->lock_started_at ?? $invoice->created_at;
        $isOverdue = Carbon::parse($lockRef)->diffInHours(now()) > $orderChangeTime;
        $user = $context['user'] ?? auth()->user();

        if ($isOverdue) {
            $hasOverride = $user && $user->hasPermission('invoices.override_time_lock');
            if (! $hasOverride) {
                return "Đã quá thời gian cho phép chỉnh sửa ({$orderChangeTime} giờ). Cần quyền override.";
            }
            $reason = $context['time_lock_override_reason'] ?? null;
            if (! $reason || strlen(trim($reason)) < 5) {
                return 'Cần nhập lý do override (ít nhất 5 ký tự).';
            }
        }

        if ($changePlan['date_changed']) {
            $hasDatePerm = $user && $user->hasPermission('invoices.change_transaction_date');
            if (! $hasDatePerm) {
                return 'Cần quyền invoices.change_transaction_date để đổi ngày hóa đơn.';
            }
            $reason = $context['transaction_date_change_reason'] ?? null;
            if (! $reason || strlen(trim($reason)) < 5) {
                return 'Cần nhập lý do đổi ngày hóa đơn (ít nhất 5 ký tự).';
            }
        }

        return null;
    }

    /**
     * Main update entry point.
     */
    public function updateInvoice(Invoice $invoice, array $payload, array $context = []): Invoice
    {
        $this->assertInvoiceIsEditable($invoice);

        app(PartnerTransactionGuard::class)->assertCanTransact(
            isset($payload['customer_id'])
                ? (int) $payload['customer_id']
                : ($invoice->customer_id ? (int) $invoice->customer_id : null),
            'customer_id'
        );

        $lockError = $this->validateLockAndPermissions($invoice, $payload, $context);
        if ($lockError) {
            throw new \Exception($lockError);
        }

        $changePlan = $this->buildChangePlan($invoice, $payload);

        if ($changePlan['only_date_changed']) {
            return $this->applyDateOnlyUpdate($invoice, $payload, $changePlan, $context);
        }

        if ($changePlan['requires_inventory_replay']) {
            return $this->applyInventoryImpactUpdate($invoice, $payload, $changePlan, $context);
        }

        if ($changePlan['only_commercial_changed']) {
            return $this->applyCommercialOnlyUpdate($invoice, $payload, $changePlan, $context);
        }

        // Nothing changed
        return $invoice;
    }

    /**
     * Date-only update: MUST NOT mutate stock, cost, debt, serial status.
     */
    private function applyDateOnlyUpdate(Invoice $invoice, array $payload, array $changePlan, array $context): Invoice
    {
        return DB::transaction(function () use ($invoice, $payload, $changePlan, $context) {
            $newTxDate = Carbon::parse($payload['transaction_date']);
            $oldTxDate = $invoice->transaction_date ?? $invoice->created_at;

            if (! Schema::hasColumn('invoices', 'transaction_date')) {
                throw new \RuntimeException('Không thể đổi ngày bán vì thiếu cột transaction_date.');
            }

            $invoice->transaction_date = $newTxDate;
            $invoice->save();

            // Update related CashFlow time if policy
            CashFlow::where('reference_type', 'Invoice')
                ->where('reference_code', $invoice->code)
                ->whereNull('deleted_at')
                ->where(function ($q) {
                    $q->whereNull('status')
                        ->orWhere('status', '!=', 'cancelled');
                })
                ->update(['time' => $newTxDate]);

            // Update warranty dates if not attached to repair/claim
            $this->updateWarrantyDatesIfSafe($invoice, $newTxDate);

            // ActivityLog
            $this->logActivity($invoice, $changePlan, $context, [
                'old_transaction_date' => $oldTxDate ? Carbon::parse($oldTxDate)->toDateTimeString() : null,
                'new_transaction_date' => $newTxDate->toDateTimeString(),
            ]);

            return $invoice->refresh();
        });
    }

    /**
     * Commercial/header update: update the persisted invoice and invoice-item rows
     * in place. This path must never replay inventory, touch serials, rebuild
     * warranty, or replace cost snapshots.
     */
    private function applyCommercialOnlyUpdate(Invoice $invoice, array $payload, array $changePlan, array $context): Invoice
    {
        $applyUpdate = function () use ($invoice, $payload, $changePlan, $context): Invoice {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $lockedItems = InvoiceItem::query()
                ->where('invoice_id', $lockedInvoice->id)
                ->lockForUpdate()
                ->get();
            $lockedInvoice->setRelation('items', $lockedItems);
            $this->assertInvoiceIsEditable($lockedInvoice);

            $payloadItems = $this->assertCommercialPayloadPreservesInventory($lockedInvoice, $payload);
            $payload = array_merge($payload, $this->canonicalCommercialAmounts($payloadItems, $payload));
            $oldTotal = (float) $lockedInvoice->total;
            $oldPaid = (float) ($lockedInvoice->customer_paid ?? 0);
            $oldDebt = $oldTotal - $oldPaid;
            $oldCustomerId = $lockedInvoice->customer_id ? (int) $lockedInvoice->customer_id : null;
            $oldSellerId = $lockedInvoice->created_by ? (int) $lockedInvoice->created_by : null;
            $oldSellerName = $lockedInvoice->seller_name;

            $updateData = $this->commercialInvoiceAttributes($lockedInvoice, $payload, $changePlan);
            $newCustomerId = array_key_exists('customer_id', $updateData)
                ? ($updateData['customer_id'] ? (int) $updateData['customer_id'] : null)
                : $oldCustomerId;
            $updateData = CustomerGroupSnapshot::applyToAttributes($updateData, $newCustomerId, 'invoices');
            $lockedInvoice->update($updateData);

            foreach ($payloadItems as $payloadItem) {
                /** @var InvoiceItem $item */
                $item = $payloadItem['invoice_item'];
                $item->update([
                    'price' => $payloadItem['price'],
                    'discount' => $payloadItem['discount'],
                    'subtotal' => ($payloadItem['price'] * (float) $item->quantity) - $payloadItem['discount'],
                    'note' => $payloadItem['note'],
                ]);
            }

            $lockedInvoice->refresh();
            $newTotal = (float) $lockedInvoice->total;
            $newPaid = (float) ($lockedInvoice->customer_paid ?? 0);
            $newDebt = $newTotal - $newPaid;

            $this->applyCommercialCustomerEffects(
                $lockedInvoice,
                $oldCustomerId,
                $newCustomerId,
                $oldTotal,
                $newTotal,
                $oldDebt,
                $newDebt,
            );

            $transactionDate = $lockedInvoice->transaction_date ?? $lockedInvoice->created_at ?? now();
            $this->syncInvoiceCashFlow($lockedInvoice, $newCustomerId, $newPaid, $transactionDate);

            if ($changePlan['date_changed']) {
                $newTxDate = Carbon::parse($payload['transaction_date']);
                CashFlow::where('reference_type', 'Invoice')
                    ->where('reference_code', $lockedInvoice->code)
                    ->active()
                    ->update(['time' => $newTxDate]);
                $this->updateWarrantyDatesIfSafe($lockedInvoice, $newTxDate);
            }

            $this->logActivity($lockedInvoice, $changePlan, $context, [
                'change_type' => 'commercial_only',
                'inventory_mutated' => false,
                'serial_mutated' => false,
                'old_total' => $oldTotal,
                'new_total' => $newTotal,
                'old_paid' => $oldPaid,
                'new_paid' => $newPaid,
                'old_customer_id' => $oldCustomerId,
                'new_customer_id' => $newCustomerId,
                'old_seller' => ['employee_id' => $oldSellerId, 'name' => $oldSellerName],
                'new_seller' => [
                    'employee_id' => $lockedInvoice->created_by,
                    'name' => $lockedInvoice->seller_name,
                ],
            ]);

            return $lockedInvoice->refresh()->load('items.product');
        };

        $partnerIds = collect([$invoice->customer_id, $payload['customer_id'] ?? $invoice->customer_id])
            ->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        if ($partnerIds === []) {
            return DB::transaction($applyUpdate);
        }

        return $this->coordinator->executeForPartners(
            $partnerIds,
            'invoice_commercial_update',
            hash('sha256', json_encode([
                'invoice_id' => (int) $invoice->id,
                'payload' => $payload,
            ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
            function ($partners) use ($applyUpdate): Invoice {
                foreach ($partners as $partner) {
                    if (! (bool) $partner->is_customer) {
                        throw ValidationException::withMessages([
                            'customer_id' => 'Đối tác không có vai trò khách hàng đã được lưu.',
                        ]);
                    }
                }

                return DB::transaction($applyUpdate);
            },
            isset($context['idempotency_key']) ? (string) $context['idempotency_key'] : null,
        );
    }

    /**
     * Inventory-impact update: reverse old sale, then apply the changed sale.
     */
    private function applyInventoryImpactUpdate(Invoice $invoice, array $payload, array $changePlan, array $context): Invoice
    {
        $applyUpdate = function () use ($invoice, $payload, $changePlan, $context) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $lockedItems = InvoiceItem::query()
                ->where('invoice_id', $invoice->id)
                ->lockForUpdate()
                ->get();
            $invoice->setRelation('items', $lockedItems);

            // --- Pre-flight validations ---
            $this->preflightContentValidation($invoice, $payload, $context);

            // Capture old values
            $oldTotal = (float) $invoice->total;
            $oldPaid = (float) ($invoice->customer_paid ?? 0);
            $oldDebt = $oldTotal - $oldPaid;
            $oldCustomerId = $invoice->customer_id;

            // --- 1. Lock and restore old serials BEFORE recomputing serial stock. ---
            // The previous ordering recomputed while serials still looked sold, which
            // could transiently force stock to zero and reject a valid replacement sale.
            $oldSerials = SerialImei::query()
                ->where('invoice_id', $invoice->id)
                ->where('status', 'sold')
                ->lockForUpdate()
                ->get();
            foreach ($oldSerials as $serial) {
                $serial->status = 'in_stock';
                $serial->sold_at = null;
                $serial->invoice_id = null;
                $serial->save();
            }

            // --- 2. Reverse old sale ---
            foreach ($invoice->items as $oldItem) {
                $product = Product::lockForUpdate()->find($oldItem->product_id);
                if ($product && $product->tracksInventory()) {
                    $costAtSale = (float) ($oldItem->cost_price ?? $product->cost_price ?? 0);
                    MovingAvgCostingService::applySaleReturn($product, (int) $oldItem->quantity, $costAtSale);
                    $product->refresh();
                    if ($product->has_serial) {
                        $product->recomputeFromSerials();
                    }
                }
            }

            // --- 3. Reverse old finance ---
            if ($oldCustomerId) {
                $oldCustomer = Customer::find($oldCustomerId);
                if ($oldCustomer) {
                    $newCustomerId = $payload['customer_id'] ?? $oldCustomerId;
                    if ((int) $oldCustomerId !== (int) $newCustomerId) {
                        // Customer changed — full reverse old
                        if (abs($oldDebt) >= 0.01) {
                            $this->recordInvoiceOwnerTransfer(
                                $oldCustomer->id,
                                $oldDebt,
                                $invoice,
                                "Đảo công nợ do chuyển hóa đơn {$invoice->code} sang khách khác",
                                ['ref_code' => $invoice->code]
                            );
                        }
                        $oldCustomer->decrement('total_spent', $oldTotal);
                    } else {
                        // Same customer — will apply diff later
                    }
                }
            }

            // --- 4. Update invoice header ---
            $newTxDate = isset($payload['transaction_date'])
                ? Carbon::parse($payload['transaction_date'])
                : ($invoice->transaction_date ?? $invoice->created_at);

            $updateData = [
                'customer_id' => $payload['customer_id'] ?? $invoice->customer_id,
                'branch_id' => $payload['branch_id'] ?? $invoice->branch_id,
                'subtotal' => $payload['subtotal'],
                'discount' => $payload['discount'] ?? 0,
                'total' => $payload['total'],
                'customer_paid' => $payload['customer_paid'] ?? 0,
                'note' => $payload['note'] ?? null,
                'is_delivery' => $payload['is_delivery'] ?? false,
                'delivery_partner' => $payload['delivery_partner'] ?? null,
                'delivery_fee' => $payload['delivery_fee'] ?? 0,
                'payment_method' => $payload['payment_method'] ?? 'Tiền mặt',
                'price_book_name' => $payload['price_book_name'] ?? $invoice->price_book_name,
            ];
            if ($changePlan['date_changed'] && Schema::hasColumn('invoices', 'transaction_date')) {
                $updateData['transaction_date'] = $newTxDate;
            }
            $updateData = CustomerGroupSnapshot::applyToAttributes($updateData, $updateData['customer_id'] ?? null, 'invoices');
            $invoice->update($updateData);

            // --- 5. Delete old items, create new ---
            $invoice->items()->delete();

            $allowOversell = Setting::get('inventory_allow_oversell', true);

            foreach ($payload['items'] as $item) {
                $product = Product::lockForUpdate()->find($item['product_id']);
                $serialIds = $item['serial_ids'] ?? [];
                $isService = $product?->isService() ?? false;
                $snapshotCostPrice = $isService ? 0.0 : (float) ($product->cost_price ?? 0);
                $serialStr = null;
                $soldSerials = collect();

                if ($isService && ! empty($serialIds)) {
                    throw new \Exception("Sản phẩm '{$product->name}' là dịch vụ, không quản lý Serial/IMEI.");
                }

                if ($product && $product->tracksInventory() && $product->has_serial && ! empty($serialIds)) {
                    $serialIds = is_array($serialIds) ? $serialIds : [$serialIds];
                    $soldSerials = SerialImei::whereIn('id', $serialIds)
                        ->where('product_id', $product->id)
                        ->get();
                    foreach ($soldSerials as $serial) {
                        $serial->status = 'sold';
                        $serial->sold_at = $newTxDate;
                        $serial->invoice_id = $invoice->id;
                        $serial->sold_cost_price = $snapshotCostPrice;
                        $serial->save();
                    }
                    $serialStr = $soldSerials->pluck('serial_number')->implode(', ');
                }

                $newInvoiceItem = $invoice->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'cost_price' => $snapshotCostPrice,
                    'discount' => $item['discount'] ?? 0,
                    'subtotal' => ($item['price'] * $item['quantity']) - ($item['discount'] ?? 0),
                    'note' => $item['note'] ?? null,
                    'serial' => $serialStr,
                ]);

                foreach ($soldSerials as $serial) {
                    InvoiceItemSerial::create([
                        'invoice_item_id' => $newInvoiceItem->id,
                        'serial_imei_id' => $serial->id,
                        'serial_number' => $serial->serial_number,
                        'cost_price' => $snapshotCostPrice,
                    ]);
                }

                if ($product && $product->tracksInventory()) {
                    if (! $allowOversell && $product->stock_quantity < $item['quantity']) {
                        throw new \Exception("Sản phẩm [{$product->sku}] {$product->name} không đủ tồn kho (Còn: {$product->stock_quantity})");
                    }
                    MovingAvgCostingService::applySale($product, (int) $item['quantity']);
                    $product->refresh();
                    if ($product->has_serial) {
                        $product->recomputeFromSerials();
                    }
                }
            }

            // --- 6. Customer debt ---
            $newTotal = (float) $payload['total'];
            $newPaid = (float) ($payload['customer_paid'] ?? 0);
            $newDebt = $newTotal - $newPaid;
            $newCustomerId = $payload['customer_id'] ?? $oldCustomerId;

            if ($oldCustomerId && (int) $oldCustomerId === (int) $newCustomerId && $newCustomerId) {
                // Same customer — apply diff
                $newCustomer = Customer::find($newCustomerId);
                if ($newCustomer) {
                    $debtDiff = $newDebt - $oldDebt;
                    $totalDiff = $newTotal - $oldTotal;
                    if (abs($debtDiff) >= 0.01) {
                        app(CustomerDebtService::class)->recordInvoiceBalanceEffect(
                            $newCustomer->id,
                            $debtDiff,
                            $invoice,
                            "Điều chỉnh công nợ do cập nhật hóa đơn {$invoice->code}",
                            ['ref_code' => $invoice->code]
                        );
                    }
                    $newCustomer->increment('total_spent', $totalDiff);
                }
            } elseif ($newCustomerId) {
                // New customer
                $newCustomer = Customer::find($newCustomerId);
                if ($newCustomer) {
                    if (abs($newDebt) >= 0.01) {
                        app(CustomerDebtService::class)->recordInvoiceBalanceEffect(
                            $newCustomer->id,
                            $newDebt,
                            $invoice,
                            "Ghi nợ do nhận hóa đơn {$invoice->code} từ khách khác",
                            ['ref_code' => $invoice->code, 'type' => 'sale']
                        );
                    }
                    $newCustomer->increment('total_spent', $newTotal);
                }
            }

            // --- 7. CashFlow ---
            CashFlow::where('reference_type', 'Invoice')
                ->where('reference_code', $invoice->code)
                ->delete();

            if ($newPaid > 0) {
                $customer = $newCustomerId ? Customer::find($newCustomerId) : null;
                CashFlow::create([
                    'code' => 'PT'.date('YmdHis').rand(10, 99),
                    'type' => 'receipt',
                    'amount' => $newPaid,
                    'time' => $newTxDate,
                    'category' => 'Thu tiền khách trả',
                    'target_type' => 'Khách hàng',
                    'target_id' => $customer?->id,
                    'target_name' => $customer?->name ?? 'Khách lẻ',
                    'reference_type' => 'Invoice',
                    'reference_code' => $invoice->code,
                    'payment_method' => $payload['payment_method'] ?? 'cash',
                    'description' => 'Thu tiền hóa đơn '.$invoice->code.($customer ? " - {$customer->name}" : ''),
                ]);
            }

            // --- 8. Warranty ---
            if ($changePlan['items_changed'] || $changePlan['serial_changed']) {
                $this->handleWarrantyOnContentUpdate($invoice);
            } elseif ($changePlan['date_changed']) {
                $this->updateWarrantyDatesIfSafe($invoice, $newTxDate);
            }

            // --- 9. ActivityLog ---
            $this->logActivity($invoice, $changePlan, $context, [
                'change_type' => 'inventory_impact',
                'inventory_mutated' => true,
                'serial_mutated' => (bool) $changePlan['serial_changed'],
                'old_total' => $oldTotal,
                'new_total' => $newTotal,
                'old_customer_id' => $oldCustomerId,
                'new_customer_id' => $newCustomerId,
            ]);

            return $invoice->refresh();
        };

        $partnerIds = collect([$invoice->customer_id, $payload['customer_id'] ?? $invoice->customer_id])
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        if ($partnerIds === []) {
            return DB::transaction($applyUpdate);
        }

        return $this->coordinator->executeForPartners(
            $partnerIds,
            'invoice_content_update',
            hash('sha256', json_encode([
                'invoice_id' => (int) $invoice->id,
                'payload' => $payload,
            ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
            function ($partners) use ($applyUpdate): Invoice {
                foreach ($partners as $partner) {
                    if (! (bool) $partner->is_customer) {
                        throw ValidationException::withMessages([
                            'customer_id' => 'Doi tac khong co vai tro khach hang da duoc luu.',
                        ]);
                    }
                }

                return DB::transaction($applyUpdate);
            },
            isset($context['idempotency_key']) ? (string) $context['idempotency_key'] : null,
        );
    }

    /** @return array<int, array{product_id:int, quantity:float, variant_id:mixed, unit_id:mixed, serial_ids:array<int, int>}> */
    private function inventoryIdentityCanonical(Invoice $invoice): array
    {
        $items = $invoice->items;
        $serialsByItem = $this->serialIdsByInvoiceItem($invoice, $items);

        return $items->map(fn (InvoiceItem $item) => [
            'product_id' => (int) $item->product_id,
            'quantity' => round((float) $item->quantity, 4),
            'variant_id' => $item->variant_id ?? null,
            'unit_id' => $item->unit_id ?? null,
            'serial_ids' => $serialsByItem[(int) $item->id] ?? [],
        ])->sort(fn (array $a, array $b) => json_encode($a) <=> json_encode($b))->values()->all();
    }

    /** @param array<int, array<string, mixed>> $items */
    private function payloadInventoryIdentityCanonical(array $items): array
    {
        return collect($items)->map(fn (array $item) => [
            'product_id' => (int) ($item['product_id'] ?? 0),
            'quantity' => round((float) ($item['quantity'] ?? 0), 4),
            'variant_id' => $item['variant_id'] ?? null,
            'unit_id' => $item['unit_id'] ?? null,
            'serial_ids' => collect($item['serial_ids'] ?? [])
                ->filter(fn ($id) => $id !== null && $id !== '')
                ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all(),
        ])->sort(fn (array $a, array $b) => json_encode($a) <=> json_encode($b))->values()->all();
    }

    /** @param \Illuminate\Support\Collection<int, InvoiceItem> $items */
    private function serialIdsByInvoiceItem(Invoice $invoice, $items): array
    {
        $itemIds = $items->pluck('id')->map(fn ($id) => (int) $id)->all();
        $linked = InvoiceItemSerial::query()->whereIn('invoice_item_id', $itemIds)
            ->whereNotNull('serial_imei_id')->get()
            ->groupBy('invoice_item_id')
            ->map(fn ($links) => $links->pluck('serial_imei_id')->map(fn ($id) => (int) $id)
                ->unique()->sort()->values()->all())->all();

        // Legacy records can predate invoice_item_serials. For a product with one
        // line, the persisted SerialImei invoice link is still an unambiguous source.
        $itemsByProduct = $items->groupBy('product_id');
        $legacyByProduct = SerialImei::query()->where('invoice_id', $invoice->id)
            ->where('status', 'sold')->get()->groupBy('product_id')
            ->map(fn ($serials) => $serials->pluck('id')->map(fn ($id) => (int) $id)
                ->unique()->sort()->values()->all());
        foreach ($items as $item) {
            if (! array_key_exists((int) $item->id, $linked)
                && ($itemsByProduct[$item->product_id] ?? collect())->count() === 1) {
                $linked[(int) $item->id] = $legacyByProduct->get($item->product_id, []);
            }

            if (! array_key_exists((int) $item->id, $linked)
                && ($itemsByProduct[$item->product_id] ?? collect())->count() > 1
                && ! empty($legacyByProduct->get($item->product_id, []))) {
                throw ValidationException::withMessages([
                    'items' => 'Không xác định được Serial/IMEI của từng dòng hóa đơn.',
                ]);
            }
        }

        return $linked;
    }

    /** @return array<int, array<string, mixed>> */
    private function assertCommercialPayloadPreservesInventory(Invoice $invoice, array $payload): array
    {
        $serialIdsByItem = $this->serialIdsByInvoiceItem($invoice, $invoice->items);
        if ($this->inventoryIdentityCanonical($invoice) !== $this->payloadInventoryIdentityCanonical($payload['items'] ?? [])) {
            throw ValidationException::withMessages([
                'items' => 'Hàng hóa, số lượng hoặc Serial/IMEI đã thay đổi. Vui lòng lưu theo luồng cập nhật tồn kho.',
            ]);
        }

        $itemsById = $invoice->items->keyBy('id');
        $usedItemIds = [];
        $resolved = [];
        foreach ($payload['items'] as $index => $payloadItem) {
            $hasItemId = array_key_exists('invoice_item_id', $payloadItem)
                && $payloadItem['invoice_item_id'] !== null
                && $payloadItem['invoice_item_id'] !== '';
            $itemId = $hasItemId ? (int) $payloadItem['invoice_item_id'] : 0;
            $invoiceItem = $itemId > 0 ? $itemsById->get($itemId) : null;
            if ($hasItemId && ! $invoiceItem) {
                throw ValidationException::withMessages([
                    "items.{$index}.invoice_item_id" => 'Dòng hóa đơn không thuộc hóa đơn đang sửa.',
                ]);
            }
            if (! $hasItemId) {
                $candidates = $invoice->items->filter(fn (InvoiceItem $item) => ! in_array($item->id, $usedItemIds, true)
                    && (int) $item->product_id === (int) $payloadItem['product_id']
                    && abs((float) $item->quantity - (float) $payloadItem['quantity']) < 0.0001);
                if ($candidates->count() !== 1) {
                    throw ValidationException::withMessages([
                        "items.{$index}.invoice_item_id" => 'Dòng hóa đơn không hợp lệ hoặc thiếu invoice_item_id ổn định.',
                    ]);
                }
                $invoiceItem = $candidates->first();
            }
            if (! $invoiceItem || (int) $invoiceItem->invoice_id !== (int) $invoice->id) {
                throw ValidationException::withMessages([
                    "items.{$index}.invoice_item_id" => 'Dòng hóa đơn không thuộc hóa đơn đang sửa.',
                ]);
            }
            if (in_array((int) $invoiceItem->id, $usedItemIds, true)) {
                throw ValidationException::withMessages([
                    "items.{$index}.invoice_item_id" => 'Một dòng hóa đơn không được dùng hai lần trong cùng yêu cầu.',
                ]);
            }
            if ((int) $invoiceItem->product_id !== (int) ($payloadItem['product_id'] ?? 0)) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => 'Sản phẩm không khớp với dòng hóa đơn đang sửa.',
                ]);
            }
            if (abs((float) $invoiceItem->quantity - (float) ($payloadItem['quantity'] ?? 0)) >= 0.0001) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => 'Số lượng không khớp với dòng hóa đơn đang sửa.',
                ]);
            }
            $payloadSerialIds = collect($payloadItem['serial_ids'] ?? [])
                ->filter(fn ($id) => $id !== null && $id !== '')
                ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            if (($serialIdsByItem[(int) $invoiceItem->id] ?? []) !== $payloadSerialIds) {
                throw ValidationException::withMessages([
                    "items.{$index}.serial_ids" => 'Serial/IMEI không khớp với dòng hóa đơn đang sửa.',
                ]);
            }
            $usedItemIds[] = (int) $invoiceItem->id;
            $price = round((float) ($payloadItem['price'] ?? -1), 2);
            $discount = round((float) ($payloadItem['discount'] ?? 0), 2);
            if ($price < 0 || $discount < 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.price" => 'Giá bán và giảm giá phải lớn hơn hoặc bằng 0.',
                ]);
            }
            $resolved[] = [
                'invoice_item' => $invoiceItem,
                'price' => $price,
                'discount' => $discount,
                'note' => $payloadItem['note'] ?? null,
            ];
        }

        if (count($usedItemIds) !== $invoice->items->count()) {
            throw ValidationException::withMessages([
                'items' => 'Danh sách dòng hóa đơn không đầy đủ.',
            ]);
        }

        return $resolved;
    }

    private function commercialInvoiceAttributes(Invoice $invoice, array $payload, array $changePlan): array
    {
        $fields = [
            'customer_id', 'branch_id', 'subtotal', 'discount', 'total', 'customer_paid',
            'note', 'is_delivery', 'delivery_partner', 'delivery_fee', 'payment_method',
            'price_book_name', 'sales_channel', 'receiver_name', 'receiver_phone',
            'receiver_address', 'receiver_ward', 'receiver_district', 'receiver_city',
            'delivery_note', 'weight', 'length', 'width', 'height', 'delivery_service',
            'expected_delivery_date', 'cod_amount', 'other_fees',
        ];
        $attributes = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $payload)) {
                $attributes[$field] = $payload[$field];
            }
        }
        if ($changePlan['date_changed'] && Schema::hasColumn('invoices', 'transaction_date')) {
            $attributes['transaction_date'] = Carbon::parse($payload['transaction_date']);
        }
        if (array_key_exists('seller_employee_id', $payload)
            && (int) $payload['seller_employee_id'] !== (int) $invoice->created_by) {
            $employee = Employee::query()->whereKey((int) $payload['seller_employee_id'])
                ->where('is_active', true)->first();
            if (! $employee) {
                throw ValidationException::withMessages([
                    'seller_employee_id' => 'Người bán không tồn tại hoặc đã ngưng hoạt động.',
                ]);
            }
            $attributes['created_by'] = $employee->id;
            $attributes['seller_name'] = $employee->name;
        }

        return $attributes;
    }

    /**
     * Recompute commercial money from locked invoice-item identity instead of
     * trusting subtotal/total values supplied by the browser.
     *
     * @param  array<int, array{invoice_item: InvoiceItem, price: float, discount: float, note: mixed}>  $payloadItems
     * @return array{subtotal: float, discount: float, delivery_fee: float, other_fees: float, total: float}
     */
    private function canonicalCommercialAmounts(array $payloadItems, array $payload): array
    {
        $subtotal = round(array_sum(array_map(
            fn (array $item) => ($item['price'] * (float) $item['invoice_item']->quantity) - $item['discount'],
            $payloadItems
        )), 2);
        $discount = round((float) ($payload['discount'] ?? 0), 2);
        $deliveryFee = round((float) ($payload['delivery_fee'] ?? 0), 2);
        $otherFees = round((float) ($payload['other_fees'] ?? 0), 2);

        if ($discount < 0 || $deliveryFee < 0 || $otherFees < 0) {
            throw ValidationException::withMessages([
                'discount' => 'Giảm giá và các khoản phí phải lớn hơn hoặc bằng 0.',
            ]);
        }

        $total = round($subtotal - $discount + $deliveryFee + $otherFees, 2);
        if ($total < 0) {
            throw ValidationException::withMessages([
                'discount' => 'Giảm giá không được lớn hơn giá trị hóa đơn.',
            ]);
        }

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'delivery_fee' => $deliveryFee,
            'other_fees' => $otherFees,
            'total' => $total,
        ];
    }

    private function applyCommercialCustomerEffects(
        Invoice $invoice,
        ?int $oldCustomerId,
        ?int $newCustomerId,
        float $oldTotal,
        float $newTotal,
        float $oldDebt,
        float $newDebt,
    ): void {
        if ($oldCustomerId && $oldCustomerId === $newCustomerId) {
            $customer = Customer::query()->find($oldCustomerId);
            if ($customer) {
                $debtDiff = $newDebt - $oldDebt;
                // The canonical debt reducer derives invoice debt from the invoice
                // and its receipt cash-flow. CustomerDebt rows are not a canonical
                // source here, so writing an extra ledger row would double-count.
                $customer->debt_amount = (float) $customer->debt_amount + $debtDiff;
                $customer->total_spent = (float) $customer->total_spent + ($newTotal - $oldTotal);
                $customer->save();
            }

            return;
        }

        if ($oldCustomerId && ($oldCustomer = Customer::query()->find($oldCustomerId))) {
            $oldCustomer->debt_amount = (float) $oldCustomer->debt_amount - $oldDebt;
            $oldCustomer->total_spent = (float) $oldCustomer->total_spent - $oldTotal;
            $oldCustomer->save();
        }
        if ($newCustomerId && ($newCustomer = Customer::query()->find($newCustomerId))) {
            // Invoice-sale ledger evidence must follow the document owner. If it
            // remains on the old customer it stops being recognised as a document
            // mirror and the canonical reducer counts it as a second receivable.
            if ($oldCustomerId) {
                CustomerDebt::query()
                    ->where('customer_id', $oldCustomerId)
                    ->where('ref_code', $invoice->code)
                    ->where('type', 'sale')
                    ->update(['customer_id' => $newCustomerId]);
            }
            $newCustomer->debt_amount = (float) $newCustomer->debt_amount + $newDebt;
            $newCustomer->total_spent = (float) $newCustomer->total_spent + $newTotal;
            $newCustomer->save();
        }
    }

    private function syncInvoiceCashFlow(Invoice $invoice, ?int $customerId, float $newPaid, Carbon $transactionDate): void
    {
        $cashFlows = CashFlow::withTrashed()->where('reference_type', 'Invoice')
            ->where('reference_code', $invoice->code)->lockForUpdate()->get();
        $cashFlow = $cashFlows->first(fn (CashFlow $flow) => $flow->deleted_at === null && $flow->status !== 'cancelled');
        if ($newPaid <= 0) {
            if ($cashFlow) {
                $cashFlow->delete();
            }

            return;
        }

        $customer = $customerId ? Customer::query()->find($customerId) : null;
        $attributes = [
            'amount' => $newPaid,
            'time' => $transactionDate,
            'target_id' => $customer?->id,
            'target_name' => $customer?->name ?? 'Khách lẻ',
            'payment_method' => $invoice->payment_method ?? 'Tiền mặt',
            'description' => 'Thu tiền hóa đơn '.$invoice->code.($customer ? " - {$customer->name}" : ''),
        ];
        if ($cashFlow) {
            $cashFlow->update($attributes);

            return;
        }
        CashFlow::create(array_merge($attributes, [
            'code' => 'PT'.now()->format('YmdHis').random_int(10, 99),
            'type' => 'receipt',
            'category' => 'Thu tiền khách trả',
            'target_type' => 'Khách hàng',
            'reference_type' => 'Invoice',
            'reference_code' => $invoice->code,
        ]));
    }

    private function assertInvoiceIsEditable(Invoice $invoice): void
    {
        $cancelled = ['Đã hủy', 'cancelled', 'canceled', 'void'];
        if (in_array(mb_strtolower((string) $invoice->status), array_map('mb_strtolower', $cancelled), true)) {
            throw ValidationException::withMessages([
                'invoice' => 'Hóa đơn đã hủy, không thể chỉnh sửa.',
            ]);
        }
    }

    private function preflightContentValidation(Invoice $invoice, array $payload, array $context): void
    {
        $this->assertInvoiceIsEditable($invoice);

        foreach ($payload['items'] as $item) {
            $product = Product::find($item['product_id']);
            if (! $product) {
                throw new \Exception("Sản phẩm ID {$item['product_id']} không tồn tại.");
            }
            if ((float) $item['quantity'] <= 0) {
                throw new \Exception("Số lượng phải > 0 cho sản phẩm {$product->name}.");
            }
            if ((float) $item['price'] < 0) {
                throw new \Exception("Giá phải >= 0 cho sản phẩm {$product->name}.");
            }

            $serialIds = $item['serial_ids'] ?? [];
            if ($product->isService() && ! empty($serialIds)) {
                throw new \Exception("Sản phẩm '{$product->name}' là dịch vụ, không quản lý Serial/IMEI.");
            }
            if ($product->tracksInventory() && $product->has_serial && ! empty($serialIds)) {
                if (count($serialIds) !== (int) $item['quantity']) {
                    throw new \Exception("Sản phẩm '{$product->name}' cần chọn đủ {$item['quantity']} serial, hiện có ".count($serialIds).'.');
                }
                if (count($serialIds) !== count(array_unique($serialIds))) {
                    throw new \Exception("Sản phẩm '{$product->name}' có serial trùng lặp.");
                }
                foreach ($serialIds as $sid) {
                    $serial = SerialImei::find($sid);
                    if (! $serial || (int) $serial->product_id !== (int) $product->id) {
                        throw new \Exception("Serial ID {$sid} không thuộc sản phẩm '{$product->name}'.");
                    }
                    // Allow if serial belongs to this invoice (keeping same serial)
                    if ((int) $serial->invoice_id === (int) $invoice->id && $serial->status === 'sold') {
                        continue;
                    }
                    $blocked = ['in_transit', 'used_for_repair', 'dismantled', 'defective'];
                    if (in_array($serial->status, $blocked)) {
                        throw new \Exception("Serial '{$serial->serial_number}' đang ở trạng thái {$serial->status}, không thể dùng.");
                    }
                    if ($serial->status === 'sold' && (int) $serial->invoice_id !== (int) $invoice->id) {
                        throw new \Exception("Serial '{$serial->serial_number}' đã bán cho hóa đơn khác.");
                    }
                }
            }
        }
    }

    private function recordInvoiceOwnerTransfer(
        int $customerId,
        float $oldDebt,
        Invoice $invoice,
        string $note,
        array $metadata,
    ): void {
        unset($invoice, $note, $metadata);
        $customer = Customer::query()->findOrFail($customerId);
        $customer->debt_amount = (float) $customer->debt_amount - $oldDebt;
        $customer->save();
    }

    private function updateWarrantyDatesIfSafe(Invoice $invoice, Carbon $newDate): void
    {
        $warranties = Warranty::where('invoice_code', $invoice->code)->get();
        foreach ($warranties as $warranty) {
            // Check if warranty has repair/claim attached
            $hasRepair = DB::table('tasks')
                ->where('warranty_id', $warranty->id)
                ->exists();
            if ($hasRepair) {
                continue; // Skip — warranty has repair attached
            }
            $months = (int) ($warranty->warranty_period ?? 0);
            $warranty->purchase_date = $newDate;
            $warranty->warranty_end_date = $months > 0 ? $newDate->copy()->addMonths($months) : $warranty->warranty_end_date;
            $warranty->save();
        }
    }

    private function handleWarrantyOnContentUpdate(Invoice $invoice): void
    {
        $warranties = Warranty::where('invoice_code', $invoice->code)->get();
        foreach ($warranties as $warranty) {
            $hasRepair = DB::table('tasks')
                ->where('warranty_id', $warranty->id)
                ->exists();
            if ($hasRepair) {
                // Policy: do not silently delete warranty with repairs
                continue;
            }
            $warranty->forceDelete();
        }
        // Regenerate warranties
        app(WarrantyGenerationService::class)->generateForInvoice($invoice->refresh()->load('items.product'));
    }

    private function logActivity(Invoice $invoice, array $changePlan, array $context, array $extra = []): void
    {
        $properties = array_merge([
            'change_plan' => $changePlan,
            'affected_tables' => $this->affectedTables($changePlan),
        ], $extra);

        if (! empty($context['time_lock_override_reason'])) {
            $properties['time_lock_override_reason'] = $context['time_lock_override_reason'];
        }
        if (! empty($context['transaction_date_change_reason'])) {
            $properties['transaction_date_change_reason'] = $context['transaction_date_change_reason'];
        }

        $action = ActivityLog::ACTION_INVOICE_UPDATE ?? 'invoice_update';

        if ($changePlan['date_changed']) {
            ActivityLog::log(
                'invoice_transaction_date_changed',
                "Đổi ngày hóa đơn {$invoice->code}",
                $invoice,
                $properties
            );
        }

        $lockRef = $invoice->lock_started_at ?? $invoice->created_at;
        $orderChangeTime = Setting::get('order_change_time', 24);
        $isOverdue = Carbon::parse($lockRef)->diffInHours(now()) > $orderChangeTime;
        if ($isOverdue && ! empty($context['time_lock_override_reason'])) {
            ActivityLog::log(
                'invoice_update_time_lock_override',
                "Sửa hóa đơn {$invoice->code} quá hạn (override)",
                $invoice,
                $properties
            );
        }

        ActivityLog::log(
            $action,
            "Cập nhật hóa đơn {$invoice->code}",
            $invoice,
            $properties
        );
    }

    private function affectedTables(array $plan): array
    {
        $tables = ['invoices'];
        if ($plan['requires_inventory_replay'] ?? false) {
            $tables = array_merge($tables, ['invoice_items', 'products', 'cash_flows', 'customer_debts', 'customers']);
            if ($plan['serial_changed']) {
                $tables[] = 'serial_imeis';
            }
        } elseif ($plan['commercial_changed'] ?? false) {
            $tables = array_merge($tables, ['invoice_items', 'cash_flows', 'customer_debts', 'customers']);
        }
        if ($plan['date_changed']) {
            $tables[] = 'cash_flows';
            $tables[] = 'warranties';
        }

        return array_unique($tables);
    }
}
