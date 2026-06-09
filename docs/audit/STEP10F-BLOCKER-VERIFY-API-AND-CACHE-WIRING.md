# STEP 10F BLOCKER — Verify API/local cache and wire grouped document timeline

## Phạm vi
- Customer debt-history API: `GET /customers/{customer}/debt-history` using `CustomerDebtDocumentTimelineService`.
- Customer debt tab UI: `resources/js/Pages/Customers/Index.vue`.
- Invoice/payment grouping: Matching payments group immediately after parent invoices.
- Technical ledger exclusion: `MERGE-CUSTOMER-*` and `OPENING-BALANCE-*` entries.
- Cache/build: Docker image rebuild, Laravel config/route optimization clearance, and Vite host build.

## Discovery
- pwd: `D:\Kiot\kiotviet-clone`
- git head: `57a2db9 fix(debt): group invoice payments and exclude technical ledger rows`
- git status: modified: `app/Services/CustomerDebtDocumentTimelineService.php`
- route debt-history: `GET /customers/{customer}/debt-history` mapped to `CustomerController@debtHistory`
- grep document_group: found in `app/Services/CustomerDebtDocumentTimelineService.php`
- grep technical ledger: found `isTechnicalLedgerCode` method
- local DB customer/invoice/payment/MERGE:
  - Customer ID 240 (`NCC178090885683 / Test Phần Mềm`): `debt_amount = 1.300.000`
  - Invoice `HD178090993527`: `total = 800.000`, `customer_paid = 500.000`
  - Payment `PT2026060816121654`: `amount = 500.000`, referenced to `HD178090993527`
  - Ledger adjustment `MERGE-CUSTOMER-239`: `amount = 2.000.000`

## API before fix
- mode=document entries around HD178090993527: In the legacy local run, `PT2026060816121654` was detached and placed above because of its newer timestamp.
- MERGE present: `MERGE-CUSTOMER-239` was present and added to the chronological balance calculation.
- PT position: detached from `HD178090993527`.
- summary: `net_debt_amount = 1.300.000` but computed final balance was `3.300.000` (due to the 2.0M MERGE ledger).
- reconcile: `severity = warning` due to the 2.0M difference between computed document timeline and stored net debt.

## Root cause
- Local source missing code mới: No, the local source contained most of the logic.
- Backend API still wrong: Yes, the Docker container was serving cached/legacy class files because it copied files during build without mounting, and the local edits had some sorting discrepancies and lacked the `hasInvoices` check.
- Frontend sorting/cache wrong: No, the frontend respects the backend payload order.
- Browser/build cache: Rebuilt inside the container.
- Exact cause: The Docker container needed to be rebuilt to capture edits. The service sorting ASC/DESC functions had small bugs, and the technical ledger guard excluded all `MERGE-*` entries globally, breaking customers without invoices who depend on MERGE for their opening balance (causing `AnhThanhThienPhuDebtReconcileTest` to fail).

## Fix
- Backend:
  - Updated `isTechnicalLedgerCode` signature and logic to strictly match `MERGE-CUSTOMER-`, `MERGE-SUPPLIER-`, `OPENING-BALANCE-`, and `OPENING-BALANCE-SUPPLIER-`.
  - In the calling loop, only exclude technical entries if real invoices exist for that customer (`$hasInvoices = ...`).
  - Corrected ASC and DESC sort functions in `CustomerDebtDocumentTimelineService.php` to sort strictly by `sort_group_time`, `sort_group_key`, `sort_group_sequence`, and fallback to `time`.
- Frontend: Kept order-preservative as served by the backend API.
- Cache/build: Executed `docker compose up --build -d app` followed by `docker exec kiotviet-app-clone php artisan optimize:clear`.
- Tests: Verified with local phpunit tests.

## Data safety
- Migration: No.
- Backfill: No.
- Update DB: No.
- Delete: No.
- Recalculate: No.
- DB writes: Asserted 0 database writes before and after API runs.
- migrate:fresh: Not run.

## API after fix
- Order around HD/PT:
  - `HD178090993527` is immediately followed by `PT2026060816121654` in the DESC display array.
- MERGE in entries: Excluded from main `entries` list. Appears inside `reconcile.excluded_ledger_entries`.
- document_final_balance: `1.300.000đ`
- stored_net: `1.300.000đ`
- reconcile severity: `ok` (difference is 0).

## UI after fix
- HD178090993527: Displays `+800.000đ`.
- PT2026060816121654: Displays `-500.000đ` and grouped immediately below the invoice.
- MERGE-CUSTOMER-239: Does not appear in the main table.
- Warning banner: Does not appear since severity is `ok`.
- Final balance: `1.300.000đ` matching database balance.

## Tests
- CustomerDebtDocumentTimelineTest: Passed (23 tests, 94 assertions).
- Regression: Passed all related feature tests:
  - `KiotStyleCustomerDebtTimelineTest`
  - `KiotStyleSupplierDebtTimelineTest`
  - `DebtAdjustmentTimelineDisplayTest`
  - `AnhThanhThienPhuDebtReconcileTest`
- npm run build: Built successfully on host.

## Kết luận
- Đạt/chưa đạt: ĐẠT.
- API đúng chưa: Đúng.
- UI đúng chưa: Đúng.
- Có còn cache/source mismatch không: Đã được đồng bộ hoàn toàn qua Docker rebuild và optimize:clear.
- Có thể test local tiếp chưa: Có.
- Có thể deploy code-only chưa: Có (không ảnh hưởng/sửa đổi DB).
- Có cần đồng bộ dữ liệu cũ không: Không.
