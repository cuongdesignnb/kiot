# STEP-22.1E — QA Fix Results (Production Data Safety)

**Ngày:** 03/05/2026
**Branch:** main
**Bối cảnh:** Sau khi deploy 22.1A/B/C/D, QA phát hiện 2 vấn đề trên production có dữ liệu thật:

1. **Lịch sử công nợ cũ bị mất** — Tab Công nợ trống đối với KH có dữ liệu cũ (trước RR-06), do 22.1B đã thay logic hợp dựng từ invoices/cashflows/purchases bằng đọc thẳng `customer_debts`. Production chưa backfill ⇒ mất lịch sử.
2. **Serial/IMEI selector không load** — `/api/products/{product}/serials` nằm trong group `permission:pos.use` ⇒ user tạo Order (không có pos.use) bị 403.

---

## 1. Bảo toàn dữ liệu cũ thế nào

- **Không** chạy migrate:fresh, không truncate, không xóa, không reset trên production.
- **Không** backfill `customer_debts` từ data cũ — để tránh ghi sai semantic ledger.
- **Không** sửa `customers.debt_amount`, không sửa `CustomerDebtService`.
- Migration mới (`order_items.serial_ids`) là **additive**, nullable, có guard `Schema::hasColumn`. Order cũ không có cột này vẫn đọc/process bình thường (cast `array` trên null = null, controller xử lý null an toàn).
- UI graceful với null/empty/legacy: badge nguồn rõ ràng, empty state chỉ hiển thị khi cả ledger và legacy đều rỗng.

---

## 2. Debt history HYBRID

`CustomerController@debtHistory` viết lại để trả CẢ ledger lẫn legacy:

| Trường | Nội dung |
|---|---|
| `entries` | Combined view: ledger trước, legacy không trùng (dedup theo `code` ↔ `ref_code`), sort theo `recorded_at`/`created_at` desc. |
| `ledger_entries` | Chỉ từ `customer_debts` (source='ledger'), running balance dùng `debt_total` snapshot. |
| `legacy_entries` | Dựng lại logic cũ từ invoices + cashflows + purchases + purchase_returns + supplier_debt_transactions, running balance tự tính. |
| `summary.net` | Lấy thẳng `customers.debt_amount` (KHÔNG tính lại từ entries). |
| `summary.source` | `'hybrid'`. |
| `summary.ledger_count` / `legacy_count` / `dedup_skipped` | Đếm để debug. |

**Dedup**: legacy entry có `code` trùng với 1 ledger entry (`ref_code`) thì bị bỏ ⇒ ưu tiên ledger. Còn lại legacy giữ nguyên với badge "Chứng từ cũ".

UI Customers/Index.vue: thêm badge inline cạnh cột "Loại":
- `Ledger` (nền xanh) — entry từ `customer_debts`.
- `Chứng từ cũ` (nền xám) — entry legacy.

Dropdown "Tất cả giao dịch" giữ nguyên (không filter trong code hiện tại) ⇒ legacy luôn được hiển thị.

---

## 3. Legacy entries còn hiển thị không

**Có**. Đối với KH có invoices/cashflows/purchases cũ chưa được ghi vào `customer_debts`:
- `legacy_entries` không rỗng.
- `entries` (combined) bao gồm legacy.
- Tab Công nợ hiển thị các dòng có badge "Chứng từ cũ".

---

## 4. Có backfill production không

**Không**. Đây là chỉnh UI/đọc. Tuyệt đối không chạy backfill `customer_debts` trong bước này. Backfill (nếu cần) phải là task riêng có:
- Script idempotent.
- Test trên DB clone.
- Snapshot trước.
- Reconcile `debt_total` snapshot từng record.

---

## 5. order_items.serial_ids xử lý order cũ thế nào

- Cột `JSON NULL` ⇒ Order cũ tự động có `serial_ids = NULL`.
- Cast `array` trên Eloquent: null → null (không exception).
- `OrderController@processOrder` đã có nhánh: nếu `has_serial && !is_array($serial_ids)` ⇒ throw exception fail-safe có message rõ ràng (Step 18.1B / RR-13). Order cũ sản phẩm thường (`!has_serial`) đi nhánh bình thường ⇒ không ảnh hưởng.
- `OrderController@index` enrich `selected_serials` cũng có guard `is_array` ⇒ Order cũ trả `selected_serials = []`.

⇒ Order cũ không serial: xem được, process bình thường.
⇒ Order cũ sản phẩm serial nhưng chưa chọn: process **fail-safe** với thông báo "Sản phẩm '{name}': order chưa lưu serial_ids. Vui lòng chọn Serial/IMEI trước khi chuyển hóa đơn." UI Orders/Index hiển thị flash error trong modal đỏ.

---

## 6. Serial endpoint thực tế là route nào

**Trước fix**: `GET /api/products/{product}/serials` nằm trong group `Route::middleware('permission:pos.use')` ⇒ 403 cho user tạo Order không có pos.use.

**Sau fix** (`routes/web.php`): chuyển ra ngoài group, đặt tên `api.products.serials`, chỉ cần `auth`:
```
GET|HEAD  api/products/{product}/serials  api.products.serials » PosController@getProductSerials
```

Response JSON array: `[{id, serial_number, status, cost_price}]`.
Filter: `status='in_stock'` AND `repair_status NOT IN ('not_started','repairing')`.

Frontend `loadAvailableSerials` (Orders/Create.vue) cập nhật:
- Header `Accept: application/json` + `X-Requested-With: XMLHttpRequest` (chống nhận HTML redirect).
- Timeout 10s.
- Phân biệt status 401/403/404/timeout/khác → message rõ ràng.
- Chấp nhận cả `data` mảng trực tiếp lẫn `{data: []}` cho an toàn.
- Nếu response không phải mảng → báo "Phản hồi không hợp lệ từ server (không phải JSON)."

---

## 7. Nếu không có serial in_stock, UI hiển thị gì

Block đỏ rõ ràng dưới cell sản phẩm:
> *"Không có Serial/IMEI in_stock cho sản phẩm này."*

Indicator `Đã chọn 0/qty` màu cam (vì chưa đủ qty). Order vẫn lưu được nhưng processOrder sẽ fail-safe.

---

## 8. Có còn treo loading không

**Không**. `loadAvailableSerials` có `try/catch/finally` đảm bảo `serialLoading` luôn về `false`. Thêm `timeout: 10000` ⇒ axios sẽ reject sau 10s nếu server treo. Mỗi nhánh lỗi đều có thông báo, không có path nào để loading kẹt mãi.

---

## 9. Có an toàn với production data không

**Có**:
- Không migration thay schema cũ (chỉ thêm `order_items.serial_ids` nullable additive).
- Không cập nhật/xóa dữ liệu thật.
- Không sửa core service (CustomerDebtService, MovingAvgCostingService, StockMovementService).
- Endpoint mới chỉ là route gating (di chuyển ra khỏi pos.use).
- `debtHistory` chỉ là endpoint **đọc**, kết quả là composition trên dữ liệu hiện có.

**Rollback dễ**: revert commit, không có dữ liệu mất.

---

## 10. Test + Build

| Lệnh | Kết quả |
|---|---|
| `php artisan test --env=testing --filter="RR06\|RR13"` | **10 passed (46 assertions)** |
| `npm run build` | OK 7.31s |
| `php artisan route:list` (api.products.serials) | Route tồn tại, không middleware permission |

---

## 11. Manual QA checklist (production-like)

- [ ] KH có lịch sử cũ (trước RR-06), không có row `customer_debts`: Tab Công nợ vẫn có dữ liệu, mỗi dòng badge "Chứng từ cũ".
- [ ] KH có `customer_debts` mới: dòng badge "Ledger".
- [ ] KH có cả hai: hiển thị hybrid, dedup ledger ưu tiên, legacy còn lại có badge.
- [ ] Order cũ không serial: xem + process được.
- [ ] Order cũ sản phẩm serial nhưng chưa chọn: process báo lỗi modal đỏ giữ mở, không tạo invoice, không trừ tồn.
- [ ] Order mới sản phẩm serial: load được serial in_stock từ `/api/products/{id}/serials` (kể cả user không có `pos.use`); chọn serial; lưu; process tạo invoice; serial chuyển `sold`.
- [ ] User KHÔNG có `pos.use`: vào Orders/Create chọn sản phẩm has_serial → checkbox list serial hiển thị (không còn 403).
- [ ] Sản phẩm has_serial nhưng không có serial in_stock: hiển thị block đỏ "Không có Serial/IMEI in_stock cho sản phẩm này.", không treo loading.

---

## 12. Files thay đổi

| File | Nội dung |
|---|---|
| `app/Http/Controllers/CustomerController.php` | `debtHistory` → hybrid (ledger + legacy + dedup + badges) |
| `routes/web.php` | Move `/api/products/{product}/serials` ra khỏi pos.use group, đặt name `api.products.serials` |
| `resources/js/Pages/Customers/Index.vue` | Badge "Ledger"/"Chứng từ cũ" cạnh cột Loại |
| `resources/js/Pages/Orders/Create.vue` | `loadAvailableSerials` với headers JSON + timeout + phân nhánh status code |
| `docs/audit/STEP-22.1E-QA-FIX-RESULTS.md` | Báo cáo này |

---

## 13. Production deploy

```
git pull origin main
php artisan route:clear
php artisan route:cache
npm ci && npm run build
php artisan optimize:clear
php artisan config:cache && php artisan view:cache
```

**Không cần migrate** (lần này không thêm migration).
**Không cần backfill** dữ liệu.
