# STEP-22.2C — Main Commit Results

## 1. Quyết định

User chọn commit thẳng vào main để test trực tiếp với dữ liệu thật trên production. QA branch đã merge fast-forward vào main, không tạo merge commit. Branch QA và backup đều giữ lại để rollback.

## 2. Rollback point

| | |
|---|---|
| Backup branch (local + remote) | `backup-before-ui-p3-20260503-223620` |
| Rollback tag (local + remote) | `before-ui-p3-serial-20260503-223620` |
| Commit trước deploy | `baa62bc` |
| Branch QA cũ (giữ lại) | `qa/ui-p3-serial-compat` |

Tất cả đã pushed lên `origin`.

## 3. Commit mới

| | |
|---|---|
| HEAD main | `b08d531` |
| Push main | ✅ `baa62bc..b08d531  main -> main` |
| Commit feat | `8509d3a` — `feat(ui): complete post-audit P3 workflows with serial compatibility` |
| Commit docs | `b08d531` — `docs(audit): step 22.2C QA branch results` |
| Số file (FF merge tổng hợp) | 9 file changed, +943 / −61 |

## 4. Build/Test (sau merge, trước push)

| Lệnh | Kết quả |
|---|---|
| `php artisan optimize:clear` | All caches cleared |
| `npm run build` | ✓ built in 6.32s |
| `php artisan test --env=testing --filter="RR02\|RR06\|RR08\|RR09\|RR13\|SerialAvailability"` | **29 passed, 2 skipped** (141 assertions, 2.81s) |
| `git diff --check` | No whitespace errors |

2 test skipped đúng kỳ vọng:
- `legacy null status` — schema NOT NULL bảo vệ.
- `legacy alias status` — ENUM chặn (chỉ kích hoạt nếu future mở rộng).

## 5. File thay đổi

| Status | File | Step |
|---|---|---|
| M | `app/Http/Controllers/OrderController.php` | 22.2A |
| M | `app/Http/Controllers/PosController.php` | 22.2A |
| M | `resources/js/Pages/Orders/Create.vue` | 22.2A + 22.2B |
| A | `app/Services/SerialAvailabilityService.php` | 22.2A |
| A | `tests/Feature/Serials/SerialAvailabilityServiceTest.php` | 22.2A |
| A | `docs/audit/STEP-22.2A-SERIAL-AVAILABILITY-CONTRACT.md` | 22.2A |
| A | `docs/audit/STEP-22.2A-SERIAL-AVAILABILITY-COMPATIBILITY-RESULTS.md` | 22.2A |
| A | `docs/audit/STEP-22.2B-SERIAL-LOADING-STUCK-FIX-RESULTS.md` | 22.2B |
| A | `docs/audit/STEP-22.2C-QA-BRANCH-RESULTS.md` | 22.2C (legacy report, giữ làm history) |

Step 22.1A/B/C/D/E đã ở main từ trước (`fd3a14e`/`254073f`/`baa62bc`).

Excluded khỏi commit:
- `.env`, `storage/logs/*`, `node_modules/`, `vendor/`.
- `database/database.sqlite`.
- `.claude/` (untracked).
- `diagnose_serial.php` (đã xoá ở 22.2A).
- `.phpunit.result.cache` (gitignored).
- `public/build/*` (không track theo trạng thái cũ).

## 6. Production deploy note

Trên server production chạy theo thứ tự:

```bash
git fetch origin
git pull origin main          # về b08d531
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force   # KHÔNG có migration mới ở step này, chỉ chạy phòng hờ schema legacy
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**Lưu ý migrate:**
- Step 22.2A/B KHÔNG thêm migration. Chỉ thay đổi controller/service/UI.
- Service đã schema-tolerant (dùng `Schema::hasColumn` cho `invoice_id`/`sold_at`/`purchase_return_id`). Nên chạy được trên cả schema cũ lẫn mới.

## 7. Rollback nếu lỗi

### 7.1 Rollback nhanh trên production (không đụng git history)

```bash
git fetch origin
git checkout before-ui-p3-serial-20260503-223620
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

⇒ Đưa code production về đúng `baa62bc`. Không ảnh hưởng remote.

### 7.2 Rollback main (chỉ làm khi chắc chắn)

```bash
git checkout main
git reset --hard before-ui-p3-serial-20260503-223620
git push origin main --force-with-lease
```

⚠️ `--force-with-lease` an toàn hơn `--force`. KHÔNG dùng `--force` thuần.

### 7.3 Khôi phục từ backup branch (an toàn nhất)

```bash
git checkout main
git reset --hard origin/backup-before-ui-p3-20260503-223620
git push origin main --force-with-lease
```

Backup branch là pointer tĩnh — không bị overwrite khi commit thêm.

## 8. Trạng thái hiện tại

| | |
|---|---|
| Commit ở main | `b08d531` |
| Pushed main | ✅ |
| Pushed tag rollback | ✅ |
| Pushed backup branch | ✅ |
| Production đã pull chưa | Đợi user thực hiện |
| Test pass | 29/29 (+2 skip) |
| Build pass | ✅ |
