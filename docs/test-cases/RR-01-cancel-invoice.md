# RR-01 — Hủy hóa đơn không được xóa record

> **Mã rủi ro:** RR-01  
> **Mức độ:** P0 — Critical  
> **Module:** Invoice  
> **File liên quan:** `app/Http/Controllers/InvoiceController.php` → method `destroy()` (dòng 554–651)  
> **Automated test:** `tests/Feature/Invoice/CancelInvoiceTest.php` (PHPUnit) + `test_rr01_cancel_invoice.php` (Script)  
> **Ngày tạo:** 02/05/2026

---

## Mục tiêu

Chứng minh rằng khi hủy hóa đơn đã phát sinh nghiệp vụ, hệ thống hiện tại **xóa vật lý** invoice thay vì đổi trạng thái sang `cancelled`. Vi phạm AGENT_RULES §1.6, §5.1, §5.2.

---

## Dữ liệu đầu vào

| Mục | Giá trị |
|---|---|
| **Sản phẩm** | SKU: `SP-TEST-01`, tên: `Sản phẩm test RR01` |
| **Tồn kho ban đầu** | `stock_quantity = 10`, `cost_price = 100000`, `inventory_total_cost = 1000000` |
| **Khách hàng** | Code: `KH-TEST-01`, `debt_amount = 0`, `total_spent = 0` |
| **Hóa đơn** | Code: `HD-TEST-01`, bán 2 SP × 150.000₫ = 300.000₫ |
| **Thanh toán** | KH trả 200.000₫, ghi nợ 100.000₫ |

---

## Phân tích code — Bằng chứng rủi ro

### Code hiện tại: `InvoiceController@destroy` (dòng 554–651)

```php
// Dòng 639-644 — CHỨNG CỨ RỦI RO
// Delete related CashFlow entries
CashFlow::where('reference_type', 'Invoice')
    ->where('reference_code', $invoice->code)
    ->delete();

$invoice->delete();  // ← XÓA VẬT LÝ INVOICE
```

### So sánh với PurchaseController@cancel (chuẩn đúng)

```php
// PurchaseController — đổi status thay vì delete
$purchase->status = 'cancelled';
$purchase->save();
// Items vẫn giữ lại cho audit trail
```

---

## Các kịch bản test

### TC-RR01-01: Hủy hóa đơn đã thanh toán đủ

**Kỳ vọng (Expected — theo AGENT_RULES):**

| # | Assert | Expected |
|---|---|---|
| 1 | Invoice tồn tại trong DB | ✅ Còn (assertDatabaseHas) |
| 2 | Invoice status = cancelled | ✅ 'Đã hủy' hoặc 'cancelled' |
| 3 | Invoice items vẫn còn | ✅ Còn để truy vết |
| 4 | Tồn kho phục hồi | ✅ stock_quantity = 10 |
| 5 | inventory_total_cost phục hồi | ✅ = 1,000,000 |
| 6 | Stock movement đảo | ✅ type = 'in_invoice_return' |
| 7 | CashFlow không bị xóa | ✅ Đổi status hoặc soft-delete |
| 8 | Công nợ KH = 0 (đã trả đủ) | ✅ Không thay đổi |

**Kết quả thực tế (phân tích code tĩnh):**

| # | Assert | Actual | Kết quả |
|---|---|---|---|
| 1 | Invoice tồn tại | `$invoice->delete()` dòng 644 → **XÓA HẲN** | ❌ **FAIL** |
| 2 | Status = cancelled | Không set status trước delete | ❌ **FAIL** |
| 3 | Items còn | FK `invoice_items.invoice_id` cascade delete → **MẤT** | ❌ **FAIL** |
| 4 | Tồn kho phục hồi | `applySaleReturn()` dòng 585 → **OK** | ✅ PASS |
| 5 | total_cost phục hồi | `applySaleReturn()` dòng 585 → **OK** | ✅ PASS |
| 6 | Stock movement đảo | `StockMovementService::record()` dòng 611 → **OK** | ✅ PASS |
| 7 | CashFlow không xóa | `CashFlow::where(...)->delete()` dòng 640 → **SoftDelete** (CashFlow có SoftDeletes) | ⚠️ PASS* |
| 8 | Công nợ đảo | `$customer->decrement('debt_amount', $debtAmount)` dòng 631 → **OK** | ✅ PASS |

> **Ghi chú về CashFlow (#7):** CashFlow model dùng `SoftDeletes` trait (dòng 10: `use SoftDeletes;`), nên `->delete()` chỉ set `deleted_at`. Record vẫn tồn tại trong DB. Tuy nhiên theo AGENT_RULES §1.7, nên đổi `status = 'cancelled'` thay vì soft-delete.

---

### TC-RR01-02: Hủy hóa đơn ghi nợ

**Mô tả:** Bán 2 SP × 150k = 300k, KH trả 200k → nợ 100k.

| # | Assert | Actual | Kết quả |
|---|---|---|---|
| 1 | Invoice tồn tại | `$invoice->delete()` → XÓA | ❌ **FAIL** |
| 2 | Items còn | Cascade delete | ❌ **FAIL** |
| 3 | Công nợ giảm 100k | `$customer->decrement('debt_amount', $debtAmount)` dòng 629-631 | ✅ PASS |
| 4 | total_spent giảm 300k | `$customer->decrement('total_spent', $invoice->total)` dòng 633 | ✅ PASS |
| 5 | Tồn kho phục hồi | `applySaleReturn()` | ✅ PASS |

---

### TC-RR01-03: Không cho hủy lặp gây cộng tồn 2 lần

| # | Assert | Actual | Kết quả |
|---|---|---|---|
| 1 | Lần 1 hủy OK | `$invoice->delete()` thành công | ✅ PASS (nhưng xóa hẳn) |
| 2 | Lần 2 từ chối | Invoice đã bị delete → route binding ModelNotFound → 404 | ⚠️ PASS* |
| 3 | Tồn kho không tăng thêm | 404 → không chạy logic → không tăng | ⚠️ PASS* |
| 4 | SM không tạo thêm | 404 → không chạy | ⚠️ PASS* |

> **⚠️ PASS sai lý do:** Hệ thống không bị cộng tồn 2 lần nhưng **không phải vì có guard idempotent** (check `if status === cancelled`). Mà vì record đã bị xóa hẳn → 404. Theo AGENT_RULES §5.5, §5.6, cần guard check status, không dựa vào việc record biến mất.

---

## Trạng thái chạy test tự động

| Test file | Phương pháp | Trạng thái | Lý do |
|---|---|---|---|
| `tests/Feature/Invoice/CancelInvoiceTest.php` | PHPUnit + DatabaseTransactions | ❌ Không chạy được | SQLite thiếu cột `inventory_total_cost`, `deleted_at` trên users. Migration MySQL-specific (`information_schema`) không tương thích SQLite. |
| `test_rr01_cancel_invoice.php` | Script trực tiếp | ❌ Không chạy được | Cùng lý do — SQLite DB thiếu schema. |

> **Nguyên nhân:** Dự án được phát triển trên MySQL nhưng `.env` hiện tại dùng SQLite (`DB_CONNECTION=sqlite`). File DB chính (`db2.sqlite` — có schema đầy đủ) đã bị xóa/comment out. Migration có MySQL-specific code (`information_schema.KEY_COLUMN_USAGE`) không tương thích SQLite.

> **Giải pháp để chạy test:** Cần MySQL server hoặc restore `db2.sqlite` với schema đầy đủ. Hoặc comment out migration MySQL-specific rồi chạy `php artisan migrate:fresh`.

---

## Tổng kết phân tích (Static Code Review)

### Điểm hệ thống làm đúng khi hủy HĐ ✅

1. **Tồn kho phục hồi** — Gọi `MovingAvgCostingService::applySaleReturn()` (dòng 585)
2. **Giá vốn phục hồi** — Dùng snapshot `cost_price` từ invoice_item (dòng 582)
3. **Stock movement đảo** — Gọi `StockMovementService::record()` type `in_invoice_return` (dòng 611)
4. **Serial restore** — Chuyển serial về `in_stock` (dòng 600)
5. **Công nợ đảo** — `$customer->decrement('debt_amount', ...)` (dòng 631)
6. **total_spent đảo** — `$customer->decrement('total_spent', ...)` (dòng 633)
7. **CashFlow soft-delete** — CashFlow model có `SoftDeletes` nên `delete()` chỉ set `deleted_at`

### Điểm hệ thống làm sai ❌

1. **Invoice bị xóa vật lý** — `$invoice->delete()` dòng 644
2. **Invoice items mất** — FK cascade delete
3. **Không có status cancelled** — Không set status trước khi delete
4. **CashFlow nên đổi status** — Dùng `->delete()` (soft) thay vì set `status = 'cancelled'`
5. **Không có guard idempotent** — Không check `if (status === 'cancelled')` trước khi hủy

### Kết luận

> **Chưa sửa code.** Hệ thống hiện tại **XÓA VẬT LÝ** invoice khi hủy, vi phạm AGENT_RULES §1.6 và §5.1. Cần chuyển sang status-based cancel theo pattern của PurchaseController.
