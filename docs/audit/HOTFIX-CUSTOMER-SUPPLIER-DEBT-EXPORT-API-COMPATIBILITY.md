# Hotfix Customer/Supplier Debt Export + API Compatibility

## Executive Summary

Hotfix này xử lý đúng phạm vi đã duyệt:

- Customer debt CSV export không còn HTTP 500 khi entries đến từ document-first timeline.
- Customer debt XLSX/CSV dùng label/date tương thích document-first cho dòng trả hàng và business time.
- Supplier debt API default document mode bổ sung các alias backward-compatible read-only như `summary.net`, `supplier_effect`, `debt_remain`, `type_label`, `partner_effect`, `supplier_partner_effect`, `source_ledger`, `partner_running_balance`.
- Không thay đổi canonical document-first fields.
- Không ghi dữ liệu, không migration, không backfill, không production command.

Kết quả broad Customer/Supplier debt suite giảm từ `50 failed` xuống `29 failed`. Các failure còn lại thuộc Customer legacy/partner timeline contract cần BA/Senior Auditor chốt trước khi sửa.

## Source/Commit Verified

| Mục | Giá trị |
|---|---|
| Branch hotfix | `hotfix/customer-supplier-debt-export-api-compatibility` |
| HEAD trước hotfix code | `1b979a95554f7ccddc744668fb1996cbb2dd6444` |
| Hotfix code commit | `e7602beb721d292e1f31e68005a9532e8664eb9d` |
| `origin/main` khi bắt đầu | `4ef0189587e4d2edc0b49cc16a239acf059ac969` |
| Pull Request | `#5` - `https://github.com/cuongdesignnb/kiot/pull/5` |
| PR base | `main` at `4ef0189587e4d2edc0b49cc16a239acf059ac969` |
| PR head khi tạo | `hotfix/customer-supplier-debt-export-api-compatibility` at `e7602beb721d292e1f31e68005a9532e8664eb9d` |
| Report triage trước đó | `docs/audit/CUSTOMER-SUPPLIER-DEBT-REGRESSION-TRIAGE-AFTER-PR4.md` |
| Production touched | Không |

Ghi chú: local `main` đã có commit audit report trước khi tách branch hotfix; `origin/main` vẫn là PR #4 SHA `4ef0189587e4d2edc0b49cc16a239acf059ac969`. PR #5 chỉ ở trạng thái ready for review; chưa merge, chưa deploy.

## Scope

Trong scope:

- Response/export compatibility layer read-only.
- Customer debt CSV/XLSX export mapper.
- Supplier debt API aliases cho document mode.
- Supplier virtual opening row read-only khi stored supplier balance có nhưng không có document rows.

Ngoài scope:

- Customer legacy/partner timeline contract còn lại.
- Payment allocation/cashflow write logic.
- Payroll.
- Migration/backfill/rebuild/opening balance thật.
- Production deploy/command.

## Bug Evidence

Trước hotfix, report triage ghi nhận:

- `SapoDebtParityTest`: PASS 12/12.
- Broad Customer/Supplier suite: `50 failed, 1 skipped, 218 passed`.
- Customer CSV export lỗi HTTP 500 tại `CustomerController.php:918` do đọc trực tiếp `$e['type']`, `$e['amount']`, `$e['balance']`.
- Supplier debt API document mode thiếu `supplier_effect`, `debt_remain`, `type_label`, `partner_effect` và `summary.net` fallback về `0`.

## Root Cause

1. Customer export dùng mapper legacy trực tiếp trên document-first entries. Document-first entries có `display_type`, `display_effect`, `customer_display_effect`, `customer_display_running_balance`, `display_time`, không luôn có `type`, `amount`, `balance`.

2. Customer XLSX export label chỉ nhìn `type/type_label`, bỏ qua `display_type`, nên return row có thể trống label.

3. SupplierController comment giữ backward compatibility nhưng `summary.net` lại lấy top-level `closing_balance`; `SupplierDebtDocumentTimelineService` không trả key top-level này.

4. Supplier document entries đã có canonical `supplier_display_effect` và `supplier_display_running_balance`, nhưng chưa expose alias legacy như `supplier_effect` và `debt_remain`.

## Fix Summary

Files changed:

- `app/Http/Controllers/CustomerController.php`
- `app/Http/Controllers/SupplierController.php`
- `app/Services/Exports/CustomerDebtExcelExportService.php`
- `app/Services/SupplierDebtDocumentTimelineService.php`
- `docs/audit/HOTFIX-CUSTOMER-SUPPLIER-DEBT-EXPORT-API-COMPATIBILITY.md`

Thay đổi chính:

- Thêm `normalizeCustomerDebtExportEntry()` cho Customer CSV export.
- Customer CSV/XLSX export ưu tiên business/display time và fallback label từ `display_type`.
- Supplier API `summary.net` dùng computed document display balance thay vì top-level `closing_balance` rỗng.
- Supplier document entries bổ sung alias read-only: `supplier_effect`, `debt_remain`, `type`, `type_label`, `source_ledger`, `partner_effect`, `supplier_partner_effect`, `partner_running_balance`, `supplier_partner_running_balance`.
- Supplier virtual opening row là display-only khi không có chứng từ nhưng stored supplier balance khác 0.

## Data Safety

| Mục | Trạng thái |
|---|---|
| Migration | Không |
| Backfill | Không |
| Update dữ liệu cũ | Không |
| Xóa dữ liệu | Không |
| Rebuild công nợ | Không |
| Opening balance thật | Không |
| Production command | Không |
| Production deploy | Không |
| Payroll touched | Không |
| Rollback plan | Revert commit hotfix |

## Tests

Env testing:

```text
APP_ENV=testing
APP_KEY=base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3319
DB_DATABASE=sales_test
DB_USERNAME=test_user
DB_PASSWORD=test_password
DB_COLLATION=utf8mb4_0900_ai_ci
CACHE_STORE=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
```

| Command | Result |
|---|---|
| `php -l app/Http/Controllers/CustomerController.php` | PASS |
| `php -l app/Http/Controllers/SupplierController.php` | PASS |
| `php -l app/Services/SupplierDebtDocumentTimelineService.php` | PASS |
| `php -l app/Services/Exports/CustomerDebtExcelExportService.php` | PASS |
| `php artisan test tests/Feature/Customers/CustomerDebtExcelExportTest.php tests/Feature/Customers/CustomerDebtTimelineBusinessTimeTest.php` | PASS: 15 passed, 1 skipped |
| `php artisan test tests/Feature/Supplier tests/Feature/Customers/CustomerDebtTimelineBusinessTimeTest.php` | PASS: 64 passed, 1 skipped |
| `php artisan test tests/Feature/Suppliers` | FAIL: 2 failed, 42 passed |
| `php artisan test tests/Feature/CustomerDebt/SapoDebtParityTest.php` | PASS: 12 passed, 41 assertions |
| `php artisan test tests/Feature/CustomerDebt tests/Feature/Customers tests/Feature/Supplier tests/Feature/Suppliers` | FAIL: 29 failed, 1 skipped, 239 passed |
| `git diff --check` | PASS |

PHP local vẫn cảnh báo thiếu optional extensions `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`; các cảnh báo này không làm test fail.

`npm run build` không chạy vì hotfix không sửa frontend/shared JS.

## Remaining Failures

Broad suite sau hotfix còn 29 failures:

- `AnhThanhThienPhuDebtReconcileTest`
- `CustomerDebtHistoryDoubleCountTest` x4
- `CustomerDebtHistoryReturnSettlementDisplayTest` x6
- `CustomerDebtUnresolvedMismatchWarningTest` x1
- `CustomerDebtVirtualOpeningTimelineTest` x1
- `DualRolePartnerDebtTimelineTest` x4
- `HOTFIXFollowUpDebtHistoryPaginationTest` x1
- `HOTFIXFollowUpDebtOffsetMirrorTest` x1
- `PartnerFinancialTimelineTest` x8
- `SupplierDualRoleTimelineFinancialDisplayTest` x1
- `SupplierDualRoleTimelineNoDashTest` x1

Nhận định:

- Customer export failures đã hết.
- Supplier default API compatibility failures đã hết.
- Supplier-only payable/partner API tests đã pass.
- 29 failures còn lại là Customer legacy/partner timeline contract: balance aliases, virtual opening policy, warning wording, return settlement merge, legacy document/reference behavior, and dual-role customer-side running balance.

## Out of Scope

Không xử lý trong PR này:

- Customer legacy/partner timeline rewrite.
- Customer virtual opening policy.
- Customer warning wording.
- Return settlement merge behavior.
- Debt offset mirror/dedup trên customer net view.
- Any production data remediation.

## Go/No-Go

| Phạm vi | Khuyến nghị |
|---|---|
| Customer export hotfix | GO |
| Supplier API compatibility hotfix | GO |
| Full Customer/Supplier debt regression sạch | NO-GO, còn 29 failures cần BA decision |
| Production deploy | Chưa thực hiện trong step này |

## Final Conclusion

Kết luận: `PARTIAL`.

Hotfix đạt mục tiêu nhỏ: Customer debt export không còn 500/sai label/date, Supplier debt API document mode giữ compatibility read-only. Broad suite giảm `50 -> 29` failures. Không có migration/backfill/production touch. Các failure còn lại không nên sửa trong PR này nếu chưa có BA/Senior Auditor chốt contract Customer legacy/partner timeline.
