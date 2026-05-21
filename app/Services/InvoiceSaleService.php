<?php

namespace App\Services;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemSerial;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SerialImei;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * RR-02: Service dùng chung cho luồng bán hàng (Invoice + POS).
 *
 * Sửa bug POS serial FK violation bằng pattern chuẩn:
 *   1. Tạo InvoiceItem TRƯỚC (có id thật).
 *   2. Tạo InvoiceItemSerial với invoice_item_id thật (KHÔNG bao giờ là 0).
 *
 * Khác biệt giữa Invoice và POS được parameterize qua $context.
 *
 * Reference design: docs/audit/STEP-16.1B-RR02-INVOICE-SALE-SERVICE-DESIGN.md
 */
class InvoiceSaleService
{
    /**
     * Tạo Invoice + items + serials + stock + costing + movement + debt + cashflow.
     *
     * @param array $payload Normalized payload (xem design mục 4)
     * @param array $context Controller-specific overrides (xem design mục 5)
     * @return Invoice Invoice đã load('items.product')
     *
     * @throws \Exception Out of stock, serial invalid, before purchase date.
     */
    public function createSale(array $payload, array $context = []): Invoice
    {
        return DB::transaction(function () use ($payload, $context) {
            // ─── 1. Pre-flight validations ───
            // Step 23.1: enforce serial selection cho has_serial item (count===qty + in_stock)
            // chạy TRƯỚC khi tạo bất kỳ record nào để tránh orphan Invoice/InvoiceItem.
            $this->assertSerialSelectionComplete($payload['items']);

            if (!empty($context['validate_before_purchase_date'])) {
                $this->assertNotBeforePurchaseDate(
                    $payload['items'],
                    $context['transaction_date'] ?? now()
                );
            }
            if (!empty($context['validate_stock_setting'])) {
                $this->assertSufficientStockBySetting($payload['items']);
            }

            // ─── 2. Tạo Invoice ───
            $invoice = Invoice::create($this->buildInvoiceAttributes($payload, $context));

            // Step 24.3: set transaction_date + lock_started_at (defensive: only if migrated)
            if (\Illuminate\Support\Facades\Schema::hasColumn('invoices', 'transaction_date')) {
                $txDate = !empty($context['transaction_date'])
                    ? Carbon::parse($context['transaction_date'])
                    : now();
                $updateFields = [
                    'transaction_date' => $txDate,
                    'lock_started_at'  => now(),
                ];
                // Backward compat: keep created_at override for legacy reports
                if (!empty($context['transaction_date'])) {
                    $updateFields['created_at'] = $txDate;
                }
                $invoice->update($updateFields);
            } elseif (!empty($context['transaction_date'])) {
                // Column doesn't exist yet but user supplied a date — only update created_at
                $invoice->update(['created_at' => Carbon::parse($context['transaction_date'])]);
            }

            // ─── 3. Loop items ───
            $allowOversell = $context['allow_oversell'] ?? false;
            foreach ($payload['items'] as $item) {
                $this->processItem($invoice, $item, $allowOversell, $context);
            }

            // ─── 4. Customer debt + dual-role ───
            $this->updateCustomerDebt(
                $payload['customer_id'] ?? null,
                (float) $payload['total'],
                (float) ($payload['customer_paid'] ?? 0),
                $invoice
            );

            // ─── 5. CashFlow ───
            $this->createCashFlowIfPaid($invoice, $payload, $context);

            // ─── 6. STEP 23.7B: Auto-generate warranty records (in-transaction → rollback-safe) ───
            app(WarrantyGenerationService::class)->generateForInvoice($invoice);

            return $invoice->load('items.product');
        });
    }

    /**
     * Build attributes cho Invoice::create() từ payload + context.
     */
    private function buildInvoiceAttributes(array $payload, array $context): array
    {
        $code = $this->generateUniqueInvoiceCode($context);

        $attrs = [
            'code'             => $code,
            'customer_id'      => $payload['customer_id'] ?? null,
            'branch_id'        => $payload['branch_id'] ?? null,
            'status'           => $context['default_status'] ?? 'Hoàn thành',
            'subtotal'         => $payload['subtotal'],
            'discount'         => $payload['discount'] ?? 0,
            'total'            => $payload['total'],
            'customer_paid'    => $payload['customer_paid'] ?? 0,
            'note'             => $payload['note'] ?? null,
            'created_by_name'  => $context['created_by_name'] ?? auth()->user()?->name ?? 'Admin',
            'is_delivery'      => $context['is_delivery'] ?? false,
            'delivery_partner' => $context['delivery_partner'] ?? null,
            'delivery_fee'     => $context['delivery_fee'] ?? 0,
            'payment_method'   => $payload['payment_method'] ?? 'cash',
        ];

        // Optional fields chỉ set khi có giá trị (không ghi đè default schema)
        if (isset($context['sales_channel'])) {
            $attrs['sales_channel'] = $context['sales_channel'];
        }
        if (isset($context['price_book_name'])) {
            $attrs['price_book_name'] = $context['price_book_name'];
        }
        if (isset($context['seller_name'])) {
            $attrs['seller_name'] = $context['seller_name'];
        }
        if (isset($context['seller_id'])) {
            $attrs['created_by'] = $context['seller_id'];
        }
        if (isset($context['transaction_date'])) {
            $attrs['sale_time'] = Carbon::parse($context['transaction_date']);
        }

        return $attrs;
    }

    private function generateUniqueInvoiceCode(array $context): string
    {
        $prefix = $context['code_prefix'] ?? ('HD' . date('YmdHis'));

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $prefix . random_int(10, 99);

            if (!Invoice::where('code', $code)->exists()) {
                return $code;
            }
        }

        return $prefix . random_int(1000, 9999);
    }

    /**
     * Xử lý 1 item: lock product, validate, tạo InvoiceItem TRƯỚC,
     * sau đó tạo InvoiceItemSerial, update SerialImei, applySale, ghi StockMovement.
     */
    private function processItem(Invoice $invoice, array $item, bool $allowOversell, array $context): void
    {
        $product = Product::lockForUpdate()->find($item['product_id']);
        if (!$product) {
            return;
        }

        $serialIds = $item['serial_ids'] ?? [];

        // Validate stock / serial
        if ($product->has_serial && !empty($serialIds)) {
            $this->assertSerialsValid($product, $serialIds);
        } elseif (!$allowOversell && $product->stock_quantity < $item['quantity']) {
            throw new \Exception(
                "Sản phẩm [{$product->sku}] {$product->name} không đủ tồn kho "
                . "(Còn: {$product->stock_quantity}). Không cho phép tồn kho âm."
            );
        }

        // Snapshot BQ trước khi trừ tồn (dùng cho InvoiceItem.cost_price + StockMovement.unit_cost)
        $snapshotCostPrice = (float) ($product->cost_price ?? 0);

        // ─── Bước A: Tạo InvoiceItem TRƯỚC ───
        $serialStr = null;
        $soldSerials = collect();
        if ($product->has_serial && !empty($serialIds)) {
            $soldSerials = SerialImei::whereIn('id', $serialIds)
                ->where('product_id', $product->id)
                ->get();
            $serialStr = $soldSerials->pluck('serial_number')->implode(', ');
        }

        $invoiceItem = $invoice->items()->create([
            'product_id' => $item['product_id'],
            'quantity'   => $item['quantity'],
            'price'      => $item['price'],
            'cost_price' => $snapshotCostPrice,
            'discount'   => $item['discount'] ?? 0,
            'subtotal'   => ($item['price'] * $item['quantity']) - ($item['discount'] ?? 0),
            'note'       => $item['note'] ?? null,
            'serial'     => $serialStr,
        ]);

        // ─── Bước B: Tạo InvoiceItemSerial với invoice_item_id THẬT ───
        // (RR-02: KHÔNG BAO GIỜ tạo invoice_item_id=0 — sửa bug POS FK violation)
        foreach ($soldSerials as $serial) {
            InvoiceItemSerial::create([
                'invoice_item_id' => $invoiceItem->id,
                'serial_imei_id'  => $serial->id,
                'serial_number'   => $serial->serial_number,
                'cost_price'      => $snapshotCostPrice,
            ]);

            $serial->status          = 'sold';
            $serial->sold_at         = now();
            $serial->invoice_id      = $invoice->id;
            $serial->sold_cost_price = $snapshotCostPrice;
            $serial->save();
        }

        // ─── Bước C: Trừ tồn + costing ───
        MovingAvgCostingService::applySale($product, (int) $item['quantity']);
        $product->refresh();
        if ($product->has_serial) {
            $product->recomputeFromSerials();
        }

        // ─── Bước D: Ghi StockMovement ───
        StockMovementService::record(
            $product,
            StockMovementService::TYPE_OUT_INVOICE,
            (int) $item['quantity'],
            $snapshotCostPrice,
            $invoice,
            [
                'branch_id' => array_key_exists('stock_movement_branch_id', $context)
                    ? $context['stock_movement_branch_id']
                    : ($invoice->branch_id ?? null),
                'ref_code'  => $invoice->code,
                'moved_at'  => $invoice->created_at ?? now(),
                'note'      => 'Xuất bán hóa đơn ' . $invoice->code,
            ]
        );
    }

    /**
     * Update customer debt + total_spent. Auto-enable dual-role nếu cần.
     * RR-06: ghi ledger qua CustomerDebtService thay vì increment debt_amount trực tiếp.
     */
    private function updateCustomerDebt(?int $customerId, float $total, float $customerPaid, ?Invoice $invoice = null): void
    {
        if (!$customerId) {
            return;
        }

        $customer = Customer::find($customerId);
        if (!$customer) {
            return;
        }

        // Auto-enable dual-role: bán cho NCC làm họ thành cả khách hàng
        if ($customer->is_supplier && !$customer->is_customer) {
            $customer->is_customer = true;
            $customer->save();
        }

        $debtAmount = $total - $customerPaid;
        if ($debtAmount != 0) {
            // RR-06: ledger ghi qua service. Service tự update customers.debt_amount + tạo customer_debts row.
            app(CustomerDebtService::class)->recordSale(
                $customer->id,
                $debtAmount,
                $invoice,
                $invoice ? "Ghi nợ bán hàng hóa đơn {$invoice->code}" : null
            );
        }
        $customer->increment('total_spent', $total);
    }

    /**
     * Tạo CashFlow receipt nếu customer_paid > 0.
     */
    private function createCashFlowIfPaid(Invoice $invoice, array $payload, array $context): void
    {
        $customerPaid = (float) ($payload['customer_paid'] ?? 0);
        if ($customerPaid <= 0) {
            return;
        }

        $customer = !empty($payload['customer_id']) ? Customer::find($payload['customer_id']) : null;
        $customerName = $customer?->name ?? 'Khách lẻ';
        $extraDesc = $context['cashflow_description_extra'] ?? '';

        CashFlow::create([
            'code'           => 'PT' . date('YmdHis') . rand(10, 99),
            'type'           => 'receipt',
            'amount'         => $customerPaid,
            'time'           => now(),
            'category'       => 'Thu tiền khách trả',
            'target_type'    => 'Khách hàng',
            'target_id'      => $customer?->id,
            'target_name'    => $customerName,
            'reference_type' => 'Invoice',
            'reference_code' => $invoice->code,
            'payment_method' => $context['cashflow_payment_method']
                ?? ($payload['payment_method'] ?? 'cash'),
            'description'    => 'Thu tiền hóa đơn ' . $invoice->code
                . ($customer ? " - {$customer->name}" : '')
                . $extraDesc,
        ]);
    }

    /**
     * Throw nếu transaction date < earliest purchase date của bất kỳ product nào.
     */
    private function assertNotBeforePurchaseDate(array $items, $transactionDate): void
    {
        $txDate = $transactionDate instanceof Carbon
            ? $transactionDate
            : Carbon::parse($transactionDate);

        foreach ($items as $item) {
            $product = Product::find($item['product_id']);
            if ($product) {
                $earliest = $product->getEarliestImportDate();
                if ($earliest && $txDate->lt($earliest)) {
                    throw new \Exception(
                        "Không thể bán sản phẩm '{$product->name}' trước ngày nhập hàng đầu tiên ("
                        . $earliest->format('d/m/Y H:i') . ')'
                    );
                }
            }
        }
    }

    /**
     * Throw nếu Setting allow_transaction_when_out_of_stock=false và stock không đủ.
     */
    private function assertSufficientStockBySetting(array $items): void
    {
        if (Setting::get('allow_transaction_when_out_of_stock', false)) {
            return;
        }
        foreach ($items as $item) {
            $product = Product::find($item['product_id']);
            if ($product && $product->stock_quantity < $item['quantity']) {
                throw new \Exception(
                    "Sản phẩm '{$product->name}' không đủ tồn kho (còn {$product->stock_quantity})."
                );
            }
        }
    }

    /**
     * Step 23.1: với mọi item có Product.has_serial=true:
     *  - Bắt buộc serial_ids != [] và count(serial_ids) === quantity.
     *  - Mọi serial_id phải thuộc product và đang sellable (in_stock + repair OK).
     * Throw \Exception (DB::transaction rollback) nếu vi phạm → KHÔNG tạo Invoice rỗng.
     * Item có has_serial=false: bỏ qua, không bắt buộc serial_ids.
     */
    private function assertSerialSelectionComplete(array $items): void
    {
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty       = (int) ($item['quantity'] ?? 0);
            if ($productId <= 0 || $qty <= 0) {
                continue;
            }
            $product = Product::find($productId);
            if (!$product || !$product->has_serial) {
                continue;
            }
            $serialIds = array_values(array_filter(array_map('intval', (array) ($item['serial_ids'] ?? []))));
            if (count($serialIds) !== $qty) {
                throw new \Exception(
                    "Sản phẩm '{$product->name}' (Serial/IMEI) cần chọn đủ "
                    . "{$qty} mã, hiện đã chọn " . count($serialIds) . '.'
                );
            }
            $blocked = app(SerialAvailabilityService::class)
                ->findBlockedIds($serialIds, $product->id);
            if (!empty($blocked)) {
                throw new \Exception(
                    "Sản phẩm '{$product->name}': có serial không hợp lệ hoặc đã bán/đang sửa "
                    . '(' . implode(',', $blocked) . ').'
                );
            }
        }
    }

    /**
     * Throw nếu serial_ids không hợp lệ:
     *   - Có serial không thuộc product, hoặc
     *   - Có serial không in_stock.
     */
    private function assertSerialsValid(Product $product, array $serialIds): void
    {
        $availableCount = SerialImei::whereIn('id', $serialIds)
            ->where('product_id', $product->id)
            ->where('status', 'in_stock')
            ->count();

        if ($availableCount < count($serialIds)) {
            throw new \Exception(
                "Sản phẩm [{$product->sku}] {$product->name} - một số Serial/IMEI "
                . 'đã bán hoặc không tồn tại.'
            );
        }
    }
}
