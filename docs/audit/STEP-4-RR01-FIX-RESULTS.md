# Kết quả Bước 4 — Sửa RR-01: Hủy hóa đơn không được xóa vật lý

> **Mã rủi ro:** RR-01  
> **Mức độ:** P0 — Critical  
> **Ngày sửa:** 02/05/2026  
> **Trạng thái:** ✅ ĐÃ SỬA — 10/10 test PASS

---

## File đã sửa

| File | Phạm vi sửa |
|---|---|
| `app/Http/Controllers/InvoiceController.php` | Method `destroy()` — 3 thay đổi |

**Không sửa:** Model, Service, Migration, Schema, module khác.

---

## Root cause

`InvoiceController@destroy` dòng 644 gọi `$invoice->delete()` — xóa vật lý invoice.  
FK `invoice_items.invoice_id` có `cascadeOnDelete` → items cũng bị xóa theo.

---

## Cách sửa

### 1. Thêm guard hủy lặp (idempotent)

```php
// Thêm ngay đầu method destroy(), TRƯỚC mọi logic khác
if ($invoice->status === 'Đã hủy') {
    return back()->with('error', 'Hóa đơn này đã được hủy trước đó.');
}
```

Nếu invoice đã ở trạng thái `'Đã hủy'`, return ngay — không chạy bất kỳ logic đảo tồn/công nợ/cashflow nào.

### 2. Thay `$invoice->delete()` → đổi status

```diff
- $invoice->delete();
+ $invoice->status = 'Đã hủy';
+ $invoice->save();
```

Invoice giữ nguyên trong DB, items giữ nguyên (không bị cascade delete).

### 3. CashFlow: đổi status thay vì delete

```diff
- CashFlow::where('reference_type', 'Invoice')
-     ->where('reference_code', $invoice->code)
-     ->delete();
+ CashFlow::where('reference_type', 'Invoice')
+     ->where('reference_code', $invoice->code)
+     ->update(['status' => 'cancelled']);
```

Đồng bộ với `CashFlowController@cancel` (dòng 189) — cũng dùng `update(['status' => 'cancelled'])`.

### 4. Giữ nguyên logic đang đúng

| Logic | Trạng thái | Lý do |
|---|---|---|
| `MovingAvgCostingService::applySaleReturn()` | ✅ Giữ nguyên | Test #4, #9 PASS |
| `StockMovementService::record()` type `in_invoice_return` | ✅ Giữ nguyên | Test #5 PASS |
| `$customer->decrement('debt_amount', ...)` | ✅ Giữ nguyên | Test #7 PASS |
| `$customer->decrement('total_spent', ...)` | ✅ Giữ nguyên | Test #7 PASS |
| Serial restore `in_stock` | ✅ Giữ nguyên | Không ảnh hưởng |
| `DB::beginTransaction()` / `DB::commit()` | ✅ Giữ nguyên | Đã có sẵn |

---

## Convention status đã chọn

| Model | Status hủy | Lý do |
|---|---|---|
| **Invoice** | `'Đã hủy'` | Dashboard, CustomerReport, ProductReport, EmployeeReport đều filter `where('status', '!=', 'Đã hủy')` |
| **CashFlow** | `'cancelled'` | CashFlowController@cancel dùng `'cancelled'`, CashFlowController@index filter `'cancelled'` |

---

## Kết quả test

```
Tests: 10 passed (20 assertions) — Duration: 1.11s
```

| # | Test | Trước sửa | Sau sửa |
|---|---|---|---|
| 1 | Invoice không bị xóa vật lý | ❌ FAIL | ✅ PASS |
| 2 | Status = 'Đã hủy' | ❌ FAIL | ✅ PASS |
| 3 | Invoice items còn tồn tại | ❌ FAIL | ✅ PASS |
| 4 | Tồn kho phục hồi về 10 | ✅ PASS | ✅ PASS |
| 5 | Stock movement đảo (in_invoice_return) | ✅ PASS | ✅ PASS |
| 6 | CashFlow không bị hard-delete | ✅ PASS | ✅ PASS |
| 7 | Công nợ KH đảo về 0 | ✅ PASS | ✅ PASS |
| 8 | Invoice ghi nợ còn tồn tại | ❌ FAIL | ✅ PASS |
| 9 | inventory_total_cost phục hồi | ✅ PASS | ✅ PASS |
| 10 | Hủy lặp không cộng tồn 2 lần | ✅ PASS | ✅ PASS |

### Kết quả tổng

- **Trước:** 4 FAIL, 6 PASS
- **Sau:** **10 PASS, 0 FAIL** ✅

---

## Rủi ro còn lại

| # | Vấn đề | Mức độ | Ghi chú |
|---|---|---|---|
| 1 | CashFlow dùng `update(['status' => 'cancelled'])` thay vì `->delete()` | ✅ Đã xử lý | Đồng bộ với CashFlowController@cancel pattern. CashFlow có SoftDeletes nhưng không cần soft-delete nữa — status-based đúng hơn. |
| 2 | PosController có hủy hóa đơn riêng không? | ⚠️ Cần kiểm tra | PosController có thể có flow hủy riêng chưa được audit. Cần rà soát ở bước RR-10 hoặc audit tiếp. |
| 3 | Report đã lọc invoice theo status chưa? | ✅ Đã có | Dashboard, CustomerReport, ProductReport, EmployeeReport, EndOfDayReport đều đã có `where('status', '!=', 'Đã hủy')`. |
| 4 | InvoiceController@index có hiển thị HĐ đã hủy không? | ⚠️ Cần kiểm tra | UI listing có thể cần badge/filter cho trạng thái hủy. Không thuộc scope RR-01. |
| 5 | RR-10 (CashFlow delete vật lý ở các module khác) | 🔴 Chưa xử lý | Scope riêng — không sửa trong bước này. |

---

## Diff tổng hợp

```diff
# InvoiceController@destroy — 3 thay đổi

## 1. Thêm guard hủy lặp (sau dòng 554)
+ // RR-01 Guard: Không cho hủy lặp — idempotent check
+ if ($invoice->status === 'Đã hủy') {
+     return back()->with('error', 'Hóa đơn này đã được hủy trước đó.');
+ }

## 2. CashFlow: đổi status thay vì delete (dòng 639-642)
- // Delete related CashFlow entries
- CashFlow::where('reference_type', 'Invoice')
-     ->where('reference_code', $invoice->code)
-     ->delete();
+ // RR-01: Đổi status CashFlow sang cancelled (không xóa)
+ CashFlow::where('reference_type', 'Invoice')
+     ->where('reference_code', $invoice->code)
+     ->update(['status' => 'cancelled']);

## 3. Invoice: đổi status thay vì delete (dòng 644)
- $invoice->delete();
+ // RR-01: Đổi trạng thái hóa đơn — KHÔNG xóa vật lý
+ $invoice->status = 'Đã hủy';
+ $invoice->save();
```
