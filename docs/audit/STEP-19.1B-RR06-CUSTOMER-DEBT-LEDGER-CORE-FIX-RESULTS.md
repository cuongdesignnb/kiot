# STEP-19.1B — Fix RR-06 Customer Debt Ledger Core

> **Bước:** 19.1B — Tạo `CustomerDebt` model + service, refactor 4 luồng core (sale, order return, invoice cancel, order convert)
> **Ngày:** 02/05/2026
> **Phạm vi sửa:** 2 file mới + 4 file controller + service refactor

---

## 1. Vấn đề đã sửa

- ✅ Có bảng `customer_debts` nhưng chưa có Model — đã tạo `App\Models\CustomerDebt`.
- ✅ Chưa có `CustomerDebtService` — đã tạo với 5 methods chính.
- ✅ Invoice/POS sale không ghi ledger — đã refactor `InvoiceSaleService::updateCustomerDebt`.
- ✅ OrderReturn store/cancel không ghi ledger — đã refactor.
- ✅ Invoice cancel không ghi ledger — đã refactor.
- ✅ Order processOrder không ghi ledger — đã refactor (RR-13 vừa xong).

---

## 2. File đã sửa

| File | Loại | Nội dung sửa |
|---|---|---|
| `app/Models/CustomerDebt.php` | Model mới | Class CustomerDebt, table `customer_debts`, fillable đủ 10 fields, casts decimal/datetime, relations `customer()`, `order()`, `orderReturn()`. |
| `app/Services/CustomerDebtService.php` | Service mới | 5 methods: `recordSale`, `recordReturn`, `recordPayment`, `recordSaleReversal`, `recordAdjustment`. Core method `record()` lock customer, update `debt_amount` theo signed amount, tạo `CustomerDebt` row với `debt_total = debt_amount sau update`. |
| `app/Services/InvoiceSaleService.php` | Refactor | `updateCustomerDebt()` nhận thêm `?Invoice $invoice`. Thay `$customer->increment('debt_amount', $debtAmount)` bằng `app(CustomerDebtService::class)->recordSale(...)`. Caller `createSale` truyền invoice. Giữ nguyên `total_spent` + auto-dual-role. |
| `app/Http/Controllers/OrderReturnController.php` | Refactor | Import `CustomerDebtService`. `store()` dòng 313: thay `$customer->decrement('debt_amount', $total)` bằng `recordReturn(...)`. `cancel()` dòng 443: thay `$customer->increment('debt_amount', $total)` bằng `recordAdjustment(...)` với amount dương để khôi phục nợ. Giữ `total_spent` increment/decrement. |
| `app/Http/Controllers/InvoiceController.php` | Refactor | Import `CustomerDebtService`. `cancel()` dòng 502: thay `$customer->decrement('debt_amount', $debtAmount)` bằng `recordSaleReversal(...)`. Giữ `total_spent` decrement. |
| `app/Http/Controllers/OrderController.php` | Refactor | Import `CustomerDebtService`. `processOrder()` dòng 464: thay `$customer->increment('debt_amount', $debtAmount)` bằng `recordSale(...)` với meta `order_id`. Giữ `total_spent` + priorDeposit/newPayment + CashFlow logic. |

**Không sửa:** Migration, schema `customer_debts` (đã có), `MovingAvgCostingService`, `StockMovementService`, `SupplierDebtTransaction`, `Customer` model, các module khác.

---

## 3. CustomerDebt model

| Aspect | Giá trị |
|---|---|
| Table | `customer_debts` |
| Fillable | `customer_id, order_id, order_return_id, ref_code, amount, debt_total, type, note, created_by, recorded_at` |
| Casts | `amount => decimal:2`, `debt_total => decimal:2`, `recorded_at => datetime` |
| Relations | `customer()`, `order()`, `orderReturn()` |

---

## 4. CustomerDebtService

### Methods
- `recordSale($customerId, $amount, ?Model $reference, ?string $note, array $meta)` — type='sale', signed = `+abs($amount)`
- `recordReturn($customerId, $amount, ...)` — type='return', signed = `-abs($amount)`
- `recordPayment($customerId, $amount, ...)` — type='payment', signed = `-abs($amount)`
- `recordSaleReversal($customerId, $amount, ...)` — type='adjustment', signed = `-abs($amount)`
- `recordAdjustment($customerId, $signedAmount, ...)` — type='adjustment', giữ dấu

### Signed amount convention
- sale → +
- return / payment / saleReversal → −
- adjustment → signed (caller quyết định dấu để +/− nợ)

### `debt_total` tính thế nào
`debt_total = $customer->debt_amount sau khi update += signedAmount`. Service:
1. `Customer::lockForUpdate()->find($customerId)`
2. `$customer->debt_amount += $signedAmount; $customer->save()`
3. Tạo `CustomerDebt` với `debt_total = (float) $customer->debt_amount`

### `created_by` / `recorded_at`
- `created_by = auth()->id()` (null nếu không login)
- `recorded_at = now()`

### Idempotency / zero amount
- Nếu `abs($signedAmount) < 0.01` → return null, không update debt, không tạo row.
- Tự bọc `DB::transaction()` an toàn khi gọi trong/ngoài transaction parent.

---

## 5. Luồng đã refactor

### 5.1. InvoiceSaleService

**Trước:**
```php
if ($debtAmount != 0) {
    $customer->increment('debt_amount', $debtAmount);
}
$customer->increment('total_spent', $total);
```

**Sau:**
```php
if ($debtAmount != 0) {
    app(CustomerDebtService::class)->recordSale(
        $customer->id,
        $debtAmount,
        $invoice,
        "Ghi nợ bán hàng hóa đơn {$invoice->code}"
    );
}
$customer->increment('total_spent', $total);
```

- **`total_spent` giữ nguyên?** ✅ Vẫn `increment`. Service không đụng `total_spent` (chỉ ledger debt).
- Auto-dual-role logic giữ nguyên.

### 5.2. OrderReturnController@store

**Trước:** `$customer->decrement('debt_amount', $validated['total'])`

**Sau:** `app(CustomerDebtService::class)->recordReturn($customer->id, $validated['total'], $return, "Giảm công nợ do trả hàng phiếu {$return->code}")`

### 5.3. OrderReturnController@cancel

**Trước:** `$customer->increment('debt_amount', $return->total)`

**Sau:**
```php
app(CustomerDebtService::class)->recordAdjustment(
    $customer->id,
    (float) $return->total, // dương = khôi phục nợ
    "Khôi phục công nợ do hủy phiếu trả hàng {$return->code}",
    ['order_return_id' => $return->id, 'ref_code' => $return->code]
);
```

- **Idempotent giữ thế nào?** Guard `if status='Đã hủy' return` đã có sẵn ở đầu method `cancel()` — Cancel lần 2 return early, không gọi service.

### 5.4. InvoiceController@cancel

**Trước:**
```php
if ($debtAmount != 0) {
    $customer->decrement('debt_amount', $debtAmount);
}
```

**Sau:**
```php
if ($debtAmount != 0) {
    app(CustomerDebtService::class)->recordSaleReversal(
        $customer->id,
        (float) $debtAmount,
        $invoice,
        "Đảo công nợ do hủy hóa đơn {$invoice->code}"
    );
}
```

- **Idempotent:** Method `cancel/destroy` của Invoice có guard `if status='Đã hủy' return` (RR-01) → Cancel lần 2 không gọi service.

### 5.5. OrderController@processOrder

**Trước:** `$customer->increment('debt_amount', $debtAmount)`

**Sau:**
```php
app(CustomerDebtService::class)->recordSale(
    $customer->id,
    (float) $debtAmount,
    $invoice,
    "Ghi nợ khi chuyển đơn hàng {$order->code} thành hóa đơn {$invoice->code}",
    ['order_id' => $order->id]
);
```

- **`priorDeposit`/`newPayment` giữ thế nào?** Toàn bộ logic vẫn ở controller: `$totalPaid = $priorDeposit + $newPayment`. Service chỉ ghi ledger cho phần `$debtAmount = $order->total_payment - $totalPaid`. CashFlow chỉ tạo cho `newPayment > 0` (giữ nguyên — không double).

---

## 6. Kết quả test

### 6.1. RR-06

| Test | Trước Step 19.1B | Sau Step 19.1B |
|---|---|---|
| `customer_debt_schema_and_model_should_exist` | ❌ FAIL (no model) | ✅ PASS |
| `invoice_credit_sale_should_create_customer_debt_transaction` | ❌ FAIL (no row) | ✅ PASS |
| `pos_credit_sale_should_create_customer_debt_transaction` | ❌ FAIL | ✅ PASS |
| `order_return_should_create_customer_debt_decrease_transaction` | ❌ FAIL | ✅ PASS |
| `cancel_invoice_should_create_customer_debt_reverse_transaction` | ❌ FAIL | ✅ PASS |
| **Tổng** | 0 PASS, 5 FAIL | ✅ **5 PASS, 0 FAIL** (14 assertions, 0.76s) |

### 6.2. Audit regression

| Test | Kết quả |
|---|---|
| `RR02InvoicePosCharacterizationTest` | ✅ 5 PASS (48) |
| `RR13OrderConvertStockTest` | ✅ 4 PASS (19) |
| `CancelInvoiceTest` | ✅ 10 PASS (20) |
| `RR01ReportControllerRegressionTest` | ✅ 8 PASS (9) |
| `RR01SupplierDualRoleRegressionTest` | ✅ 2 PASS (4) |
| `RR01CashFlowCancelledRegressionTest` | ✅ 4 PASS (4) |
| `RR03StockTransferTest` | ✅ 5 PASS (12) |
| `RR03StockTransferRouteTest` | ✅ 3 PASS (10) |
| `RR04StockTakeTest` | ✅ 5 PASS (12) |
| `RR05MovingAvgCostingZeroStockTest` | ✅ 5 PASS (15) |
| `RR05SerialImeiCostingTest` | ✅ 4 PASS (16) |
| `RR07RepairPartsTest` | ✅ 4 PASS (9) |
| `RR08OrderReturnSerialRollbackTest` | ✅ 4 PASS (15) |
| `RR09DamageStockTest` | ✅ 5 PASS (12) |
| `RR10CashFlowDeletionTest` | ✅ 5 PASS (12) |
| `RR11OrderReturnQtyTest` | ✅ 4 PASS (8) |
| `RR12StockTransferCancelReceivedTest` | ✅ 5 PASS (23) |
| **Tổng audit regression** | ✅ **82 PASS** |

### 6.3. Tổng

| Mục | Kết quả |
|---|---|
| **RR-06** | ✅ 5 PASS, 0 FAIL |
| **Audit regression (P0+P1+RR13)** | ✅ 82 PASS |
| **Tổng tests sau Bước 19.1B** | ✅ **87 PASS, 0 FAIL** |

---

## 7. Direct debt update còn lại (sau Step 19.1B)

Scan `->increment('debt_amount'|->decrement('debt_amount'|debt_amount\s*[+\-]=|update(['debt_amount'`:

| File | Dòng | Method | Trạng thái |
|---|---|---|---|
| `app/Http/Controllers/CustomerController.php` | 207, 210 | merge (debt_amount + supplier_debt_amount) | 🔵 Step 19.1C |
| `app/Http/Controllers/CustomerController.php` | 469 | `addDebt` (manual) | 🔵 Step 19.1C |
| `app/Http/Controllers/CustomerController.php` | 521 | `reduceDebt` / payment | 🔵 Step 19.1C |
| `app/Http/Controllers/CustomerController.php` | 591 | `setDebt` (set thẳng) | 🔵 Step 19.1C |
| `app/Http/Controllers/CustomerController.php` | 642, 645 | merge (debt_amount + supplier_debt_amount) | 🔵 Step 19.1C |
| `app/Http/Controllers/InvoiceController.php` | 364 | `update` — `oldCustomer->decrement` (đổi customer) | 🔵 Step 19.1C |
| `app/Http/Controllers/InvoiceController.php` | 377 | `update` — `newCustomer->increment` (debt diff) | 🔵 Step 19.1C |
| `app/Http/Controllers/InvoiceController.php` | 382 | `update` — `newCustomer->increment` (full debt) | 🔵 Step 19.1C |

Còn lại (không thuộc RR-06 — supplier debt hoặc purchase debt):
- `PurchaseController` lines 345, 573, 598, 704: `supplier_debt_amount` (NCC, không phải customer)
- `PurchaseReturnController` 252, 468: `supplier_debt_amount`
- `SupplierController` 470, 487: `purchase->decrement('debt_amount', ...)` (debt trên Purchase model, không phải Customer)

→ **8 chỗ direct customer debt update còn lại** (5 trong CustomerController + 3 trong InvoiceController@update). Phạm vi 19.1C.

---

## 8. Rủi ro còn lại

| # | Khu vực | Mức độ | Ghi chú |
|---|---|---|---|
| 1 | `InvoiceController@update` 3 patches debt | 🔵 Step 19.1C | Logic phức tạp (đổi customer, đổi total). Có thể tạo 2 transaction (decrement old + increment new) hoặc 1 adjustment. |
| 2 | `CustomerController` manual adjustment (5 chỗ) | 🔵 Step 19.1C | `addDebt`, `reduceDebt`, `setDebt`, merge. |
| 3 | Closure RR-06 chưa làm | 🔵 Step 19.1C | Còn 8 chỗ direct update — chưa thể closure đầy đủ. |
| 4 | Legacy `customer_debts` production | P3 | Nếu production có data cũ trong bảng này (từ trước khi codebase ngừng dùng), có thể cần reconcile/backfill. |
| 5 | Reports đọc `debt_amount` | P3 | Có thể migrate sang đọc ledger để có balance theo thời điểm (running balance). |
| 6 | Supplier vs Customer debt cho dual-role | P3 | Customer dual-role (vừa khách vừa NCC) hiện debt chia 2 trường `debt_amount` + `supplier_debt_amount`. Pattern OK, không cần đổi ở RR-06. |

---

## 9. Kết luận

✅ **RR-06 core đã fixed.**

- Model `CustomerDebt` + `CustomerDebtService` sẵn sàng dùng cho toàn hệ thống.
- 4 luồng core đã refactor: Invoice/POS sale (qua InvoiceSaleService), OrderReturn store + cancel, InvoiceController cancel, OrderController processOrder.
- **5/5 RR-06 tests PASS** + **82 audit regression PASS** = 87/87.
- Pattern thống nhất với `SupplierDebtTransaction`.

⚠️ **Chưa thể closure RR-06** vì còn 8 chỗ direct debt update:
- `CustomerController` (5 chỗ): manual adjustment + merge.
- `InvoiceController@update` (3 chỗ): đổi customer / đổi total.

→ **Step 19.1C** sẽ:
1. Refactor `CustomerController` manual adjustments (`addDebt`, `reduceDebt`, `setDebt`) qua `recordAdjustment` / `recordPayment`.
2. Refactor `CustomerController` merge (2 chỗ) — phức tạp, có thể dùng `recordAdjustment` opening_balance.
3. Refactor `InvoiceController@update` (3 chỗ) — đổi customer / debt diff.
4. Cập nhật RISK_REGISTER → RR-06 = ✅ Fixed/Verified.
5. Tạo `RR-06-CLOSURE-REPORT.md`.

**Có chuyển sang Step 19.1C?** ✅ Có.

---

## 10. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Mục 4 (công nợ) |
| `docs/audit/RISK_REGISTER.md` | RR-06 P2 |
| `docs/test-cases/RR-06-customer-debt-ledger.md` | Test case spec |
| `docs/audit/STEP-19.1A-RR06-CUSTOMER-DEBT-LEDGER-TEST-RESULTS.md` | Test chứng minh lỗi (0 PASS, 5 FAIL) |
| `docs/audit/STEP-19.1B-RR06-CUSTOMER-DEBT-LEDGER-CORE-FIX-RESULTS.md` | File này (5 PASS, 0 FAIL) |
| `tests/Feature/CustomerDebt/RR06CustomerDebtLedgerTest.php` | Feature test (5 PASS) |
| `app/Models/CustomerDebt.php` | Model mới |
| `app/Services/CustomerDebtService.php` | Service mới |
| `app/Services/InvoiceSaleService.php` | Refactored |
| `app/Http/Controllers/OrderReturnController.php` | Refactored store + cancel |
| `app/Http/Controllers/InvoiceController.php` | Refactored cancel |
| `app/Http/Controllers/OrderController.php` | Refactored processOrder |
| `app/Models/SupplierDebtTransaction.php` | Pattern tham chiếu |
