# STEP-16.1B — RR-02 InvoiceSaleService Design

> **Bước:** 16.1B — Thiết kế `InvoiceSaleService` cho RR-02 trên tài liệu
> **Ngày:** 02/05/2026
> **Phạm vi:** Chỉ tạo tài liệu thiết kế. **Không sửa code, không tạo service, không refactor.**

---

## 1. Mục tiêu

Thiết kế service dùng chung cho cả `InvoiceController@store` và `PosController@checkout` để:
1. Loại bỏ duplicate logic (~150 dòng giống nhau).
2. Sửa bug POS FK violation (`invoice_item_id=0`) bằng pattern đúng (tạo `InvoiceItem` trước, `InvoiceItemSerial` sau).
3. Đảm bảo cả hai luồng có behavior tương đương về tồn/giá vốn/movement/serial/CashFlow/debt.
4. Giữ characterization tests RR-02 + toàn bộ P0/P1 regression PASS sau refactor.

---

## 2. Bug phát hiện từ characterization test (Step 16.1A)

### Bug nghiêm trọng — POS serial FK violation

`PosController@checkout` dòng 156-161 tạo `InvoiceItemSerial` **trước** khi có `InvoiceItem.id`:
```php
\App\Models\InvoiceItemSerial::create([
    'invoice_item_id' => 0, // sẽ update sau
    'serial_imei_id' => $serial->id,
    ...
]);
```

Sau đó (dòng 184-187) update `invoice_item_id` về id thật:
```php
\App\Models\InvoiceItemSerial::where('invoice_item_id', 0)
    ->whereIn('serial_imei_id', ...)
    ->update(['invoice_item_id' => $invoiceItem->id]);
```

**Vi phạm FK constraint** `invoice_item_serials_invoice_item_id_foreign` (REFERENCES invoice_items.id ON DELETE CASCADE):
```
SQLSTATE[23000]: Integrity constraint violation: 1452
SQL: insert into invoice_item_serials (invoice_item_id, ...) values (0, ...)
```

→ POS hoàn toàn **không bán được hàng serial** trong DB có FK strict mode.

### Test fail chứng minh
- `test_pos_sale_serial_creates_valid_invoice_item_serial_without_zero_invoice_item_id` — kỳ vọng HTTP 200, thực tế HTTP 500 (FK violation).

### Hệ quả
- Bug nghiêm trọng severity HIGH — POS production với hàng serial broken.
- Sửa tự nhiên bằng cách áp dụng pattern Invoice (tạo InvoiceItem trước → InvoiceItemSerial với id thật) trong service mới.

---

## 3. Service interface đề xuất

### Class

```php
namespace App\Services;

class InvoiceSaleService
{
    public function __construct(
        private MovingAvgCostingService $costing,    // có thể static method nên ko cần inject
        // hoặc gọi static trực tiếp như controller hiện tại
    ) {}
}
```

(Chọn dùng static method giống RR-04/RR-09 để giữ pattern hiện hành — không inject DI vì các service hiện đều là static.)

### Method chính

```php
public function createSale(array $payload, array $context = []): Invoice
```

### Input

- `$payload` (array) — đã được controller validate và normalize:
  - Field danh sách ở mục 4 (Normalized payload).
- `$context` (array) — controller-specific overrides:
  - Field danh sách ở mục 5 (Context).

### Output

- `Invoice` model đã `load('items.product')` để controller render response.

### Exceptions

- `\Exception` — out of stock (nếu setting/cờ bật).
- `\Exception` — serial không hợp lệ (không thuộc product hoặc không in_stock).
- `\Exception` — bán trước ngày nhập (nếu context bật).
- `\Illuminate\Database\QueryException` — DB error (FK, unique).

Service **KHÔNG bắt** exception — controller sẽ bắt và mapping sang HTTP response (redirect with error / JSON 500).

### Transaction

- Toàn bộ flow trong `DB::transaction(function () { ... })` bên trong service. Controller không cần wrap thêm.

---

## 4. Normalized payload

| Field | InvoiceController source | PosController source | Service field | Required | Type |
|---|---|---|---|---|---|
| customer_id | `$validated['customer_id']` | `$validated['customer_id']` | `customer_id` | nullable | int\|null |
| branch_id | `$validated['branch_id']` | (null) | `branch_id` | nullable | int\|null |
| subtotal | `$validated['subtotal']` | `$validated['subtotal']` | `subtotal` | required | numeric |
| discount | `$validated['discount']` | `$validated['discount']` | `discount` | nullable | numeric (default 0) |
| total | `$validated['total']` | `$validated['total']` | `total` | required | numeric |
| customer_paid | `$validated['customer_paid']` | `$validated['customer_paid']` | `customer_paid` | nullable | numeric (default 0) |
| payment_method | `$validated['payment_method']` (free) | `$validated['payment_method']` (in:cash,transfer) | `payment_method` | nullable | string |
| note | `$validated['note']` | composed (CK info + customer note) | `note` | nullable | string |
| items[].product_id | required | required | `product_id` | required | int |
| items[].quantity | required | required | `quantity` | required | int |
| items[].price | required | required | `price` | required | numeric |
| items[].discount | nullable | nullable | `discount` | nullable | numeric |
| items[].serial_ids | array of ints | array | `serial_ids` | nullable | array |
| items[].note | nullable | (none) | `note` | nullable | string |

### Field chỉ Invoice có
- `is_delivery`, `delivery_partner`, `delivery_fee` (logistic)
- `price_book_id`, `price_book_name`
- `order_date` (action date)
- `validate_before_purchase_date` (luôn bật)
- `Setting::allow_transaction_when_out_of_stock` check

### Field chỉ POS có
- `employee_id` (seller)
- `sale_time` (sale timestamp)
- `bank_account_info` (CK info → composed vào note)
- `sales_channel` luôn `'Bán trực tiếp'`

→ Các field này được map vào `$context` (mục 5) hoặc `payload['note']` thay vì payload chính.

---

## 5. Context

| Context field | Ý nghĩa | Invoice value | POS value |
|---|---|---|---|
| `source` | Identify source for logging | `'invoice'` | `'pos'` |
| `code_prefix` | Invoice code prefix | `'HD' . date('YmdHis')` | `'HD' . time()` |
| `default_status` | Status set vào Invoice | `'Hoàn thành'` | `'Hoàn thành'` (chuẩn hóa, fix bug POS không set) |
| `sales_channel` | Invoice.sales_channel | nullable | `'Bán trực tiếp'` |
| `price_book_name` | Invoice.price_book_name | resolve từ price_book_id | (không set, default null) |
| `seller_name` | Invoice.seller_name (POS) | (không set) | `$employee?->name` |
| `seller_id` (created_by) | Invoice.created_by | (không set) | `$employee?->id` |
| `created_by_name` | Invoice.created_by_name | `auth()->user()?->name ?? 'Admin'` | (không set, có seller_name) |
| `is_delivery` | Invoice.is_delivery | từ payload | false |
| `delivery_partner` | Invoice.delivery_partner | từ payload | null |
| `delivery_fee` | Invoice.delivery_fee | từ payload | 0 |
| `transaction_date` | Invoice.created_at override | `Carbon::parse(order_date)` nếu có | `Carbon::parse(sale_time)` nếu có |
| `validate_before_purchase_date` | Bật check "không bán trước ngày nhập" | true | false (giữ behavior POS) |
| `allow_oversell` | Bật bán âm tồn | từ Setting `inventory_allow_oversell` (default false) | từ Setting (default true ở POS code) |
| `validate_stock_setting` | Bật check stock theo Setting `allow_transaction_when_out_of_stock` | true | false (POS bỏ qua) |
| `cashflow_payment_method` | CashFlow.payment_method | `payment_method ?? 'cash'` | `payment_method ?? 'cash'` |
| `cashflow_description_extra` | Extra info trong CashFlow.description | "" | `' - CK: ' + bank_account_info` nếu transfer |
| `stock_movement_branch_id` | StockMovement.branch_id | `$invoice->branch_id` | null (POS legacy) |

→ Tất cả khác biệt được parameterize qua context, không hard-code trong service.

---

## 6. Business flow

```text
public function createSale(array $payload, array $context = []): Invoice
{
    return DB::transaction(function () use ($payload, $context) {

        // ═══════ 6.1. Pre-flight validations (theo context) ═══════
        if ($context['validate_before_purchase_date'] ?? false) {
            $this->assertNotBeforePurchaseDate(
                $payload['items'],
                $context['transaction_date'] ?? now()
            );
        }
        if ($context['validate_stock_setting'] ?? false) {
            $this->assertSufficientStockBySetting($payload['items']);
        }

        // ═══════ 6.2. Tạo Invoice ═══════
        $invoice = Invoice::create([
            'code'            => $context['code_prefix'] . rand(10, 99),
            'customer_id'     => $payload['customer_id'] ?? null,
            'branch_id'       => $payload['branch_id'] ?? null,
            'status'          => $context['default_status'] ?? 'Hoàn thành',
            'subtotal'        => $payload['subtotal'],
            'discount'        => $payload['discount'] ?? 0,
            'total'           => $payload['total'],
            'customer_paid'   => $payload['customer_paid'] ?? 0,
            'note'            => $payload['note'] ?? null,
            'created_by'      => $context['seller_id'] ?? null,
            'created_by_name' => $context['created_by_name'] ?? auth()->user()?->name ?? 'Admin',
            'seller_name'     => $context['seller_name'] ?? null,
            'sales_channel'   => $context['sales_channel'] ?? null,
            'is_delivery'     => $context['is_delivery'] ?? false,
            'delivery_partner'=> $context['delivery_partner'] ?? null,
            'delivery_fee'    => $context['delivery_fee'] ?? 0,
            'payment_method'  => $payload['payment_method'] ?? 'cash',
            'price_book_name' => $context['price_book_name'] ?? null,
        ]);

        if (!empty($context['transaction_date'])) {
            $invoice->update(['created_at' => Carbon::parse($context['transaction_date'])]);
        }

        // ═══════ 6.3. Loop items ═══════
        foreach ($payload['items'] as $item) {
            $product = Product::lockForUpdate()->find($item['product_id']);
            if (!$product) continue;

            $serialIds = $item['serial_ids'] ?? [];
            $allowOversell = $context['allow_oversell'] ?? false;

            // Validate stock/serial
            if ($product->has_serial && !empty($serialIds)) {
                $this->assertSerialsValid($product, $serialIds);
            } elseif (!$allowOversell && $product->stock_quantity < $item['quantity']) {
                throw new \Exception("Sản phẩm [{$product->sku}] {$product->name} không đủ tồn kho (Còn: {$product->stock_quantity}).");
            }

            // BQ snapshot trước trừ tồn
            $snapshotCostPrice = (float) ($product->cost_price ?? 0);

            // ─── Bước 1: tạo InvoiceItem TRƯỚC (sửa bug POS FK violation) ───
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

            // ─── Bước 2: tạo InvoiceItemSerial với invoice_item_id THẬT ───
            foreach ($soldSerials as $serial) {
                InvoiceItemSerial::create([
                    'invoice_item_id' => $invoiceItem->id,  // ← KHÔNG BAO GIỜ là 0
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

            // ─── Bước 3: Trừ tồn + costing ───
            MovingAvgCostingService::applySale($product, (int) $item['quantity']);
            $product->refresh();
            if ($product->has_serial) {
                $product->recomputeFromSerials();
            }

            // ─── Bước 4: Ghi StockMovement ───
            StockMovementService::record(
                $product,
                StockMovementService::TYPE_OUT_INVOICE,
                (int) $item['quantity'],
                $snapshotCostPrice,
                $invoice,
                [
                    'branch_id' => $context['stock_movement_branch_id'] ?? $invoice->branch_id ?? null,
                    'ref_code'  => $invoice->code,
                    'moved_at'  => $invoice->created_at ?? now(),
                    'note'      => "Xuất bán hóa đơn {$invoice->code}",
                ]
            );
        }

        // ═══════ 6.4. Customer debt + dual-role ═══════
        $customer = !empty($payload['customer_id'])
            ? Customer::find($payload['customer_id'])
            : null;
        $debtAmount = $payload['total'] - ($payload['customer_paid'] ?? 0);

        if ($customer) {
            if ($customer->is_supplier && !$customer->is_customer) {
                $customer->is_customer = true;
                $customer->save();
            }
            if ($debtAmount != 0) {
                $customer->increment('debt_amount', $debtAmount);
            }
            $customer->increment('total_spent', $payload['total']);
        }

        // ═══════ 6.5. CashFlow ═══════
        $customerPaid = $payload['customer_paid'] ?? 0;
        if ($customerPaid > 0) {
            $extraDesc = $context['cashflow_description_extra'] ?? '';
            CashFlow::create([
                'code'            => 'PT' . date('YmdHis') . rand(10, 99),
                'type'            => 'receipt',
                'amount'          => $customerPaid,
                'time'            => now(),
                'category'        => 'Thu tiền khách trả',
                'target_type'     => 'Khách hàng',
                'target_id'       => $customer?->id,
                'target_name'     => $customer?->name ?? 'Khách lẻ',
                'reference_type'  => 'Invoice',
                'reference_code'  => $invoice->code,
                'payment_method'  => $context['cashflow_payment_method'] ?? ($payload['payment_method'] ?? 'cash'),
                'description'     => "Thu tiền hóa đơn {$invoice->code}"
                    . ($customer ? " - {$customer->name}" : '')
                    . $extraDesc,
            ]);
        }

        return $invoice->load('items.product');
    });
}
```

---

## 7. Serial/IMEI handling

### Quy ước cứng

1. **Tạo `InvoiceItem` TRƯỚC**, có `id` thật rồi mới tạo `InvoiceItemSerial`.
2. **Không bao giờ tạo `InvoiceItemSerial` với `invoice_item_id=0`**.
3. **Không update `invoice_item_id` sau khi insert** (pattern POS hiện tại bị xóa).
4. **Validate serial:**
   - `count(serial_ids) === quantity` (nếu hàng `has_serial`).
   - Tất cả serial thuộc product (`product_id` match).
   - Tất cả serial `status === 'in_stock'` trước khi đánh dấu sold.
5. **Update SerialImei:**
   - `status = 'sold'`
   - `sold_at = now()`
   - `invoice_id = $invoice->id`
   - `sold_cost_price = $snapshotCostPrice` (BQ tại thời điểm bán)
6. **InvoiceItemSerial fields:**
   - `invoice_item_id = $invoiceItem->id` (id thật)
   - `serial_imei_id = $serial->id`
   - `serial_number = $serial->serial_number`
   - `cost_price = $snapshotCostPrice`

### Test bắt buộc PASS sau refactor
- `test_pos_sale_serial_creates_valid_invoice_item_serial_without_zero_invoice_item_id` — fail trước refactor → PASS sau refactor (xác nhận bug POS đã được sửa).

---

## 8. Stock / Costing / Movement

### MovingAvgCostingService::applySale
- Gọi **sau** khi tạo InvoiceItem + InvoiceItemSerial + update SerialImei.
- Truyền `(int) $item['quantity']` (numbers from payload, không phải qty từ serial collection).
- Service sẽ giảm `stock_quantity` + `inventory_total_cost` theo BQ hiện tại; giữ `cost_price` BQ.

### Product::recomputeFromSerials
- Gọi sau `applySale` nếu `has_serial=true`.
- Đảm bảo `stock_quantity` khớp số serial in_stock (audit count).

### StockMovementService::record
- `type = TYPE_OUT_INVOICE` ('out_invoice').
- `ref = $invoice` (Invoice model).
- `qty = $item['quantity']`.
- `unit_cost = $snapshotCostPrice` (BQ tại thời điểm bán).
- `branch_id`:
  - Invoice context: `$context['stock_movement_branch_id'] ?? $invoice->branch_id`.
  - POS context: `$context['stock_movement_branch_id'] ?? null` (giữ behavior POS hiện tại).
- `ref_code = $invoice->code`.
- `moved_at = $invoice->created_at ?? now()`.
- `note = "Xuất bán hóa đơn {$invoice->code}"`.

---

## 9. CashFlow / Customer debt

### CashFlow
- Tạo **chỉ khi `customer_paid > 0`** (giữ behavior cả Invoice và POS).
- `type = 'receipt'`.
- `amount = customer_paid`.
- `category = 'Thu tiền khách trả'`.
- `target_type = 'Khách hàng'`, `target_id = $customer?->id`, `target_name = $customer?->name ?? 'Khách lẻ'`.
- `reference_type = 'Invoice'`, `reference_code = $invoice->code`.
- `payment_method = $context['cashflow_payment_method'] ?? $payload['payment_method'] ?? 'cash'`.
- `description = "Thu tiền hóa đơn {code} - {customer_name}{extraDesc}"`.

### Customer debt
- `$debtAmount = $payload['total'] - ($payload['customer_paid'] ?? 0)`.
- Nếu `$customer` không null:
  - Auto-enable dual-role: nếu `is_supplier && !is_customer` → `is_customer = true; save()`.
  - Nếu `$debtAmount != 0` → `$customer->increment('debt_amount', $debtAmount)`.
  - Luôn `$customer->increment('total_spent', $payload['total'])`.

---

## 10. Khác biệt giữ ở Controller

### InvoiceController@store giữ
- HTTP validate request (rules cũ).
- Resolve `price_book_name` từ `price_book_id`.
- Resolve `transaction_date` từ `order_date`.
- Build context với:
  - `code_prefix = 'HD' . date('YmdHis')`
  - `validate_before_purchase_date = true`
  - `validate_stock_setting = true` (theo Setting)
  - `is_delivery, delivery_partner, delivery_fee` từ payload
  - `price_book_name` resolved
- Gọi `service->createSale(...)`.
- Catch exception → `back()->withErrors([...])->withInput()`.
- Success → `redirect()->route('invoices.index')->with('success', ...)`.

### PosController@checkout giữ
- HTTP validate request (POS rules: payment_method strict, customer_paid required).
- Resolve `employee` từ `employee_id`.
- Compose `note` từ `bank_account_info` + transfer info.
- Build context với:
  - `code_prefix = 'HD' . time()`
  - `default_status = 'Hoàn thành'` (CHUẨN HÓA — POS hiện không set)
  - `sales_channel = 'Bán trực tiếp'`
  - `seller_name = $employee?->name`
  - `seller_id = $employee?->id`
  - `validate_before_purchase_date = false`
  - `validate_stock_setting = false`
  - `cashflow_description_extra = ' - CK: ' . $bank_account_info` nếu transfer
- Gọi `service->createSale(...)`.
- Catch exception → `Log::error + return JSON 500`.
- Success → return JSON `{success, invoice_code, message}`.

### KHÔNG đưa vào service
- Response format (redirect/JSON).
- Validation rules HTTP cụ thể.
- UI flash message strings.
- POS-specific Log entries.
- Permission/middleware.

---

## 11. Migration / Schema

✅ **Không cần migration mới.**

Bug POS là thứ tự tạo record (logic), không phải thiếu schema. FK constraint `invoice_item_serials_invoice_item_id_foreign` đã tồn tại đúng — service mới chỉ cần tạo records theo đúng thứ tự để FK pass.

Backlog ghi nhận (không xử lý ở Bước 16.1B-E):
- `Invoice` model có cột `seller_name`, `created_by`, `sale_time` (đã được dùng trong code) — verify tồn tại trong migration trước khi refactor.

---

## 12. Kế hoạch refactor từng bước

### Step 16.1C — Tạo service + chuyển POS sang dùng service

**Lý do POS trước:** POS đang **broken** với hàng serial. Sửa trước có giá trị nghiệp vụ ngay.

Việc làm:
1. Tạo `app/Services/InvoiceSaleService.php` theo design ở mục 6.
2. Sửa `PosController@checkout`:
   - Build payload normalized + context (theo mục 10).
   - Gọi `app(InvoiceSaleService::class)->createSale($payload, $context)`.
   - Xóa logic inline cũ (tạo Invoice, items, serials, stock, movement, cashflow, debt).
   - Giữ validation rules + response JSON.
3. Chạy:
   - `--filter=RR02InvoicePosCharacterizationTest` → kỳ vọng **5 PASS** (TC-P02 chuyển từ FAIL → PASS).
   - `--filter=CancelInvoiceTest` → 10 PASS (Invoice path không đụng).
   - Toàn bộ P0+P1 regression → giữ 73 PASS.

**Tín hiệu thành công:** TC-P02 PASS (bug POS serial đã sửa).

**Rollback nếu fail:** revert PosController + xóa service. Invoice path không bị ảnh hưởng.

### Step 16.1D — Chuyển InvoiceController sang dùng service

Việc làm:
1. Sửa `InvoiceController@store`:
   - Build payload normalized + context (theo mục 10).
   - Gọi `service->createSale(...)`.
   - Xóa logic inline cũ.
   - Giữ validation, resolve price_book, redirect response.
2. Chạy:
   - `--filter=RR02InvoicePosCharacterizationTest` → 5 PASS.
   - `--filter=CancelInvoiceTest` → 10 PASS.
   - `--filter=RR01ReportControllerRegressionTest` → 8 PASS.
   - `--filter=RR01SupplierDualRoleRegressionTest` → 2 PASS.
   - `--filter=RR01CashFlowCancelledRegressionTest` → 4 PASS.
   - `--filter=RR05*` → 9 PASS (cost integrity).
   - `--filter=RR08OrderReturnSerialRollbackTest` → 4 PASS (serial flow upstream).
   - `--filter=RR11OrderReturnQtyTest` → 4 PASS.
   - Toàn bộ P0+P1 regression → 73 PASS.

**Tín hiệu thành công:** Cả 73 + 5 = 78 tests PASS.

**Rollback nếu fail:** revert InvoiceController. POS đã refactor có thể giữ vì service vẫn hoạt động.

### Step 16.1E — Cleanup + Closure

Việc làm:
1. Xóa các đoạn comment cũ, dead code (nếu có) trong 2 controller.
2. Verify không còn duplicate logic.
3. Cập nhật RISK_REGISTER RR-02 status = ✅ Fixed/Verified.
4. Tạo `docs/audit/RR-02-CLOSURE-REPORT.md`.
5. Chạy lại toàn bộ test verify.

---

## 13. Risk controls

### Nguyên tắc cứng
1. **Không sửa test để che lỗi.** Test fail = code fail, sửa code.
2. **Không sửa MovingAvgCostingService / StockMovementService.** Service mới chỉ orchestrate, không sửa các service nền.
3. **Không sửa migration / schema** trong Bước 16.1C-E.
4. **Không refactor module khác.**

### Test gates mỗi step

| Step | Tests bắt buộc PASS | Nếu fail |
|---|---|---|
| 16.1C (POS → service) | RR02 5/5 (đặc biệt P02), CancelInvoice 10/10, P0+P1 regression 73/73 | Revert PosController + xóa service |
| 16.1D (Invoice → service) | RR02 5/5, CancelInvoice 10/10, RR01* 14/14, RR05* 9/9, RR08 4/4, RR11 4/4, full regression 73/73 | Revert InvoiceController |
| 16.1E (cleanup) | Tất cả như 16.1D | Revert cleanup |

### Rollback plan

- Mỗi step được commit riêng (không amend).
- Nếu test fail sau commit của step nào → `git revert` commit đó, chạy lại test → state về trước step.
- Service mới không phá compatibility với các service nền (CostingService, MovementService, CashFlow, Customer model) → an toàn rollback.

### Concurrency
- Service vẫn dùng `Product::lockForUpdate()` như controllers hiện tại.
- DB::transaction wrap toàn bộ flow → atomic.
- POS bug `invoice_item_id=0` được sửa triệt để bằng thứ tự tạo record đúng (không còn race trong service mới).

### Monitoring sau deploy
- Watch log `POS Checkout Error` — không được xuất hiện FK violation nữa.
- Watch report tồn/giá vốn — không được lệch sau khi user POS bán hàng serial.

---

## 14. Đặc tả service file (chưa tạo)

### File: `app/Services/InvoiceSaleService.php`

```php
<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItemSerial;
use App\Models\Product;
use App\Models\SerialImei;
use App\Models\Customer;
use App\Models\CashFlow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class InvoiceSaleService
{
    public function createSale(array $payload, array $context = []): Invoice
    {
        // (xem flow ở mục 6)
    }

    private function assertNotBeforePurchaseDate(array $items, $transactionDate): void { ... }
    private function assertSufficientStockBySetting(array $items): void { ... }
    private function assertSerialsValid(Product $product, array $serialIds): void { ... }
}
```

Helper validation methods:
- `assertNotBeforePurchaseDate(items, txDate)` — throw nếu txDate < earliest purchase date.
- `assertSufficientStockBySetting(items)` — throw nếu Setting `allow_transaction_when_out_of_stock=false` và stock insufficient.
- `assertSerialsValid(product, serial_ids)` — throw nếu count != quantity hoặc serial không thuộc product hoặc không in_stock.

---

## 15. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Mục 8 (refactor cần test trước) |
| `docs/audit/RISK_REGISTER.md` | RR-02 P1 cuối cùng |
| `docs/test-cases/RR-02-invoice-pos-duplicate.md` | Test case spec |
| `docs/audit/STEP-16.1A-RR02-INVOICE-POS-CHARACTERIZATION-TEST-RESULTS.md` | Test characterization (4 PASS, 1 FAIL) |
| `docs/audit/STEP-16.1B-RR02-INVOICE-SALE-SERVICE-DESIGN.md` | File này — design |
| `tests/Feature/Sales/RR02InvoicePosCharacterizationTest.php` | Lưới an toàn cho refactor |
| `app/Http/Controllers/InvoiceController.php` | Pattern đúng — tham chiếu |
| `app/Http/Controllers/PosController.php` | Có bug `invoice_item_id=0` cần sửa qua service |
| `app/Services/MovingAvgCostingService.php` | Service nền — không sửa |
| `app/Services/StockMovementService.php` | Service nền — không sửa |

---

## 16. Kết luận thiết kế

✅ **Service `InvoiceSaleService` đã được thiết kế đầy đủ trên tài liệu.**

- Interface đơn giản: `createSale(array $payload, array $context): Invoice`.
- Pattern đúng: tạo InvoiceItem trước → InvoiceItemSerial sau → fix bug POS FK violation.
- Khác biệt Invoice/POS được parameterize qua `$context`, không hard-code trong service.
- Kế hoạch refactor 3 step (16.1C, 16.1D, 16.1E) với test gates rõ ràng và rollback plan.
- Không cần migration / schema change.

**Sẵn sàng chuyển sang Bước 16.1C** — tạo service file + chuyển POS sang dùng service trước (sửa bug POS serial FK violation).
