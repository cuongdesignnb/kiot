<?php

namespace App\Services\Orders;

use App\Models\ActivityLog;
use App\Models\InvoiceItemSerial;
use App\Models\Order;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\Setting;
use App\Services\CustomerDebtService;
use App\Services\Integrations\PcWebsite\PcInventoryReservationService;
use App\Services\Integrations\PcWebsite\PcOrderImportService;
use App\Services\LockPeriodService;
use App\Services\MovingAvgCostingService;
use App\Services\SerialAvailabilityService;
use App\Services\StockMovementService;
use App\Support\Customers\CustomerGroupSnapshot;

/**
 * Converts an Order (đơn đặt hàng) into a sales Invoice: deposit handling
 * (order.amount_paid = prior deposit), serial checks, costing, stock
 * movement, customer debt, cash flow, reservation consumption and warranty
 * generation, wrapped in the partner debt coordinator for customers.
 *
 * Moved verbatim from OrderController::processOrder so the order screen /
 * POS and the Website PC "deliver" integration share one code path.
 */
class OrderInvoiceConversionService
{
    /**
     * @param  array  $validated  The validated processOrder payload (amount_paid, payment_method, ...);
     *                            also part of the debt coordinator payload hash.
     * @param  array{
     *     from_pos?: mixed,
     *     has_items?: bool,
     *     items?: mixed,
     *     has_delivery?: bool,
     *     delivery?: mixed,
     *     idempotency_key?: ?string,
     *     invoice_attributes?: array,
     * }  $options  Request-derived flags (POS conversion) and extra invoice attributes.
     *
     * @throws \Throwable Business rejections are thrown as exceptions; the caller decides the response.
     */
    public function convert(Order $order, array $validated, array $options = []): \App\Models\Invoice
    {
        $fromPos = $options['from_pos'] ?? null;
        $hasItems = (bool) ($options['has_items'] ?? false);
        $itemsInput = $options['items'] ?? null;
        $hasDelivery = (bool) ($options['has_delivery'] ?? false);
        $deliveryInput = $options['delivery'] ?? [];
        $idempotencyKey = $options['idempotency_key'] ?? null;
        $invoiceOverrides = (array) ($options['invoice_attributes'] ?? []);

        $processMutation = function () use ($order, $validated, $fromPos, $hasItems, $itemsInput, $hasDelivery, $deliveryInput, $invoiceOverrides): \App\Models\Invoice {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status === 'completed') {
                throw new \InvalidArgumentException('Đơn hàng đã được xử lý trước đó.');
            }
            if (in_array($order->status, ['cancelled', 'ended'], true)) {
                throw new \InvalidArgumentException('Đơn hàng đã bị hủy hoặc kết thúc.');
            }
            app(\App\Services\PartnerTransactionGuard::class)->assertCanTransact(
                $order->customer_id ? (int) $order->customer_id : null,
                'customer_id'
            );

            $order->load('items.product', 'customer');
            $customer = $order->customer;

            // Goods auto-created by a website order with no purchase
            // receipt / cost yet must not be invoiced at COGS = 0.
            app(\App\Services\CostPendingSaleGuard::class)->assertCanInvoiceProductIds(
                $order->items->pluck('product_id')
            );

            if ($order->external_source === PcOrderImportService::SOURCE) {
                app(LockPeriodService::class)->assertNotLocked(now(), 'pc_order_convert_to_invoice');
                app(PcInventoryReservationService::class)->assertProcessable($order);
            }

            $newPayment = $validated['amount_paid']; // Additional payment at conversion
            $priorDeposit = max(0.0, (float) ($order->amount_paid ?? 0));
            $totalPaid = $priorDeposit + $newPayment;
            $depositAppliedThisInvoice = min($priorDeposit, (float) $order->total_payment);
            $paymentMethod = $validated['payment_method'] ?? 'cash';

            // 1) Create Invoice from Order — link via order_id
            $invoiceData = [
                'code' => 'HD'.time().rand(10, 99),
                'order_id' => $order->id,
                'branch_id' => $order->branch_id,
                'subtotal' => $order->total_price,
                'discount' => $order->discount,
                'total' => $order->total_payment,
                'customer_paid' => $totalPaid,
                'order_deposit_applied_amount' => $depositAppliedThisInvoice,
                'customer_id' => $customer?->id,
                'created_by_name' => $order->created_by_name,
                'seller_name' => $order->assigned_to_name,
                'sales_channel' => $order->sales_channel ?? 'Bán trực tiếp',
                'price_book_name' => $order->price_book_name,
                'payment_method' => $paymentMethod,
                'note' => 'Từ đơn hàng '.$order->code,
                'status' => 'Hoàn thành',
            ];

            if ($fromPos && $hasDelivery) {
                $deliveryData = $deliveryInput;
                $invoiceData = array_merge($invoiceData, [
                    'is_delivery' => (bool) ($deliveryData['is_delivery'] ?? false),
                    'delivery_partner' => $deliveryData['delivery_partner'] ?? null,
                    'tracking_code' => $deliveryData['tracking_code'] ?? null,
                    'delivery_fee' => $deliveryData['delivery_fee'] ?? 0,
                    'cod_amount' => $deliveryData['cod_amount'] ?? 0,
                    'receiver_name' => $deliveryData['receiver_name'] ?? null,
                    'receiver_phone' => $deliveryData['receiver_phone'] ?? null,
                    'receiver_address' => $deliveryData['receiver_address'] ?? null,
                    'receiver_ward' => $deliveryData['receiver_ward'] ?? null,
                    'receiver_district' => $deliveryData['receiver_district'] ?? null,
                    'receiver_city' => $deliveryData['receiver_city'] ?? null,
                    'weight' => $deliveryData['weight'] ?? 0,
                    'delivery_note' => $deliveryData['delivery_note'] ?? null,
                ]);
            }

            if ($invoiceOverrides !== []) {
                $invoiceData = array_merge($invoiceData, $invoiceOverrides);
            }

            $invoiceData = CustomerGroupSnapshot::applyToAttributes($invoiceData, $invoiceData['customer_id'] ?? null, 'invoices');
            $invoice = \App\Models\Invoice::create($invoiceData);
            app(\App\Services\Debt\PartnerDebtMutationCoordinator::class)->checkpoint('document');

            // 2) Create Invoice Items + Deduct stock — RR-13: qua MovingAvgCostingService + StockMovement
            foreach ($order->items as $orderItem) {
                $product = $orderItem->product
                    ? Product::lockForUpdate()->find($orderItem->product->id)
                    : null;
                if (! $product) {
                    continue;
                }

                $qty = (int) $orderItem->qty;
                $allowOversell = Setting::get('inventory_allow_oversell', true);

                // RR-13: Serial product — phải có serial_ids rõ ràng. Không chọn đại.
                $serialIds = [];
                if ($product->has_serial) {
                    if ($fromPos && $hasItems) {
                        $posItem = collect($itemsInput)->firstWhere('product_id', $product->id);
                        if ($posItem && isset($posItem['serial_ids']) && is_array($posItem['serial_ids'])) {
                            $serialIds = array_values(array_filter($posItem['serial_ids'], fn ($v) => $v !== null && $v !== ''));
                        }
                    } else {
                        if (isset($orderItem->serial_ids) && is_array($orderItem->serial_ids)) {
                            $serialIds = $orderItem->serial_ids;
                        }
                    }

                    if (empty($serialIds)) {
                        throw new \Exception(
                            "Sản phẩm '{$product->name}' là hàng Serial/IMEI nhưng đơn hàng "
                            .'chưa lưu serial_ids. Vui lòng chọn Serial/IMEI trước khi chuyển hóa đơn.'
                        );
                    }
                    if (count($serialIds) !== $qty) {
                        throw new \Exception(
                            "Sản phẩm '{$product->name}': số lượng serial (".count($serialIds).") không khớp số lượng đặt ({$qty})."
                        );
                    }
                    // Step 22.2A: validate qua SerialAvailabilityService — schema-tolerant.
                    $availability = app(SerialAvailabilityService::class);
                    $blocked = $availability->findBlockedIds($serialIds, $product->id);
                    if (! empty($blocked)) {
                        throw new \Exception(
                            "Sản phẩm '{$product->name}': Serial/IMEI không khả dụng (id: ".implode(', ', $blocked).').'
                        );
                    }
                    if ($order->external_source === PcOrderImportService::SOURCE) {
                        $orderItem->update(['serial_ids' => $serialIds]);
                    }
                } elseif (! $allowOversell && $product->stock_quantity < $qty) {
                    throw new \Exception(
                        "Sản phẩm [{$product->sku}] {$product->name} không đủ tồn kho "
                        ."(Còn: {$product->stock_quantity})"
                    );
                }

                // Hàng thường snapshot BQ. Hàng serial snapshot theo từng
                // serial được chọn để COGS không bị lẫn với BQ của cả mã.
                $costSnapshot = (float) ($product->cost_price ?? 0);
                $soldSerials = collect();
                $serialCostSnapshot = null;
                if ($product->has_serial && ! empty($serialIds)) {
                    $soldSerials = SerialImei::whereIn('id', $serialIds)
                        ->where('product_id', $product->id)
                        ->lockForUpdate()
                        ->get();
                    if ($soldSerials->count() !== $qty) {
                        throw new \Exception("Sản phẩm '{$product->name}' cần chọn đúng số Serial/IMEI để xuất bán.");
                    }

                    if ($soldSerials->contains(fn (SerialImei $serial) => $serial->status !== 'in_stock')) {
                        throw new \Exception("Sản phẩm '{$product->name}' có Serial/IMEI vừa được thay đổi trạng thái, vui lòng chọn lại.");
                    }

                    // Chuyển đơn hàng cũng là một đường bán Serial/IMEI.
                    // Giữ cùng chốt thời gian nghiệp vụ như POS/hoá đơn để
                    // một serial đã trả không thể bị bán lại lùi ngày.
                    app(\App\Services\SerialBusinessTimeGuard::class)->assertNewSaleCanUseBusinessTime(
                        $soldSerials,
                        $invoice->transaction_date ?? $invoice->created_at ?? now(),
                    );

                    $serialCostSnapshot = \App\Services\SerialCostingService::snapshotForSale($soldSerials);
                    $costSnapshot = $serialCostSnapshot['unit_cost'];
                }

                // RR-13: Tạo InvoiceItem TRƯỚC (pattern đúng — giống RR-02 InvoiceSaleService)
                // The package picked at the POS wins over the one saved on the order.
                $posLine = $fromPos && is_array($itemsInput)
                    ? collect($itemsInput)->firstWhere('product_id', $product->id)
                    : null;
                $warrantyPolicy = app(\App\Services\WarrantyPolicyChoice::class)->nameFor(
                    $product,
                    is_array($posLine) && ! empty($posLine['warranty_policy']) ? $posLine['warranty_policy'] : $orderItem->warranty_policy,
                );

                $invoiceItem = $invoice->items()->create([
                    'product_id' => $orderItem->product_id,
                    'quantity' => $qty,
                    'price' => $orderItem->price,
                    'cost_price' => $costSnapshot,
                    'warranty_policy' => $warrantyPolicy,
                ]);

                // RR-13: Với hàng serial, tạo InvoiceItemSerial + đánh dấu serial sold
                if ($product->has_serial && $soldSerials->isNotEmpty()) {
                    foreach ($soldSerials as $serial) {
                        $serialCost = $serialCostSnapshot['serial_costs'][(int) $serial->id]
                            ?? round((float) $serial->cost_price, 0);

                        InvoiceItemSerial::create([
                            'invoice_item_id' => $invoiceItem->id,
                            'serial_imei_id' => $serial->id,
                            'serial_number' => $serial->serial_number,
                            'cost_price' => $serialCost,
                        ]);
                        $serial->status = 'sold';
                        $serial->sold_at = now();
                        $serial->invoice_id = $invoice->id;
                        $serial->sold_cost_price = $serialCost;
                        $serial->save();
                    }
                }

                // RR-13: Trừ tồn + cập nhật BQ qua service (thay raw $product->stock_quantity -= $qty)
                MovingAvgCostingService::applySale($product, $qty);
                $product->refresh();
                if ($product->has_serial) {
                    $product->recomputeFromSerials();
                }

                // RR-13: Ghi StockMovement out_invoice
                StockMovementService::record(
                    $product->fresh(),
                    StockMovementService::TYPE_OUT_INVOICE,
                    $qty,
                    $costSnapshot,
                    $invoice,
                    [
                        'branch_id' => $invoice->branch_id ?? null,
                        'ref_code' => $invoice->code,
                        'moved_at' => $invoice->created_at ?? now(),
                        'note' => "Xuất bán từ đơn hàng {$order->code} sang hóa đơn {$invoice->code}",
                    ]
                );
            }

            // 3) Customer debt tracking — debt = total - totalPaid
            // RR-06: ghi ledger qua CustomerDebtService thay vì increment trực tiếp.
            $debtAmount = $order->total_payment - $totalPaid;
            if ($customer) {
                // Deposit receipts already booked against this customer
                // (CashFlow reference_type=Order, e.g. a Website PC payment)
                // reduced the stored debt when they were recorded; do not
                // subtract that part of the deposit a second time. Orders
                // whose deposit only lives in orders.amount_paid add 0 here.
                $debtAmount += min($priorDeposit, $this->bookedDepositReceipts($order, (int) $customer->id));
                if (abs($debtAmount) >= 0.01) {
                    app(CustomerDebtService::class)->recordInvoiceBalanceEffect(
                        $customer->id,
                        (float) $debtAmount,
                        $invoice,
                        "Ghi nợ khi chuyển đơn hàng {$order->code} thành hóa đơn {$invoice->code}",
                        ['order_id' => $order->id]
                    );
                }
                $customer->increment('total_spent', $order->total_payment);
            }

            // 4) CashFlow for the NEW payment at conversion time (deposit was already recorded)
            if ($newPayment > 0) {
                \App\Models\CashFlow::create([
                    'code' => 'PT'.time().rand(10, 99),
                    'type' => 'receipt',
                    'amount' => $newPayment,
                    'time' => now(),
                    'category' => 'Thu tiền khách trả',
                    'target_type' => 'Khách hàng',
                    'target_id' => $customer?->id,
                    'target_name' => $customer?->name ?? 'Khách lẻ',
                    'reference_type' => 'Invoice',
                    'reference_code' => $invoice->code,
                    'payment_method' => $paymentMethod,
                    'description' => 'Xử lý đơn '.$order->code.' → HD '.$invoice->code,
                ]);
            }
            app(\App\Services\Debt\PartnerDebtMutationCoordinator::class)->checkpoint('evidence');

            // Note: Không gọi DebtOffsetService - unified ledger view tự xử lý bù trừ

            // 6) Update Order status
            $orderUpdateData = [
                'status' => 'completed',
            ];

            if ($fromPos && $hasDelivery) {
                $deliveryData = $deliveryInput;
                $orderUpdateData = array_merge($orderUpdateData, [
                    'is_delivery' => (bool) ($deliveryData['is_delivery'] ?? false),
                    'delivery_partner' => $deliveryData['delivery_partner'] ?? null,
                    'tracking_code' => $deliveryData['tracking_code'] ?? null,
                    'delivery_fee' => $deliveryData['delivery_fee'] ?? 0,
                    'cod_amount' => $deliveryData['cod_amount'] ?? 0,
                    'receiver_name' => $deliveryData['receiver_name'] ?? null,
                    'receiver_phone' => $deliveryData['receiver_phone'] ?? null,
                    'receiver_address' => $deliveryData['receiver_address'] ?? null,
                    'receiver_ward' => $deliveryData['receiver_ward'] ?? null,
                    'receiver_district' => $deliveryData['receiver_district'] ?? null,
                    'receiver_city' => $deliveryData['receiver_city'] ?? null,
                    'weight' => $deliveryData['weight'] ?? 0,
                    'delivery_note' => $deliveryData['delivery_note'] ?? null,
                ]);
            }

            $order->update($orderUpdateData);
            if ($order->external_source === PcOrderImportService::SOURCE) {
                app(PcInventoryReservationService::class)->consumeForOrder($order);
            }
            app(\App\Services\Debt\PartnerDebtMutationCoordinator::class)->checkpoint('projection');

            // 7) STEP 23.7B: Auto-generate warranty records (in-transaction → rollback-safe)
            app(\App\Services\WarrantyGenerationService::class)->generateForInvoice($invoice);

            ActivityLog::log('order_convert', "Chuyển đơn {$order->code} → hóa đơn {$invoice->code}", $order);

            // Batch 10 đợt 4 — item 32: selling below cost is allowed but recorded.
            try {
                app(\App\Services\SalesControls\BelowCostSaleService::class)
                    ->recordForInvoice($invoice->fresh(['items.product']), 'order_convert');
            } catch (\Throwable $e) {
                report($e);
            }

            return $invoice;
        };

        $customerId = (int) ($order->customer_id ?? 0);
        if ($customerId > 0) {
            return app(\App\Services\Debt\PartnerDebtMutationCoordinator::class)->execute(
                $customerId,
                'order_convert_to_invoice',
                hash('sha256', json_encode([
                    'order_id' => (int) $order->id,
                    'payload' => $validated,
                ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)),
                function (\App\Models\Customer $lockedCustomer) use ($processMutation): \App\Models\Invoice {
                    if (! (bool) $lockedCustomer->is_customer) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'customer_id' => 'Doi tac khong co vai tro khach hang da duoc luu.',
                        ]);
                    }

                    return \Illuminate\Support\Facades\DB::transaction($processMutation);
                },
                $idempotencyKey,
            );
        }

        return \Illuminate\Support\Facades\DB::transaction($processMutation);
    }

    /**
     * Active deposit receipts of the order that are linked to the customer
     * and therefore already part of the customer's stored debt.
     */
    private function bookedDepositReceipts(Order $order, int $customerId): float
    {
        return (float) \App\Models\CashFlow::query()
            ->active()
            ->where('type', 'receipt')
            ->where('reference_type', 'Order')
            ->where('reference_code', $order->code)
            ->where('target_id', $customerId)
            ->sum('amount');
    }
}
