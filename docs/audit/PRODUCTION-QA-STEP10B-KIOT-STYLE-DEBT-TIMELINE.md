# PRODUCTION QA — STEP 10B Kiot-style debt voucher timeline

> Lưu ý môi trường: phần "Read-only audit" dưới đây được chạy ở **LOCAL docker
> chứa bản DB production vừa import** (pre-deploy verification). Agent KHÔNG có
> SSH tới production `/www/wwwroot/kiot.cuongdesign.net`, nên các mục Deploy /
> Manual QA browser phải do người vận hành chạy trực tiếp trên production và
> điền kết quả thật.

## Phạm vi
- Customer timeline: ưu tiên phiếu thu thật, multi-receipt, fallback non-clickable.
- Supplier timeline: PN/PCPN/CB click (prefix-routing), TTNH fallback non-clickable.
- DebtAdjustment: giữ hiển thị, final = 0.

## Production source
- Path: `/www/wwwroot/kiot.cuongdesign.net`
- Branch: main
- Commit STEP 10: `b45d3c71b0a09da58825c400c9655fb6cd6f2772`
- Commit STEP 10B: `36672ebbe448a4ccbb771c4011d5524a729b44f4`
- Đã trên origin/main: ✅ (origin/main = `6f84ca5`, chứa `36672eb` — verified `git merge-base --is-ancestor` = OK)
- HEAD trước/sau deploy: **(người vận hành điền sau khi pull)**

## Deploy commands (người vận hành chạy trên production)
```bash
cd /www/wwwroot/kiot.cuongdesign.net
git status && git fetch origin main && git pull origin main
git merge-base --is-ancestor 36672ebbe448a4ccbb771c4011d5524a729b44f4 HEAD && echo STEP10B_OK
composer dump-autoload
php artisan optimize:clear
rm -rf public/build && npm run build
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
# Restart PHP-FPM nếu UI còn cache code cũ
```
KHÔNG chạy: `php artisan migrate*`, data-fix, `debt:apply-fix-plan --apply`.

## Data safety
- Migration: Không. Backfill: Không. Update/Delete/Recalculate DB: Không.
- Ghi cash_flows/invoices/customers/customer_debts/purchases: Không.
- migrate:fresh: Không. Commit audit JSON/MD/CSV: Không.

## Read-only audit (LOCAL pre-deploy, DB production import)
| Case | Kết quả |
|---|---|
| `KH177460073148` (Anh Bảy) | DebtAdjustment `PT26042215161822` giữ nguyên; `TTHD177460073796` fallback **non-clickable**; `clickable_fallback_rows=0`; `receipt_allocation_mismatches=0`; `missing_click_target=0`. DebtAdjustment row clickable trong UI (detail_available=true, modal=null → gate cho phép). |
| `KH178047230447` (Nguyễn Đình Hoan) | `HD178047231653` paid 4.650.000 không có phiếu thu thật → `TTHD...` fallback non-clickable. `clickable_fallback_rows=0`. |
| Multi-receipt candidate | DB hiện **không có** hóa đơn nào có >1 phiếu thu thật (`COUNT(*)>1` = 0). Case này được cover bằng automated test `test_paid_invoice_with_multiple_real_receipts...` (pass). |
| Supplier `NCC177950763826` | PN/PTN rows click qua prefix-routing (UI); `clickable_fallback_rows=0`; `receipt_allocation_mismatches=0`; `missing_click_target=0`. |
| clickable_fallback_rows | **0** (cả customer + supplier) |
| receipt_allocation_mismatches | **0** |

## Manual QA browser (người vận hành điền)
- Hóa đơn 1 phiếu thu: …
- Hóa đơn nhiều phiếu thu: … (lưu ý: production có thể chưa có case này)
- Fallback TTHD không click: …
- Anh Bảy DebtAdjustment final=0: …
- Supplier PN/PCPN/CB click: …

## Issues found
- Audit command heuristic ban đầu báo `missing_click_target` cho dòng có
  `detail_available=true` nhưng `detail_modal_type=null` (DebtAdjustment) và
  cho supplier rows (click qua prefix-routing). Đây là **false-negative của
  diagnostic, KHÔNG phải lỗi UI** — đã chỉnh heuristic khớp đúng frontend gate.
- 2 test PRE-EXISTING fail trên clean HEAD (không do STEP 10/10B):
  `HOTFIXFollowUpDebtOffsetMirrorTest` (CB balance), `CancelInvoicePaymentDebtFlowTest`.

## Kết luận
- Pre-deploy local verification: **Đạt** — code chạy đúng trên dữ liệu production-shape, không ghi DB.
- Production deploy/QA: chờ người vận hành chạy runbook + điền kết quả thật.
- Không ghi DB. Bước chuẩn hóa `cash_flows.reference_id` / `cash_flow_allocations` / backfill phiếu thu cần xác nhận riêng.
