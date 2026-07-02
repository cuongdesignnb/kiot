# HOTFIX 24.36+24.37 - Local DB Serial Costing & Dismantled Restore

## 1. Context
- Production dump was imported into local Docker only.
- Local DB used: `kiot_db` on `127.0.0.1:3319`.
- Docker container: `sales_mysql_test`.
- No production command or SQL apply was run.

## 2. Source Checked
- `app/Console/Commands/RebuildMovingAvgCosting.php`
- `app/Console/Commands/RestoreCompletedDismantledSerials.php`
- `app/Services/TaskService.php`
- `app/Models/Product.php`
- `app/Models/SerialImei.php`
- `app/Models/InvoiceItemSerial.php`
- `resources/js/Pages/Welcome.vue`

## 3. Local DB Audit
- DB import was rechecked after the initial incomplete import. Final `kiot_db` has 99 tables.
- `serial_imeis`: 450 rows.
- `tasks`: 395 rows.
- Product `SP26032722444`: product id `102`, product stock `1`, product BQ before fix `17,395,000`.
- Serial `7HKH0Z2`: serial id `384`, product id `323`, before restore `status=dismantled`, `repair_status=ready`.

## 4. SQL Result
Raw SQL output is saved in `docs/audit/HOTFIX-24.36-24.37-local-sql-result.md`.

| Query | Result | Meaning | Hard error? | Need fix? |
|---|---:|---|---|---|
| Schema preflight | Passed | Required tables/columns exist in local `kiot_db`. | No | No |
| Status audit | Found Vietnamese and English statuses | Code must normalize `Hoàn thành`, `Đã hủy`, `completed`, `cancelled`, return statuses. | No | Yes |
| Product aggregate vs serial aggregate | 70 serial products mismatched | Issue is not isolated to one SKU. | One product has hard error | Yes |
| Product `SP26032722444` | Product BQ `17,395,000`, in-stock serial total `4,400,000` | Product aggregate was stale vs serial state. | No | Yes |
| Duplicate serial links | `279`, `394` had canceled + completed links | Canceled invoice links polluted serial link audit. | No | Yes |
| Sold serial missing completed link | Hard error found in batch dry-run for product `#253 SP26041290828`, invoice item `#88` qty mismatch | Batch apply must stop until this product is reviewed. | Yes | Yes |
| Dismantled latest completed | `7HKH0Z2` eligible; product 323 had another eligible serial `1GKH0Z2` | Legacy completed repair tasks did not re-trigger serial restore. | No | Yes |

## 5. Root Cause
- `RebuildMovingAvgCosting` filtered invoices with a narrow blacklist, so `Đã hủy` was not excluded.
- Serial product final aggregate was taken from timeline result instead of `serial_imeis.status='in_stock'`.
- Duplicate `invoice_item_serials` links existed on canceled invoices.
- Legacy repair tasks completed before the previous hotfix did not re-run status transition logic.

## 6. Code Fix
- Added `App\Support\Status\BusinessStatus` for status normalization.
- Changed `costing:rebuild-moving-avg` to dry-run by default; writes require `--apply`; `--dry-run` + `--apply` fails.
- Added serial sale validation, diff preview, per-product transaction apply, and hard-error gating.
- Added final serial product aggregate from in-stock serial state.
- Hardened `serials:restore-completed-dismantled` with `--product`, `--serial`, `--explain`, row locks, and idempotent apply.
- Added:
  - `serials:audit-invoice-links`
  - `serials:cleanup-cancelled-invoice-links`

## 7. Local Dry-Run
- Before fix, `SP26032722444` dry-run returned `qty=6`, `total=26,092,500`, `BQ=4,348,750`.
- After fix, `SP26032722444` dry-run returned final `qty=1`, `total=4,400,000`, `BQ=4,400,000`.
- Diff preview before apply for `SP26032722444`:
  - `invoice_items`: 7 rows, total diff `12,995,000`.
  - `serial_imeis.sold_cost_price`: 7 rows, total diff `12,995,000`.
  - `invoice_item_serials.cost_price`: 1 row, total diff `-13,405,000`.
- Batch dry-run for all mismatched serial products:
  - Products scanned: 70.
  - Hard errors: 1.
  - Warnings: 131.
  - Blocker: product `#253 SP26041290828`, invoice item `#88` item qty `1`, resolved serial qty `0`.

## 8. Local Apply
- Local DB snapshot created before apply: `storage/app/audit-backups/kiot_db_before_hotfix_2436_2437.sql`.
- Applied local cleanup:
  - `SP26032722444`: deleted canceled link `35` for serial `279`.
  - Product `323`: deleted canceled link `34` for serial `394`.
  - Idempotency: second cleanup apply deleted `0` rows for both products.
- Applied local costing only for `SP26032722444` because batch has one hard error.
  - Product updated: 1.
  - `invoice_items` updated: 7.
  - `invoice_item_serials` updated: 1.
  - `serial_imeis.sold_cost_price` updated: 7.
- Applied local restore:
  - `7HKH0Z2`: `dismantled` -> `in_stock`, `repair_status=ready`.
  - Products recomputed: 1.
  - Idempotency: second restore apply updated `0` rows.

## 9. Data Safety
- Migration: none.
- Backfill: command-based local data fix only.
- Updated local `products`: yes, for product `102`; product `323` stock count recomputed by restore.
- Updated local `invoice_items`: yes, product `102` only.
- Updated local `invoice_item_serials`: yes, product `102` cost and canceled links `35`, `34` deleted.
- Updated local `serial_imeis`: yes, product `102` sold snapshots; serial `384` restored.
- Production updated: no.
- Batch all-product apply: not run because hard error exists.

## 10. Tests
| Command | Result |
|---|---|
| `php artisan test --env=testing --filter=HOTFIX2436SerialMovingAvgCostingSafetyTest` | PASS, 9 tests / 34 assertions |
| `php artisan test --env=testing --filter=HOTFIX2437RestoreCompletedDismantledLegacySerialTest` | PASS, 3 tests / 16 assertions |
| `php artisan test --env=testing --filter=HOTFIX2435CompletedDismantledSerialBecomesSellableTest` | PASS, 7 tests / 19 assertions |
| `php artisan test --env=testing --filter=HOTFIX2434ProductSerialDismantledDisplayTest` | PASS, 7 tests / 27 assertions |
| `php artisan test --env=testing --filter=MovingAvgCosting` | PASS, 14 tests / 49 assertions |
| `php artisan test --env=testing --filter=RebuildMovingAvgCosting` | No tests found |
| `php artisan test --env=testing --filter=Serial` | PASS, 148 passed / 2 skipped |
| `php artisan test --env=testing --filter=Invoice` | PASS, 118 passed / 2 skipped |
| `php artisan test --env=testing --filter=Product` | PASS, 86 passed |
| `php artisan test --env=testing --filter=Repair` | PASS, 54 passed |
| `php artisan test --env=testing --filter=POS` | PASS, 44 passed |
| `npm run build` | PASS |

## 11. Manual QA Local
- SQL-level acceptance for `SP26032722444` passed:
  - `products.stock_quantity = 1`.
  - `products.inventory_total_cost = 4,400,000`.
  - `products.cost_price = 4,400,000`.
  - In-stock serial: `5CD024K671`.
- Dry-run after apply for `SP26032722444` has 0 COGS diffs and 0 hard errors.
- Duplicate link check after cleanup:
  - Serial `279`: one completed link remains, invoice `172`.
  - Serial `394`: one completed link remains, invoice `172`.
- SQL-level acceptance for `7HKH0Z2` passed:
  - `status = in_stock`.
  - `repair_status = ready`.
  - Latest tasks `SC-0333` and `SC-0314` are completed.
- Browser UI manual QA was not executed in this run.

## 12. Production Plan Proposed
- Cần xác nhận trước khi triển khai.
- Production must be backed up first.
- Production must run dry-run first, not apply.
- Because local batch dry-run found hard error product `#253 SP26041290828`, production batch apply must wait until the missing serial link/mismatch for invoice item `#88` is reviewed.
- Required production confirmation:
  - Backup DB completed?
  - Count of mismatched serial products.
  - Count of duplicate/canceled invoice serial links.
  - Count of serials to restore.
  - Count of invoice items to update.
  - Count of serial sold cost snapshots to update.
  - Whether deleting eligible canceled invoice links is approved.
  - Rollback plan and restore test.

## 13. Conclusion
- Local root cause identified: yes.
- `SP26032722444` local BQ now matches remaining in-stock serial: yes.
- `7HKH0Z2` local status is now sellable/in-stock: yes.
- Batch all serial products cannot be applied yet because one hard error remains.
- Production was not updated.
- Commit SHA: recorded in final response after commit.
