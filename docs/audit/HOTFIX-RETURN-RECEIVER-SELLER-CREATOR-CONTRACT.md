# P0 hotfix: contract người nhận trả, người bán và người tạo

## Phạm vi và trạng thái

Hotfix này tách ba vai trò trong phiếu trả hàng:

- **Người bán gốc**: lấy từ hóa đơn nguồn (`invoices.created_by` và snapshot `invoices.seller_name`).
- **Người nhận trả**: nhân viên active được chọn để chịu trách nhiệm tiếp nhận hàng trả.
- **Người tạo phiếu**: snapshot audit tại thời điểm tạo (`returns.created_by_name`).

Không merge, deploy hoặc chạy lệnh ghi trên production trong phạm vi này.

## Production audit SELECT-only

Audit production được cung cấp theo yêu cầu và không có remediation:

```text
return.code=TH2026071109580972
return.invoice_id=321
return.invoice_code=HD178270389387
return.created_by_name=Vũ Hồng Nhung
return.seller_name=NULL
source_invoice.created_by=6
source_invoice.employee_name=Vũ Hồng Nhung
source_invoice.seller_name=Vũ Hồng Nhung
source_invoice.created_by_name=Trần Văn Tiến
other_invoice.code=HD178373894266
other_invoice.created_by=NULL
other_invoice.seller_name=Trần Văn Tin
other_invoice.created_by_name=Vũ Hồng Nhung
active_employees.count=7
```

Kết luận: return creator không phải seller; invoice nguồn là nguồn seller canonical. Không backfill dữ liệu legacy.

## Root cause

`Returns/Index.vue` dùng fallback tên cá nhân hardcode cho seller và creator khi cột DB null. Vì vậy một return legacy có thể hiển thị tên không thuộc document. Select “Người nhận trả” cũng chỉ có một option, không có `v-model`, không có danh sách employee, không có endpoint và không được persist.

## Request/response contract

### Trước hotfix

- `POST /returns` và POS return không có receiver contract ổn định.
- UI lấy `seller_name` hoặc `created_by_name` để lấp vào “Người nhận trả”.
- Không có `received_by_employee_id`/`received_by_name` trên `returns`.
- Không có endpoint cập nhật receiver.

### Sau hotfix

Migration thêm hai cột nullable, không backfill:

```text
returns.received_by_employee_id  nullable FK employees.id, nullOnDelete, indexed
returns.received_by_name         nullable snapshot
```

Creation payload có thể gửi:

```json
{
  "received_by_employee_id": 12
}
```

Backend kiểm tra employee tồn tại và `is_active=true`, sau đó lưu cả ID và tên snapshot trong cùng transaction. Legacy caller không gửi receiver vẫn giữ null để không tự suy đoán receiver.

Reassignment:

```text
PATCH /returns/{return}/receiver
body: { received_by_employee_id: <active employee id> }
```

Endpoint lock phiếu và employee, từ chối phiếu đã hủy, chỉ update hai receiver fields, ghi ActivityLog old/new và trả:

```json
{
  "success": true,
  "return": {
    "id": 123,
    "received_by_employee_id": 12,
    "received_by_name": "Tên nhân viên"
  }
}
```

Không endpoint nào đổi `invoice_id`, customer, creator, seller attribution, item/serial, stock, debt, cashflow, payment hoặc totals.

## Canonical display và report invariant

Returns index/show hiển thị `original_seller_name` do `SellerResolver` suy ra từ invoice nguồn. Thứ tự là employee của `invoices.created_by`, snapshot `invoices.seller_name`, sau cùng `Chưa xác định người bán`. `returns.created_by_name` không bao giờ được dùng làm seller.

Employee reports tiếp tục dùng source invoice seller resolver. Thay receiver C thành D chỉ đổi operational metadata, không đổi seller bucket hay số liệu tài chính.

## Danh sách file thay đổi

- `database/migrations/2026_08_01_000000_add_receiver_fields_to_returns_table.php`
- `app/Models/OrderReturn.php`
- `app/Models/ActivityLog.php`
- `app/Services/OrderReturnCreationService.php`
- `app/Services/PosReturnExchangeService.php`
- `app/Support/Reports/SellerResolver.php`
- `app/Http/Controllers/OrderReturnController.php`
- `app/Http/Controllers/PosController.php`
- `routes/web.php`
- `resources/js/Pages/Returns/Index.vue`
- `resources/js/Pages/Returns/Show.vue`
- `resources/js/Pages/POS/Index.vue`
- `tests/Feature/OrderReturn/ReturnReceiverSellerCreatorContractTest.php`
- `docs/audit/HOTFIX-RETURN-RECEIVER-SELLER-CREATOR-CONTRACT.md`

## Test evidence

### Automated

Evidence from the final local run:

- `ReturnReceiverSellerCreatorContractTest`: **PASS**, 5 tests / 20 assertions.
- Order-return targeted regression (`Step232SalesReturnFlowTest`, `Step246EReturnFeeTypeTest`, `RR11OrderReturnQtyTest`, `RR08OrderReturnSerialRollbackTest`): **PASS**, 30 tests / 101 assertions.
- POS targeted regression (`Step246PosQuickReturnTest`, `Step246BPosReturnExchangeTest`): **PASS**, 43 tests / 195 assertions.
- Reports/debt regression (`HOTFIX2431EmployeeReportReturnSellerScopeTest`, `HOTFIX2428SellerCreatorContractTest`, `RR06CustomerDebtLedgerTest`): **PASS**, 25 tests / 114 assertions.
- `npm run build`: **PASS**. Pint, PHP lint and `git diff --check`: **PASS**.

The QA database was a disposable local SQLite copy under `storage/`, never production. It was removed after QA. No production database command was run.

### Manual QA evidence

| Case | Expected | Actual | Result |
|---|---|---|---|
| `/returns` renders | No server/schema error | Page rendered on disposable local QA DB; no `categories.deleted_at` or other schema error | PASS |
| Audited return `TH2026071109580972` | Seller = source invoice seller Vũ Hồng Nhung | Table and expanded row showed source invoice seller Vũ Hồng Nhung; no hardcoded fallback | PASS |
| Audited return creator | Creator = Vũ Hồng Nhung snapshot | Expanded row showed creator Vũ Hồng Nhung, separate from seller/receiver | PASS |
| Legacy receiver null | Shows no selected receiver/unknown until saved | Receiver field was initially unassigned; no receiver mutation occurred until explicit save | PASS |
| Active employee selector | All active employees available | Selector listed all active employees in the disposable QA dataset and preserved the selected value | PASS |
| Select receiver and save | ID/name persist after reload | Selected employee `NV-QA-03`, saved successfully, inline Vietnamese success message shown, value `3` persisted after reload | PASS |
| Receiver reassignment | Invoice seller/creator/items/financial data unchanged | Endpoint is receiver-only; read-only QA comparison showed seller/creator and financial fields unchanged | PASS |
| Reports | Source seller remains attribution | Seller resolver/report regression tests passed with source invoice seller invariant | PASS |
| Inventory/debt/cashflow | No mutation from receiver-only update | Receiver update writes only receiver ID/name and ActivityLog; regression suite passed | PASS |

Browser QA note: the in-app browser bridge rendered some Vietnamese glyphs as `?`/mojibake in snapshots, but the application response/source strings and stored UTF-8 values were Vietnamese. No browser validation alert appeared.

## Data safety statement

Migration is additive and reversible. It is nullable by design for legacy returns. There is no migration backfill, delete, merge, production DB command, or change to checkout, invoices, purchases, stock, cashflow, debt, serials or report calculations. Receiver update has a narrow transaction and only writes receiver ID/name.

## Rollback plan

1. Disable the receiver UI/route if an application rollback is needed.
2. Deploy the previous application version.
3. If schema rollback is approved separately, run the migration `down` in the normal release process; it drops only the two new nullable receiver fields.
4. Do not backfill or rewrite legacy returns during rollback.

## Main return creation flow gate (PR #38)

The original review blocker was that /returns linked to /orders/create?action=return while only the POS flow had been updated. The blocker is now closed:

- OrderController::create passes active employees as id, name, and code, and eager-loads the source invoice creator.
- Orders/Create.vue renders the receiver selector only in return mode, excludes inactive employees, defaults to the source invoice seller when active, and sends received_by_employee_id to POST /returns.
- Missing receiver is blocked inline with Vietnamese text; no browser validation alert is used.
- Interactive POST /returns and POS return/exchange require an integer existing employee ID; the service still enforces active status.
- The unused legacy default_receiver_employee_id index attribute was removed. Legacy rows are not silently assigned.

### Fresh MySQL QA database

The browser run used a disposable MySQL 8.0 container/database named kiot_pr38_qa, isolated from production. Repository migrations ran from an empty database through 2026_08_01_000000_add_receiver_fields_to_returns_table; migration count was 180 and no migration was added for QA. Seed data was limited to one admin, one branch, four employees (three active, one inactive), one customer, one product, and one source invoice.

Read-only schema evidence:

  categories exists                         YES
  categories.deleted_at exists              YES
  customers.status exists                   YES
  receiver migration applied                YES
  production database accessed              NO

### Main-flow manual QA evidence

| Case | EXPECTED | ACTUAL | PASS/FAIL |
|---|---|---|---|
| /returns to Trả hàng | Opens /orders/create?action=return without schema error | Opened successfully on fresh MySQL QA; no categories.deleted_at error | PASS |
| Receiver selector | Visible in return mode and contains all active employees only | Listed NV-QA-01, NV-QA-02, NV-QA-03; NV-QA-04 inactive employee absent | PASS |
| Source seller default | Active source seller is the initial receiver candidate | QA Seller Active (NV-QA-01) was selected from invoice HD-QA-RETURN-001 | PASS |
| Missing receiver | Submit is blocked inline and creates no return | Clearing receiver and submitting showed the Vietnamese inline message; no browser dialog; draft remained | PASS |
| Return draft | Product, quantity, note, and payment remain intact while receiver is changed | Product QA Return Product, quantity 1, note QA purchase return note, payment 20 remained in the form | PASS |
| Create with another active receiver | Return succeeds and receiver persists | Selected QA Receiver Active (NV-QA-03), created return TH2026080109024012, and reload showed receiver QA Receiver Active | PASS |
| Attribution invariant | Seller and creator remain separate from receiver | Show page displayed seller QA Seller Active, receiver QA Receiver Active, creator QA Admin, source invoice unchanged | PASS |
| Browser validation alert | No browser alert for validation | getJsDialog returned none; validation was inline | PASS |

### Automated evidence after blocker fix

- ReturnReceiverSellerCreatorContractTest: PASS, 10 tests / 39 assertions.
- Order-return targeted suite (Step232, Step246E, RR11, RR08): PASS, 30 tests / 101 assertions.
- POS targeted suite (Step246PosQuickReturnTest, Step246BPosReturnExchangeTest): PASS, 43 tests / 195 assertions.
- Reports + RR06 debt + paid-refund suite: PASS as part of the clean-database run.
- Combined clean disposable MySQL target run: PASS, 147 tests / 604 assertions.
- npm run build: PASS.
- php vendor/bin/pint --test on changed PHP: PASS.
- PHP lint on changed PHP: PASS (only existing missing OCI/Firebird extension startup warnings).
- git diff --check: PASS.
- GitHub Actions PC integration gate run #22: PASS on commit c280eed; migration, provider contract, Order/POS conversion, rollback/re-apply, changed PHP style/syntax, frontend build, and tracked-source scan all passed.

Migration status: MIGRATION=YES (the receiver migration is part of PR #38); BACKFILL=NO. No production schema/data command was run.
