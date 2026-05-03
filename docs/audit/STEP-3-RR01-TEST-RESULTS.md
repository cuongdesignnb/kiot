# Kết quả Bước 3 + 3.1 — RR-01: Kiểm thử hủy hóa đơn

> **Mã rủi ro:** RR-01  
> **Mức độ:** P0 — Critical  
> **Ngày thực hiện:** 02/05/2026  
> **Trạng thái:** ✅ Test đã chạy — 4 FAIL, 6 PASS  
> **Business code đã sửa:** KHÔNG — chỉ tạo test + setup môi trường

---

## 1. Thiết lập môi trường test (Bước 3.1)

### 1.1 Kiểm tra port MySQL trên máy

Đã kiểm tra 20+ container Docker MySQL hiện có:

| Port | Dự án sử dụng |
|---|---|
| 3306 | (mặc định — không dùng) |
| 3307 | fb_post_creator_db, vnpt_mysql, giapha_mysql, drupal_mysql, mainsource-db-1 |
| 3317 | wine_mysql |
| 3318 | asia_db |
| **3319** | **— TRỐNG → đã chọn cho dự án này** |
| 3320 | vitesoft_mysql |
| 3344 | digitalstore_mysql |
| 3347 | product-lookup-mysql |
| 3641 | phuduc-db |
| 3847 | dreamhome_mysql |
| 13307 | esim_mysql |
| 23491 | wp-db-1 |
| 33061 | pc_mysql |
| 33062 | lg_mysql |
| 33065 | homestay_mysql |
| 33069 | glass_mysql, noithat_db_dev |
| 33917 | sonet_course_db-mysql-1 |

### 1.2 Port đã chọn

- **Port:** `3319`
- **Lý do:** Không trùng bất kỳ dự án nào. Nằm trong khoảng ưu tiên (3319-3399).

### 1.3 Môi trường test

| Config | Giá trị |
|---|---|
| APP_ENV | testing |
| DB_CONNECTION | mysql |
| DB_HOST | 127.0.0.1 |
| DB_PORT | **3319** |
| DB_DATABASE | sales_test |
| DB_USERNAME | test_user |
| DB_PASSWORD | test_password |
| Container name | `sales_mysql_test` |
| MySQL version | 8.0 |
| Container status | ✅ Healthy |

### 1.4 File đã tạo

| File | Mục đích |
|---|---|
| `docker-compose.testing.yml` | MySQL 8.0 test container, host port 3319 |
| `.env.testing.example` | Template env cho test (commit-safe) |
| `.env.testing` | Copy từ example, dùng cho chạy test local |

### 1.5 Migration fixes (chỉ sửa lỗi cú pháp/ordering, KHÔNG sửa business logic)

| File | Vấn đề | Sửa |
|---|---|---|
| `create_customer_debts_table.php` | Tên file `2025_07_07_...` chạy trước `customers` (2026_02_28) và `orders` (2026_03_01) | Đổi tên → `2026_03_01_100000_...` để chạy sau cả 2 bảng cha |
| `create_roles_table.php` dòng 16 | `json('permissions')->default('[]')` — MySQL 8.0 strict mode không cho JSON default | Đổi → `json('permissions')->nullable()` |

### 1.6 Cách chạy lại test

```powershell
# 1. Khởi động MySQL test
docker compose -f docker-compose.testing.yml up -d

# 2. Đợi MySQL healthy
docker inspect --format '{{.State.Health.Status}}' sales_mysql_test

# 3. Copy env (nếu chưa có)
Copy-Item .env.testing.example .env.testing

# 4. Clear config cache
php artisan config:clear

# 5. Migrate (chỉ lần đầu hoặc khi cần reset)
php artisan migrate:fresh --env=testing --force

# 6. Chạy test
php artisan test --env=testing --filter=CancelInvoiceTest

# 7. Dừng container khi xong
docker compose -f docker-compose.testing.yml down
```

---

## 2. Test cases đã tạo (Bước 3)

### 2.1 File test

| File | Loại | Mô tả |
|---|---|---|
| `docs/test-cases/RR-01-cancel-invoice.md` | Tài liệu test case | 3 kịch bản, mapping assert ↔ code |
| `tests/Feature/Invoice/CancelInvoiceTest.php` | PHPUnit automated test | 10 test methods, 18 assertions |
| `test_rr01_cancel_invoice.php` | Script test trực tiếp | Backup test theo pattern dự án |

### 2.2 Danh sách test methods

| # | Method | Kịch bản | Assert chính |
|---|---|---|---|
| 1 | `test_TC_RR01_01_cancel_invoice_should_not_delete_record` | TC-01: HĐ trả đủ | Invoice phải còn trong DB |
| 2 | `test_TC_RR01_01_cancel_invoice_should_set_status_cancelled` | TC-01 | Status = 'Đã hủy' |
| 3 | `test_TC_RR01_01_cancel_invoice_should_keep_items` | TC-01 | invoice_items còn |
| 4 | `test_TC_RR01_01_cancel_invoice_should_restore_stock` | TC-01 | stock_quantity phục hồi |
| 5 | `test_TC_RR01_01_cancel_invoice_should_create_stock_movement` | TC-01 | Có SM type in_invoice_return |
| 6 | `test_TC_RR01_01_cancel_invoice_cashflow_should_not_be_hard_deleted` | TC-01 | CashFlow withTrashed tồn tại |
| 7 | `test_TC_RR01_02_cancel_invoice_with_debt_should_restore_customer_debt` | TC-02: HĐ ghi nợ | debt_amount đảo về 0 |
| 8 | `test_TC_RR01_02_cancel_invoice_with_debt_should_keep_invoice` | TC-02 | Invoice + items còn |
| 9 | `test_TC_RR01_02_cancel_invoice_should_restore_inventory_total_cost` | TC-02 | inventory_total_cost phục hồi |
| 10 | `test_TC_RR01_03_double_cancel_should_not_double_restore_stock` | TC-03: Hủy lặp | stock không tăng thêm lần 2 |

---

## 3. Kết quả chạy test

```
Tests: 4 failed, 6 passed (18 assertions)
Duration: 1.31s
```

### 3.1 Chi tiết từng test

| # | Test | Kết quả | Chi tiết |
|---|---|---|---|
| 1 | Invoice không bị xóa vật lý | ❌ **FAIL** | `The table is empty` — `$invoice->delete()` dòng 644 xóa hẳn |
| 2 | Status = cancelled | ❌ **FAIL** | `Invoice bị xóa vật lý — null` — không có status nào vì record biến mất |
| 3 | Items còn tồn tại | ❌ **FAIL** | `The table is empty` — FK cascade delete khi invoice bị xóa |
| 4 | Tồn kho phục hồi về 10 | ✅ **PASS** | `applySaleReturn()` hoạt động đúng |
| 5 | Stock movement đảo chiều | ✅ **PASS** | `StockMovementService::record()` ghi type `in_invoice_return` |
| 6 | CashFlow không bị hard-delete | ✅ **PASS** | CashFlow dùng `SoftDeletes` → chỉ set `deleted_at` |
| 7 | Công nợ KH đảo về 0 | ✅ **PASS** | `$customer->decrement('debt_amount', ...)` hoạt động đúng |
| 8 | Invoice ghi nợ còn tồn tại | ❌ **FAIL** | `The table is empty` — cùng lỗi delete vật lý |
| 9 | inventory_total_cost phục hồi | ✅ **PASS** | `applySaleReturn()` cập nhật đúng |
| 10 | Hủy lặp không cộng tồn 2 lần | ✅ **PASS** | Lần 2 trả 404 (record đã bị xóa) |

### 3.2 Phân tích kết quả

#### Điểm hệ thống làm ĐÚNG ✅

1. **Tồn kho phục hồi** — `MovingAvgCostingService::applySaleReturn()` gọi đúng, snapshot `cost_price` từ `invoice_item`
2. **Giá vốn phục hồi** — `inventory_total_cost` được cộng lại đúng
3. **Stock movement đảo** — Ghi sổ cái type `in_invoice_return`
4. **Công nợ đảo** — `debt_amount` và `total_spent` giảm đúng
5. **CashFlow an toàn** — Dùng `SoftDeletes` nên `->delete()` chỉ set `deleted_at`, không mất record
6. **Không cộng tồn 2 lần** — Lần hủy thứ 2 trả 404 (nhưng lý do sai — xem bên dưới)

#### Điểm hệ thống làm SAI ❌

| # | Vấn đề | Code gây lỗi | Dòng | Vi phạm AGENT_RULES |
|---|---|---|---|---|
| 1 | **Invoice bị xóa vật lý** | `$invoice->delete();` | InvoiceController.php:644 | §1.6 — Không xóa vật lý chứng từ đã phát sinh |
| 2 | **Invoice items mất** | FK `invoice_items.invoice_id` cascade delete | Migration + dòng 644 | §5.2 — Mất dữ liệu truy vết |
| 3 | **Không có status cancelled** | Không gọi `$invoice->status = 'Đã hủy'` trước delete | InvoiceController.php:644 | §5.1 — Phải đổi trạng thái |
| 4 | **Idempotent sai lý do** | Lần 2 trả 404 vì record biến mất, không phải vì guard check | InvoiceController.php:554 | §5.5, §5.6 — Cần guard check status |

#### So sánh với PurchaseController (chuẩn đúng)

```diff
# PurchaseController@cancel (ĐÚNG)
+ $purchase->status = 'cancelled';
+ $purchase->save();
  // Items vẫn giữ cho audit trail

# InvoiceController@destroy (SAI)
- CashFlow::where(...)->delete();
- $invoice->delete();
  // Mất hết dữ liệu
```

---

## 4. Kết luận

### Trạng thái hiện tại

- **RR-01 đã được CHỨNG MINH** bằng automated test (4/10 test FAIL)
- **Root cause:** `$invoice->delete()` tại InvoiceController.php dòng 644
- **Ảnh hưởng:** Mất lịch sử chứng từ, không thể truy vết giao dịch đã hủy
- **Business code đã sửa:** KHÔNG — chỉ tạo test

### Hành động tiếp theo

Chuyển sang **Bước 4: Sửa code RR-01** với scope:

1. Thay `$invoice->delete()` → `$invoice->status = 'Đã hủy'; $invoice->save();`
2. Thêm guard: `if ($invoice->status === 'Đã hủy') return back()->with('error', ...);`
3. CashFlow: đổi `->delete()` → `->update(['status' => 'cancelled'])`
4. Chạy lại test → expect 10/10 PASS

### Lưu ý trước khi sửa (theo AGENT_RULES §8)

| Mục | Giá trị |
|---|---|
| **Mã rủi ro** | RR-01 |
| **Test case fail** | TC-RR01-01 (#1, #2, #3), TC-RR01-02 (#8) |
| **Dữ liệu đầu vào** | Product stock=10, cost=100k; Invoice 2×150k |
| **Kết quả kỳ vọng** | Invoice còn tồn tại với status=cancelled |
| **Kết quả thực tế** | Invoice bị xóa vật lý (table empty) |
| **File/function** | `InvoiceController@destroy` dòng 639–644 |

---

## 5. Tham chiếu file

| File | Đường dẫn |
|---|---|
| Tài liệu test case | `docs/test-cases/RR-01-cancel-invoice.md` |
| PHPUnit test | `tests/Feature/Invoice/CancelInvoiceTest.php` |
| Script test | `test_rr01_cancel_invoice.php` |
| Docker compose | `docker-compose.testing.yml` |
| Env template | `.env.testing.example` |
| Agent Rules | `AGENT_RULES.md` |
| Risk Register | `docs/audit/RISK_REGISTER.md` |
| Audit Report | Artifact: `system_audit_report.md` |
