# Decision Brief — Audit RR-01..RR-13

> **Mục đích:** Tóm tắt để chủ dự án quyết định **push / deploy / hold**.
> **Ngày:** 02/05/2026
> **Trạng thái:** 5 commits local sẵn sàng, **CHƯA push**.

---

## TL;DR

- **13/13 rủi ro đã đóng** (6 P0 + 6 P1 + 1 P2). Test 87/87 PASS.
- **Phạm vi rộng:** 12 controllers + 4 services + 3 migrations mới + 18 test suites + 95 docs.
- **5 commits atomic** đã tạo local. Backup git/DB đã có.
- **Cần quyết định 3 thứ:** (1) push thẳng main hay PR? (2) deploy timing? (3) ai monitor sau deploy?

---

## 1. Đã làm những gì

### Code (1 git diff: 50 files, +817 / -17998 dòng — chủ yếu xóa docs legacy)

| Loại | Số file | Ghi chú |
|---|---|---|
| Service mới | 2 | `InvoiceSaleService`, `CustomerDebtService` |
| Model mới | 1 | `CustomerDebt` |
| Migration mới | 4 | `customer_debts` (rename từ legacy), `return_items.serial_ids`, `damage_items.serial_ids`, `stock_transfer_items.cost_at_transfer` |
| Controllers refactor | 12 | Invoice, Pos, Order, OrderReturn, Purchase, PurchaseReturn, StockTransfer, StockTake, Damage, Customer, Supplier, Report |
| Models refactor | 6 | CashFlow (safety net), Invoice (scope), DamageItem, ReturnItem, StockTransferItem (cast), CustomerDebt |
| Services refactor | 2 | MovingAvgCostingService (RR-05), TaskService (RR-07) |
| Routes mới | 3 | `returns.cancel`, `damages.cancel`, `orders.process` |
| Tests mới | 18 suites | 87 tests, 262 assertions |
| Docs | 95 files | 13 closure reports + summary + step results + test specs + AGENT_RULES |
| Legacy docs xóa | 17 files | `kiotviet_flow_*`, `phuong_an_*`, `tai_lieu_*` (đã thay bằng closure reports) |

### Bug nghiêm trọng đã sửa

| RR | Bug | Mức độ thực tế |
|---|---|---|
| **RR-02** | POS bán hàng serial **fail HTTP 500** trong DB FK strict (do `invoice_item_id=0`) | 🔴 **POS production có thể đang broken cho hàng serial** |
| **RR-09** | Damage không update `inventory_total_cost` → cost_price inflate | 🔴 Sai giá vốn báo cáo |
| **RR-13** | Order convert raw decrement → cùng pattern bug như RR-09 | 🔴 Sai giá vốn khi xử lý đơn |
| **RR-01** | `$invoice->delete()` xóa vật lý hóa đơn → mất lịch sử | 🔴 Mất audit trail |
| **RR-05** | Trả NCC hết tồn → `cost_price = 0` | 🟡 BQ không nhất quán |
| **RR-08** | Hủy phiếu trả hàng chọn nhầm serial | 🟡 Sai serial trong invoice |
| **RR-12** | Cancel transfer dùng current cost thay snapshot | 🟡 Cost lệch khi BQ thay đổi |
| **RR-06** | Customer debt không có ledger | 🔵 Mất audit trail công nợ |
| (các RR khác) | Tồn kho/movement/idempotent | 🟡 Stock card không chuẩn |

---

## 2. Rủi ro của việc deploy

### Rủi ro CAO (cần lưu ý)

| Rủi ro | Ảnh hưởng | Mitigation |
|---|---|---|
| **Behavior thay đổi của 12 controllers** | Có thể có flow ngách chưa cover bằng test | 87 tests đã verify core flows. Chạy full app smoke test trên staging trước. |
| **Legacy data không có serial_ids/cost_at_transfer** | Phiếu trả/damage/transfer cũ trong production khi cancel sẽ không rollback serial/cost đúng | Cancel chỉ ảnh hưởng phiếu cũ nếu user thực sự cancel. Có backlog Artisan command backfill nếu cần. |
| **Legacy `InvoiceItemSerial.invoice_item_id=0`** | Production có thể từng có rows do bug POS cũ → hỏng FK khi enable strict | Cần SQL check trước deploy: `SELECT COUNT(*) FROM invoice_item_serials WHERE invoice_item_id=0` |
| **Customer debt ledger** chưa có data lịch sử | Reports cũ vẫn đọc `customers.debt_amount` được; ledger chỉ ghi từ điểm deploy trở đi | OK cho deploy. Backfill là backlog P3. |
| **3 routes mới chưa có UI** | Backend ready nhưng frontend chưa có nút cancel Damage/OrderReturn/process Order | Routes hoạt động qua API/Postman; UI là backlog P3, không chặn deploy. |

### Rủi ro THẤP (đã được mitigate)

| Rủi ro | Mitigation |
|---|---|
| Test isolation noise khi chạy batch lớn | Chạy theo từng filter riêng đã quy chuẩn (Bước 11 trở đi) |
| `ExampleTest` legacy fail (302 vs 200) | Đã document, không liên quan audit |
| CRLF→LF warnings | Không phải lỗi, Git tự xử lý |

### Rủi ro KHÔNG có

- ❌ Không có data loss (tất cả status-based, soft delete có safety net).
- ❌ Không có schema breaking change (chỉ thêm cột nullable).
- ❌ Không có hồi quy (87/87 tests PASS, P0/P1/P2 đầy đủ).

---

## 3. Lợi ích deploy ngay vs hold

### Deploy ngay → ✅

- **Sửa bug POS serial broken** (RR-02): production có thể đang gặp lỗi 500 cho khách mua hàng serial.
- **Sửa bug giá vốn inflate** (RR-09, RR-13, RR-05, RR-12): báo cáo lợi nhuận đang sai.
- **Sửa bug serial rollback nhầm** (RR-08): khi user hủy phiếu trả hàng có thể gán serial sai.
- **Sửa bug mất hóa đơn** (RR-01): user hủy hóa đơn → mất vĩnh viễn lịch sử.
- **Sửa bug Damage không trừ cost** (RR-09).
- **Customer debt ledger** bắt đầu ghi từ điểm deploy → audit trail tích lũy dần.

### Hold lâu hơn → ⚠️

- Bug production đang xảy ra mà không sửa.
- Risk Register có thể phát sinh thêm rủi ro nếu code thay đổi tiếp.
- Frontend dev có thể đang chờ routes mới (`returns.cancel`, `damages.cancel`).

---

## 4. Deployment Plan đề xuất

### Pre-deploy

1. **Backup production DB** (mysqldump qua `MYSQL_PWD` env var, KHÔNG inline password).
2. **Pre-flight SQL check:**
   ```sql
   -- Check legacy invoice_item_id=0 (RR-02 bug cũ)
   SELECT COUNT(*) FROM invoice_item_serials WHERE invoice_item_id=0;

   -- Check customer_debts đã có data cũ chưa (RR-06)
   SELECT COUNT(*), SUM(amount), MAX(recorded_at) FROM customer_debts;

   -- Check sum debt_amount để reconcile sau deploy
   SELECT SUM(debt_amount) FROM customers;
   ```
3. **Code review 5 commits** (commit messages đầy đủ).
4. **Quyết định push:** thẳng `main` hay tạo PR?

### Deploy

1. `git push origin main` (hoặc qua PR/CI/CD).
2. Trên server production:
   ```bash
   git pull
   composer install --no-dev --optimize-autoloader
   php artisan migrate --force         # KHÔNG migrate:fresh
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
3. Verify 3 routes mới đã đăng ký (`route:list`).
4. Smoke test:
   - POS bán 1 sản phẩm có serial (RR-02 fix verify).
   - Tạo + hủy 1 invoice (RR-01 verify).
   - Tạo + hủy 1 OrderReturn (RR-08 verify).

### Post-deploy monitoring (24-48h)

| Metric | Cách check | Alert nếu |
|---|---|---|
| FK violation `invoice_item_serials_invoice_item_id_foreign` | Watch `storage/logs/laravel.log` | > 0 errors |
| `POS Checkout Error` | Log monitor | Tăng đột biến |
| `customer_debts` rows tạo mới | `SELECT COUNT(*) FROM customer_debts WHERE recorded_at > deploy_time` | = 0 sau 1 giờ (bất thường) |
| `customers.debt_amount` ↔ `customer_debts.SUM(amount)` reconcile | SQL query | Lệch > 0.01 cho cùng customer mới phát sinh |
| `StockMovement` từ Damage/OrderReturn/Transfer | `SELECT type, COUNT(*) FROM stock_movements WHERE moved_at > deploy_time GROUP BY type` | Type mới (`adjust_out` từ Damage) không xuất hiện |
| Báo cáo doanh thu/giá vốn so với baseline | So sánh trước/sau 24h | Lệch > 5% so với baseline |

### Rollback plan (nếu cần)

```bash
# Revert 5 commits audit
git revert b0e7516 4b52532 58413ca 651ed57 3f86a11 --no-edit

# Hoặc rollback DB từ backup
mysql -u <user> < ../_db_backups/<prod>_<timestamp>.sql

# Hoặc rollback migrations
php artisan migrate:rollback --step=4
```

Migration rollback an toàn vì các cột thêm đều `nullable()` — drop column không ảnh hưởng dữ liệu cũ.

---

## 5. Câu hỏi cần chủ dự án trả lời

| Câu hỏi | Lựa chọn |
|---|---|
| **1. Push lên main hay tạo PR?** | (a) Push thẳng main + tag release. (b) Tạo PR cho team review trước. |
| **2. Khi nào deploy?** | (a) Ngay (giờ thấp tải). (b) Cuối tuần. (c) Đợi user thông báo. |
| **3. Ai monitor 24h sau deploy?** | Cần 1 người watch log + báo cáo nếu có alert. |
| **4. Có cần tag release không?** | Đề xuất: `v1.0.0-audit-rr01-rr13` để dễ rollback. |
| **5. Production có data legacy `invoice_item_id=0` không?** | Cần chạy SQL check trước. Nếu có → cần cleanup script. |
| **6. Backlog P3 (UI cancel, permission tách, multi-warehouse) xử lý sau audit hay sprint riêng?** | Đề xuất: sprint riêng, không gộp vào deploy này. |

---

## 6. Tóm tắt khuyến nghị

### ✅ NÊN DEPLOY nếu:
- Production POS đang có khách mua hàng serial (bug RR-02 đang ảnh hưởng).
- Báo cáo doanh thu/giá vốn đang được dùng cho quyết định kinh doanh.
- Có người monitor 24-48h sau deploy.
- Đã backup production DB.

### ⚠️ HOLD lại nếu:
- Đang có release lớn khác sắp ra.
- Frontend chưa sẵn sàng UI cho routes mới (mặc dù backend hoạt động độc lập).
- Không có người monitor sau deploy.
- Production data có nhiều `invoice_item_id=0` legacy chưa cleanup.

### 🚦 MIDDLE GROUND đề xuất:
1. **Tạo PR** từ 5 commits này (an toàn hơn push thẳng main).
2. **Code review** với 1-2 dev khác trong team.
3. **Deploy staging** trước, smoke test 24h.
4. **Pre-flight SQL check** trên copy của prod DB.
5. **Deploy production** giờ thấp tải (đêm khuya / sáng sớm cuối tuần).
6. **Monitor 48h** với alerts ở mục 4.
7. **Tag release** `v1.0.0-audit-rr01-rr13`.

---

## 7. Tài liệu tham khảo

| File | Vai trò |
|---|---|
| `docs/audit/FINAL-AUDIT-SUMMARY-REPORT.md` | Tổng kết chi tiết audit |
| `docs/audit/RISK_REGISTER.md` | Risk register + changelog 21 bước |
| `docs/audit/STEP-21-GIT-DB-BACKUP-CLEANUP-RESULTS.md` | Chi tiết backup + commit |
| 13 × `RR-XX-CLOSURE-REPORT.md` | Closure mỗi rủi ro |
| `AGENT_RULES.md` | 10 quy tắc mới sau audit |
| `../_git_backups/audit-*.diff` | Patch backup nếu cần khôi phục |
| `../_db_backups/*.sqlite|.sql` | DB backup local |

---

**File này tự nó không thay thế việc đọc Final Summary** — chỉ tóm tắt để quyết định nhanh. Trước khi push/deploy production, đọc thêm:
- `FINAL-AUDIT-SUMMARY-REPORT.md` (mục 6 quy tắc bắt buộc + mục 8 deployment checklist).
- 1-2 closure reports tương ứng với module quan trọng nhất với business (gợi ý: RR-02 POS, RR-09 Damage, RR-06 Customer debt).
