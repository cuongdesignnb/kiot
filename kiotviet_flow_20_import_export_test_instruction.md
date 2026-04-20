# KiotViet Flow 20 — Import / Export Large Data
**Purpose:** Agent instruction to verify that the target system behaves like KiotViet for the **Import / Export dữ liệu lớn** flow, and to apply **minimal fixes only when a proven deviation is found**.

---

## 0) Scope and non-goals

This flow covers only bulk data movement behavior:

- import/export product list
- import/export customer list
- import/export supplier list
- export transaction list and history views where relevant
- import processing status / asynchronous job behavior if implemented
- duplicate handling and validation behavior
- partial success vs stop-on-error behavior
- rollback safety when import fails
- large-file processing, batching, and idempotency
- permission checks around import/export
- filter-aware export
- auditability of import/export actions

This flow does **not** retest:
- detailed product creation logic (Flow 01)
- purchase receipt domain behavior (Flow 02)
- sales invoice domain behavior (Flow 03)
- reports correctness (Flow 12)

Only verify bulk data ingestion / extraction and its safety.

---

## 1) Ground truth reference behavior from KiotViet

Use KiotViet as the behavioral reference for this flow:

1. KiotViet supports **Import** and **Xuất file** for product lists from the product list screen.
2. KiotViet also documents importing/exporting **customer lists** from Excel.
3. KiotViet also documents importing/exporting **supplier lists** from Excel.
4. Product import/export and many other import/export actions are tracked through a common **Xử lý import, xuất file** popup/status center with states such as:
   - export: processing, ready to download, downloaded, error
   - import: processing, success, error
5. KiotViet product import uses a **template file**, and some newer KiotViet import flows show explicit duplicate-handling rules such as:
   - report error and stop import
   - replace old name with new name when duplicate code but different name is found
6. Transaction/history screens also support **Xuất file** with filter-aware exports in several modules such as purchase receipts and supplier history.
7. Therefore, a compatible target system should support:
   - deterministic template-based import,
   - explicit import job status,
   - explicit validation/error reporting,
   - export that reflects current list/filter truth.

---

## 2) Agent operating rules

You must follow these rules strictly:

1. **Read source before changing anything.**
   - Find all import/export routes, controllers, services, jobs, queue workers, storage paths, templates, validation rules, history logs, and UI pages.

2. **Do not refactor broadly.**
   - Only apply the smallest fix that corrects a proven deviation.

3. **Do not change unrelated flows.**
   - If a fix touches product, customer, supplier, stock, or report logic, note the ripple effect and rerun only the linked mini-set.

4. **Log every deviation with evidence.**
   - Include reproduction, expected behavior, actual behavior, affected files, and whether the issue is parsing, validation, queueing, persistence, export filtering, or permission level.

5. **Retest after every fix.**
   - Re-run failed cases first, then the regression mini-set.

6. **Never mark PASS by visual guess.**
   - Confirm both visible UI state and persisted imported/exported truth.

---

## 3) Preconditions

Before executing this flow, confirm these are already working:

- Flow 01: foundation data
- Flow 02: purchase receipt
- Flow 03: sales invoice
- Flow 11: permissions
- Flow 12: reports/reconciliation
- Flow 13: audit history

If any prerequisite is broken, stop and mark this flow as **BLOCKED**.

---

## 4) Fixed test dataset

Use deterministic files. Do not improvise random headers.

Prepare these files:

### Product import files
1. `products_valid_small.xlsx`
   - 5 valid products
2. `products_valid_update.xlsx`
   - same product codes with changed names/prices
3. `products_duplicate_conflict.xlsx`
   - duplicate code cases / conflicting name cases
4. `products_invalid_rows.xlsx`
   - missing required fields, invalid numbers
5. `products_large.xlsx`
   - at least 2,000 rows or the largest safe test size for the environment

### Customer import files
1. `customers_valid_small.xlsx`
2. `customers_duplicate_phone.xlsx`
3. `customers_invalid_rows.xlsx`

### Supplier import files
1. `suppliers_valid_small.xlsx`
2. `suppliers_duplicate_phone.xlsx`
3. `suppliers_invalid_rows.xlsx`

### Export verification datasets
Create seed data so export can be checked with filters:
- 20 products across multiple groups
- 10 customers
- 10 suppliers
- several purchase receipts and supplier history rows
- several filters that produce non-trivial subsets

### Users
- `admin`
- `data_manager01` — allowed import/export
- `viewer01` — view/export only if designed
- `sale01` — no data import permission unless the system explicitly allows it

---

## 5) Source discovery checklist

Before testing, inspect and document:

### Import layer
- import routes/endpoints
- upload handlers
- background jobs / queue workers
- parser libraries
- template generation paths
- validation rules
- duplicate handling rules
- transactional boundaries
- error-report file generation
- import status table / history log

### Export layer
- export routes/endpoints
- query builders for export
- export job / async processing if any
- storage and download links
- filter serialization logic
- CSV/XLSX generator path

### Security / lifecycle layer
- permissions / middleware
- audit logging
- cleanup rules for uploaded files
- retry behavior / idempotency
- lock-period interaction if any

Deliver a short map:

- routes:
- controllers:
- services:
- jobs/workers:
- models/tables:
- storage paths:
- templates:
- validators:
- policies/middleware:
- UI screens:

Do this before running cases.

---

## 6) Canonical truth model to validate

Suggested processing states:

### Import request
- `queued` / `processing`
- `success`
- `error`

### Export request
- `processing`
- `ready_to_download`
- `downloaded`
- `error`

Names may differ, but behavior must map closely. Create a mapping table in the report if needed.

---

## 7) Core invariants

Validate these invariants in every relevant case:

1. Import must use a deterministic template/header contract.
2. Invalid rows must not silently produce corrupted records.
3. Duplicate handling must be explicit and stable.
4. If stop-on-error behavior exists, the import result must reflect it clearly.
5. If partial-success behavior exists, imported vs failed rows must be clearly separated.
6. Export must reflect current filters and truth source, not a stale cache.
7. Large import/export must have observable processing state if asynchronous.
8. Re-running the same import file must not create uncontrolled duplicates unless that is the explicit design.
9. Permissions must be enforced in both UI and backend.
10. Audit/history should preserve who imported/exported and when if the system supports it.

---

## 8) Test cases

Run cases in order. Stop only for blockers.

### 20A — Product export baseline
**Goal:** verify export from product list.

Steps:
1. Login as `data_manager01`.
2. Open product list.
3. Export with no filter.
4. Export with a product-group filter.
5. Download both files.

Expected:
- export request succeeds
- file row count matches current list/filter truth
- exported headers match expected template or export format
- filtered export contains only filtered items

Checks:
- if export is async, request should show processing then ready-to-download state
- downloaded file contents must match the visible filter result

---

### 20B — Product import with valid template
**Goal:** verify small valid import.

Steps:
1. Download product template from the system if supported.
2. Fill `products_valid_small.xlsx` according to the template.
3. Import the file.
4. Track status until completion.

Expected:
- import request enters processing/success states
- all valid rows are created
- created products become searchable in product list
- imported fields persist correctly

DB checks:
- exact number of products created
- key fields match file values
- no unexpected stock/posting side effects unless the import explicitly supports such fields

---

### 20C — Product import update existing records
**Goal:** verify update path via import.

Steps:
1. Export current products.
2. Modify names/prices for a subset and save as `products_valid_update.xlsx`.
3. Import update file.
4. Reopen product list and detail.

Expected:
- existing target products are updated according to system rules
- untouched products remain unchanged
- update behavior is explicit, not accidental duplicate creation

Checks:
- updated fields persisted exactly once
- old and new values can be compared
- if the system distinguishes create vs update mode, that distinction must be visible

---

### 20D — Product duplicate conflict handling
**Goal:** verify duplicate handling is explicit.

Steps:
1. Import `products_duplicate_conflict.xlsx`.
2. If the system offers duplicate-handling options, test each supported mode:
   - error and stop
   - replace / overwrite permitted fields
3. Observe results.

Expected:
- duplicate behavior matches the chosen option
- no silent overwrite without configuration
- error report is explicit enough to identify failed rows

Fail examples:
- some duplicates overwrite and some do not with no rule
- import says success but rows were skipped silently
- duplicate code produces two active products unexpectedly

---

### 20E — Product invalid-row validation
**Goal:** verify invalid data handling.

Steps:
1. Import `products_invalid_rows.xlsx`.
2. Observe result status and row-level feedback.

Expected:
- invalid rows are rejected explicitly
- required-field and numeric-format errors are understandable
- valid rows are either fully blocked or partially imported according to the designed rule, but the rule must be explicit

Checks:
- no corrupted partial records
- error report or UI list identifies row numbers and reasons where supported

---

### 20F — Large product import processing
**Goal:** verify large-file behavior.

Steps:
1. Import `products_large.xlsx`.
2. Observe processing state center / popup / job status.
3. Wait for completion.
4. Verify a sample of rows and total counts.

Expected:
- large import is processed safely
- UI exposes processing/progress/status clearly enough
- browser refresh does not lose import history
- final counts match expected result

Checks:
- no duplicate job created by accidental double submit
- timeout is handled safely if using background processing
- import request remains traceable after completion

---

### 20G — Customer import/export
**Goal:** verify customer bulk data flow.

Steps:
1. Export current customer list.
2. Import `customers_valid_small.xlsx`.
3. Search imported customers.
4. Import `customers_duplicate_phone.xlsx`.
5. Import `customers_invalid_rows.xlsx`.

Expected:
- export works and matches current filtered truth
- valid customers import correctly
- duplicate phone handling follows system rules explicitly
- invalid rows do not silently create broken customers

Checks:
- imported customers are usable in sales flow
- customer duplicates do not corrupt receivable truth

---

### 20H — Supplier import/export
**Goal:** verify supplier bulk data flow.

Steps:
1. Export current supplier list.
2. Import `suppliers_valid_small.xlsx`.
3. Search imported suppliers.
4. Import `suppliers_duplicate_phone.xlsx`.
5. Import `suppliers_invalid_rows.xlsx`.

Expected:
- export works and matches current filtered truth
- valid suppliers import correctly
- duplicate phone handling follows system rules explicitly
- invalid rows do not silently create broken suppliers

Checks:
- imported suppliers are usable in purchase flow
- supplier duplicates do not corrupt payable truth

---

### 20I — Export transaction/history lists
**Goal:** verify export from operational/history screens.

Steps:
1. Export purchase receipt list with a status/time filter.
2. Export supplier history from supplier detail if supported.
3. Export customer list/history if supported in your system.
4. Compare row counts and sample rows with current filters.

Expected:
- exported rows match filtered list truth
- summary vs detail export modes (if any) behave as labeled
- exports are downloadable and readable

This aligns with KiotViet docs that several transaction/history screens expose export actions, including purchase receipts and supplier history.

---

### 20J — Import/export status center
**Goal:** verify processing center behavior.

Steps:
1. Trigger at least one import and one export.
2. Open the processing center / popup.
3. Observe status transitions.

Expected:
- import shows processing -> success/error
- export shows processing -> ready-to-download -> downloaded/error
- completed tasks remain traceable for a reasonable period
- clicking to download fetches the correct file

Fail examples:
- job succeeded but UI remains stuck at processing
- wrong file is downloaded from a finished export entry
- status disappears on page reload unexpectedly

---

### 20K — Retry / idempotency / double-submit safety
**Goal:** verify accidental re-submit does not corrupt data.

Steps:
1. Submit the same small import file twice quickly.
2. Retry a failed import after fixing the file.
3. Retry export of the same filtered dataset.

Expected:
- duplicate import requests are handled safely according to system design
- data is not duplicated uncontrollably
- retry after correction succeeds cleanly
- duplicate exports may create separate files, but each must reflect the correct requested filter

Checks:
- import request deduplication or explicit non-deduplication behavior must be documented in the report

---

### 20L — Permissions
**Goal:** verify import/export access control.

Steps:
1. Login as `viewer01`.
2. Verify export permission if designed, but no import permission.
3. Login as `sale01`.
4. Attempt product/customer/supplier import directly through UI and backend.
5. Login as `data_manager01`.
6. Verify allowed operations work.

Expected:
- unauthorized users cannot import via backend even if UI is bypassed
- export permission follows configured role boundaries
- templates/download endpoints also respect permissions where appropriate

---

### 20M — Audit/history of import/export actions
**Goal:** verify traceability.

Steps:
1. Perform one import and one export as `data_manager01`.
2. Open audit/history if supported.
3. Inspect records.

Expected:
- actions are traceable by user/time/type
- if file metadata is stored, it should be associated correctly
- failed imports should still leave a history entry if the system supports audit logging

---

### 20N — File/template contract changes
**Goal:** verify strictness of template/header contract.

Steps:
1. Rename one required column header in a valid file.
2. Reorder columns if the system claims order-insensitive header mapping.
3. Remove one required column.
4. Re-import.

Expected:
- system behavior is explicit:
  - header-name based mapping if supported,
  - or strict template matching if required
- missing required headers fail clearly
- no shifted-column corruption occurs

Fail examples:
- wrong column order imports data into wrong fields silently
- missing header causes rows to be imported with wrong mapping

---

### 20O — Linked-flow usability after import
**Goal:** verify imported data works in business flows.

Steps:
1. Use an imported product in sales invoice and purchase receipt.
2. Use an imported customer in sales invoice.
3. Use an imported supplier in purchase receipt.

Expected:
- imported records are not merely visible in list; they are operationally usable
- search/find behavior works
- no hidden required fields missing from imported records

---

## 9) Minimal regression set after any fix

After fixing any failed case, rerun:

- 20A product export baseline
- 20B valid product import
- 20D duplicate conflict handling
- 20E invalid-row validation
- 20F large import processing
- 20G customer import/export
- 20H supplier import/export
- 20J status center
- 20L permissions
- 20O linked-flow usability

If the fix touched product creation/update, rerun the smallest linked checks from Flow 01 and Flow 03.
If the fix touched customer import, rerun the smallest linked checks from Flow 04.
If the fix touched supplier import, rerun the smallest linked checks from Flow 06.
If the fix touched export truth, rerun the smallest linked checks from Flow 12.

---

## 10) Defect classification

Tag each defect with one primary label:

- `IMPORT_TEMPLATE_CONTRACT`
- `IMPORT_PRODUCT_CREATE`
- `IMPORT_PRODUCT_UPDATE`
- `IMPORT_DUPLICATE_HANDLING`
- `IMPORT_ROW_VALIDATION`
- `IMPORT_LARGE_FILE`
- `IMPORT_CUSTOMER`
- `IMPORT_SUPPLIER`
- `IMPORT_STATUS_CENTER`
- `IMPORT_IDEMPOTENCY`
- `EXPORT_FILTER_TRUTH`
- `EXPORT_DOWNLOAD`
- `EXPORT_HISTORY_SCREEN`
- `IMPORT_EXPORT_PERMISSION`
- `IMPORT_EXPORT_AUDIT`
- `IMPORT_HEADER_MAPPING`
- `IMPORTED_RECORD_USABILITY`

Severity:
- `S1` corrupts data or causes uncontrolled duplication
- `S2` wrong status/filter/result but workaround exists
- `S3` UI mismatch or weak feedback
- `S4` cosmetic only

---

## 11) Report format required from the agent

Use this exact structure.

### A. Summary
- Flow: 20 — Import / Export Large Data
- Result: PASS / PASS WITH DEVIATIONS / FAIL / BLOCKED
- Tested commit:
- Environment:
- Tester agent:
- Date/time:

### B. Source map
- routes:
- controllers:
- services:
- jobs/workers:
- models/tables:
- storage paths:
- templates:
- validators:
- policies/middleware:
- UI screens:

### C. Case results
For each case 20A → 20O provide:
- status: PASS / FAIL / NA / BLOCKED
- notes:
- screenshots/logs/query evidence if relevant

### D. Defects
For each defect:
- defect id
- title
- label
- severity
- reproduction
- expected
- actual
- evidence
- suspected root cause
- changed files
- fix summary

### E. Data verification
- import requests created:
- export requests created:
- status snapshots:
- products imported/updated:
- customers imported:
- suppliers imported:
- failed rows:
- export row counts:
- downloaded file checks:
- linked-flow checks:

### F. Regression results
- cases rerun:
- results:
- remaining risks:

### G. Final verdict
- Is the target flow behaviorally equivalent to KiotViet for normal operation?
- What deviations remain?
- Are any deviations accepted intentionally?

---

## 12) Stop conditions

Stop execution and mark **BLOCKED** if:

- upload/storage is globally broken
- queue/background worker is unavailable and the flow requires it
- templates cannot be downloaded/generated
- exported files cannot be retrieved in test env
- permission system blocks all access unexpectedly

---

## 13) Fix policy

When fixing:
1. Prefer validator/parser/job fixes over UI-only masking.
2. Preserve historical import/export request records.
3. Add or update automated tests if infrastructure exists.
4. If no test harness exists, add at least one reproducible scripted check for the defect.
5. Do not silently skip rows without explicit reporting.
6. Do not “fake” exported totals/rows from a different query than the list truth source.

---

## 14) Completion criteria

Flow 20 is considered complete only if:

- core cases 20A, 20B, 20D, 20E, 20F, 20G, 20H, 20J, 20L, 20O all PASS
- no unresolved S1 or S2 defect remains
- import/export status visibility is stable
- duplicate handling is explicit
- filtered export matches visible truth
- imported records are operationally usable
- permissions pass

If any of the above fails, this flow is not complete.
