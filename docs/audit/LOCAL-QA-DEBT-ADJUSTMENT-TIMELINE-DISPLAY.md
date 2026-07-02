# LOCAL QA — DebtAdjustment timeline display

## Phạm vi

- Local path: `D:\Kiot\kiotviet-clone`.
- DB host: `127.0.0.1`.
- DB name: `kiot_db`.
- DB là production thật hay copy: local DB chứa dữ liệu production/case production; xử lý như production copy, không ghi dữ liệu.
- Partner: `KH177460073148`.
- Invoice: `HD177598589311`.
- Cashflow: `PT26042215161822`.

## Source

- Branch: `main`.
- HEAD: `334119515c24f31cf9d124b5286b7164b33299ff`.
- Commit expected: `334119515c24f31cf9d124b5286b7164b33299ff`.
- Git status: clean for tracked source; `kiot_db.sql.zip` remains untracked and was not committed.

## Data safety

- Migration: no.
- Backfill: no.
- Update DB: no.
- Delete DB: no.
- Recalculate: no.
- Ghi DB: no on `kiot_db`.
- migrate:fresh: no.
- Tests có chạy không: yes, because `.env.testing` points to separate test DB `127.0.0.1:3319/sales_test`.
- Nếu không chạy test, lý do: not applicable.

## Read-only audit

- Strategy command: pass.
- Inspect command: pass.
- Timeline invoice entry: `true`.
- Timeline DebtAdjustment entry: `true`.
- DebtAdjustment display effect: `-15000000`.
- Display final: `0`.
- Stored debt: `0`.
- Reconcile mismatch: `false`.
- Source counts from inspect: `customer_debt_count = 0`, `cash_flow_count = 2`, `invoice_count = 2`.

## Manual QA

- Customer debt tab Anh Bảy: not executed in browser in this session because no authenticated local UI session/credential was available; read-only inspect confirms the timeline data that the UI consumes.
- Sổ quỹ `PT26042215161822`: not executed in browser; read-only snapshot confirms cashflow remains `receipt`, amount `15000000`, `reference_type = DebtAdjustment`, `reference_code = null`.
- Phiếu thu thường: guarded by automated test `regular receipt is not marked as debt adjustment`.
- Supplier timeline: guarded by automated test `SupplierDualRoleTimelineNoDashTest`.

## DB before/after

- `customer_debts` count for customer `35`: before `0`, after `0`.
- `cash_flows` record `PT26042215161822`: before one record, after one record; amount/reference unchanged.
- `invoices` record `HD177598589311`: before one record, after one record; total/customer_paid unchanged.
- `customers.debt_amount`: before `0.00`, after `0.00`.
- `customers.supplier_debt_amount`: before `0.00`, after `0.00`.

## Build/test

- `npm run build`: pass.
- `php artisan optimize:clear`: pass.
- `composer dump-autoload`: pass; existing PSR-4 warning for `App\View\Components\layouts` remains unrelated.
- `DebtAdjustmentTimelineDisplayTest`: pass.
- `AnhThanhThienPhuDebtReconcileTest`: pass.
- `SupplierDualRoleTimelineNoDashTest`: pass.
- `AuditDebtCandidatePairCommandTest`: pass.
- `PlanDebtAdjustmentStrategyCommandTest`: pass.
- `DebtEvidenceMatcherTest`: pass.
- `DiffDebtPartnerCommandV2Test`: pass.
- `ApplyDebtFixPlanCommandTest`: pass.
- `PlanDebtFixCommandTest`: pass.
- `InspectDebtPartnerCommandTest`: pass.
- `AuditDebtLedgerCommandTest`: pass.
- PHP startup warnings for missing optional extensions `oci8_*`, `pdo_firebird`, `pdo_oci` remain environmental and did not block tests/build.

## Production guide

- Có thể deploy chưa: yes for code-only deploy after operator with SSH/aaPanel access runs the production checklist; UI manual QA still must be completed after deploy.
- Commit cần deploy: `334119515c24f31cf9d124b5286b7164b33299ff`.
- Commands:

```bash
cd /www/wwwroot/kiot.cuongdesign.net
pwd
git status
git branch --show-current
git log --oneline -5

git fetch origin main
git pull origin main
git rev-parse HEAD

composer dump-autoload
php artisan optimize:clear
rm -rf public/build
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

- Do not run:

```bash
php artisan migrate:fresh
php artisan migrate
php artisan debt:apply-fix-plan --apply
```

- Production read-only QA:

```bash
mkdir -p storage/app/audits/production-debt-adjustment-final-qa

php artisan debt:strategy-debt-adjustment \
  --dry-run \
  --partner-code=KH177460073148 \
  --invoice-code=HD177598589311 \
  --cashflow-code=PT26042215161822 \
  --export-json=storage/app/audits/production-debt-adjustment-final-qa/KH177460073148-strategy-prod.json \
  --export-md=storage/app/audits/production-debt-adjustment-final-qa/KH177460073148-strategy-prod.md

php artisan debt:inspect-partner \
  --dry-run \
  --code=KH177460073148 \
  --include-raw \
  --include-timeline \
  --pretty \
  --export=storage/app/audits/production-debt-adjustment-final-qa/KH177460073148-inspect-prod.json
```

- QA sau deploy:
  - Confirm customer timeline has invoice `+15000000`, DebtAdjustment `-15000000`, final `0`.
  - Confirm cashflow `PT26042215161822` remains `DebtAdjustment`.
  - Confirm regular receipts are not duplicated or relabeled.
  - Confirm supplier/dual-role timeline is unchanged.
- Rollback:

```bash
cd /www/wwwroot/kiot.cuongdesign.net
git revert 334119515c24f31cf9d124b5286b7164b33299ff
composer dump-autoload
php artisan optimize:clear
rm -rf public/build
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

## Kết luận

- Đạt local read-only QA cho code-only DebtAdjustment timeline display.
- Có thể deploy production theo runbook bởi người có quyền SSH/aaPanel.
- Có ghi DB không: không trên `kiot_db`; automated tests chỉ dùng DB riêng `sales_test`.
- Cần xử lý trước deploy: bảo đảm production worktree sạch, pull đúng commit, chạy build/cache, sau đó manual UI QA bằng tài khoản hợp lệ.
