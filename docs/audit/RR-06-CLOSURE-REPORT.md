# RR-06 Closure Report — Customer debt phải có ledger và service

> **Mã rủi ro:** RR-06
> **Mức độ ban đầu:** 🔵 P2 — Medium (cải thiện kiến trúc, audit trail)
> **Trạng thái cuối:** ✅ **Fixed/Verified**
> **Ngày đóng:** 02/05/2026
> **Test verification:** 87 PASS, 0 FAIL (5 RR-06 + 82 audit regression)

---

## 1. Tóm tắt lỗi ban đầu

- **Bảng `customer_debts` có nhưng không dùng** — migration `2026_03_01_100000_create_customer_debts_table.php` + bổ sung `type` ở `2026_04_09_150000`, schema đầy đủ (`customer_id, order_id, order_return_id, ref_code, amount, debt_total, type, note, created_by, recorded_at`) nhưng codebase không write.
- **Thiếu `CustomerDebt` model** — không có file `app/Models/CustomerDebt.php`.
- **Thiếu `CustomerDebtService`** — không có service tập trung logic.
- **`customers.debt_amount` bị `increment`/`decrement` trực tiếp ở 13 chỗ** trong 6 file controller/service.
- **Không có audit trail** — không truy vết được lịch sử biến động công nợ KH theo thời gian; không reconcile được giữa CashFlow ↔ Invoice ↔ debt.
- **Bất đối xứng với `SupplierDebtTransaction`** — NCC có ledger đầy đủ (model + service usage trong `SupplierController`, `DebtOffsetService`), KH không có dù schema đã sẵn.

---

## 2. Discovery

### Schema `customer_debts` (đã có trước RR-06)

```sql
- id, customer_id (FK), order_id (FK nullable), order_return_id (nullable),
- ref_code, amount (decimal 15,2 signed), debt_total (running balance),
- type (sale/payment/adjustment/return/...),
- note, created_by, recorded_at, timestamps
```

### Trước fix
- Model `CustomerDebt`: ❌
- Service `CustomerDebtService`: ❌
- Bảng được populate: ❌

### 13 chỗ direct update `customers.debt_amount`

| File | Dòng | Method | Pattern |
|---|---|---|---|
| `Services/InvoiceSaleService.php` | 235 | `updateCustomerDebt` | `increment` (sale) |
| `Controllers/InvoiceController.php` | 363, 376, 381 | `update` | `decrement` (old) + `increment` (diff/new) |
| `Controllers/InvoiceController.php` | 502 | `cancel` | `decrement` (reverse) |
| `Controllers/OrderController.php` | 464 | `processOrder` | `increment` (convert) |
| `Controllers/OrderReturnController.php` | 313 | `store` | `decrement` (return) |
| `Controllers/OrderReturnController.php` | 443 | `cancel` | `increment` (re-add) |
| `Controllers/CustomerController.php` | 207 | mergeFromImport | `+=` (merge) |
| `Controllers/CustomerController.php` | 469 | `recordPayment` (manual) | `decrement` |
| `Controllers/CustomerController.php` | 521 | `recordPayment` (auto) | `decrement` |
| `Controllers/CustomerController.php` | 591 | `debtAdjust` | `update(['debt_amount' => $target])` |
| `Controllers/CustomerController.php` | 642 | `merge` | `+=` (merge) |

### Pattern tham chiếu `SupplierDebtTransaction`
- Model: `App\Models\SupplierDebtTransaction`
- Migration: `2026_03_20_155910_create_supplier_debt_transactions_table.php`
- Schema: `supplier_id, code, type, amount, debt_remain, purchase_id, note, user_id, timestamps`
- Được dùng trong `SupplierController` (createDebtTransaction, payment), `DebtOffsetService`, `CustomerController` (dual-role)

---

## 3. Các thay đổi đã thực hiện

| Bước | Nội dung | File liên quan | Kết quả test |
|---|---|---|---|
| **Step 19.1A** | Discovery + viết test chứng minh thiếu ledger | `tests/Feature/CustomerDebt/RR06CustomerDebtLedgerTest.php`, `docs/test-cases/RR-06-customer-debt-ledger.md`, `docs/audit/STEP-19.1A-...-TEST-RESULTS.md`, `docs/audit/RISK_REGISTER.md` | 0 PASS, 5 FAIL |
| **Step 19.1B** | Tạo Model + Service + refactor 4 luồng core | `app/Models/CustomerDebt.php`, `app/Services/CustomerDebtService.php`, `app/Services/InvoiceSaleService.php`, `app/Http/Controllers/OrderReturnController.php`, `app/Http/Controllers/InvoiceController.php` (cancel), `app/Http/Controllers/OrderController.php` (processOrder), `docs/audit/STEP-19.1B-...-FIX-RESULTS.md` | 5 PASS + 82 audit = 87 PASS |
| **Step 19.1C** | Refactor 8 chỗ còn lại + closure | `app/Http/Controllers/CustomerController.php` (5 patches: mergeFromImport, recordPayment×2, debtAdjust, merge), `app/Http/Controllers/InvoiceController.php` (3 patches: update flow), `docs/audit/RISK_REGISTER.md`, `docs/audit/RR-06-CLOSURE-REPORT.md` (file này) | 87/87 PASS |

---

## 4. File đã sửa

| File | Loại | Nội dung sửa |
|---|---|---|
| `app/Models/CustomerDebt.php` | Model mới | Class `CustomerDebt`, table `customer_debts`, fillable 10 fields, casts decimal/datetime, relations `customer()`, `order()`, `orderReturn()` |
| `app/Services/CustomerDebtService.php` | Service mới | 5 methods (`recordSale`, `recordReturn`, `recordPayment`, `recordSaleReversal`, `recordAdjustment`). Core `record()` lock customer + signed amount + tạo ledger + `debt_total` running. Idempotency `abs($amount)<0.01 → return null`. |
| `app/Services/InvoiceSaleService.php` | Refactor | `updateCustomerDebt` nhận thêm `?Invoice $invoice` + gọi `recordSale`. Caller `createSale` truyền invoice. |
| `app/Http/Controllers/OrderReturnController.php` | Refactor (store + cancel) | Import service. `store()` → `recordReturn`; `cancel()` → `recordAdjustment` (amount dương khôi phục nợ). |
| `app/Http/Controllers/InvoiceController.php` | Refactor (cancel + update) | Import service. `cancel()` → `recordSaleReversal`. `update()` (3 patches): old customer `recordAdjustment(-oldDebt)`; same customer `recordAdjustment(debtDiff)`; new customer `recordSale(newDebt)`. |
| `app/Http/Controllers/OrderController.php` | Refactor (processOrder) | Import service. `processOrder()` → `recordSale` với meta `order_id`. |
| `app/Http/Controllers/CustomerController.php` | Refactor (5 patches) | Import service. `mergeFromImport` → `recordAdjustment` cho target. `recordPayment` (manual + auto) → `recordPayment` với ref_code = CashFlow.code. `debtAdjust` → `recordAdjustment` với delta. `merge` → `recordAdjustment` cho target customer. |
| `docs/audit/RISK_REGISTER.md` | Doc | RR-06 status = ✅ Fixed/Verified, P2 closed: 1/1, thêm 3 changelog (19.1A/B/C) |
| `docs/audit/RR-06-CLOSURE-REPORT.md` | Doc | File này |

**Không sửa:** Migration, schema, `SupplierDebtTransaction`, `Customer` model, `MovingAvgCostingService`, `StockMovementService`, các module khác.

---

## 5. CustomerDebtService

### Method
- `recordSale($customerId, $amount, ?Model $reference, ?string $note, array $meta)` — type='sale', signed=`+abs($amount)`
- `recordReturn(...)` — type='return', signed=`-abs($amount)`
- `recordPayment(...)` — type='payment', signed=`-abs($amount)`
- `recordSaleReversal(...)` — type='adjustment', signed=`-abs($amount)`
- `recordAdjustment(int $customerId, float $signedAmount, ?string $note, array $meta)` — type='adjustment', giữ dấu

### Signed amount convention
- `sale` → +
- `return`/`payment`/`saleReversal` → −
- `adjustment` → signed (caller quyết định dấu)

### `debt_total` (running balance)
1. `Customer::lockForUpdate()->find($customerId)`
2. `$customer->debt_amount += $signedAmount; $customer->save()`
3. Ghi `CustomerDebt` với `debt_total = (float) $customer->debt_amount` sau update

### Zero amount
`abs($signedAmount) < 0.01` → return null, không update, không tạo row.

### `lockForUpdate`
Service tự bọc `DB::transaction()` + lock customer → an toàn khi gọi trong/ngoài transaction parent.

### `created_by` / `recorded_at`
- `created_by = auth()->id()` (null nếu không login)
- `recorded_at = now()`

---

## 6. Luồng đã refactor

| Luồng | Trước | Sau |
|---|---|---|
| Invoice/POS sale (qua `InvoiceSaleService::updateCustomerDebt`) | `$customer->increment('debt_amount', $debtAmount)` | `recordSale($customerId, $debtAmount, $invoice)` |
| Order convert (`OrderController@processOrder`) | `$customer->increment('debt_amount', $debtAmount)` | `recordSale($customerId, $debtAmount, $invoice, ['order_id' => $order->id])` |
| OrderReturn store | `$customer->decrement('debt_amount', $total)` | `recordReturn($customerId, $total, $return)` |
| OrderReturn cancel | `$customer->increment('debt_amount', $return->total)` | `recordAdjustment($customerId, +$return->total, ...)` (khôi phục nợ) |
| Invoice cancel | `$customer->decrement('debt_amount', $debtAmount)` | `recordSaleReversal($customerId, $debtAmount, $invoice)` |
| Invoice update — đổi customer (old) | `$oldCustomer->decrement('debt_amount', $oldDebt)` | `recordAdjustment($oldCustomerId, -$oldDebt, ...)` |
| Invoice update — same customer | `$customer->increment('debt_amount', $debtDiff)` | `recordAdjustment($customerId, $debtDiff, ...)` |
| Invoice update — new customer | `$newCustomer->increment('debt_amount', $newDebt)` | `recordSale($newCustomerId, $newDebt, $invoice, ...)` |
| Customer manual `addDebt`/`recordPayment` | `decrement('debt_amount', $amount)` | `recordPayment($customerId, $amount, ..., ['ref_code' => $cf->code])` |
| Customer `debtAdjust` (set thẳng) | `update(['debt_amount' => $target])` | `recordAdjustment($customerId, $debtDelta, ..., ['ref_code' => $cf->code])` |
| Customer `mergeFromImport` | `$existing->debt_amount += $source->debt_amount` | `recordAdjustment($existingId, $sourceDebt, ['ref_code' => 'MERGE-IMPORT-' . $sourceId])` |
| Customer `merge` | `$target->debt_amount += $customer->debt_amount` | `recordAdjustment($targetId, $sourceDebt, ['ref_code' => 'MERGE-CUSTOMER-' . $sourceId])` |

---

## 7. Scan direct update sau refactor

```
Grep: ->increment('debt_amount'|->decrement('debt_amount'|debt_amount\s*[+\-]=|update\(['debt_amount'
```

Kết quả còn lại trong `app/`:

| File | Dòng | Pattern | Đánh giá |
|---|---|---|---|
| `CustomerController.php` | 221 | `$existing->supplier_debt_amount += ...` | ✅ OK — supplier debt (không thuộc RR-06) |
| `CustomerController.php` | 692 | `$target->supplier_debt_amount += ...` | ✅ OK — supplier debt |
| `PurchaseController.php` | 345, 573, 598, 704 | `supplier_debt_amount` | ✅ OK — supplier debt |
| `PurchaseReturnController.php` | 252, 468 | `supplier_debt_amount` | ✅ OK — supplier debt |
| `SupplierController.php` | 470, 487 | `$purchase->decrement('debt_amount', ...)` | ✅ OK — `Purchase.debt_amount` (debt trên Purchase model, không phải Customer.debt_amount) |

→ **0 chỗ direct customer debt update còn lại** ngoài `CustomerDebtService`. RR-06 sạch sẽ.

---

## 8. Test verification

### Môi trường
```
APP_ENV=testing, DB_CONNECTION=mysql, DB_HOST=127.0.0.1, DB_PORT=3319, DB_DATABASE=sales_test
```

### Kết quả final (02/05/2026)

| Nhóm test | File | Tests | Assertions | Kết quả |
|---|---|---:|---:|---|
| RR-06 customer debt ledger | `RR06CustomerDebtLedgerTest.php` | 5 | 14 | ✅ **5 PASS** |
| RR-02 invoice/POS characterization | `RR02InvoicePosCharacterizationTest.php` | 5 | 48 | ✅ **5 PASS** |
| RR-13 order convert | `RR13OrderConvertStockTest.php` | 4 | 19 | ✅ **4 PASS** |
| RR-01 cancel invoice | `CancelInvoiceTest.php` | 10 | 20 | ✅ **10 PASS** |
| RR-01 report P0 | `RR01ReportControllerRegressionTest.php` | 8 | 9 | ✅ **8 PASS** |
| RR-01 supplier dual-role | `RR01SupplierDualRoleRegressionTest.php` | 2 | 4 | ✅ **2 PASS** |
| RR-01 cashflow cancelled | `RR01CashFlowCancelledRegressionTest.php` | 4 | 4 | ✅ **4 PASS** |
| RR-03 stock transfer | `RR03StockTransferTest.php` | 5 | 12 | ✅ **5 PASS** |
| RR-03 stock transfer route | `RR03StockTransferRouteTest.php` | 3 | 10 | ✅ **3 PASS** |
| RR-04 stock take | `RR04StockTakeTest.php` | 5 | 12 | ✅ **5 PASS** |
| RR-05 unit | `RR05MovingAvgCostingZeroStockTest.php` | 5 | 15 | ✅ **5 PASS** |
| RR-05 feature serial | `RR05SerialImeiCostingTest.php` | 4 | 16 | ✅ **4 PASS** |
| RR-07 repair parts | `RR07RepairPartsTest.php` | 4 | 9 | ✅ **4 PASS** |
| RR-08 serial rollback | `RR08OrderReturnSerialRollbackTest.php` | 4 | 15 | ✅ **4 PASS** |
| RR-09 damage | `RR09DamageStockTest.php` | 5 | 12 | ✅ **5 PASS** |
| RR-10 cashflow deletion | `RR10CashFlowDeletionTest.php` | 5 | 12 | ✅ **5 PASS** |
| RR-11 order return qty | `RR11OrderReturnQtyTest.php` | 4 | 8 | ✅ **4 PASS** |
| RR-12 stock transfer cancel received | `RR12StockTransferCancelReceivedTest.php` | 5 | 23 | ✅ **5 PASS** |
| **Tổng** | | **87** | **262** | ✅ **87 PASS, 0 FAIL** |

---

## 9. Quy ước mới sau RR-06

1. **KHÔNG được update `customers.debt_amount` trực tiếp** ngoài `CustomerDebtService`. Mọi thay đổi công nợ KH phải đi qua service.
2. **Mọi thay đổi công nợ KH phải tạo `customer_debts` row** với:
   - `customer_id`, `amount` (signed), `debt_total` (running balance), `type` (sale/return/payment/adjustment), `ref_code`, `note`, `created_by`, `recorded_at`.
3. **`amount` là signed** — service ensures dấu theo type (sale=+, return/payment/saleReversal=−, adjustment=signed).
4. **`debt_total` là running balance** sau update — đọc ledger đủ để rebuild trạng thái công nợ tại bất kỳ thời điểm nào.
5. **Service lock customer** (`Customer::lockForUpdate()`) trước khi update + tạo row → atomic + race-safe.
6. **`CashFlow` và `CustomerDebt` là 2 ledger độc lập** nhưng có thể reconcile qua `ref_code` (CashFlow.code = CustomerDebt.ref_code cho payment/adjustment).
7. **Pattern thống nhất với `SupplierDebtTransaction`** — cả KH và NCC đều có ledger riêng.
8. **Idempotency:** service trả null nếu `abs($amount) < 0.01`, không tạo zero-amount row.

---

## 10. Rủi ro còn lại đưa vào backlog

| # | Khu vực | Mức độ | Ghi chú |
|---|---|---|---|
| 1 | Legacy `customer_debts` production | Backward compat | Migration backfill type cho thấy bảng từng được dùng (legacy data). Cần Artisan command reconcile/backfill nếu production có `customer_debts` rows cũ không khớp `customers.debt_amount`. |
| 2 | Reports đọc ledger | P3 | Có thể migrate báo cáo công nợ sang đọc `customer_debts` để có balance theo thời điểm + lịch sử biến động. |
| 3 | Opening balance import | P3 | Khi import KH mới có dư nợ ban đầu, nên tạo `CustomerDebt` opening_balance row thay vì set `debt_amount` trực tiếp. |
| 4 | UI lịch sử công nợ KH | P3 | Trang `Customers/Show` có thể thêm tab "Lịch sử công nợ" đọc từ `customer_debts`. |
| 5 | Dual-role reconciliation | P3 | Customer dual-role có cả `debt_amount` (KH nợ) + `supplier_debt_amount` (ta nợ). 2 ledger riêng (`customer_debts` + `supplier_debt_transactions`) — báo cáo tổng có thể cần view union. |
| 6 | Test multi-flow + reconcile | P3 | Có thể bổ sung test sale → return → payment → cancel trong cùng customer để verify running balance + reconcile. |

---

## 11. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Mục 4 (công nợ — yêu cầu mọi thay đổi phải có chứng từ/log) |
| `docs/audit/RISK_REGISTER.md` | Bảng rủi ro tổng — RR-06 = ✅ Fixed/Verified |
| `docs/test-cases/RR-06-customer-debt-ledger.md` | Test case spec |
| `docs/audit/STEP-19.1A-RR06-CUSTOMER-DEBT-LEDGER-TEST-RESULTS.md` | Discovery + test fail (0/5) |
| `docs/audit/STEP-19.1B-RR06-CUSTOMER-DEBT-LEDGER-CORE-FIX-RESULTS.md` | Core fix (5/5 + 82) |
| `docs/audit/RR-06-CLOSURE-REPORT.md` | File này |
| `tests/Feature/CustomerDebt/RR06CustomerDebtLedgerTest.php` | Feature test (5 PASS) |
| `app/Models/CustomerDebt.php` | Model mới |
| `app/Services/CustomerDebtService.php` | Service mới |
| `app/Services/InvoiceSaleService.php` | Refactored sale |
| `app/Http/Controllers/OrderReturnController.php` | Refactored store + cancel |
| `app/Http/Controllers/InvoiceController.php` | Refactored cancel + update |
| `app/Http/Controllers/OrderController.php` | Refactored processOrder |
| `app/Http/Controllers/CustomerController.php` | Refactored 5 patches |
| `app/Models/SupplierDebtTransaction.php` | Pattern tham chiếu |
| `database/migrations/2026_03_01_100000_create_customer_debts_table.php` | Schema có sẵn |

---

## 12. Kết luận

✅ **RR-06 đã Fixed/Verified.**

- `CustomerDebt` model + `CustomerDebtService` sẵn sàng dùng cho toàn hệ thống.
- 13/13 chỗ direct customer debt update đã chuyển qua service.
- Scan: 0 direct customer debt update còn lại ngoài service.
- **87/87 tests PASS** — 18 file test, 262 assertions.
- Pattern thống nhất với `SupplierDebtTransaction`.
- Audit trail đầy đủ — mọi biến động công nợ KH đều có row trong `customer_debts`.

### Tổng kết tiến độ audit (final)

| Mã | Module | Mức | Trạng thái |
|---|---|---|---|
| RR-01 | Invoice cancel | P0 | ✅ Fixed/Verified |
| RR-02 | Invoice/POS duplicate | P1 | ✅ Fixed/Verified |
| RR-03 | Stock transfer | P0 | ✅ Fixed/Verified |
| RR-04 | Stock take | P0 | ✅ Fixed/Verified |
| RR-05 | Costing zero stock | P1 | ✅ Fixed/Verified |
| RR-06 | Customer debt | P2 | ✅ **Fixed/Verified (Bước 19.1C)** |
| RR-07 | Repair parts | P0 | ✅ Fixed/Verified |
| RR-08 | OrderReturn rollback serial | P1 | ✅ Fixed/Verified |
| RR-09 | Damage | P1 | ✅ Fixed/Verified |
| RR-10 | CashFlow deletion | P0 | ✅ Fixed/Verified |
| RR-11 | OrderReturn qty | P0 | ✅ Fixed/Verified |
| RR-12 | StockTransfer cost snapshot | P1 | ✅ Fixed/Verified |
| RR-13 | Order convert | P1 | ✅ Fixed/Verified |

**🎉 Toàn bộ 13/13 rủi ro đã đóng** (6 P0 + 6 P1 + 1 P2). Risk Register sạch sẽ.

**Có thể chuyển sang final audit summary** — tạo `FINAL-AUDIT-SUMMARY-REPORT.md` tổng hợp toàn bộ Bước 1-19.
