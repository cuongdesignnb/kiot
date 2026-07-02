# Step 22.2A — Serial/IMEI Availability Compatibility — RESULTS

**Status:** Implementation done, NOT committed/pushed (chờ user QA pass).

## 1. Discovery — Schema thật

Local sqlite có schema cũ (thiếu invoice_id/sold_at/sold_cost_price/purchase_return_id) → confirm rằng dev DB không đại diện production. Schema production từ migrations:

```sql
serial_imeis (
  id, product_id, serial_number,
  status ENUM('in_stock','sold','returning','warranty','defective','returned')
         NOT NULL DEFAULT 'in_stock',
  repair_status NULLABLE,
  invoice_id NULLABLE,
  sold_at NULLABLE,
  sold_cost_price NULLABLE,
  purchase_id NULLABLE,
  purchase_return_id NULLABLE,
  cost_price, original_cost,
  warranty_expires_at, variant_id,
  created_at, updated_at
)
```

| Câu hỏi | Phát hiện |
|---|---|
| status có NULL được? | KHÔNG — `NOT NULL DEFAULT 'in_stock'` |
| status alias `available`/`ready`? | KHÔNG ở DB hiện tại — không có trong ENUM |
| Sellable status thực tế | Chỉ `'in_stock'` |
| Blocked ENUM values | `sold`, `returning`, `warranty`, `defective`, `returned` |
| Cột legacy có thể thiếu (cũ/cũ hơn) | `invoice_id`, `sold_at`, `sold_cost_price`, `purchase_return_id` — cần `Schema::hasColumn` |
| Endpoint cũ filter | `status='in_stock'` + `repair_status NULL OR NOT IN [not_started,repairing]` |
| HTTP status sau 22.1E | 200 (route đã out khỏi `pos.use` middleware) |
| Root cause "không load" còn lại | Filter quá cứng + endpoint trả raw model → frontend không phân biệt legacy. Sửa qua service abstraction. |

## 2. Files đã thay đổi

| File | Tóm tắt |
|---|---|
| `app/Services/SerialAvailabilityService.php` (mới) | 1 service duy nhất quyết định sellable. Schema-tolerant qua `Schema::hasColumn`. Status-tolerant cho future ENUM mở rộng. |
| `app/Http/Controllers/PosController.php` @getProductSerials | Inject service, dùng `querySellableForProduct` + `normalizeForResponse`. Response thêm `label`, `is_legacy_status`. |
| `app/Http/Controllers/OrderController.php` (3 chỗ) | `store`, `update`, `processOrder` thay `where('status','in_stock')` bằng `findBlockedIds()`. Lỗi message liệt kê id offending. |
| `resources/js/Pages/Orders/Create.vue` | Đổi message "in_stock" → "khả dụng". Hiển thị badge `(cũ)` khi `is_legacy_status=true`. Dùng `s.label` fallback. |
| `tests/Feature/Serials/SerialAvailabilityServiceTest.php` (mới) | 7 test cases (5 chạy + 2 skipped do schema bảo vệ). |
| `docs/audit/STEP-22.2A-SERIAL-AVAILABILITY-CONTRACT.md` (mới) | Contract chính thức. |

## 3. Test results

```
php artisan test --filter="RR02|RR06|RR08|RR09|RR13|SerialAvailability"

  Tests:    2 skipped, 29 passed (141 assertions)
  Duration: 3.04s
```

- 24 regression cũ (RR02|RR06|RR08|RR09|RR13) — pass.
- 5 SerialAvailability mới — pass.
- 2 skipped: `legacy null status` (DB NOT NULL bảo vệ) + `alias status` (ENUM chặn) — đúng kỳ vọng.

`npm run build` — OK 7.45s.

## 4. Behaviour matrix sau fix

| Tình huống serial | Trước 22.2A | Sau 22.2A |
|---|---|---|
| status='in_stock', repair NULL, không invoice/sold | Hiển thị | Hiển thị |
| status='in_stock', repair='ready' | Hiển thị | Hiển thị |
| status='in_stock', repair='repairing' | Ẩn | Ẩn |
| status='in_stock' nhưng có invoice_id (data inconsistency) | **Hiển thị nhầm** | **Ẩn (an toàn)** |
| status='in_stock' nhưng có sold_at | **Hiển thị nhầm** | **Ẩn (an toàn)** |
| status='in_stock' nhưng có purchase_return_id | **Hiển thị nhầm** | **Ẩn (an toàn)** |
| status='returning'/'warranty'/'defective' | Ẩn | Ẩn |
| status='sold' / 'returned' | Ẩn | Ẩn |
| (Future) status NULL chưa bán | Ẩn (filter cứng) | Hiển thị + badge "cũ" |
| (Future) status='available'/'ready' | Ẩn | Hiển thị + badge "cũ" |

⇒ Service nghiêm ngặt hơn ở data-inconsistency edge case (status='in_stock' nhưng invoice_id set) và rộng hơn ở legacy variant.

## 5. KHÔNG làm

- KHÔNG migrate:fresh production.
- KHÔNG truncate/update mass.
- KHÔNG tự normalize status.
- KHÔNG tự chọn serial.
- KHÔNG sửa MovingAvgCostingService / StockMovementService / InvoiceSaleService.
- KHÔNG sửa test để che lỗi (chỉ skip những case schema bảo vệ).
- KHÔNG commit. KHÔNG push.

## 6. Việc tiếp theo nếu QA fail trên production

1. User cung cấp 1 product_id thực tế + dump 5-10 row `serial_imeis` của product đó (status, repair_status, invoice_id, sold_at, purchase_return_id, cost_price). Không cần data nhạy cảm.
2. Ta đối chiếu với `querySellableForProduct` rule, tìm field nào cản.
3. Nếu cần migration: tạo command `php artisan serials:audit --product=ID --dry-run` (chưa tạo bước này).

## 7. Câu lệnh kiểm tra nhanh

```bash
php artisan test --filter="SerialAvailability"
php artisan test --filter="RR13"
npm run build
```

## 8. Tệp tạm

`diagnose_serial.php` ở root — script discovery dev. Có thể xóa hoặc giữ tham khảo.

## 9. QA checklist cho user

1. Mở Order → Create → chọn product `has_serial=1`. Kiểm tra:
   - Selector hiển thị danh sách serial in_stock.
   - Serial đang `repairing` không xuất hiện.
   - Serial `sold` không xuất hiện.
   - Số "Đã chọn x/y" cập nhật khi tick.
2. Tạo Order với serial đã chọn → process Order → Invoice → kiểm tra serial chuyển status `sold`.
3. Thử validate âm tính: gọi API `GET /api/products/{id}/serials` cho product không có serial → trả `[]` không lỗi.
4. Báo lại nếu UI vẫn rỗng dù DB có serial in_stock — ta sẽ chuyển sang Step 22.2B (audit data thật).

## 10. Commit message dự kiến (chờ user OK)

```
feat(serials): step 22.2A SerialAvailabilityService + schema-tolerant filter

- New SerialAvailabilityService (sellable rule centralized)
- PosController.getProductSerials uses service
- OrderController store/update/processOrder validate via service
- Orders/Create.vue legacy badge + neutral message
- Adds invoice_id/sold_at/purchase_return_id null-check via Schema::hasColumn
- 7 new feature tests (5 pass + 2 schema-skip)
- Contract doc + results doc
```
