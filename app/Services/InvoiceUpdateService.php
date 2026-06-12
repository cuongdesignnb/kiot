<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\Setting;
use App\Models\Warranty;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STEP 24.3 — Invoice Update Engine with Impact Safety.
 *
 * Responsibilities:
 *   1. Build a change plan comparing old invoice vs new payload.
 *   2. Execute date-only updates (no stock/cost/debt mutation).
 *   3. Execute content updates (full reverse + re-apply within DB transaction).
 *   4. Enforce time lock, permission override, e-invoice block.
 */
class InvoiceUpdateService
{
    /**
     * Build change plan: detect what changed between current invoice and payload.
     */
    public function buildChangePlan(Invoice $invoice, array $payload): array
    {
        $invoice->loadMissing('items');

        $plan = [
            'date_changed'      => false,
            'header_changed'    => false,
            'financial_changed' => false,
            'items_changed'     => false,
            'serial_changed'    => false,
            'customer_changed'  => false,
        ];

        // 1. Date changed
        if (array_key_exists('transaction_date', $payload) && $payload['transaction_date'] !== null) {
            $oldTxDate = $invoice->transaction_date
                ? Carbon::parse($invoice->transaction_date)
                : Carbon::parse($invoice->created_at);
            $newTxDate = Carbon::parse($payload['transaction_date']);

            if (!$oldTxDate->copy()->seconds(0)->equalTo($newTxDate->copy()->seconds(0))) {
                $plan['date_changed'] = true;
            }
        }

        // 2. Header changed
        $headerFields = ['branch_id', 'note', 'sales_channel', 'payment_method', 'price_book_name'];
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

        // 3. Customer changed
        $oldCustId = $invoice->customer_id;
        $newCustId = $payload['customer_id'] ?? $oldCustId;
        if ((int) $oldCustId !== (int) $newCustId) {
            $plan['customer_changed'] = true;
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

        // 5. Items changed + serial changed
        $oldItems = $invoice->items->map(fn($i) => [
            'product_id' => (int) $i->product_id,
            'quantity'   => round((float) $i->quantity, 2),
            'price'      => round((float) $i->price, 2),
            'discount'   => round((float) ($i->discount ?? 0), 2),
            'note'       => (string) ($i->note ?? ''),
        ])->sortBy('product_id')->values()->toArray();

        $newItems = collect($payload['items'] ?? [])->map(fn($i) => [
            'product_id' => (int) $i['product_id'],
            'quantity'   => round((float) $i['quantity'], 2),
            'price'      => round((float) $i['price'], 2),
            'discount'   => round((float) ($i['discount'] ?? 0), 2),
            'note'       => (string) ($i['note'] ?? ''),
        ])->sortBy('product_id')->values()->toArray();

        if ($oldItems !== $newItems) {
            $plan['items_changed'] = true;
        }

        // Serial comparison
        $oldSerialIds = SerialImei::where('invoice_id', $invoice->id)
            ->where('status', 'sold')
            ->pluck('id')->sort()->values()->toArray();
        $newSerialIds = collect($payload['items'] ?? [])
            ->pluck('serial_ids')->flatten()->filter()->map(fn($v) => (int) $v)
            ->sort()->values()->toArray();
        if ($oldSerialIds !== $newSerialIds) {
            $plan['serial_changed'] = true;
        }

        // Derived
        $plan['only_date_changed'] = $plan['date_changed']
            && !$plan['header_changed']
            && !$plan['financial_changed']
            && !$plan['items_changed']
            && !$plan['serial_changed']
            && !$plan['customer_changed'];

        $plan['content_changed'] = $plan['items_changed']
            || $plan['serial_changed']
            || $plan['financial_changed']
            || $plan['customer_changed'];

        return $plan;
    }

    /**
     * Validate time lock, permissions, e-invoice block.
     * Returns null if OK, or error string.
     */
    public function validateLockAndPermissions(Invoice $invoice, array $payload, array $context): ?string
    {
        // E-invoice block — absolute, even with override
        if (Setting::get('block_edit_cancel_einvoice', false) && !empty($invoice->einvoice_code)) {
            return 'Không thể sửa hóa đơn đã xuất hóa đơn điện tử.';
        }

        $changePlan = $this->buildChangePlan($invoice, $payload);
        $orderChangeTime = Setting::get('order_change_time', 24);
        $lockRef = $invoice->lock_started_at ?? $invoice->created_at;
        $isOverdue = Carbon::parse($lockRef)->diffInHours(now()) > $orderChangeTime;
        $user = $context['user'] ?? auth()->user();

        if ($isOverdue) {
            $hasOverride = $user && $user->hasPermission('invoices.override_time_lock');
            if (!$hasOverride) {
                return "Đã quá thời gian cho phép chỉnh sửa ({$orderChangeTime} giờ). Cần quyền override.";
            }
            $reason = $context['time_lock_override_reason'] ?? null;
            if (!$reason || strlen(trim($reason)) < 5) {
                return 'Cần nhập lý do override (ít nhất 5 ký tự).';
            }
        }

        if ($changePlan['date_changed']) {
            $hasDatePerm = $user && $user->hasPermission('invoices.change_transaction_date');
            if (!$hasDatePerm) {
                return 'Cần quyền invoices.change_transaction_date để đổi ngày hóa đơn.';
            }
            $reason = $context['transaction_date_change_reason'] ?? null;
            if (!$reason || strlen(trim($reason)) < 5) {
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
        $lockError = $this->validateLockAndPermissions($invoice, $payload, $context);
        if ($lockError) {
            throw new \Exception($lockError);
        }

        $changePlan = $this->buildChangePlan($invoice, $payload);

        if ($changePlan['only_date_changed']) {
            return $this->applyDateOnlyUpdate($invoice, $payload, $changePlan, $context);
        }

        if ($changePlan['content_changed'] || $changePlan['header_changed']) {
            return $this->applyContentUpdate($invoice, $payload, $changePlan, $context);
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

            if (!Schema::hasColumn('invoices', 'transaction_date')) {
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
     * Content update: reverse old sale, apply new sale.
     */
    private function applyContentUpdate(Invoice $invoice, array $payload, array $changePlan, array $context): Invoice
    {
        return DB::transaction(function () use ($invoice, $payload, $changePlan, $context) {
            $invoice->load('items');

            // --- Pre-flight validations ---
            $this->preflightContentValidation($invoice, $payload, $context);

            // Capture old values
            $oldTotal = (float) $invoice->total;
            $oldPaid = (float) ($invoice->customer_paid ?? 0);
            $oldDebt = $oldTotal - $oldPaid;
            $oldCustomerId = $invoice->customer_id;

            // --- 1. Reverse old sale ---
            foreach ($invoice->items as $oldItem) {
                $product = Product::find($oldItem->product_id);
                if ($product) {
                    $costAtSale = (float) ($oldItem->cost_price ?? $product->cost_price ?? 0);
                    MovingAvgCostingService::applySaleReturn($product, (int) $oldItem->quantity, $costAtSale);
                    $product->refresh();
                    if ($product->has_serial) {
                        $product->recomputeFromSerials();
                    }
                }
            }

            // Restore old serials
            SerialImei::where('invoice_id', $invoice->id)
                ->where('status', 'sold')
                ->update([
                    'status'     => 'in_stock',
                    'sold_at'    => null,
                    'invoice_id' => null,
                ]);

            // --- 2. Reverse old finance ---
            if ($oldCustomerId) {
                $oldCustomer = Customer::find($oldCustomerId);
                if ($oldCustomer) {
                    $newCustomerId = $payload['customer_id'] ?? $oldCustomerId;
                    if ((int) $oldCustomerId !== (int) $newCustomerId) {
                        // Customer changed — full reverse old
                        if (abs($oldDebt) >= 0.01) {
                            app(CustomerDebtService::class)->recordInvoiceBalanceReversal(
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

            // --- 3. Update invoice header ---
            $newTxDate = isset($payload['transaction_date'])
                ? Carbon::parse($payload['transaction_date'])
                : ($invoice->transaction_date ?? $invoice->created_at);

            $updateData = [
                'customer_id'    => $payload['customer_id'] ?? $invoice->customer_id,
                'branch_id'      => $payload['branch_id'] ?? $invoice->branch_id,
                'subtotal'       => $payload['subtotal'],
                'discount'       => $payload['discount'] ?? 0,
                'total'          => $payload['total'],
                'customer_paid'  => $payload['customer_paid'] ?? 0,
                'note'           => $payload['note'] ?? null,
                'is_delivery'    => $payload['is_delivery'] ?? false,
                'delivery_partner' => $payload['delivery_partner'] ?? null,
                'delivery_fee'   => $payload['delivery_fee'] ?? 0,
                'payment_method' => $payload['payment_method'] ?? 'Tiền mặt',
                'price_book_name' => $payload['price_book_name'] ?? $invoice->price_book_name,
            ];
            if ($changePlan['date_changed'] && Schema::hasColumn('invoices', 'transaction_date')) {
                $updateData['transaction_date'] = $newTxDate;
            }
            $invoice->update($updateData);

            // --- 4. Delete old items, create new ---
            $invoice->items()->delete();

            $allowOversell = Setting::get('inventory_allow_oversell', true);

            foreach ($payload['items'] as $item) {
                $product = Product::lockForUpdate()->find($item['product_id']);
                $serialIds = $item['serial_ids'] ?? [];
                $snapshotCostPrice = (float) ($product->cost_price ?? 0);
                $serialStr = null;
                $soldSerials = collect();

                if ($product && $product->has_serial && !empty($serialIds)) {
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

                $invoice->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                    'price'      => $item['price'],
                    'cost_price' => $snapshotCostPrice,
                    'discount'   => $item['discount'] ?? 0,
                    'subtotal'   => ($item['price'] * $item['quantity']) - ($item['discount'] ?? 0),
                    'note'       => $item['note'] ?? null,
                    'serial'     => $serialStr,
                ]);

                if ($product) {
                    if (!$allowOversell && $product->stock_quantity < $item['quantity']) {
                        throw new \Exception("Sản phẩm [{$product->sku}] {$product->name} không đủ tồn kho (Còn: {$product->stock_quantity})");
                    }
                    MovingAvgCostingService::applySale($product, (int) $item['quantity']);
                    $product->refresh();
                    if ($product->has_serial) {
                        $product->recomputeFromSerials();
                    }
                }
            }

            // --- 5. Customer debt ---
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

            // --- 6. CashFlow ---
            CashFlow::where('reference_type', 'Invoice')
                ->where('reference_code', $invoice->code)
                ->delete();

            if ($newPaid > 0) {
                $customer = $newCustomerId ? Customer::find($newCustomerId) : null;
                CashFlow::create([
                    'code'           => 'PT' . date('YmdHis') . rand(10, 99),
                    'type'           => 'receipt',
                    'amount'         => $newPaid,
                    'time'           => $newTxDate,
                    'category'       => 'Thu tiền khách trả',
                    'target_type'    => 'Khách hàng',
                    'target_id'      => $customer?->id,
                    'target_name'    => $customer?->name ?? 'Khách lẻ',
                    'reference_type' => 'Invoice',
                    'reference_code' => $invoice->code,
                    'payment_method' => $payload['payment_method'] ?? 'cash',
                    'description'    => 'Thu tiền hóa đơn ' . $invoice->code . ($customer ? " - {$customer->name}" : ''),
                ]);
            }

            // --- 7. Warranty ---
            if ($changePlan['items_changed'] || $changePlan['serial_changed']) {
                $this->handleWarrantyOnContentUpdate($invoice);
            } elseif ($changePlan['date_changed']) {
                $this->updateWarrantyDatesIfSafe($invoice, $newTxDate);
            }

            // --- 8. ActivityLog ---
            $this->logActivity($invoice, $changePlan, $context, [
                'old_total' => $oldTotal,
                'new_total' => $newTotal,
                'old_customer_id' => $oldCustomerId,
                'new_customer_id' => $newCustomerId,
            ]);

            return $invoice->refresh();
        });
    }

    private function preflightContentValidation(Invoice $invoice, array $payload, array $context): void
    {
        if ($invoice->status === 'Đã hủy') {
            throw new \Exception('Không thể sửa hóa đơn đã hủy.');
        }

        foreach ($payload['items'] as $item) {
            $product = Product::find($item['product_id']);
            if (!$product) {
                throw new \Exception("Sản phẩm ID {$item['product_id']} không tồn tại.");
            }
            if ((float) $item['quantity'] <= 0) {
                throw new \Exception("Số lượng phải > 0 cho sản phẩm {$product->name}.");
            }
            if ((float) $item['price'] < 0) {
                throw new \Exception("Giá phải >= 0 cho sản phẩm {$product->name}.");
            }

            $serialIds = $item['serial_ids'] ?? [];
            if ($product->has_serial && !empty($serialIds)) {
                if (count($serialIds) !== (int) $item['quantity']) {
                    throw new \Exception("Sản phẩm '{$product->name}' cần chọn đủ {$item['quantity']} serial, hiện có " . count($serialIds) . '.');
                }
                if (count($serialIds) !== count(array_unique($serialIds))) {
                    throw new \Exception("Sản phẩm '{$product->name}' có serial trùng lặp.");
                }
                foreach ($serialIds as $sid) {
                    $serial = SerialImei::find($sid);
                    if (!$serial || (int) $serial->product_id !== (int) $product->id) {
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
            'change_plan'     => $changePlan,
            'affected_tables' => $this->affectedTables($changePlan),
        ], $extra);

        if (!empty($context['time_lock_override_reason'])) {
            $properties['time_lock_override_reason'] = $context['time_lock_override_reason'];
        }
        if (!empty($context['transaction_date_change_reason'])) {
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
        if ($isOverdue && !empty($context['time_lock_override_reason'])) {
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
        if ($plan['content_changed']) {
            $tables = array_merge($tables, ['invoice_items', 'products', 'cash_flows', 'customer_debts', 'customers']);
            if ($plan['serial_changed']) {
                $tables[] = 'serial_imeis';
            }
        }
        if ($plan['date_changed']) {
            $tables[] = 'cash_flows';
            $tables[] = 'warranties';
        }
        return array_unique($tables);
    }
}
