# HOTFIX 24.34 — Product Serial Dismantled Display

## 1. Vấn đề

- Tab `Serial/IMEI` trên trang chi tiết sản phẩm:
  - Một số dòng serial hiện raw tiếng Anh `dismantled` ở cột status bên phải.
  - Cùng dòng đó vẫn có badge `🔧 Đang sửa` ở bên trái.
- Filter dropdown thiếu option `Đã bóc tách`; backend `?status=ready` / `?status=repairing` cũng không phải status vật lý chuẩn nên có thể trả sai.

## 2. Source đã kiểm tra

- `app/Http/Controllers/ProductController.php` (`index` counts + `serials` filter)
- `app/Services/SerialAvailabilityService.php` (BLOCKED_STATUSES gồm `dismantled` — POS đã đúng từ trước)
- `app/Models/SerialImei.php`
- `resources/js/Pages/Welcome.vue` (badge order + filter dropdown + status label)
- `tests/Feature/Tasks/HOTFIX2416RepairSerialReadyStatusTest.php`
- `tests/Feature/Tasks/HOTFIX2418ReassembledSerialRestoresStockTest.php`
- `docs/audit/HOTFIX-24.16-REPAIR-SERIAL-DISMANTLED-READY.md`, `docs/audit/HOTFIX-24.18-REASSEMBLED-SERIAL-RESTORES-STOCK.md`

## 3. Data đã kiểm tra

Không truy vấn production trong session này. Tester chạy 4 SELECT trong brief để đối soát:

- Liệt kê serial theo `status`, `repair_status` của product đang lỗi.
- Đếm theo cặp `(status, repair_status)`.
- Đối soát công thức `ready_count` / `repairing_count` / `dismantled_count` (xem mục 5).

Code fix không phụ thuộc data thực tế — bug tái hiện rõ với chỉ 1 serial `status=dismantled` + `repair_status=repairing` (xem TC-2).

## 4. Root cause

- **UI**: `serialStatusLabel()` chưa có entry `dismantled` → cột status bên phải fallback ra raw `dismantled`.
- **UI**: thứ tự badge bên trái ưu tiên `repair_status === 'repairing'` / `'not_started'` TRƯỚC khi xét `status === 'dismantled'`. Một serial `dismantled` mà `repair_status=repairing` (chưa được đổi về `ready` lúc bóc) sẽ rơi vào nhánh đầu → hiện "🔧 Đang sửa".
- **Backend**: `ProductController@serials` filter `status` chỉ làm `where('status', $request->status)`. Khi FE gửi `ready` hoặc `repairing` (giá trị không phải status vật lý), query trả 0 row hoặc match nhầm. Không có khái niệm `dismantled` semantic.
- **Backend index counts**: thiếu `dismantled_count` ở cấp product → UI không có cách hiển thị "Đã bóc tách: X" tách bạch.

`dismantled` không bao giờ bị tính là sẵn bán: `Product.stock_quantity` (counts in_stock_count/ready_count) lấy `status='in_stock'`; `SerialAvailabilityService::BLOCKED_STATUSES` đã chặn `dismantled` khỏi POS. Bug chỉ ở tầng hiển thị/filter.

## 5. Phương án sửa

- **Backend `ProductController@serials`** — semantic filter:
  - `ready`: `status=in_stock AND (repair_status IS NULL OR NOT IN (not_started, repairing))`.
  - `repairing`: `status=in_stock AND repair_status IN (not_started, repairing)`.
  - `dismantled`: `status=dismantled`.
  - Giá trị khác fallback `where('status', $value)` (backward compat).
- **Backend `ProductController@index`** — thêm `dismantled_count = serialImeis().where('status','dismantled')->count()` cho product có `has_serial`. Tách hoàn toàn khỏi `repairing_count` và `ready_count`.
- **Frontend `Welcome.vue`**:
  - `serialStatusLabel()` map `dismantled → "Đã bóc tách"`.
  - Badge bên trái: `v-if="s.status === 'dismantled'"` đặt FIRST, sau đó mới đến `repair_status` branches. Serial dismantled luôn hiện `⚠ Đã bóc tách` (đỏ), không bao giờ hiện `🔧 Đang sửa` hay `✓ Sẵn bán`.
  - Cột status bên phải + ô status trong cost-table: thêm class đỏ riêng cho `dismantled`.
  - Background row dismantled: `bg-red-50/40` (override vàng `repair_status=repairing`).
  - Tên serial: text đỏ nếu dismantled (không bị overwritten bởi orange của repair).
  - Filter dropdown thêm option `dismantled` → "⚠ Đã bóc tách".

Không sửa data, không recompute, không migration.

## 6. File đã sửa

| File | Nội dung |
|---|---|
| `app/Http/Controllers/ProductController.php` | `serials()` filter semantic cho `ready`/`repairing`/`dismantled`. `index()` thêm `dismantled_count`. |
| `resources/js/Pages/Welcome.vue` | `serialStatusLabel` map `dismantled`. Badge order ưu tiên `status==='dismantled'`. Filter dropdown thêm option. Color classes cho dismantled (status, row, tên serial). |
| `tests/Feature/Products/HOTFIX2434ProductSerialDismantledDisplayTest.php` | NEW — 7 TC. |

## 7. Data safety

| Loại | Kết quả |
|---|---|
| Migration | Không |
| Backfill | Không |
| Update dữ liệu cũ | Không |
| Recalculate tồn kho | Không |
| Recalculate giá vốn | Không |
| Stock movement | Không |
| Sửa serial / invoice / return / cashflow | Không |

## 8. Tests

| Lệnh | Kết quả |
|---|---|
| `php artisan test --filter=HOTFIX2434ProductSerialDismantledDisplayTest` | ✅ **7 passed / 27 assertions**, 0.81s |
| `php artisan test --filter="HOTFIX2434\|HOTFIX2416\|HOTFIX2418\|HOTFIX2433\|HOTFIX2432\|HOTFIX2431\|HOTFIX2430\|Serial\|Product\|Repair\|Invoice\|POS\|Pos"` | ✅ **333 passed / 4 skipped / 1855 assertions**, 51.82s, zero fail |
| `npm run build` | ✅ **built in 6.62s** |

**7 TC trong `HOTFIX2434ProductSerialDismantledDisplayTest`:**

1. `filter_ready_excludes_dismantled` — `?status=ready` bỏ serial `dismantled` dù có `repair_status=ready`.
2. `filter_repairing_excludes_dismantled` — `?status=repairing` bỏ serial `dismantled + repair_status=repairing` (exactly the bug shown trong ảnh user).
3. `filter_dismantled_returns_only_dismantled` — `?status=dismantled` chỉ có serial `status=dismantled`.
4. `product_index_counts_dismantled_separately` — `ready_count=10`, `repairing_count=4`, `dismantled_count=5`, `in_stock_count=14`, `total_serial_count=19`.
5. `pos_sellable_serial_feed_excludes_dismantled` — `GET /api/products/{p}/serials` (`SerialAvailabilityService::querySellableForProduct`) không trả serial `dismantled` (xác nhận POS đã đúng từ trước).
6. `filter_all_returns_every_status` — `?status=all` trả tất cả status (escape hatch).
7. `dismantled_serial_response_keeps_status_dismantled` — API vẫn emit raw `status='dismantled'`, FE `serialStatusLabel` đảm nhận việc map sang `Đã bóc tách`.

Đặc biệt 24.16 và 24.18 vẫn pass:

- `tests/Feature/Tasks/HOTFIX2416RepairSerialReadyStatusTest.php` ✅
- `tests/Feature/Tasks/HOTFIX2418ReassembledSerialRestoresStockTest.php` ✅

(Đã chạy chung trong filter regression "Repair|Serial|Product" — zero fail.)

## 9. Manual QA

- Tab Serial/IMEI của sản phẩm: serial `status=dismantled` hiển thị `⚠ Đã bóc tách` (đỏ), không hiện `Đang sửa`, không hiện `Sẵn bán`. Cột status bên phải hiển thị `Đã bóc tách` (đỏ), không raw `dismantled`.
- Filter `✓ Sẵn bán`: không có serial `dismantled`.
- Filter `🔧 Đang sửa/Chờ xử lý`: chỉ có `status=in_stock + repair_status in (not_started,repairing)`. Không có `dismantled`.
- Filter `⚠ Đã bóc tách`: chỉ có `status=dismantled`.
- POS: serial `dismantled` không xuất hiện trong dropdown chọn serial, không bán được. `Sẵn bán` count khớp serial thật.

## 10. Kết luận

- Còn raw `dismantled` trong UI: **Không**. `serialStatusLabel` map đầy đủ.
- Dismantled còn bị tính là sẵn bán: **Không** (đã đúng từ HOTFIX 24.16/24.18; HOTFIX 24.34 chỉ fix hiển thị/filter).
- POS bán được serial dismantled: **Không** (TC-5 pin).
- Có thể deploy: ✅
- Commit SHA: `2573064` — `fix(products): display dismantled serials correctly`.
- Push status: chưa push (chờ user xác nhận push to origin/main).
