# STEP 10C — Bulk Kiot-style debt voucher audit

## Phạm vi
- Customer + Supplier timeline: quét toàn bộ, phân loại risk + severity mỗi dòng.
- Read-only hoàn toàn — không ghi DB.

## Bối cảnh DB local
- Local DB đã import production và đang khớp production (dùng như production-shape để phát triển/test).
- Quy mô quét thực tế: **190 customers + 54 suppliers = 244 đối tác**, 1300 ledger rows.

## Source đã kiểm tra
- `app/Console/Commands/AuditKiotStyleDebtVoucherCommand.php` (mở rộng bulk).
- `app/Services/PartnerDebtLedgerService.php` (buildCustomerNetLedger / buildSupplierPayableLedger).
- Models Customer/Invoice/CashFlow/Purchase.
- Frontend click gate (Customers/Suppliers Index.vue) — heuristic audit mirror.
- Tests command.

## Thay đổi code
- **Options mới**: `--all`, `--all-customers`, `--all-suppliers`, `--only-risk`, `--limit`, `--chunk`, `--summary-only`, `--max-rows`, `--export-csv`. Giữ `--dry-run` (bắt buộc), `--customer-code`, `--supplier-code`, `--export-json`, `--export-md`.
- **Mode guard**: thiếu dry-run → fail; thiếu mode → fail; single + bulk cùng lúc → fail.
- **Tách `auditPartner(Customer, view)`**: dùng cho cả single + bulk; trả `partner / summary / invoice_receipt_groups / risks / rows`.
- **Severity**: critical (`clickable_fallback`, `receipt_allocation_mismatch`, `balance_mismatch`, `duplicate_receipt`, `orphan_cashflow`, `audit_exception`), warning (`virtual_fallback`, `missing_real_voucher`, `missing_click_target`), info, ok.
- **Bulk scan**: `chunkById($chunk)` (không nạp toàn DB vào RAM), progress mỗi chunk, `try/catch` từng partner (lỗi → `audit_exception`, không crash cả scan), honour `--limit`.
- **Checks**: `duplicate_receipt` (group theo cash_flow_id), `balance_mismatch` (từ `reconcile.has_mismatch` của service — không tự recalculate), `orphan_cashflow` (bounded SELECT, STRICT theo target_id của chính partner — đã sửa false-positive cross-supplier).
- **Export**: JSON (summary/top_risks/partners), CSV (severity,risk,view,partner_id,partner_code,partner_name,document_code,document_type,amount,reference_code,message,suggested_action), MD (summary + top 50 critical/warning). `--only-risk` lọc partner sạch. File tự tạo thư mục, không commit.
- Single-mode JSON shape (summary/invoice_receipt_groups/rows) giữ nguyên — backward compat.

## Data safety
| | |
|---|---|
| Migration / Backfill / Update / Delete / Recalculate | Không |
| Ghi DB (cash_flows/invoices/customers/customer_debts/purchases/supplier_debt_transactions) | Không |
| migrate:fresh | Không |
| Commit file export (storage/app/audits) | Không |

Test `no_db_writes` xác nhận count các bảng không đổi trước/sau bulk.

## Local audit result (DB production import, `--all --only-risk`)
| Metric | Value |
|---|---|
| customers_scanned | 190 |
| suppliers_scanned | 54 |
| partners_scanned | 244 |
| partners_with_risk | 213 |
| critical_partners | 22 |
| warning_partners | 191 |
| ok_partners | 31 |
| total_rows | 1300 |
| **clickable_fallback_rows** | **0** ✅ |
| **receipt_allocation_mismatches** | **0** ✅ |
| **duplicate_receipt** | **0** ✅ |
| **orphan_cashflow** | **0** ✅ |
| **audit_exceptions** | **0** ✅ |
| virtual_fallbacks | 253 (warning — hóa đơn cũ trả tiền nhưng chưa có phiếu thu thật) |
| missing_click_target | 112 (warning) |
| balance_mismatch | 22 (critical — **data drift legacy có sẵn**, cached debt ≠ computed) |

Export local: `storage/app/audits/step10c-bulk/local-all-risk.{json,csv,md}` (không commit).

**Diễn giải 22 critical**: đều là `balance_mismatch` — số dư công nợ lưu trữ lệch với số tính lại từ ledger. Đây là **vấn đề dữ liệu cũ tồn tại từ trước**, được STEP 10C surface ra để review, KHÔNG do STEP 10/10B tạo. Không tự sửa.

## Tests
| Suite | Kết quả |
|---|---|
| `AuditKiotStyleDebtVoucherCommandTest` (single) | ✅ 3 passed |
| `BulkKiotStyleDebtVoucherAuditCommandTest` (NEW) | ✅ 8 passed (dry-run guard, no-mode, single+bulk conflict, all-customers classify, only-risk filter, limit, csv/md, no-db-writes) |
| Debt regression (KiotStyle/DebtAdjustment/AnhThanhThienPhu/SupplierDualRoleNoDash) | ✅ 30 passed / 218 assertions tổng |
| `npm run build` | ✅ 7.27s |

## Production runbook (read-only — chạy sau khi pull commit)
```bash
cd /www/wwwroot/kiot.cuongdesign.net
git fetch origin main && git pull origin main
composer dump-autoload && php artisan optimize:clear
rm -rf public/build && npm run build
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan queue:restart

mkdir -p storage/app/audits/step10c-production
php artisan debt:audit-kiot-vouchers --dry-run --all --only-risk --chunk=200 \
  --export-json=storage/app/audits/step10c-production/all-risk.json \
  --export-csv=storage/app/audits/step10c-production/all-risk.csv \
  --export-md=storage/app/audits/step10c-production/all-risk.md
```
Expected: không ghi DB, `clickable_fallback_rows=0`, `receipt_allocation_mismatches=0`. Critical (`balance_mismatch`) nếu có → review, **chưa sửa**.

## Kết luận
- **Đạt STEP 10C code-only. Không ghi DB.** Local DB khớp production nên kết quả có giá trị pre-production.
- Có thể deploy command + chạy production read-only.
- 22 critical `balance_mismatch` là data drift cũ — mọi data-fix/backfill/reference_id/allocation phải tách step riêng và **cần xác nhận trước**.
- Commit SHA: pending.
