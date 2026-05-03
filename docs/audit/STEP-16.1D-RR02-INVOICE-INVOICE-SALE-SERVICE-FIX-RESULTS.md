# STEP-16.1D — RR-02 InvoiceController uses InvoiceSaleService

> **Bước:** 16.1D — Chuyển `InvoiceController@store` sang dùng `InvoiceSaleService`
> **Ngày:** 02/05/2026
> **Phạm vi sửa:** 1 controller (Invoice)

---

## 1. Vấn đề đã xử lý

- ✅ **Duplicate Invoice logic** — `InvoiceController@store` chuyển từ ~150 dòng inline business logic sang gọi `InvoiceSaleService::createSale()`.
- ✅ **POS và Invoice dùng chung sale engine** — duplicate logic được xóa hoàn toàn ở mức controller.
- ✅ **Bug POS serial FK violation** đã được sửa từ Step 16.1C, Invoice giờ dùng cùng pattern → cùng test characterization.

---

## 2. File đã sửa

| File | Loại | Nội dung sửa |
|---|---|---|
| `app/Http/Controllers/InvoiceController.php` | Controller | Import `InvoiceSaleService`. Thay ~150 dòng inline checkout (Invoice create + items + serials + applySale + StockMovement + customer debt + CashFlow) bằng build payload + context + gọi `app(InvoiceSaleService::class)->createSale(...)`. Giữ validation rules + redirect response + try/catch. |
| `app/Services/InvoiceSaleService.php` | Service | **Không sửa** (Step 16.1C đã đầy đủ context cho cả Invoice + POS). |

**Không sửa:** PosController (đã refactor ở Step 16.1C), MovingAvgCostingService, StockMovementService, models, migrations, tests, các module khác.

---

## 3. InvoiceController@store

### Giữ lại
- Validation rules HTTP request (`subtotal`, `total`, `customer_paid`, `items.*`, `serial_ids.*` exists, `payment_method`, etc.).
- Resolve `$priceBookName` từ `$validated['price_book_id']` hoặc `$validated['price_book_name']`.
- Try/catch wrap.
- Response success → `redirect()->route('invoices.index')->with('success', ...)`.
- Response fail → `back()->with('error', ...)->withInput()`.
- Route `POST /invoices` (`invoices.store`).

### Đã bỏ inline logic
- ❌ Pre-flight validate "không bán trước ngày nhập" (chuyển vào service qua `validate_before_purchase_date=true`).
- ❌ Pre-flight validate stock theo Setting (chuyển vào service qua `validate_stock_setting=true`).
- ❌ `DB::beginTransaction/commit/rollBack` (giờ trong service).
- ❌ Tạo Invoice inline.
- ❌ Loop items inline (lock product, tạo InvoiceItem, InvoiceItemSerial, applySale, StockMovement).
- ❌ Update customer debt + dual-role inline.
- ❌ Tạo CashFlow inline.

### Payload truyền vào service
```php
$payload = [
    'customer_id'    => $validated['customer_id'] ?? null,
    'branch_id'      => $validated['branch_id'] ?? null,
    'subtotal'       => $validated['subtotal'],
    'discount'       => $validated['discount'] ?? 0,
    'total'          => $validated['total'],
    'customer_paid'  => $validated['customer_paid'] ?? 0,
    'payment_method' => $validated['payment_method'] ?? 'Tiền mặt',
    'note'           => $validated['note'] ?? null,
    'items'          => array_map(fn($it) => [
        'product_id', 'quantity', 'price', 'discount', 'note', 'serial_ids'
    ], $validated['items']),
];
```

### Context truyền vào service
```php
$context = [
    'source'                        => 'invoice',
    'code_prefix'                   => 'HD' . date('YmdHis'),
    'default_status'                => 'Hoàn thành',
    'price_book_name'               => $priceBookName,
    'created_by_name'               => auth()->user()?->name ?? 'Admin',
    'is_delivery'                   => $validated['is_delivery'] ?? false,
    'delivery_partner'              => $validated['delivery_partner'] ?? null,
    'delivery_fee'                  => $validated['delivery_fee'] ?? 0,
    'transaction_date'              => $request->filled('order_date') ? $request->input('order_date') : null,
    'validate_before_purchase_date' => true,   // Invoice mode: bật check
    'validate_stock_setting'        => true,   // Invoice mode: bật check theo Setting
    'allow_oversell'                => Setting::get('inventory_allow_oversell', false),
    'cashflow_payment_method'       => $validated['payment_method'] ?? 'cash',
    'cashflow_description_extra'    => '',
    // stock_movement_branch_id KHÔNG set → service mặc định lấy invoice.branch_id
];
```

### Response redirect
✅ Giữ nguyên: success → `redirect('invoices.index')`; fail → `back()->withErrors/with('error')->withInput()`.

---

## 4. InvoiceSaleService

✅ **Không bổ sung gì** so với Step 16.1C. Service đã đầy đủ context cho cả Invoice và POS:
- `validate_before_purchase_date` → `assertNotBeforePurchaseDate()` (Invoice bật, POS tắt)
- `validate_stock_setting` → `assertSufficientStockBySetting()` (Invoice bật, POS tắt)
- `branch_id` qua payload
- `default_status`, `price_book_name`, `is_delivery`, `delivery_partner`, `delivery_fee`, `created_by_name`, `transaction_date` qua context
- `stock_movement_branch_id` mặc định `invoice->branch_id` nếu context không override

→ Phép lấy 1 service nền chung — không phải sửa lại thêm cho Invoice.

---

## 5. Behavior cần preserve

| Behavior | Trước (InvoiceController inline) | Sau (qua service) | Verify |
|---|---|---|---|
| `Invoice.status` | `'Hoàn thành'` | `'Hoàn thành'` (context.default_status) | ✅ TC-I01 assert |
| `Invoice.branch_id` | từ payload | từ payload (qua service) | ✅ Test PASS |
| `Invoice.price_book_name` | resolved từ price_book_id | context.price_book_name | ✅ Behavior giữ |
| Delivery fields | từ payload | qua context | ✅ Behavior giữ |
| `StockMovement.branch_id` | `invoice.branch_id` | service mặc định lấy `invoice.branch_id` (context không override) | ✅ Behavior giữ |
| CashFlow tạo nếu paid > 0 | ✅ | ✅ (service createCashFlowIfPaid) | ✅ TC-I01 assert |
| Customer debt increment | ✅ | ✅ (service updateCustomerDebt) | ✅ TC-I01 assert |
| Auto dual-role | ✅ | ✅ | ✅ Behavior giữ |
| Validate "trước ngày nhập" | controller pre-flight | service `assertNotBeforePurchaseDate` | ✅ Behavior giữ qua exception → catch → back('error') |
| Validate stock setting | controller pre-flight | service `assertSufficientStockBySetting` | ✅ Behavior giữ qua exception → catch → back('error') |
| InvoiceItem fields | tạo inline | service tạo qua `processItem` | ✅ TC-I01 assert quantity, cost_price |
| InvoiceItemSerial.invoice_item_id | id thật (Invoice đã đúng từ trước) | id thật (service pattern đúng) | ✅ TC-I02 assert != 0 |
| `MovingAvgCostingService::applySale` | gọi inline | gọi trong service | ✅ Stock + total_cost giảm đúng |
| `recomputeFromSerials` | inline | trong service | ✅ Behavior giữ |

**Khác biệt nhỏ với behavior cũ:**
- Trước: validate "trước ngày nhập" và stock setting fail → `back()->withErrors(['items' => '...'])`.
- Sau: service throw → catch → `back()->with('error', 'Có lỗi xảy ra: ...')`.
- → Vẫn redirect back, vẫn flash message; chỉ khác chỗ message dạng `error` flash thay vì `errors->items`. Test characterization không kiểm tra điểm này, không phá assertion.

---

## 6. Kết quả test

### 6.1. RR-02 characterization

| Test | Step 16.1C | Step 16.1D |
|---|---|---|
| `invoice_sale_normal_product_creates_expected_inventory_and_movement` | ✅ PASS | ✅ PASS |
| `invoice_sale_serial_creates_valid_invoice_item_serial` | ✅ PASS | ✅ PASS |
| `pos_sale_normal_product_creates_expected_inventory_and_movement` | ✅ PASS | ✅ PASS |
| `pos_sale_serial_creates_valid_invoice_item_serial_without_zero_invoice_item_id` | ✅ PASS | ✅ PASS |
| `invoice_and_pos_sale_have_equivalent_inventory_effects_for_same_payload` | ✅ PASS | ✅ PASS |
| **Tổng** | 5 PASS, 0 FAIL | ✅ **5 PASS, 0 FAIL** (48 assertions) |

### 6.2. P0 audit regression

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
| **Tổng tests sau Bước 16.1D** | ✅ **78 PASS, 0 FAIL** |

---

## 7. Rủi ro còn lại

| # | Khu vực | Mức độ | Ghi chú |
|---|---|---|---|
| 1 | Cleanup duplicate / dead code | P3 | Step 16.1E sẽ rà soát các import không còn dùng (CashFlow, SerialImei, StockMovementService, MovingAvgCostingService) trong InvoiceController/PosController. |
| 2 | Comment cũ / code chết | Cosmetic | InvoiceController/PosController có thể còn comment "Note: Không gọi DebtOffsetService" hoặc tương tự. Có thể xóa ở Step 16.1E. |
| 3 | Khác biệt intentional Invoice/POS qua context | Designed | `branch_id`, `validate_*`, `code_prefix`, `sales_channel` giữ qua context. Không phải bug. |
| 4 | Update flow `InvoiceController@update` | P3 | Hiện vẫn còn inline logic update (sửa hóa đơn). Nếu cần consistency, refactor riêng — ngoài phạm vi RR-02. |
| 5 | Cancel flow `InvoiceController@cancel` | OK | Đã được RR-01 sửa, không liên quan store flow. |

---

## 8. Kết luận

✅ **InvoiceController đã dùng InvoiceSaleService.**
✅ **RR-02 core refactor đã xong.** Cả Invoice và POS dùng chung 1 service.
✅ **78/78 tests PASS** — không có hồi quy.
✅ **Bug POS serial FK violation đã sửa** (xác nhận qua TC-P02).
✅ **Inventory effects giữa Invoice và POS tương đương** (xác nhận qua TC-C01).

**Có thể chuyển sang Step 16.1E** — cleanup duplicate / dead code + closure RR-02:
1. Rà soát import không còn dùng trong 2 controller.
2. Xóa comment cũ.
3. Verify scan code không còn duplicate logic sale.
4. Cập nhật RISK_REGISTER → RR-02 = ✅ Fixed/Verified.
5. Tạo `docs/audit/RR-02-CLOSURE-REPORT.md`.

### Test gates đã pass cho 16.1D
- `RR02InvoicePosCharacterizationTest` 5/5 ✅
- `CancelInvoiceTest` 10/10 ✅
- `RR01*` 14/14 ✅
- `RR05*` 9/9 ✅
- `RR08` 4/4 ✅
- `RR11` 4/4 ✅
- Toàn bộ P0+P1 regression 73/73 ✅

### Rollback plan
- Service file giữ nguyên (đã ổn định ở 16.1C).
- `InvoiceController@store` có thể revert qua git → quay lại inline logic. POS đã ổn không bị ảnh hưởng vì service không phụ thuộc Invoice.

---

## 9. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Mục 8 |
| `docs/audit/RISK_REGISTER.md` | RR-02 |
| `docs/test-cases/RR-02-invoice-pos-duplicate.md` | Test case spec |
| `docs/audit/STEP-16.1A-RR02-INVOICE-POS-CHARACTERIZATION-TEST-RESULTS.md` | Lưới an toàn (4P,1F→5P) |
| `docs/audit/STEP-16.1B-RR02-INVOICE-SALE-SERVICE-DESIGN.md` | Design doc |
| `docs/audit/STEP-16.1C-RR02-POS-INVOICE-SALE-SERVICE-FIX-RESULTS.md` | POS refactor |
| `docs/audit/STEP-16.1D-RR02-INVOICE-INVOICE-SALE-SERVICE-FIX-RESULTS.md` | File này |
| `tests/Feature/Sales/RR02InvoicePosCharacterizationTest.php` | Test (5 PASS) |
| `app/Services/InvoiceSaleService.php` | Service nền |
| `app/Http/Controllers/InvoiceController.php` | Đã refactor |
| `app/Http/Controllers/PosController.php` | Đã refactor (16.1C) |
