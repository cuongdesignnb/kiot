# STEP-19.1A — RR-06 Customer Debt Ledger Test Results

> **Bước:** 19.1A — Discovery + viết test chứng minh thiếu customer debt ledger
> **Ngày:** 02/05/2026
> **Phạm vi:** Chỉ discovery + viết test. **Không sửa business code, không tạo migration/model/service.**

---

## 1. Mục tiêu

Chứng minh customer debt hiện chưa có ledger/service đủ để truy vết — `customers.debt_amount` được increment/decrement trực tiếp ở 9 chỗ, không ghi vào `customer_debts` (mặc dù bảng đã tồn tại).

→ Kết quả: **5/5 test FAIL** chứng minh RR-06.

---

## 2. Discovery

| Nội dung | Kết quả |
|---|---|
| Có `customer_debts` chưa | ✅ **Đã tồn tại từ migration `2026_03_01_100000_create_customer_debts_table.php`** — schema: `id, customer_id, order_id, order_return_id, ref_code, amount, debt_total, type (sale/payment/adjustment/return), note, created_by, recorded_at, timestamps` |
| Có `CustomerDebt` model chưa | ❌ **Không** — `app/Models/CustomerDebt*.php` không tồn tại |
| Có `CustomerDebtService` chưa | ❌ **Không** |
| Supplier debt ledger hiện có gì | ✅ Đầy đủ: `App\Models\SupplierDebtTransaction`, migration `2026_03_20_155910_create_supplier_debt_transactions_table.php`, được dùng nhiều trong `SupplierController` (createDebtTransaction, payment), `DebtOffsetService`, `CustomerController` (cho dual-role customer/supplier). Schema: `supplier_id, code, type, amount, debt_remain, purchase_id, note, user_id, timestamps`. |
| Các nơi increment/decrement customer debt (9 chỗ) | `InvoiceSaleService:235` (sale), `InvoiceController:363/376/381/502` (update + cancel), `OrderController:464` (processOrder), `OrderReturnController:313/443` (return + cancel return), `CustomerController:469/521/591/207/642` (manual + merge) |
| Luồng bán hàng qua `InvoiceSaleService` | `updateCustomerDebt()` chỉ `$customer->increment('debt_amount', $debtAmount)` — không ghi `customer_debts` |
| Luồng trả hàng `OrderReturnController` | `store()` dòng 313: `$customer->decrement('debt_amount', $validated['total'])` — không ghi |
| Luồng Order convert | `processOrder()` dòng 464: `$customer->increment('debt_amount', $debtAmount)` — không ghi |
| Luồng `CustomerController` manual adjustment | `addDebt`/`reduceDebt`/`setDebt` (dòng 469, 521, 591): `$customer->decrement/update(['debt_amount'])` — không ghi |
| Test hiện có cho customer debt ledger | ❌ Không. Các P0/P1 test (RR-01 SupplierDualRole, RR-11) chỉ assert `customers.debt_amount`, không assert `customer_debts` row |
| Rủi ro phát hiện | (1) **Mất audit trail** — không truy vết được lịch sử biến động công nợ. (2) Không reconcile được giữa `cash_flows` ↔ `customer_debts` ↔ `invoices`. (3) Migration backfill type cho thấy bảng từng được dùng (legacy) → có thể có data cũ trong production nhưng codebase hiện không write. (4) Bất đối xứng so với supplier (đã có ledger). |

---

## 3. Dữ liệu test

| Mục | Giá trị |
|---|---|
| Customer | `debt_amount=0`, `total_spent=0` |
| Product | `cost=100k`, `stock=100`, `total=10M`, `has_serial=false` |
| Invoice payload | `total=1M`, `customer_paid=400k` → debt 600k |
| POS payload | tương tự Invoice |
| OrderReturn payload | qty=1, `total=200k` |
| Cancel invoice | qua `route('invoices.destroy')` |

---

## 4. Test đã tạo

`tests/Feature/CustomerDebt/RR06CustomerDebtLedgerTest.php` — 5 test:

| Test | Kỳ vọng | Thực tế | Kết quả |
|---|---|---|---|
| `customer_debt_schema_and_model_should_exist` | Bảng + Model `CustomerDebt`/`CustomerDebtTransaction` tồn tại | Bảng có ✅, Model ❌ | ❌ FAIL |
| `invoice_credit_sale_should_create_customer_debt_transaction` | Có row `customer_debts` với `ref_code=invoice.code, amount=600k, type=sale` | `customer_debts` rỗng | ❌ FAIL (`0 !> 0`) |
| `pos_credit_sale_should_create_customer_debt_transaction` | Tương tự qua POS | rỗng | ❌ FAIL |
| `order_return_should_create_customer_debt_decrease_transaction` | Có row decrease/return type | rỗng | ❌ FAIL |
| `cancel_invoice_should_create_customer_debt_reverse_transaction` | Có row reverse | rỗng | ❌ FAIL |

---

## 5. Kết quả chạy test

```
Tests:    5 failed (10 assertions)
Duration: 0.74s
```

| Mục | Kết quả |
|---|---|
| Tổng số test | 5 |
| Pass | 0 |
| Fail | 5 |
| Skipped | 0 |

→ **5/5 FAIL chứng minh RR-06.** Customer debt thay đổi đúng `debt_amount` nhưng không ghi `customer_debts` → mất audit trail.

---

## 6. Nguyên nhân fail

| Test fail | Nguyên nhân |
|---|---|
| TC-01 (model) | `App\Models\CustomerDebt` chưa được tạo. |
| TC-02 (invoice sale) | `InvoiceSaleService::updateCustomerDebt()` dòng 235 chỉ `$customer->increment('debt_amount', $debtAmount)` — không ghi `customer_debts` |
| TC-03 (POS sale) | POS dùng cùng `InvoiceSaleService` → cùng nguyên nhân TC-02 |
| TC-04 (order return) | `OrderReturnController@store` dòng 313 chỉ `$customer->decrement('debt_amount', $validated['total'])` |
| TC-05 (cancel invoice) | `InvoiceController@cancel` dòng 502 chỉ `$customer->decrement('debt_amount', $debtAmount)` |

→ **Bảng `customer_debts` tồn tại nhưng KHÔNG được populate ở bất kỳ luồng nào.**

---

## 7. Regression

| Test | Kết quả |
|---|---|
| `RR02InvoicePosCharacterizationTest` | ✅ 5 PASS |
| `RR13OrderConvertStockTest` | ✅ 4 PASS |
| `CancelInvoiceTest` | ✅ 10 PASS |
| `RR11OrderReturnQtyTest` | ✅ 4 PASS |
| `RR10CashFlowDeletionTest` | ✅ 5 PASS |

→ Không có hồi quy do Bước 19.1A (vì không sửa code).

---

## 8. Đề xuất sửa Step 19.1B

### A. Tạo Model + Service

1. **`app/Models/CustomerDebt.php`** (tên model match với schema `customer_debts`):
   ```php
   class CustomerDebt extends Model
   {
       protected $fillable = [
           'customer_id', 'order_id', 'order_return_id', 'ref_code',
           'amount', 'debt_total', 'type', 'note', 'created_by', 'recorded_at',
       ];
       protected $casts = [
           'amount' => 'decimal:2',
           'debt_total' => 'decimal:2',
           'recorded_at' => 'datetime',
       ];
       public function customer() { return $this->belongsTo(Customer::class); }
   }
   ```

2. **`app/Services/CustomerDebtService.php`** với methods:
   - `recordSale($customerId, $amount, $invoice, $note=null)` — type='sale', amount > 0
   - `recordReturn($customerId, $amount, $orderReturn, $note=null)` — type='return', amount < 0
   - `recordPayment($customerId, $amount, $refCode, $note=null)` — type='payment', amount < 0
   - `recordAdjustment($customerId, $amount, $note=null)` — type='adjustment'
   - `recordSaleReversal($customerId, $amount, $invoice, $note=null)` — type='adjustment' với amount âm để đảo sale.
   - Mỗi method: `DB::transaction(...)`:
     - `Customer::lockForUpdate()->find($customerId)`
     - Update `customers.debt_amount += $amount` (signed)
     - Tạo `CustomerDebt` row với `debt_total = customer.debt_amount sau update`, `recorded_at = now()`, `created_by = auth()->user()?->id`
     - Return `CustomerDebt` model

### B. Refactor các luồng

1. **`InvoiceSaleService::updateCustomerDebt`** dòng 235: thay `$customer->increment('debt_amount', $debtAmount)` bằng `app(CustomerDebtService::class)->recordSale($customerId, $debtAmount, $invoice)`. Service handles cả update debt_amount + ghi ledger.
2. **`OrderReturnController@store`** dòng 313: dùng `recordReturn(...)`.
3. **`OrderReturnController@cancel`** dòng 443: dùng `recordSaleReversal()` hoặc `recordAdjustment(...)`.
4. **`InvoiceController@cancel`** dòng 502: dùng `recordSaleReversal(...)`.
5. **`InvoiceController@update`** dòng 363/376/381 (3 patches phức tạp — đổi customer hoặc đổi total): có thể tạo 2 transaction (decrease cho old, increase cho new).
6. **`OrderController@processOrder`** dòng 464: dùng `recordSale(...)` (cho phần debt phát sinh khi convert).
7. **`CustomerController` manual adjustments** (dòng 469, 521, 591): dùng `recordAdjustment()` hoặc `recordPayment()`.
8. **`CustomerController` merge** (dòng 207, 642): tạo opening_balance transaction cho customer đích.

### C. Test gates Step 19.1B

- `RR06CustomerDebtLedgerTest` 5/5 PASS.
- `RR02InvoicePosCharacterizationTest` 5/5 PASS (giữ behavior).
- `RR11OrderReturnQtyTest` 4/4 PASS.
- `CancelInvoiceTest` 10/10 PASS.
- Toàn bộ P0/P1 audit regression PASS (82 tests).

### D. Phạm vi không xử lý

- Không tạo migration mới (bảng đã có).
- Không thay đổi `cash_flows` schema.
- Không refactor `InvoiceController@update` quá rộng — có thể chỉ wire vào service tối thiểu.
- Không xử lý opening balance migration cho data cũ — backlog P3.

---

## 9. Kết luận

✅ **RR-06 đã được chứng minh.**

- Bảng `customer_debts` tồn tại nhưng KHÔNG được dùng.
- Model `CustomerDebt` chưa có.
- Service `CustomerDebtService` chưa có.
- 9 chỗ update `customers.debt_amount` trực tiếp, mất audit trail.
- Bất đối xứng với `SupplierDebtTransaction` (đã có ledger đầy đủ).

**Đủ điều kiện chuyển sang Step 19.1B?** ✅ Có.

**Phạm vi sửa Step 19.1B:**
1. Tạo `App\Models\CustomerDebt` (không cần migration).
2. Tạo `App\Services\CustomerDebtService` với 4-5 methods chính.
3. Refactor `InvoiceSaleService::updateCustomerDebt` → gọi service.
4. Refactor `OrderReturnController@store` + `@cancel` → gọi service.
5. Refactor `InvoiceController@cancel` → gọi service.
6. Refactor `OrderController@processOrder` → gọi service (qua `InvoiceSaleService` đã wire).
7. (Optional) Refactor `CustomerController` manual adjustment + merge → gọi service.

→ Có thể chia nhỏ thành 19.1B (core: model + service + InvoiceSaleService + OrderReturn + InvoiceCancel) và 19.1C (CustomerController + InvoiceUpdate) nếu phạm vi rộng. Quyết định ở Step 19.1B.

---

## 10. Tài liệu liên quan

| File | Nội dung |
|---|---|
| `AGENT_RULES.md` | Mục 4 (công nợ) — yêu cầu mọi thay đổi công nợ phải có chứng từ/log |
| `docs/audit/RISK_REGISTER.md` | RR-06 P2 — chưa xử lý |
| `docs/test-cases/RR-06-customer-debt-ledger.md` | Test case spec |
| `tests/Feature/CustomerDebt/RR06CustomerDebtLedgerTest.php` | Feature test (0 PASS, 5 FAIL) |
| `app/Models/SupplierDebtTransaction.php` | Pattern tham chiếu |
| `database/migrations/2026_03_20_155910_create_supplier_debt_transactions_table.php` | Pattern tham chiếu |
| `database/migrations/2026_03_01_100000_create_customer_debts_table.php` | Schema có sẵn cho RR-06 |
| `database/migrations/2026_04_09_150000_add_type_to_customer_debts_table.php` | Migration thêm `type` + `order_return_id` |
| `app/Services/InvoiceSaleService.php` | `updateCustomerDebt` cần refactor |
| `app/Http/Controllers/InvoiceController.php` | `cancel` + `update` cần refactor |
| `app/Http/Controllers/OrderReturnController.php` | `store` + `cancel` cần refactor |
| `app/Http/Controllers/OrderController.php` | `processOrder` cần refactor |
| `app/Http/Controllers/CustomerController.php` | Manual adjustments cần refactor |
