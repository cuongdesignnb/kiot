# STEP-16.1C — RR-02 POS uses InvoiceSaleService

> **Bước:** 16.1C — Tạo `InvoiceSaleService` + chuyển `PosController@checkout` sang dùng service
> **Ngày:** 02/05/2026
> **Phạm vi sửa:** 1 service mới + 1 controller (POS only)

---

## 1. Vấn đề đã xử lý

- ✅ **Bug POS serial FK violation**: trước đây `PosController@checkout` tạo `InvoiceItemSerial(invoice_item_id=0)` rồi update sau → vi phạm FK constraint, POS bán serial **fail 500** trong DB FK strict.
- ✅ **Duplicate logic POS**: tách sang `InvoiceSaleService::createSale()`. POS controller giảm từ ~190 dòng business logic xuống ~60 dòng (chỉ build payload + context).
- ⚠️ Duplicate logic Invoice **chưa xử lý** ở bước này (Step 16.1D sẽ chuyển InvoiceController).

---

## 2. File đã sửa

| File | Loại | Nội dung sửa |
|---|---|---|
| `app/Services/InvoiceSaleService.php` | Service mới | `createSale($payload, $context): Invoice` — toàn bộ flow tạo invoice + items + serials + stock + costing + movement + debt + cashflow trong DB::transaction. Pattern đúng: tạo InvoiceItem trước → InvoiceItemSerial với id thật. |
| `app/Http/Controllers/PosController.php` | Controller | Import `InvoiceSaleService`. Thay ~130 dòng inline logic checkout bằng build payload + context + gọi `app(InvoiceSaleService::class)->createSale(...)`. Giữ validation, response JSON, log error. |

**Không sửa:** InvoiceController, MovingAvgCostingService, StockMovementService, models, migrations, tests, các module khác.

---

## 3. InvoiceSaleService

### File
`app/Services/InvoiceSaleService.php`

### Method chính
```php
public function createSale(array $payload, array $context = []): Invoice
```

### Flow chính (5 bước)

1. **Pre-flight validations** (theo context):
   - `validate_before_purchase_date` (Invoice mode) → `assertNotBeforePurchaseDate()`.
   - `validate_stock_setting` (Invoice mode) → `assertSufficientStockBySetting()`.

2. **Tạo Invoice** với fields từ `buildInvoiceAttributes()`:
   - Required: code (từ context.code_prefix + rand), customer_id, branch_id, status, subtotal, discount, total, customer_paid, payment_method.
   - Optional (chỉ set khi context cho): sales_channel, price_book_name, seller_name, created_by (seller_id), sale_time.

3. **Loop items** qua `processItem()`:
   - `Product::lockForUpdate()`.
   - Validate stock/serial theo flag `allow_oversell`.
   - **Bước A:** Tạo `InvoiceItem` TRƯỚC (có id thật).
   - **Bước B:** Tạo `InvoiceItemSerial` với `invoice_item_id = $invoiceItem->id` (KHÔNG bao giờ là 0). Update `SerialImei` (status='sold', sold_at, invoice_id, sold_cost_price).
   - **Bước C:** `MovingAvgCostingService::applySale()` + `recomputeFromSerials()` nếu has_serial.
   - **Bước D:** `StockMovementService::record(TYPE_OUT_INVOICE)` với branch_id từ context hoặc invoice.

4. **Customer debt + dual-role** qua `updateCustomerDebt()`:
   - Auto-enable `is_customer=true` nếu `is_supplier && !is_customer`.
   - Increment `debt_amount` nếu != 0.
   - Increment `total_spent` luôn.

5. **CashFlow** qua `createCashFlowIfPaid()`:
   - Chỉ tạo nếu `customer_paid > 0`.
   - Type='receipt', category='Thu tiền khách trả'.
   - Description = "Thu tiền hóa đơn {code} - {customer} {extra}".

### Cách xử lý serial
- Validate `count(serial_ids)` thuộc product, `status='in_stock'` qua `assertSerialsValid()`.
- **Tạo `InvoiceItem` trước** → có id thật → tạo `InvoiceItemSerial` với id đúng → update `SerialImei` sang sold.
- **KHÔNG** dùng `invoice_item_id=0` rồi update sau.

### Cách xử lý stock/cost/movement
- Snapshot `cost_price` TRƯỚC `applySale` (dùng cho `InvoiceItem.cost_price`, `InvoiceItemSerial.cost_price`, `SerialImei.sold_cost_price`, `StockMovement.unit_cost`).
- `applySale` giảm `stock_quantity` + `inventory_total_cost`, giữ `cost_price` BQ.
- `recomputeFromSerials` sync stock với serial in_stock count (audit, không đụng cost).
- `StockMovement.branch_id` = context override hoặc invoice.branch_id (mặc định null cho POS).

### Cách xử lý CashFlow/debt
- Dùng cùng pattern Invoice + POS hiện tại.
- Khác biệt parameterize qua `cashflow_payment_method` và `cashflow_description_extra`.

---

## 4. PosController@checkout

### Giữ lại
- Validation rules (payment_method strict in:cash,transfer; customer_paid required).
- Resolve `Employee` từ `employee_id`.
- Compose `note` (CK info nếu transfer).
- Response JSON `{success, invoice_code, message}`.
- Log `POS Checkout Error` nếu exception.
- Route `POST /api/pos/checkout`.

### Đã bỏ inline logic
- ❌ `DB::beginTransaction()` / `commit()` / `rollBack()` (giờ trong service).
- ❌ Tạo Invoice inline.
- ❌ Loop items inline (lock product, tạo item, serial, applySale, movement).
- ❌ `InvoiceItemSerial::create([invoice_item_id => 0])` + update sau (BUG).
- ❌ Update customer debt inline.
- ❌ Tạo CashFlow inline.

### Payload truyền vào service
```php
$payload = [
    'customer_id'    => $validated['customer_id'] ?? null,
    'branch_id'      => null, // POS legacy
    'subtotal'       => $validated['subtotal'],
    'discount'       => $validated['discount'],
    'total'          => $validated['total'],
    'customer_paid'  => $validated['customer_paid'],
    'payment_method' => $paymentMethod,
    'note'           => $isTransfer && !empty($bankInfo) ? 'Chuyển khoản: ' . $bankInfo : null,
    'items'          => array_map(fn($it) => [
        'product_id', 'quantity', 'price', 'discount', 'serial_ids'
    ], $validated['items']),
];
```

### Context truyền vào service
```php
$context = [
    'source'                         => 'pos',
    'code_prefix'                    => 'HD' . time(),
    'default_status'                 => 'Hoàn thành',  // RR-02 chuẩn hóa: POS giờ luôn set status
    'sales_channel'                  => 'Bán trực tiếp',
    'seller_id'                      => $employee?->id,
    'seller_name'                    => $employee?->name,
    'created_by_name'                => auth()->user()?->name ?? 'POS',
    'transaction_date'               => $validated['sale_time'] ?? null,
    'validate_before_purchase_date'  => false,  // POS legacy: không validate
    'validate_stock_setting'         => false,  // POS legacy: không validate
    'allow_oversell'                 => Setting::get('inventory_allow_oversell', true),
    'cashflow_payment_method'        => $paymentMethod,
    'cashflow_description_extra'     => $isTransfer && !empty($bankInfo) ? ' - CK: ' . $bankInfo : '',
    'stock_movement_branch_id'       => null, // POS legacy: branch_id = null
];
```

### Response JSON
✅ Giữ nguyên format `{success, invoice_code, message}`. Status 200 thành công, 500 nếu exception.

---

## 5. Bug POS serial

### Trước sửa
```php
\App\Models\InvoiceItemSerial::create([
    'invoice_item_id' => 0, // sẽ update sau
    'serial_imei_id' => $serial->id,
    'serial_number' => $serial->serial_number,
    'cost_price' => $snapshotCostPrice,
]);
// ... tạo invoice_item ...
\App\Models\InvoiceItemSerial::where('invoice_item_id', 0)
    ->whereIn('serial_imei_id', ...)
    ->update(['invoice_item_id' => $invoiceItem->id]);
```
→ FK violation `invoice_item_serials_invoice_item_id_foreign` → POS bán serial **fail 500**.

### Sau sửa (trong service)
```php
$invoiceItem = $invoice->items()->create([...]); // tạo InvoiceItem TRƯỚC

foreach ($soldSerials as $serial) {
    InvoiceItemSerial::create([
        'invoice_item_id' => $invoiceItem->id, // ← id THẬT, không bao giờ là 0
        'serial_imei_id'  => $serial->id,
        'serial_number'   => $serial->serial_number,
        'cost_price'      => $snapshotCostPrice,
    ]);
    $serial->status = 'sold';
    // ... update SerialImei ...
}
```
→ FK pass. POS bán serial thành công.

### Còn `invoice_item_id=0` không?
❌ **Không**. Test `test_pos_sale_serial_creates_valid_invoice_item_serial_without_zero_invoice_item_id` PASS với 2 assertions:
- `$iisRecord->invoice_item_id != 0` ✅
- `InvoiceItemSerial::where('invoice_item_id', 0)->count() === 0` ✅

---

## 6. Kết quả test

### 6.1. RR-02 characterization

| Test | Trước Step 16.1C | Sau Step 16.1C |
|---|---|---|
| `invoice_sale_normal_product_creates_expected_inventory_and_movement` | ✅ PASS | ✅ PASS |
| `invoice_sale_serial_creates_valid_invoice_item_serial` | ✅ PASS | ✅ PASS |
| `pos_sale_normal_product_creates_expected_inventory_and_movement` | ✅ PASS | ✅ PASS |
| `pos_sale_serial_creates_valid_invoice_item_serial_without_zero_invoice_item_id` | ❌ FAIL (500 FK) | ✅ **PASS** |
| `invoice_and_pos_sale_have_equivalent_inventory_effects_for_same_payload` | ✅ PASS | ✅ PASS |
| **Tổng** | 4 PASS, 1 FAIL | ✅ **5 PASS, 0 FAIL** (48 assertions, 0.76s) |

### 6.2. P0 audit regression (10 filter)

| Test | Kết quả |
|---|---|
| `CancelInvoiceTest` | ✅ 10 PASS (20) |
| `RR01ReportControllerRegressionTest` | ✅ 8 PASS (9) |
| `RR01SupplierDualRoleRegressionTest` | ✅ 2 PASS (4) |
| `RR01CashFlowCancelledRegressionTest` | ✅ 4 PASS (4) |
| `RR03StockTransferTest` | ✅ 5 PASS (12) |
| `RR03StockTransferRouteTest` | ✅ 3 PASS (10) |
| `RR04StockTakeTest` | ✅ 5 PASS (12) |
| `RR07RepairPartsTest` | ✅ 4 PASS (9) |
| `RR10CashFlowDeletionTest` | ✅ 5 PASS (12) |
| `RR11OrderReturnQtyTest` | ✅ 4 PASS (8) |
| **Tổng P0** | ✅ **50 PASS** |

### 6.3. P1 regression

| Test | Kết quả |
|---|---|
| `RR05MovingAvgCostingZeroStockTest` | ✅ 5 PASS (15) |
| `RR05SerialImeiCostingTest` | ✅ 4 PASS (16) |
| `RR08OrderReturnSerialRollbackTest` | ✅ 4 PASS (15) |
| `RR09DamageStockTest` | ✅ 5 PASS (12) |
| `RR12StockTransferCancelReceivedTest` | ✅ 5 PASS (23) |
| **Tổng P1** | ✅ **23 PASS** |

### 6.4. Tổng

| Mục | Kết quả |
|---|---|
| **RR-02** | ✅ 5 PASS, 0 FAIL |
| **P0 audit** | ✅ 50 PASS |
| **P1 regression** | ✅ 23 PASS |
| **Tổng tests sau Bước 16.1C** | ✅ **78 PASS, 0 FAIL** |

---

## 7. Rủi ro còn lại

| # | Khu vực | Mức độ | Ghi chú |
|---|---|---|---|
| 1 | InvoiceController vẫn chưa dùng service | P1 chưa đóng | Step 16.1D sẽ chuyển. Duplicate logic vẫn còn ở Invoice controller (~150 dòng). |
| 2 | Behavior khác biệt POS vs Invoice giữ qua context | Intentional | `branch_id=null`, `validate_before_purchase_date=false`, `validate_stock_setting=false` giữ nguyên cho POS — đây là backward-compat. Step 16.1E có thể chuẩn hóa nếu user yêu cầu. |
| 3 | Legacy data `InvoiceItemSerial.invoice_item_id=0` trong production cũ | Backward compat | Nếu production có records cũ với invoice_item_id=0 (do bug POS lúc trước), cần Artisan command cleanup. Không xử lý ở step này. |
| 4 | POS không validate "trước ngày nhập" | P3 | Giữ behavior hiện tại để không phá. Có thể chuẩn hóa cùng InvoiceController ở Step 16.1E. |
| 5 | POS code prefix dùng `time()` thay vì `date('YmdHis')` | Cosmetic | Khác Invoice. Giữ behavior. |
| 6 | POS `created_by_name` default 'POS' thay vì 'Admin' | Cosmetic | Để phân biệt nguồn. |

---

## 8. Kết luận

✅ **POS đã dùng InvoiceSaleService.**
✅ **POS serial bug đã fixed.** Không còn FK violation. Test P02 PASS.
✅ **78/78 tests PASS** — không có hồi quy.

**Có thể chuyển sang Step 16.1D** — chuyển InvoiceController sang dùng cùng service.

### Test gates để vào 16.1D
- `RR02InvoicePosCharacterizationTest` 5/5 PASS ✅
- `CancelInvoiceTest` 10/10 PASS ✅
- Toàn bộ P0+P1 regression PASS ✅

### Rollback plan (nếu cần)
- Service file `app/Services/InvoiceSaleService.php` có thể xóa.
- `PosController@checkout` có thể revert qua git → Invoice path không bị ảnh hưởng vì chưa đụng tới.

---

## 9. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Mục 8 (refactor có test trước) |
| `docs/audit/RISK_REGISTER.md` | RR-02 |
| `docs/test-cases/RR-02-invoice-pos-duplicate.md` | Test case spec |
| `docs/audit/STEP-16.1A-RR02-INVOICE-POS-CHARACTERIZATION-TEST-RESULTS.md` | Lưới an toàn (4 PASS, 1 FAIL) |
| `docs/audit/STEP-16.1B-RR02-INVOICE-SALE-SERVICE-DESIGN.md` | Design doc |
| `docs/audit/STEP-16.1C-RR02-POS-INVOICE-SALE-SERVICE-FIX-RESULTS.md` | File này |
| `tests/Feature/Sales/RR02InvoicePosCharacterizationTest.php` | Test (5 PASS) |
| `app/Services/InvoiceSaleService.php` | Service mới |
| `app/Http/Controllers/PosController.php` | Đã refactor |
| `app/Http/Controllers/InvoiceController.php` | **Chưa refactor** — Step 16.1D |
