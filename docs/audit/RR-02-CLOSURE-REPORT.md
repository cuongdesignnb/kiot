# RR-02 Closure Report — Invoice/POS dùng chung InvoiceSaleService

> **Mã rủi ro:** RR-02
> **Mức độ ban đầu:** 🟡 P1 — High
> **Trạng thái cuối:** ✅ **Fixed/Verified**
> **Ngày đóng:** 02/05/2026
> **Test verification:** 78 PASS, 0 FAIL (5 RR-02 + 50 P0 audit + 23 P1 regression)

---

## 1. Tóm tắt lỗi ban đầu

- **Lỗi duplicate logic:** `InvoiceController@store` và `PosController@checkout` mỗi nơi ~150-190 dòng inline business logic trùng nhau (tạo Invoice + items + serials + costing + stock movement + customer debt + cashflow). Sửa 1 nơi quên nơi kia → inconsistent.
- **Root cause:** Hai luồng bán hàng chia sẻ logic giống nhau nhưng không được tách thành service. Mỗi nơi viết lại theo pattern riêng → POS có pattern sai (`invoice_item_id=0` rồi update) khác Invoice (id thật ngay).
- **Bug POS serial `invoice_item_id=0`:** `PosController@checkout` tạo `InvoiceItemSerial` với `invoice_item_id=0` trước, sau đó mới tạo `InvoiceItem` và update `invoice_item_id` về id thật. Vi phạm FK constraint `invoice_item_serials_invoice_item_id_foreign` → **POS bán serial fail HTTP 500** trong DB FK strict mode.
- **Ảnh hưởng:**
  - POS production **không bán được hàng serial** với DB FK strict (severity HIGH).
  - Maintainability kém: thay đổi 1 luồng phải nhớ thay luồng kia.
  - Race condition tiềm ẩn nếu nhiều POS checkout đồng thời cùng thao tác trên `invoice_item_id=0`.
- **Vì sao cần refactor service:**
  - Loại bỏ duplicate code (~150 dòng × 2 controller).
  - Sửa bug POS bằng pattern đúng (Invoice pattern).
  - Đảm bảo behavior tương đương cho cả 2 luồng — tests characterization xác nhận.

---

## 2. Discovery

### Pattern InvoiceController@store (đúng)
- Validate request → resolve `priceBookName` → `DB::beginTransaction`.
- Tạo `Invoice` với status='Hoàn thành'.
- Loop items: lock product → tạo `InvoiceItem` **TRƯỚC** → tạo `InvoiceItemSerial` với `invoice_item_id = $invoiceItem->id` (id thật) → update `SerialImei` → `applySale` → `StockMovement`.
- Update customer debt + dual-role.
- Tạo CashFlow nếu paid > 0.
- Commit → redirect.

### Pattern PosController@checkout (sai — bug)
- Validate → `DB::beginTransaction`.
- Tạo `Invoice` (không set status, không set branch_id).
- Loop items: lock product → tạo `InvoiceItemSerial(invoice_item_id=0)` **TRƯỚC** ❌ → tạo `InvoiceItem` → `update(['invoice_item_id' => $invoiceItem->id])` ❌ → `applySale` → `StockMovement`.
- Update customer debt.
- Tạo CashFlow.
- Commit → JSON.

### FK constraint
- `invoice_item_serials.invoice_item_id` REFERENCES `invoice_items.id` ON DELETE CASCADE.
- Insert `invoice_item_id=0` → `invoice_items` không có id=0 → **FK violation 1452** → POS HTTP 500.
- Bug này được Step 16.1A characterization test phát hiện.

---

## 3. Các thay đổi đã thực hiện

| Bước | Nội dung | File liên quan | Kết quả test |
|---|---|---|---|
| **Step 16.1A** | Discovery + viết characterization tests (5 test cases bao phủ Invoice/POS sản phẩm thường + serial + tương đương) | `tests/Feature/Sales/RR02InvoicePosCharacterizationTest.php`, `docs/test-cases/RR-02-invoice-pos-duplicate.md`, `docs/audit/STEP-16.1A-RR02-...-TEST-RESULTS.md` | 4 PASS, 1 FAIL (TC-P02 — bug POS FK confirmed) |
| **Step 16.1B** | Thiết kế `InvoiceSaleService` (interface + payload + context + flow + plan refactor 3 step) | `docs/audit/STEP-16.1B-RR02-INVOICE-SALE-SERVICE-DESIGN.md` | (design only, không sửa code) |
| **Step 16.1C** | Tạo `InvoiceSaleService` + chuyển `PosController@checkout` sang dùng service. Pattern đúng: tạo InvoiceItem trước → InvoiceItemSerial với id thật. | `app/Services/InvoiceSaleService.php`, `app/Http/Controllers/PosController.php`, `docs/audit/STEP-16.1C-...-FIX-RESULTS.md` | 5 PASS, 0 FAIL — TC-P02 chuyển từ FAIL → PASS (bug POS FK đã sửa) |
| **Step 16.1D** | Chuyển `InvoiceController@store` sang dùng cùng service. Duplicate logic đã xóa. | `app/Http/Controllers/InvoiceController.php`, `docs/audit/STEP-16.1D-...-FIX-RESULTS.md` | 5 PASS, 0 FAIL |
| **Step 16.1E** | Cleanup imports không còn dùng trong PosController. Cập nhật RISK_REGISTER + tạo closure report. | `app/Http/Controllers/PosController.php`, `docs/audit/RISK_REGISTER.md`, `docs/audit/RR-02-CLOSURE-REPORT.md` (file này) | 78 PASS, 0 FAIL (5 RR-02 + 50 P0 + 23 P1) |

---

## 4. File đã sửa

| File | Loại | Nội dung sửa |
|---|---|---|
| `app/Services/InvoiceSaleService.php` | Service mới | ~280 dòng. Class với method chính `createSale($payload, $context): Invoice`. 5 helper private (buildInvoiceAttributes, processItem, updateCustomerDebt, createCashFlowIfPaid, validate*). Wrap `DB::transaction`. Pattern đúng: tạo InvoiceItem TRƯỚC → InvoiceItemSerial với id thật. |
| `app/Http/Controllers/PosController.php` | Controller refactor | Step 16.1C: thay ~130 dòng inline checkout bằng build payload + context + gọi `app(InvoiceSaleService::class)->createSale(...)`. Step 16.1E cleanup: xóa imports `MovingAvgCostingService`, `StockMovementService` không còn dùng. Giữ validation, response JSON, log error, route. |
| `app/Http/Controllers/InvoiceController.php` | Controller refactor | Step 16.1D: thay ~150 dòng inline store bằng build payload + context + gọi service. Giữ validation, resolve `priceBookName`, redirect response. **Imports khác giữ nguyên** vì `update()`, `cancel()`, các method khác vẫn dùng `CashFlow`, `SerialImei`, `MovingAvgCostingService`, `StockMovementService`, `DebtOffsetService`. |
| `docs/audit/RISK_REGISTER.md` | Doc | RR-02 status = ✅ Fixed/Verified, P1 closed: 5/5, thêm 4 dòng changelog (Step 16.1B/C/D/E) |
| `docs/audit/RR-02-CLOSURE-REPORT.md` | Doc | File này |

**Không sửa:** MovingAvgCostingService, StockMovementService, models, migrations, tests characterization, các module khác.

---

## 5. InvoiceSaleService

### Method chính

```php
public function createSale(array $payload, array $context = []): Invoice
```

### Flow (5 bước, atomic trong DB::transaction)

1. **Pre-flight validations** theo context:
   - `validate_before_purchase_date` (Invoice mode bật) → `assertNotBeforePurchaseDate()`.
   - `validate_stock_setting` (Invoice mode bật) → `assertSufficientStockBySetting()`.
2. **Tạo Invoice** với attributes từ `buildInvoiceAttributes($payload, $context)`.
3. **Loop items** qua `processItem($invoice, $item, $allowOversell, $context)`:
   - `Product::lockForUpdate()`.
   - Validate stock/serial.
   - Snapshot `cost_price` TRƯỚC `applySale`.
   - **Bước A:** Tạo `InvoiceItem` TRƯỚC (có id thật).
   - **Bước B:** Tạo `InvoiceItemSerial` với `invoice_item_id = $invoiceItem->id` (KHÔNG bao giờ là 0). Update `SerialImei` (status='sold', sold_at, invoice_id, sold_cost_price).
   - **Bước C:** `MovingAvgCostingService::applySale()` + `recomputeFromSerials()` nếu has_serial.
   - **Bước D:** `StockMovementService::record(TYPE_OUT_INVOICE)` với branch_id từ context hoặc invoice.
4. **Customer debt + dual-role** qua `updateCustomerDebt()`:
   - Auto-enable `is_customer=true` nếu `is_supplier && !is_customer`.
   - Increment `debt_amount` nếu != 0; luôn increment `total_spent`.
5. **CashFlow** qua `createCashFlowIfPaid()`:
   - Tạo nếu `customer_paid > 0`.
   - Type='receipt', category='Thu tiền khách trả'.

### Khác biệt Invoice/POS qua context

| Context field | Invoice value | POS value |
|---|---|---|
| `source` | `'invoice'` | `'pos'` |
| `code_prefix` | `'HD' . date('YmdHis')` | `'HD' . time()` |
| `default_status` | `'Hoàn thành'` | `'Hoàn thành'` (chuẩn hóa) |
| `sales_channel` | (không set) | `'Bán trực tiếp'` |
| `validate_before_purchase_date` | true | false |
| `validate_stock_setting` | true | false |
| `allow_oversell` | Setting (default false) | Setting (default true) |
| `cashflow_description_extra` | `''` | `' - CK: ' + bank_info` nếu transfer |
| `stock_movement_branch_id` | unset → invoice.branch_id | `null` (POS legacy) |
| `seller_id` / `seller_name` | (không set) | từ employee |

---

## 6. Bug POS serial đã sửa

### Trước sửa (`PosController@checkout` cũ)
```php
\App\Models\InvoiceItemSerial::create([
    'invoice_item_id' => 0, // sẽ update sau ❌
    'serial_imei_id'  => $serial->id,
    ...
]);
// ... tạo invoice_item ...
\App\Models\InvoiceItemSerial::where('invoice_item_id', 0)
    ->whereIn('serial_imei_id', ...)
    ->update(['invoice_item_id' => $invoiceItem->id]);
```

→ FK violation:
```
SQLSTATE[23000]: Integrity constraint violation: 1452
foreign key constraint fails (sales_test.invoice_item_serials,
CONSTRAINT invoice_item_serials_invoice_item_id_foreign
FOREIGN KEY (invoice_item_id) REFERENCES invoice_items (id) ON DELETE CASCADE)
SQL: insert into invoice_item_serials (invoice_item_id, ...) values (0, ...)
```

### Sau sửa (qua `InvoiceSaleService::processItem`)
```php
// Bước A: tạo InvoiceItem trước (có id thật)
$invoiceItem = $invoice->items()->create([...]);

// Bước B: tạo InvoiceItemSerial với invoice_item_id thật
foreach ($soldSerials as $serial) {
    InvoiceItemSerial::create([
        'invoice_item_id' => $invoiceItem->id, // ← id THẬT, không bao giờ là 0
        ...
    ]);
}
```

### Test chứng minh
- `test_pos_sale_serial_creates_valid_invoice_item_serial_without_zero_invoice_item_id`:
  - Trước Step 16.1C: ❌ FAIL (HTTP 500 FK violation)
  - Sau Step 16.1C: ✅ PASS — 2 assertions:
    - `$iisRecord->invoice_item_id != 0`
    - `InvoiceItemSerial::where('invoice_item_id', 0)->count() === 0`

→ POS bán serial **không còn fail FK**.

---

## 7. Test verification

### Môi trường

```
APP_ENV=testing
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3319
DB_DATABASE=sales_test
```

### Kết quả final (02/05/2026)

| Nhóm test | File | Tests | Assertions | Kết quả |
|---|---|---:|---:|---|
| RR-02 characterization | `RR02InvoicePosCharacterizationTest.php` | 5 | 48 | ✅ **5 PASS** |
| RR-01 cancel invoice | `CancelInvoiceTest.php` | 10 | 20 | ✅ **10 PASS** |
| RR-01 report P0 | `RR01ReportControllerRegressionTest.php` | 8 | 9 | ✅ **8 PASS** |
| RR-01 supplier P1 | `RR01SupplierDualRoleRegressionTest.php` | 2 | 4 | ✅ **2 PASS** |
| RR-01 cashflow P1 | `RR01CashFlowCancelledRegressionTest.php` | 4 | 4 | ✅ **4 PASS** |
| RR-03 stock transfer | `RR03StockTransferTest.php` | 5 | 12 | ✅ **5 PASS** |
| RR-03 stock transfer route | `RR03StockTransferRouteTest.php` | 3 | 10 | ✅ **3 PASS** |
| RR-04 stock take | `RR04StockTakeTest.php` | 5 | 12 | ✅ **5 PASS** |
| RR-05 unit | `RR05MovingAvgCostingZeroStockTest.php` | 5 | 15 | ✅ **5 PASS** |
| RR-05 feature serial | `RR05SerialImeiCostingTest.php` | 4 | 16 | ✅ **4 PASS** |
| RR-07 repair parts | `RR07RepairPartsTest.php` | 4 | 9 | ✅ **4 PASS** |
| RR-08 serial rollback | `RR08OrderReturnSerialRollbackTest.php` | 4 | 15 | ✅ **4 PASS** |
| RR-09 damage stock | `RR09DamageStockTest.php` | 5 | 12 | ✅ **5 PASS** |
| RR-10 cashflow deletion | `RR10CashFlowDeletionTest.php` | 5 | 12 | ✅ **5 PASS** |
| RR-11 order return qty | `RR11OrderReturnQtyTest.php` | 4 | 8 | ✅ **4 PASS** |
| RR-12 stock transfer cancel received | `RR12StockTransferCancelReceivedTest.php` | 5 | 23 | ✅ **5 PASS** |
| **Tổng** | | **78** | **229** | ✅ **78 PASS, 0 FAIL** |

---

## 8. Quy ước mới sau RR-02

### Bán hàng

1. **Logic tạo sale phải đi qua `InvoiceSaleService::createSale()`.** Không được duplicate logic ở controller.
2. **Không được tạo `InvoiceItemSerial` trước `InvoiceItem`.** Vi phạm FK constraint.
3. **Không được dùng `invoice_item_id=0`** ở bất kỳ điểm nào.
4. **Pattern bắt buộc:** tạo `InvoiceItem` → có id thật → tạo `InvoiceItemSerial` với id đó.
5. **Khác biệt Invoice/POS** parameterize qua `$context`, không hard-code trong service.

### Controller responsibility

1. **Controller chỉ làm:** validate request HTTP, normalize payload, build context, response (redirect/JSON), try/catch.
2. **Service làm:** tất cả business logic + DB::transaction + costing + movement + debt + cashflow.
3. **Không gọi `MovingAvgCostingService` / `StockMovementService`** trực tiếp trong controller cho luồng bán hàng — service đã handle.

### Test gates

1. Mỗi PR refactor sale phải pass `RR02InvoicePosCharacterizationTest` 5/5.
2. P0/P1 regression 73/73 phải tiếp tục pass.

---

## 9. Rủi ro còn lại đưa vào backlog

| # | Khu vực | Mức độ | Ghi chú |
|---|---|---|---|
| 1 | `InvoiceController@update` | P3 | Vẫn còn inline logic update hóa đơn (~200 dòng). Có thể tách `InvoiceSaleService::updateSale()` nếu cần consistency — ngoài phạm vi RR-02. |
| 2 | Legacy `InvoiceItemSerial.invoice_item_id=0` trong production | Backward compat | Nếu production từng có data cũ với invoice_item_id=0 (do bug POS lúc trước), cần Artisan command cleanup. Không xử lý ở step này. |
| 3 | POS khác Invoice có chủ ý | Designed | `branch_id=null`, `validate_before_purchase_date=false`, `validate_stock_setting=false`, `allow_oversell=true`, `code_prefix=time()` — giữ qua context. Có thể chuẩn hóa sau nếu nghiệp vụ yêu cầu. |
| 4 | RR-06 customer_debt_transactions | P2 | P2 cuối cùng chưa xử lý: tách bảng `customer_debt_transactions` + `CustomerDebtService`. Pattern tương tự `supplier_debt_transactions`. |
| 5 | Cosmetic backlog từ các RR khác | P3 | Backlog từ RR-05/RR-08/RR-09/RR-12 (legacy backfill, UI, permission tách, multi-warehouse architecture). Đã ghi nhận trong các closure report. |

---

## 10. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Mục 8 (refactor cần test trước) |
| `docs/audit/RISK_REGISTER.md` | Bảng rủi ro tổng — RR-02 = Fixed/Verified |
| `docs/test-cases/RR-02-invoice-pos-duplicate.md` | Test case spec |
| `docs/audit/STEP-16.1A-RR02-INVOICE-POS-CHARACTERIZATION-TEST-RESULTS.md` | Lưới an toàn (4 PASS, 1 FAIL — bug POS xác định) |
| `docs/audit/STEP-16.1B-RR02-INVOICE-SALE-SERVICE-DESIGN.md` | Design doc đầy đủ (16 mục) |
| `docs/audit/STEP-16.1C-RR02-POS-INVOICE-SALE-SERVICE-FIX-RESULTS.md` | POS refactor (5 PASS — bug FK đã sửa) |
| `docs/audit/STEP-16.1D-RR02-INVOICE-INVOICE-SALE-SERVICE-FIX-RESULTS.md` | Invoice refactor (5 PASS) |
| `docs/audit/RR-02-CLOSURE-REPORT.md` | File này — closure report |
| `tests/Feature/Sales/RR02InvoicePosCharacterizationTest.php` | Characterization test (5 PASS) |
| `app/Services/InvoiceSaleService.php` | Service nền RR-02 |
| `app/Http/Controllers/InvoiceController.php` | Đã refactor store() |
| `app/Http/Controllers/PosController.php` | Đã refactor checkout() + cleanup imports |

---

## 11. Kết luận

✅ **RR-02 đã Fixed/Verified.**

- Cả `InvoiceController@store` và `PosController@checkout` dùng chung `InvoiceSaleService`.
- Bug POS serial FK violation đã sửa triệt để (TC-P02 PASS).
- Duplicate logic đã được loại bỏ (~300 dòng tách thành service).
- 78/78 tests PASS — không có hồi quy.
- Pattern thống nhất với các RR khác đã sửa (RR-04 stock take, RR-08 return, RR-09 damage, RR-12 stock transfer): mỗi nghiệp vụ có service nền riêng.

### Tổng kết tiến độ audit

| Mã | Module | Mức | Trạng thái |
|---|---|---|---|
| RR-01 | Invoice cancel | P0 | ✅ Fixed/Verified |
| RR-02 | Invoice/POS duplicate | P1 | ✅ **Fixed/Verified (Bước 16.1E)** |
| RR-03 | Stock transfer | P0 | ✅ Fixed/Verified |
| RR-04 | Stock take | P0 | ✅ Fixed/Verified |
| RR-05 | Costing zero stock | P1 | ✅ Fixed/Verified |
| RR-06 | Customer debt | P2 | 🔵 Chưa xử lý |
| RR-07 | Repair parts | P0 | ✅ Fixed/Verified |
| RR-08 | OrderReturn rollback serial | P1 | ✅ Fixed/Verified |
| RR-09 | Damage | P1 | ✅ Fixed/Verified |
| RR-10 | CashFlow deletion | P0 | ✅ Fixed/Verified |
| RR-11 | OrderReturn qty | P0 | ✅ Fixed/Verified |
| RR-12 | StockTransfer multi-warehouse | P1 | ✅ Fixed/Verified |

**Toàn bộ P0+P1 đã đóng** (6/6 P0 + 5/5 P1). Còn 1 P2 (RR-06).

**Sẵn sàng:**
- Chuyển sang RR-06 (P2 cuối cùng) — tách bảng `customer_debt_transactions` + `CustomerDebtService`, hoặc
- Tổng kết audit P0+P1 (tương tự `P0-AUDIT-SUMMARY-REPORT.md` Bước 11) trước khi xử lý P2.

**Tổng tiến độ: 11/12 rủi ro đã đóng** (6 P0 + 5 P1).
