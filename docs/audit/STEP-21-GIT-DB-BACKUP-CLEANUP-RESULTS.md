# STEP-21 — Git/DB Backup/Cleanup Results

> **Bước:** 21 — Git backup + Database backup + Cleanup + Commit audit RR-01..RR-13
> **Ngày:** 02/05/2026
> **Phạm vi:** Chỉ backup, scan, cleanup file rác, commit. **Không sửa business logic, không sửa UI, không push remote.**

---

## 1. Git status ban đầu

| Mục | Giá trị |
|---|---|
| Branch | `main` |
| Modified | 21 files (12 controllers + 5 models + 2 services + .gitignore + 1 migration deleted by rename) |
| Untracked | 30+ files (services mới, migrations mới, tests, docs/audit/*, AGENT_RULES, docker-compose.testing) |
| Deleted | 27 files (legacy KiotViet docs + 1 legacy migration) |
| Tổng diff | 50 files changed, +817 / -17998 (chủ yếu xóa docs legacy) |

### File nhạy cảm phát hiện

| File | Trạng thái | Xử lý |
|---|---|---|
| `.env.testing` | Chứa DB credentials test | ❌ KHÔNG commit; thêm vào `.gitignore` |
| `.env.testing.example` | Template không secret | ✅ Commit (an toàn) |
| `test_rr01_cancel_invoice.php` | File rác ad-hoc ở root | ❌ Xóa |
| `database/database.sqlite` | Local SQLite dev DB (không tracked) | ❌ Không commit |
| `.claude/settings.local.json` | Tool settings local | ❌ Để untracked (không thêm gitignore vì không phải junk) |

---

## 2. Git backup

Folder backup ngoài repo: `../_git_backups/` (timestamp `20260503-125137`).

| File | Kích thước | Mục đích |
|---|---|---|
| `audit-working-tree-20260503-125137.diff` | 687 KB | Patch full diff working tree trước cleanup |
| `audit-status-20260503-125137.txt` | 3.3 KB | Snapshot `git status --short` |
| `audit-diff-stat-20260503-125137.txt` | 3.3 KB | Snapshot `git diff --stat` |

**Backup branch:** Không tạo (chọn cách patch backup vì 4 commit sạch theo plan đã đủ atomic).

**Khôi phục nếu cần:**
```bash
git apply ../_git_backups/audit-working-tree-20260503-125137.diff
```

Không add nhầm file nhạy cảm trong backup.

---

## 3. Database backup

Folder backup ngoài repo: `../_db_backups/` (timestamp `20260503-125137`).

### MySQL test DB (Docker `sales_mysql_test` port 3319)

| Mục | Giá trị |
|---|---|
| DB connection | `mysql` (Docker container `sales_mysql_test`, image `mysql:8.0`) |
| DB database | `sales_test` |
| Backup file | `../_db_backups/sales_test_20260503-125137.sql` |
| Size | 156 KB |
| Tool | `mysqldump --single-transaction --routines --triggers --events` |
| Password handling | Qua env var `MYSQL_PWD` (KHÔNG inline trong command) |
| Verify | ✅ File tạo OK (156KB > 0) |

### SQLite dev DB (theo `.env` chính)

| Mục | Giá trị |
|---|---|
| DB connection | `sqlite` (theo `.env` `DB_CONNECTION=sqlite`) |
| File path | `database/database.sqlite` |
| Backup file | `../_db_backups/database_20260503-125137.sqlite` |
| Size | 636 KB |
| Tool | `cp` (copy file) |
| Verify | ✅ File copy OK |

**Lưu ý:** Đây đều là DB development/testing local. Production deploy sẽ cần backup riêng theo môi trường thật.

**Không commit file backup database** vào git (folder ngoài repo).

---

## 4. Migration status

| Migration | Trạng thái testing DB (sau migrate:fresh) | Pending main `.env` SQLite |
|---|---|---|
| `2026_03_01_100000_create_customer_debts_table` | ✅ Ran | ⏳ Pending (do not run migrate:fresh on dev DB) |
| `2026_04_09_150000_add_type_to_customer_debts_table` | ✅ Ran | ⏳ Pending |
| `2026_05_02_120000_add_serial_ids_to_return_items_table` | ✅ Ran | ⏳ Pending |
| `2026_05_02_120100_add_serial_ids_to_damage_items_table` | ✅ Ran | ⏳ Pending |
| `2026_05_02_120200_add_cost_at_transfer_to_stock_transfer_items_table` | ✅ Ran | ⏳ Pending |

**Quy tắc:**
- Test DB: chạy `php artisan migrate:fresh --env=testing --force` (đã làm).
- Main dev / staging / production: chỉ chạy `php artisan migrate --force` sau khi đã backup. **KHÔNG `migrate:fresh` trên DB thật.**
- Step 21 không chạy migrate trên main `.env` — để user quyết định khi deploy.

---

## 5. Route check

```
php artisan route:list | grep -E "(returns.cancel|damages.cancel|orders.process)"
```

| Route name | Method | URI | Controller@method | Middleware |
|---|---|---|---|---|
| `returns.cancel` | POST | `returns/{return}/cancel` | `OrderReturnController@cancel` | `permission:returns.create` |
| `damages.cancel` | POST | `damages/{damage}/cancel` | `DamageController@cancel` | `permission:damages.create` |
| `orders.process` | POST | `orders/{order}/process` | `OrderController@processOrder` | `permission:orders.edit` |

✅ Đầy đủ 3 route mới đã đăng ký theo audit (RR-08, RR-09, RR-13).

---

## 6. Cleanup

| Action | File |
|---|---|
| Đã xóa | `test_rr01_cancel_invoice.php` (file rác ad-hoc ở root, không thuộc test suite Laravel) |
| Đã xóa | `.phpunit.result.cache` (cache file) |
| Đã add gitignore | `.env.testing` (chứa DB credentials) |
| Đã clear | `php artisan optimize:clear` (config/events/routes/views cache) |
| Đã revert | `database/migrations/2026_03_11_100001_create_roles_table.php` (sửa nhỏ ngoài scope audit, revert để giữ scope clean) |

### File untracked không commit (giữ nguyên)

| File | Lý do |
|---|---|
| `.claude/settings.local.json` | Local tool settings (Claude Code) |
| `.env.testing` | Đã ignored (DB credentials) |
| `database/database.sqlite` | Local dev DB (không tracked, có file ignore phù hợp) |

---

## 7. Test final

| Test suite | Result |
|---|---|
| `RR06CustomerDebtLedgerTest` | ✅ 5 PASS (14 assertions) |
| `RR13OrderConvertStockTest` | ✅ 4 PASS (19) |
| `RR02InvoicePosCharacterizationTest` | ✅ 5 PASS (48) |
| `CancelInvoiceTest` | ✅ 10 PASS (20) |
| `RR01ReportControllerRegressionTest` | ✅ 8 PASS (9) |
| `RR01SupplierDualRoleRegressionTest` | ✅ 2 PASS (4) |
| `RR01CashFlowCancelledRegressionTest` | ✅ 4 PASS (4) |
| `RR03StockTransferTest` | ✅ 5 PASS (12) |
| `RR03StockTransferRouteTest` | ✅ 3 PASS (10) |
| `RR04StockTakeTest` | ✅ 5 PASS (12) |
| `RR05MovingAvgCostingZeroStockTest` | ✅ 5 PASS (15) |
| `RR05SerialImeiCostingTest` | ✅ 4 PASS (16) |
| `RR07RepairPartsTest` | ✅ 4 PASS (9) |
| `RR08OrderReturnSerialRollbackTest` | ✅ 4 PASS (15) |
| `RR09DamageStockTest` | ✅ 5 PASS (12) |
| `RR10CashFlowDeletionTest` | ✅ 5 PASS (12) |
| `RR11OrderReturnQtyTest` | ✅ 4 PASS (8) |
| `RR12StockTransferCancelReceivedTest` | ✅ 5 PASS (23) |
| **Tổng** | ✅ **87 PASS, 0 FAIL** (262 assertions) |

---

## 8. Diff summary

```
git diff --check  → OK (chỉ warning CRLF→LF, không phải lỗi)
git diff --stat   → 50 files changed, +817 / -17998 deletions
```

Phân nhóm theo commit:

| Nhóm | Files | Lines |
|---|---|---|
| Models + Services + Migrations | 14 (3 new model/service + 7 modified + 4 migrations) | +752 / -14 |
| Controllers + Routes | 13 (12 controllers + routes/web.php) | +668 / -460 |
| Tests + Test infra | 20 (18 test files + docker-compose + .env.testing.example) | +4779 / 0 |
| Docs + AGENT_RULES + cleanup legacy | 95 (docs/audit + docs/test-cases + AGENT_RULES + .gitignore + 17 legacy docs deleted) | +11810 / -17491 |

---

## 9. Commit plan / Commit đã tạo

✅ **4 commits đã tạo theo plan, tất cả sạch:**

| # | Hash | Type | Subject |
|---|---|---|---|
| 1 | `651ed57` | `feat(audit)` | add audited services, models and persistence snapshots |
| 2 | `58413ca` | `fix(audit)` | route business flows through audited services |
| 3 | `4b52532` | `test(audit)` | add regression coverage for RR-01..RR-13 |
| 4 | `b0e7516` | `docs(audit)` | close RR-01..RR-13 risk register with full closure reports |

```
git log --oneline -5
b0e7516 docs(audit): close RR-01..RR-13 risk register with full closure reports
4b52532 test(audit): add regression coverage for RR-01..RR-13
58413ca fix(audit): route business flows through audited services
651ed57 feat(audit): add audited services, models and persistence snapshots
0a449f1 fix: header row totals now computed live from items   ← previous commit before audit
```

❌ **KHÔNG push** — đợi chủ dự án xác nhận.

---

## 10. Kết luận

✅ **An toàn để commit/push.**

- Working tree sạch (chỉ `.claude/` local untracked).
- 4 commits atomic, mỗi commit có message rõ ràng + Co-Authored-By Claude.
- Git patch backup đã lưu ngoài repo (`../_git_backups/`).
- Database backup đã lưu ngoài repo (`../_db_backups/`):
  - `sales_test_20260503-125137.sql` (156 KB)
  - `database_20260503-125137.sqlite` (636 KB)
- Không có file nhạy cảm trong commit (`.env.testing` đã ignored).
- Tests 87/87 PASS verify trước commit.
- Routes mới đã verify (`returns.cancel`, `damages.cancel`, `orders.process`).
- Migrations mới đã ran trên test DB.

**Cần xử lý gì trước khi push?**
1. Chủ dự án **review 4 commits** + lịch sử.
2. Quyết định push lên `main` hay tạo branch riêng (PR).
3. Khi deploy production:
   - Backup production DB trước (chưa làm — đây là local).
   - `php artisan migrate --force` (KHÔNG `migrate:fresh`).
   - Verify 3 routes mới + permissions.
   - Monitor `customer_debts`, `StockMovement`, `CashFlow` 24-48h sau deploy.

**Backlog không chặn push:**
- 18 mục P3 từ Final Audit Summary mục 7 (UI, permission tách, legacy backfill, multi-warehouse).

---

## 11. Tài liệu liên quan

| File | Vai trò |
|---|---|
| `AGENT_RULES.md` | Bộ luật bắt buộc |
| `docs/audit/RISK_REGISTER.md` | Risk register sạch sẽ |
| `docs/audit/FINAL-AUDIT-SUMMARY-REPORT.md` | Final summary (Bước 20) |
| `docs/audit/STEP-21-GIT-DB-BACKUP-CLEANUP-RESULTS.md` | File này (Bước 21) |
| 13 closure reports `RR-01..RR-13-CLOSURE-REPORT.md` | Closure mỗi rủi ro |
| `../_git_backups/audit-*-20260503-125137.diff/.txt` | Git patch backup (ngoài repo) |
| `../_db_backups/database_20260503-125137.sqlite` | SQLite dev backup (ngoài repo) |
| `../_db_backups/sales_test_20260503-125137.sql` | MySQL test backup (ngoài repo) |
