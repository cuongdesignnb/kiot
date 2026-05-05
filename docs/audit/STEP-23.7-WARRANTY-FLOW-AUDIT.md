# STEP 23.7 — Warranty Flow Audit

**Date:** 2026-05-05
**Branch:** main (uncommitted)
**Scope:** Module Bảo hành — index/search/update/export/print + kiểm tra hiện trạng sinh warranty từ sales.

---

## 1. Discovery

| Luồng | Entry point | Đọc/Ghi DB | Serial liên quan | Rủi ro |
|---|---|---|---|---|
| Index | `GET /warranties` → `WarrantyController@index` ([app/Http/Controllers/WarrantyController.php](app/Http/Controllers/WarrantyController.php#L26)) | **GHI** (auto-seed nếu rỗng) → sau patch chỉ ĐỌC | hiển thị `serial_imei` | **BUG-1** demo data tạo trên production |
| Search/filter/sort | Cùng entry, qua `FilterableIndex` trait | ĐỌC | `serial_imei` searchable | An toàn |
| Update | `PUT /warranties/{warranty}` → `WarrantyController@update` ([app/Http/Controllers/WarrantyController.php](app/Http/Controllers/WarrantyController.php#L130)) | GHI 5 field maintenance | có thể sửa `serial_imei` | **BUG-2** thiếu `min:0`/`max:100` |
| Export | `GET /warranties/export` → `WarrantyController@export` | ĐỌC + xuất CSV | hiển thị | An toàn |
| Print | `GET /warranties/{warranty}/print` → `WarrantyController@print` | ĐỌC | hiển thị | An toàn |
| Sinh từ bán hàng | `InvoiceSaleService` / `PosController` / `OrderController` / `InvoiceController` | KHÔNG GHI Warranty | N/A | Backlog 23.7B |

### Trả lời câu hỏi Discovery

1. **WarrantyController@index có còn auto seed không?** — Trước patch: CÓ (tạo "Anh Khải" + "HD008229.01"). Sau patch: KHÔNG.
2. **Search/filter/sort có mutate DB không?** — KHÔNG. Chỉ build Eloquent query + paginate.
3. **Export/print có mutate DB không?** — KHÔNG.
4. **Update cho sửa những field nào?** — chỉ `maintenance_note`, `has_reminder_off`, `warranty_period`, `warranty_end_date`, `serial_imei`. KHÔNG cho sửa `invoice_code`, `product_id`, `customer_name`, `purchase_date`.
5. **Invoice/POS/Order có tự tạo warranty không?** — KHÔNG. Grep `Warranty::create|new Warranty\(` toàn bộ `app/` chỉ trả về 0 match (sau khi xóa auto-seed). Schema `purchase_items.warranty_months` đã tồn tại nhưng chưa có hook sinh record `warranties` từ sales.
6. **Hàng serial bán ra hiện có warranty record không?** — KHÔNG có hook tự động.

---

## 2. Business rules verified

### 2.1 Index
- ✅ Read-only: chỉ paginate, không tạo gì (TC-23.7-01).
- ✅ Không auto seed: bảng rỗng → vẫn rỗng sau GET. Không còn "Anh Khải"/"HD008229.01".

### 2.2 Search/filter/sort
- ✅ Read-only: count trước/sau bằng nhau với `search`, `status=valid|expired`, `sort_by=invoice_code`, `time_filter=this_month` (TC-23.7-02).

### 2.3 Update
- ✅ Field được sửa: `maintenance_note`, `has_reminder_off`, `warranty_period`, `warranty_end_date`, `serial_imei`.
- ✅ Validation:
  - `warranty_period` phải `integer` `min:0` (TC-23.7-04).
  - `warranty_end_date` phải `date` hợp lệ (TC-23.7-05).
  - `serial_imei` `string max:100`.
- ✅ Field bị bảo vệ: `invoice_code`, `product_id`, `customer_name`, `purchase_date` không đổi dù client có gửi (TC-23.7-06).

### 2.4 Export/print
- ✅ Read-only (TC-23.7-08).

### 2.5 Warranty generation from sales
- **Hiện trạng:** chưa có. Test TC-23.7-09 grep tĩnh 4 file core sales đảm bảo 0 occurrence `Warranty::create` / `new Warranty(`.
- **Kết luận:** không cần vá ở step này. Nếu nghiệp vụ yêu cầu, sang Step 23.7B (xem backlog).

### 2.6 Legacy data
- ✅ `serial_imei` null vẫn hiển thị bình thường (TC-23.7-03).
- ✅ `invoice_code` là string tự do, không FK với `invoices` → backward compat OK.

---

## 3. Bugs found & fix

| Mã | Mô tả | Mức | File | Cách xử lý |
|---|---|---|---|---|
| **BUG-23.7-1** | `WarrantyController@index` auto seed 2 record demo ("Anh Khải", "HD008229.01", serial "3VGMK73") khi bảng rỗng → ô nhiễm production data lần đầu vào trang. | **CAO** | `WarrantyController.php` (cũ L28-52) | Xóa toàn bộ block `if (Warranty::count() === 0)`. |
| **BUG-23.7-2** | `update()` thiếu `min:0` cho `warranty_period` → cho phép âm. Thiếu `max:100` cho `serial_imei`. | Trung | `WarrantyController.php` `update()` | Thêm rule `integer\|min:0` và `string\|max:100`. Dùng `$data = $request->validate(...)` rồi `update($data)` thay cho `request->only(...)`. |

### Không vá (ghi backlog)

- Auto-generate warranty từ Invoice/POS — chưa có nghiệp vụ rõ, không vá ngầm.
- FK `warranties.invoice_id`/`invoice_item_id`/`serial_imei_id` — chưa có yêu cầu.

---

## 4. Files changed

| File | Nội dung |
|---|---|
| [app/Http/Controllers/WarrantyController.php](app/Http/Controllers/WarrantyController.php) | Xóa auto-seed trong `index()`. Siết validation `update()` (`min:0`, `max:100`, dùng `$data` validated). |
| [tests/Feature/Warranty/Step237WarrantyFlowTest.php](tests/Feature/Warranty/Step237WarrantyFlowTest.php) | NEW — 9 tests, 29 assertions. |
| [docs/audit/STEP-23.7-WARRANTY-FLOW-AUDIT.md](docs/audit/STEP-23.7-WARRANTY-FLOW-AUDIT.md) | NEW — báo cáo. |

KHÔNG sửa: model `Warranty`, migration, `InvoiceSaleService`, `PosController`, `OrderController`, `InvoiceController`, view print, Vue UI.

---

## 5. Tests

| Test | Kết quả |
|---|---|
| TC-23.7-01 `warranty_index_does_not_seed_dummy_data` | ✅ |
| TC-23.7-02 `warranty_index_search_is_read_only` | ✅ |
| TC-23.7-03 `warranty_legacy_record_without_serial_still_displays` | ✅ |
| TC-23.7-04 `warranty_update_allows_maintenance_fields` | ✅ |
| TC-23.7-05 `warranty_update_validates_warranty_period_non_negative` | ✅ |
| TC-23.7-06 `warranty_update_validates_warranty_end_date` | ✅ |
| TC-23.7-07 `warranty_update_does_not_change_protected_fields` | ✅ |
| TC-23.7-08 `warranty_export_is_read_only` | ✅ |
| TC-23.7-09 `invoice_sale_current_warranty_generation_behavior` (lock no Warranty::create in sales core) | ✅ |

### Regression

| Cluster | Kết quả |
|---|---|
| `--filter="Warranty\|Step237"` | ✅ **9 passed**, 29 assertions |
| `--filter="RR02\|RR06\|RR08\|RR09\|RR11\|RR12\|RR13\|SerialAvailability\|RequireSerial\|CustomerSearch\|Order\|Purchase\|PurchaseReturn\|StockTake\|StockTransfer\|Damage\|Step232\|Step233\|Step234\|Step235\|Step236"` | ✅ **143 passed**, 2 skipped, 522 assertions |

---

## 6. Build

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | ✅ |
| `npm run build` | ✅ built in 6.56s |

---

## 7. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration mới? | ❌ Không |
| Có seed demo không? | ❌ Đã xóa auto-seed |
| Có backfill production không? | ❌ Không |
| Có update dữ liệu cũ không? | ❌ Không |
| Có sửa sales/inventory core không? | ❌ Không |

**Lưu ý production:** Trên server đã chạy code cũ ít nhất một lần với bảng `warranties` rỗng → 2 record demo "Anh Khải"/"HD008229.01" có thể đã tồn tại. **KHÔNG xóa tự động** — admin tự kiểm tra rồi xóa tay nếu thấy. Test TC-23.7-01 chỉ kiểm code mới, không backfill DB cũ.

---

## 8. Backlog

- **STEP 23.7B** — Sinh warranty tự động từ invoice/POS:
  - Hàng serial: mỗi `serial_imei` bán ra (status `sold`) → 1 `warranty` record với `invoice_code`, `purchase_date`, `warranty_period` từ `product.warranty_months` (nếu thêm cột) hoặc `purchase_items.warranty_months`.
  - Hàng thường: tạo 1 record/invoice item nếu sản phẩm có `warranty_months > 0`.
- **STEP 23.7C** — Backfill warranty từ invoices cũ bằng artisan command `warranty:backfill --dry-run` (in báo cáo trước, áp dụng sau).
- **STEP 23.7D** — Liên kết FK `warranty.invoice_id` / `invoice_item_id` / `serial_imei_id` (cần migration + nullable để backward compat).
- **STEP 23.7E** — Module sửa chữa/bảo trì (ticket repair, trạng thái, lịch sử) nếu có nghiệp vụ riêng.

---

## 9. Manual QA sau deploy

- [ ] Vào danh sách bảo hành khi bảng rỗng → không tự tạo dữ liệu.
- [ ] Search/filter/sort → count không đổi.
- [ ] Update ghi chú bảo trì → lưu OK.
- [ ] Update `warranty_period = -1` → toast error.
- [ ] Export → không đổi dữ liệu.
- [ ] Print → không đổi dữ liệu.
- [ ] Record legacy `serial_imei = null` vẫn hiển thị.
- [ ] (Nếu có) xóa tay 2 record demo "Anh Khải"/"HD008229.01" còn sót do code cũ (kiểm `SELECT * FROM warranties WHERE customer_name = 'Anh Khải' OR invoice_code = 'HD008229.01'`).

---

## 10. Conclusion

| Mục | Trạng thái |
|---|---|
| Index read-only | ✅ |
| Auto seed đã xóa | ✅ |
| Update an toàn | ✅ (validation siết, field bảo vệ) |
| Export/print read-only | ✅ |
| Test pass | ✅ 9 + 143 regression |
| Build pass | ✅ |
| Có thể deploy production | ✅ |

**Kết luận:** Đã đóng lỗ hổng auto-seed demo. Update validation strict. Sales core chưa có hook sinh warranty (đã khóa bằng test grep tĩnh để tránh thêm ngầm). Không migration, không backfill. Sẵn sàng commit + deploy.
