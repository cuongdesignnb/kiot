# RR-06 — Customer debt phải có ledger và service

> **Mã rủi ro:** RR-06
> **Mức độ:** P2 — Cải thiện kiến trúc, reconcile công nợ, truy vết lịch sử
> **Ngày tạo:** 02/05/2026

---

## Mục tiêu

Đảm bảo **mọi thay đổi công nợ khách hàng** vừa cập nhật `customers.debt_amount` vừa ghi `customer_debts` để truy vết.

## Discovery hiện trạng

| Mục | Kết quả |
|---|---|
| Bảng `customer_debts` | ✅ Đã tồn tại (migration `2026_03_01_100000_create_customer_debts_table.php`) — columns: `id, customer_id, order_id, order_return_id, ref_code, amount, debt_total, type (sale/payment/adjustment/return), note, created_by, recorded_at, timestamps` |
| Model `CustomerDebt` | ❌ **Chưa có** (`app/Models/CustomerDebt*.php` không tồn tại) |
| Service `CustomerDebtService` | ❌ **Chưa có** |
| Bảng có được populate không | ❌ **Không** — `Grep customer_debts\|CustomerDebt::` không có match nào trong `app/` |
| Pattern tham chiếu `SupplierDebtTransaction` | ✅ Có (`app/Models/SupplierDebtTransaction.php` + `database/migrations/2026_03_20_155910`). Được dùng nhiều: `SupplierController` (createDebtTransaction, payment flow), `DebtOffsetService`, `CustomerController` (cho dual-role). |

### Các nơi đang `increment`/`decrement` `customers.debt_amount` trực tiếp (9 chỗ)

| File | Dòng | Method | Reference |
|---|---|---|---|
| `app/Services/InvoiceSaleService.php` | 235 | `updateCustomerDebt` | Bán hàng (Invoice + POS qua service) |
| `app/Http/Controllers/InvoiceController.php` | 363, 376, 381 | `update` | Sửa hóa đơn (đổi customer / đổi total) |
| `app/Http/Controllers/InvoiceController.php` | 502 | `cancel` | Hủy hóa đơn (đảo nợ) |
| `app/Http/Controllers/OrderController.php` | 464 | `processOrder` | Convert Order → Invoice |
| `app/Http/Controllers/OrderReturnController.php` | 313 | `store` | Trả hàng (giảm nợ) |
| `app/Http/Controllers/OrderReturnController.php` | 443 | `cancel` | Hủy trả hàng (đảo lại) |
| `app/Http/Controllers/CustomerController.php` | 469, 521, 591 | manual adjustment | Tăng/giảm/set thủ công |
| `app/Http/Controllers/CustomerController.php` | 207, 642 | merge | Gộp customer |

→ Tất cả update trực tiếp `customers.debt_amount`, **không** ghi vào `customer_debts`. Mất audit trail.

---

## Test cases

### TC-RR06-01: Customer debt ledger model phải tồn tại

**Kỳ vọng:**
- File `app/Models/CustomerDebt.php` hoặc tương đương tồn tại.
- Class có thể gọi `CustomerDebt::query()`.

→ Hiện tại FAIL — model chưa có.

### TC-RR06-02: Bán hàng nợ phải ghi customer_debts row

**Setup:**
- Customer `debt_amount = 0`.
- Tạo Invoice qua `route('invoices.store')` với `total = 1_000_000`, `customer_paid = 400_000`.

**Kỳ vọng:**
- `customer.debt_amount = 600_000` (đã đúng từ InvoiceSaleService).
- Có row trong `customer_debts`:
  - `customer_id = customer.id`
  - `amount = 600_000` (positive cho sale)
  - `type = 'sale'`
  - `ref_code = invoice.code`
  - `debt_total = 600_000` (running balance after)

→ Hiện tại FAIL — không có row trong `customer_debts`.

### TC-RR06-03: POS bán hàng nợ phải ghi customer_debts row

Tương tự TC-02 nhưng qua POS endpoint `/api/pos/checkout`. Cùng kỳ vọng (POS dùng `InvoiceSaleService` từ RR-02).

### TC-RR06-04: Trả hàng khách phải ghi customer_debts decrease row

**Setup:**
- Customer `debt_amount = 600_000` (sau khi bán nợ).
- Tạo OrderReturn `total = 200_000`.

**Kỳ vọng:**
- `customer.debt_amount = 400_000`.
- Có row `customer_debts`:
  - `amount = -200_000` (negative cho return) hoặc `type = 'return'`.
  - `ref_code = orderReturn.code`.

→ Hiện tại FAIL — `OrderReturnController@store` dòng 313 chỉ `decrement('debt_amount', total)` không ghi ledger.

### TC-RR06-05: Hủy hóa đơn phải ghi customer_debts reverse row

**Setup:**
- Invoice nợ `600_000`.
- Hủy invoice qua `route('invoices.destroy')`.

**Kỳ vọng:**
- `customer.debt_amount = 0`.
- Có row `customer_debts`:
  - `amount = -600_000` (đảo nợ) hoặc `type = 'cancel'`.
  - `ref_code = invoice.code`.

→ Hiện tại FAIL — `InvoiceController@cancel` dòng 502 chỉ `decrement('debt_amount', $debtAmount)` không ghi ledger.

---

## Phạm vi sửa (Bước 19.1B kỳ vọng)

### A. Tạo Model + Service

1. **`app/Models/CustomerDebt.php`** — model cho bảng `customer_debts`.
2. **`app/Services/CustomerDebtService.php`** với methods:
   - `recordSale($customerId, $amount, $invoiceCode, $invoiceId, $note)` — type='sale', amount > 0
   - `recordPayment($customerId, $amount, $refCode, $note)` — type='payment', amount < 0
   - `recordReturn($customerId, $amount, $orderReturnCode, $orderReturnId, $note)` — type='return', amount < 0
   - `recordAdjustment($customerId, $amount, $note)` — type='adjustment'
   - Mỗi method: tạo `CustomerDebt` row, update `customers.debt_amount`, set `debt_total = customer.debt_amount sau update`.
3. **Pattern tham chiếu:** `SupplierDebtTransaction` trong `SupplierController` và `DebtOffsetService`.

### B. Refactor các nơi dùng

1. **`InvoiceSaleService::updateCustomerDebt`** dòng 235: thay `$customer->increment('debt_amount', $debtAmount)` bằng `app(CustomerDebtService::class)->recordSale(...)`.
2. **`OrderReturnController@store`** dòng 313: tương tự, dùng `recordReturn()`.
3. **`OrderReturnController@cancel`** dòng 443: dùng `recordReturnReversal()` hoặc `recordAdjustment()`.
4. **`InvoiceController@cancel`** dòng 502: dùng `recordSaleReversal()` hoặc `recordAdjustment()`.
5. **`InvoiceController@update`** dòng 363, 376, 381: phức tạp — có thể tạo 2 transactions (decrease cho old, increase cho new).
6. **`OrderController@processOrder`** dòng 464: dùng `recordSale()`.
7. **`CustomerController` manual adjustments** dòng 469, 521, 591: dùng `recordAdjustment()` hoặc `recordPayment()`.
8. **`CustomerController` merge** dòng 207, 642: tạo opening_balance transaction cho customer đích.

→ Phạm vi sửa rộng — Step 19.1B sẽ chia nhỏ.

### C. CashFlow link

`CashFlow` đã có `target_type='Khách hàng', target_id, reference_type='Invoice', reference_code`. Có thể link `customer_debts.ref_code` với `cash_flows.reference_code` để reconcile.

---

## Lưu ý: customer_debts cũ có thể có row từ migration backfill

Migration `add_type_to_customer_debts_table` có statement:
```sql
UPDATE customer_debts SET type = CASE
    WHEN amount > 0 THEN 'sale'
    WHEN amount < 0 THEN 'payment'
    ELSE 'adjustment'
END WHERE type = 'sale'
```

→ Hint rằng bảng này từng được dùng (legacy), nhưng hiện tại codebase không còn ghi vào. Có thể có data cũ trong production.

Test fresh DB sẽ không có row → fail rõ.
