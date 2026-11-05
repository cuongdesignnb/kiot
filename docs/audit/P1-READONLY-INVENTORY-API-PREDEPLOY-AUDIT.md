# P1 — Audit trước deploy: API tồn kho chỉ đọc + sửa xuất file (PR #100)

- PR: https://github.com/cuongdesignnb/kiot/pull/100
- Base: `production-customer-group` @ `365e36ed` (PR #99). Nhánh PR không lệch base (merge-base = base head).
- Ngày audit: 2026-11-05.
- Phạm vi: chỉ đánh giá code và môi trường test. **Không** có quyền SSH production (cổng 22 bị từ chối), nên trạng thái server phải được người deploy xác nhận tại Checkpoint B.

## 1. Kết luận

**Có thể deploy, với điều kiện thực hiện đúng checklist ở mục 4.** Rủi ro chính:

1. **Đụng vào tích hợp Website PC đang chạy thật.** `PcProductSyncService` được refactor để dùng chung quy tắc đếm serial. Production xác nhận tích hợp đang bật (`/api/integrations/v1/pc/connection` trả `401 INVALID_INTEGRATION_CLIENT`, không phải `503`).
2. **Đổi cách chạy 2 nút xuất file.** Nội dung file không đổi, nhưng cách đọc dữ liệu thay đổi.
3. **Bắt buộc có migration và build frontend.**

API mới được deploy ở trạng thái **tắt** (`INVENTORY_API_ENABLED=false`). Chỉ bật sau khi smoke test xong.

## 2. Bằng chứng

| Hạng mục | Kết quả |
|---|---|
| Base drift | `origin/production-customer-group` = `365e36ed` = merge-base của PR |
| PR mở khác vào cùng base đụng cùng file | Không có |
| Backup branch `backup/prod-*` gần nhất | Đều nằm trong base (không có hotfix prod nằm ngoài nhánh) |
| CI GitHub "Provider contract and POS regression" | Pass ở `fa672180` |
| Test local (MySQL 8 test DB) | InventoryApi + PcIntegration + Products + Invoices: **207 passed, 1 skipped** |
| Pint (các file PHP đổi) | Pass |
| `npm run build` | Pass, có chunk `InventoryApi-*.js` |
| `php artisan route:cache` / `config:cache` | Thành công |
| Migration `2026_11_05_000001` | `migrate` → `migrate:rollback --step=1` → `migrate` đều thành công trên test DB |
| Production trước deploy (HTTP, chỉ đọc) | `/up` 200; `/api/public/v1/inventory/products` 404; `/settings/integrations/inventory-api` 404; `/products/export`, `/invoices/export` 302 → login |

## 3. Đánh giá theo từng thay đổi

### 3.1. Schema (migration `2026_11_05_000001_create_inventory_api_tokens_table`)

- Chỉ **thêm** 2 bảng mới: `inventory_api_tokens` và `inventory_api_access_logs`. Không sửa hay xóa bảng cũ, không ghi dữ liệu.
- FK tới `users.id` dùng `nullOnDelete`.
- Có cột `json` (`abilities`, `query`). MySQL 8 chạy được; MariaDB lưu `json` dưới dạng `longtext` nên cũng chạy được.
- Rollback (`down`) xóa 2 bảng. Chỉ rollback khi chưa cần giữ token hoặc nhật ký truy cập.

### 3.2. API mới `/api/public/v1/inventory/*`

- Chỉ có 2 route GET. Middleware riêng `inventory.api`, không đi qua Sanctum hay HMAC của Website PC.
- Có test chứng minh token này không gọi được `/api/user`, `/api/media`, `/api/integrations/v1/pc/*`; POST/PUT/PATCH/DELETE → 405.
- Mặc định **tắt**: mọi request trả `503 API_DISABLED`. Trang Cài đặt hiện cảnh báo khi API đang tắt.
- Rate limit dùng `RateLimiter` trên cache store mặc định, giống tích hợp PC hiện tại.
- Không trả giá vốn hay trường nội bộ (có test).

### 3.3. Refactor `PcProductSyncService` (rủi ro trung bình)

- Hai sub-query `ready_serial_quantity` và `repairing_serial_quantity` được chuyển sang `App\Support\Inventory\SerialReadiness`. SQL sinh ra giữ nguyên điều kiện:
  - `status = 'in_stock'`
  - Sẵn bán: `repair_status IS NULL OR NOT IN ('not_started','repairing')`
  - Đang sửa: `repair_status IN ('not_started','repairing')`
- Toàn bộ test `tests/Feature/PcIntegration` vẫn pass, gồm contract v1/v2, đơn hàng, đặt giữ hàng, chữ ký HMAC.
- Smoke bắt buộc sau deploy: xem mục 4, Checkpoint D.

### 3.4. Xuất hóa đơn (`InvoiceController::export`)

- **Trước:** `Invoice::with('customer')->get()` nạp toàn bộ hóa đơn. Đo được khoảng 254MB với 30k hóa đơn, đây là nguyên nghi ngờ gây lỗi 503.
- **Sau:**
  - Một query lấy khách hàng, cộng một `cursor()` chỉ lấy 10 cột cần cho CSV.
  - Bộ lọc, cột CSV và thứ tự (mới nhất trước) giữ nguyên; thêm `id desc` làm tiêu chí phụ để thứ tự ổn định.
  - Đo được 100k hóa đơn: 6,9s, thêm khoảng 40MB.
- Lưu ý: PDO MySQL mặc định lưu tạm toàn bộ kết quả của `cursor()` dưới dạng mảng thô. Phần này nhỏ hơn nhiều so với Eloquent model, nhưng vẫn tăng theo số hóa đơn.

### 3.5. Xuất hàng hóa (`ProductController::export`, `ProductExcelExportService`)

- Đọc theo lô `lazy(500)`, định dạng theo cả cột thay vì từng ô, độ rộng cột tính sẵn (tối đa 60) thay vì auto-size.
- Đo được: 3k sản phẩm từ 10,1s xuống 2,7s; 10k sản phẩm khoảng 10s, 166MB.
- Nội dung, tiêu đề, sheet, quyền xem giá vốn không đổi. Có test mới cho tìm kiếm + nhiều lô (505 dòng, không trùng, không thiếu).
- Khác biệt nhìn thấy được: độ rộng cột có thể hơi khác so với auto-size cũ.

### 3.6. `ExportRuntime::prepare()`

- Hai action xuất được nâng `memory_limit` lên ít nhất 512M và `set_time_limit(300)`, chỉ trong request đó.
- Cần xác nhận trên server:
  - `pm.max_children × 512M` không vượt RAM nếu nhiều người xuất cùng lúc.
  - php-fpm không cấm `ini_set`.
  - `request_terminate_timeout` (php-fpm) và `fastcgi_read_timeout` (nginx) đủ cho file lớn.

### 3.7. Nguyên nhân 503 chưa được xác nhận bằng log

Số liệu ở 3.4 và 3.5 đo bằng dữ liệu giả lập. Trước khi deploy nên đọc log để chắc bản sửa đúng chỗ:

```bash
grep -n "Allowed memory size\|Maximum execution time" storage/logs/laravel*.log | tail -20
```

```bash
sudo tail -n 200 /var/log/nginx/error.log | grep -i -E "upstream timed out|limiting requests|export"
```

Nếu log cho thấy nguyên nhân khác, ví dụ nginx `limit_req` trả 503, thì phải sửa cấu hình server; code trong PR này không giải quyết được.

## 4. Checklist deploy

### Checkpoint A — trước khi merge

```text
PR100_CI_GREEN = YES
PR100_UNRESOLVED_THREADS = 0
EXPECTED_PR100_HEAD = <head SHA tại thời điểm merge>
BASE_STILL_365e36ed_OR_REBASED_AND_RETESTED = YES
```

### Checkpoint B — trên server, trước khi pull

1. Ghi lại SHA đang chạy: `git rev-parse HEAD`. Tạo nhánh backup theo quy ước: `backup/prod-before-pr100-<yyyymmdd-hhmmss>`.
2. Chạy `git status --porcelain`. **Nếu có file sửa tay trên server thì DỪNG** và đối chiếu trước khi pull.
3. Backup DB có thể khôi phục. Ghi mã hoặc thời gian backup ra ngoài Git.
4. Đọc log nguyên nhân 503 (mục 3.7).
5. Xác nhận giới hạn PHP-FPM/nginx (mục 3.6).

### Checkpoint C — deploy

```dotenv
INVENTORY_API_ENABLED=false
```

```bash
git pull origin production-customer-group
php artisan migrate --force
npm ci && npm run build
php artisan optimize:clear
php artisan route:list --path=api/public/v1/inventory
```

- Không cần `composer install` (không thêm package).
- Nếu server không build frontend tại chỗ, phải đưa `public/build` mới lên. Nếu thiếu, trang Cài đặt → API tồn kho sẽ lỗi 500.

### Checkpoint D — smoke test (API vẫn tắt)

- `route:list` có đúng 2 route GET `api/public/v1/inventory/products|serials`.
- `curl https://kiot.cuongdesign.net/api/public/v1/inventory/products` trả `503 API_DISABLED`.
- **Website PC (laptopplus.vn) vẫn đồng bộ bình thường:** số tồn, `available_quantity`, máy đang sửa hiển thị như trước deploy. Đối chiếu 2–3 SKU có serial đang sửa.
- Nút **Xuất file** ở Hàng hóa và Hóa đơn tải được file; mở file kiểm tra cột và số dòng.
- POS bán hàng, tạo hóa đơn, tồn kho, serial hoạt động bình thường; `laravel.log` không có lỗi mới.
- Cài đặt → **API tồn kho (chỉ đọc)** mở được, có cảnh báo "API đang tắt".

### Checkpoint E — bật API

1. Tạo token cho Laptop Plus trên trang Cài đặt. Token chỉ hiện 1 lần.
2. Đặt `INVENTORY_API_ENABLED=true`, chạy `php artisan optimize:clear`.
3. `curl -H "Authorization: Bearer <token>" ".../api/public/v1/inventory/products?limit=2"` trả 200, có `data` và `meta`. Thử token sai thì trả 401.
4. Gửi token và `docs/integrations/INVENTORY-READONLY-API.md` cho khách qua kênh riêng.

## 5. Rollback

1. **Lỗi API mới:** đặt `INVENTORY_API_ENABLED=false`, chạy `php artisan optimize:clear`, hoặc thu hồi token. Không cần rollback code.
2. **Lỗi Website PC hoặc xuất file:** checkout lại SHA đã ghi ở Checkpoint B, chạy `npm run build` và `php artisan optimize:clear`.
   - Có thể giữ 2 bảng mới: chúng không ảnh hưởng code cũ.
3. Chỉ chạy `php artisan migrate:rollback --step=1` khi chắc chắn không cần giữ token hay nhật ký truy cập.
