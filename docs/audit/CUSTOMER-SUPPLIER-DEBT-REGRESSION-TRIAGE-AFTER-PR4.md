# Customer/Supplier Debt Regression Triage After PR #4

## Executive Summary

Triage được thực hiện read-only trên source `origin/main` sau PR #4.

Kết luận chính:

- `SapoDebtParityTest` vẫn PASS 12/12. Các nghiệp vụ signed debt, overpayment, allocation, order summary, merge marker và merged-source guard không bị vỡ trong lần chạy này.
- Broad Customer/Supplier debt regression xác nhận còn đúng 50 failures: `218 passed, 50 failed, 1 skipped`.
- 50 failures không thuộc payroll reconciliation hotfix trực tiếp. Chúng tập trung ở Customer/Supplier debt timeline/export/API compatibility sau khi hệ thống chuyển sang document-first timeline.
- Có lỗi thật cần hotfix tiếp theo, nổi bật nhất là legacy Customer CSV export HTTP 500 và Supplier debt API default trả `summary.net = 0`/thiếu field backward-compatible trong nhiều case.
- Không sửa code trong bước này. Không migration, không backfill, không production command.

## Scope

Trong scope:

- Reproduce các nhóm test Customer/Supplier debt theo yêu cầu.
- Phân loại failure theo nhóm:
  - Test expectation outdated
  - Fixture/setup invalid
  - Real backend bug
  - Export bug
  - Legacy compatibility decision
- Lập report audit riêng.

Ngoài scope:

- Không fix Customer/Supplier debt.
- Không sửa payroll.
- Không deploy production.
- Không migrate production.
- Không chạy production artisan command.
- Không backfill/rebuild/opening balance.

## Source / Commit Verified

| Mục | Giá trị |
|---|---|
| Repo | `cuongdesignnb/kiot` local worktree |
| Branch khi audit | `main` |
| HEAD trước audit | `4ef0189587e4d2edc0b49cc16a239acf059ac969` |
| `origin/main` | `4ef0189587e4d2edc0b49cc16a239acf059ac969` |
| PR #4 merge target | Đã có trên `origin/main` |
| Production touched | Không |

## Test Environment

Các lệnh test được chạy với env testing rõ ràng:

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

Ghi chú môi trường: PHP local vẫn cảnh báo thiếu optional extensions `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`. Các warning này xuất hiện ở đầu test run nhưng không phải nguyên nhân của 50 debt failures.

## Commands Run

| Lệnh | Kết quả |
|---|---|
| `php artisan test tests/Feature/CustomerDebt/SapoDebtParityTest.php` | PASS: 12 passed, 41 assertions |
| `php artisan test tests/Feature/CustomerDebt` | PASS: 17 passed, 55 assertions |
| `php artisan test tests/Feature/Customers` | FAIL: 30 failed, 1 skipped, 118 passed, 644 assertions |
| `php artisan test tests/Feature/Supplier` | FAIL: 7 failed, 52 passed, 225 assertions |
| `php artisan test tests/Feature/Suppliers` | FAIL: 13 failed, 31 passed, 200 assertions |
| `php artisan test tests/Feature/CustomerDebt tests/Feature/Customers tests/Feature/Supplier tests/Feature/Suppliers` | FAIL: 50 failed, 1 skipped, 218 passed, 1124 assertions |
| `git diff --check` | PASS |

JUnit evidence được ghi tại:

```text
storage/app/uat-evidence/supplier-junit.xml
storage/app/uat-evidence/suppliers-junit.xml
storage/app/uat-evidence/debt-broad-junit.xml
```

Các file evidence này không được commit.

## Sapo Debt Parity Result

`tests/Feature/CustomerDebt/SapoDebtParityTest.php` PASS toàn bộ 12 kịch bản:

- recordSale không nhận âm.
- Invoice overpayment tạo credit âm.
- Thu nợ dư giữ full CashFlow và unallocated credit.
- Credit dùng ở lần mua sau.
- Order summary Option A với/không cọc.
- Merge marker amount = 0, không double debt.
- Dual-role net zero.
- Invalid allocation bị chặn.
- Cancelled invoice không được allocation.
- CashFlow cancellation idempotent.
- Source partner đã merge bị chặn.

Đây là tín hiệu quan trọng: regression 50 lỗi không phá vỡ parity Sapo đã port.

## Failure Inventory

| Nhóm | Failed | Ghi chú |
|---|---:|---|
| `tests/Feature/Customers` | 30 | Customer export, legacy history, return settlement, partner financial timeline, dual-role display |
| `tests/Feature/Supplier` | 7 | Supplier default debt API thiếu legacy fields / summary net |
| `tests/Feature/Suppliers` | 13 | Supplier payable/dual-role orientation, partner timeline, virtual opening, legacy aliases |
| Tổng unique failures | 50 | Xác nhận bởi broad command và JUnit |

## Failure Classification Summary

| Phân loại | Count | Nhóm test chính | Kết luận |
|---|---:|---|---|
| Test expectation outdated | 1 | `CustomerDebtUnresolvedMismatchWarningTest` | Chỉ lệch wording warning mới |
| Fixture/setup invalid | 0 | Không tính 2 lỗi `MissingAppKey` transient vì rerun với `APP_KEY` đã pass | Không còn setup failure trong kết quả cuối |
| Real backend bug | 20 | `Supplier`, `Suppliers` public API debt tests | Default supplier debt endpoint/document service không giữ đủ contract `summary.net`, `supplier_effect`, `debt_remain`, `type_label`, `partner_effect` |
| Export bug | 3 | Customer CSV/XLSX export tests | Legacy CSV có HTTP 500, CSV business date sai, XLSX return row thiếu label |
| Legacy compatibility decision | 26 | Customer legacy/partner timeline tests | Cần BA chốt giữ compatibility alias hay cập nhật tests/frontend theo document-first contract |

Tổng: 50.

## Detailed Failure Groups

### A. Export Bug - 3 failures

| Test | Hiện tượng | Phân loại | Blocker |
|---|---|---|---|
| `CustomerDebtExcelExportTest::return_entry_has_credit_amount_and_no_auto_settlement_adjustment` | Return row column C expected `Trả hàng bán`, actual `null` | Export bug | Có, nếu BA cần xuất Excel công nợ KH chuẩn |
| `CustomerDebtExcelExportTest::legacy_csv_without_query_still_works` | HTTP 500 `Undefined array key "type"` tại `app/Http/Controllers/CustomerController.php:918` | Export bug / real runtime bug | Có |
| `CustomerDebtTimelineBusinessTimeTest::customer_csv_export_uses_business_date` | CSV không dùng business/display time đúng | Export bug | Có nếu dùng CSV |

Root cause trực tiếp đã thấy:

```text
app/Http/Controllers/CustomerController.php:918
collect($entries)->map(fn($e) => [$e['code'], $e['type'], $e['amount'], $e['balance'], ...])
```

Document-first entries không đảm bảo có keys legacy `type`, `amount`, `balance`. Legacy CSV path cần mapper phòng thủ hoặc contract alias.

### B. Supplier Debt API Contract - 20 failures

Các failure trong `tests/Feature/Supplier` và `tests/Feature/Suppliers` tập trung vào:

- `summary.net` trả `0` thay vì payable/net expected.
- Entry thiếu `debt_remain`.
- Entry thiếu `supplier_effect`.
- Entry thiếu `type_label`.
- Partner view thiếu `partner_effect`, `supplier_partner_effect`, `source_ledger`.
- Virtual opening summary keys không còn xuất hiện như legacy expectation.

Code hiện tại:

- `SupplierController::debtTransactions()` mặc định dùng `SupplierDebtDocumentTimelineService` khi `mode` không phải `legacy`.
- `mode=legacy` mới dùng `PartnerDebtLedgerService`.
- `SupplierDebtDocumentTimelineService` xuất các key document-first như `display_effect`, `supplier_display_effect`, `display_balance_*`.
- Controller comment vẫn ghi backward-compatible keys, nhưng `summary.net` non-partner đang lấy `$ledger['closing_balance'] ?? 0.0`; document service không trả top-level `closing_balance`, nên nhiều test nhận `0`.

Đây nên coi là backend/API contract bug cho hotfix tiếp theo, vì default API có comment/contract backward-compatible nhưng response không đáp ứng.

### C. Customer Legacy / Partner Timeline Compatibility - 26 failures

Các failure còn lại trong `tests/Feature/Customers` thuộc nhóm:

- `CustomerDebtHistoryDoubleCountTest`
- `CustomerDebtHistoryReturnSettlementDisplayTest`
- `CustomerDebtVirtualOpeningTimelineTest`
- `DualRolePartnerDebtTimelineTest`
- `HOTFIXFollowUpDebtHistoryPaginationTest`
- `HOTFIXFollowUpDebtOffsetMirrorTest`
- `PartnerFinancialTimelineTest`
- `AnhThanhThienPhuDebtReconcileTest`

Mẫu lỗi:

- Expected legacy keys như `balance`, `debt_total`, `supplier_effect`, `customer_effect`, `customer_balance_effect`.
- Expected virtual opening behavior khi stored balance có nhưng không có ledger/history.
- Expected TTHD/reference-only và return settlement merge theo legacy timeline.
- Expected pagination summary full-ledger theo old running balance fields.
- Expected dual-role partner financial timeline giữ đủ display/effect aliases.

Nhận định:

- Một phần là compatibility contract thật nếu frontend/export vẫn đọc các key cũ.
- Một phần là test expectation cần cập nhật nếu BA quyết định document-first là contract mới.
- Không nên sửa vội từng assert. Cần BA chọn một hướng:
  - Giữ document-first làm canonical nhưng bổ sung alias compatibility read-only.
  - Hoặc cập nhật test/frontend bỏ dependency vào field legacy.

### D. Outdated Test Wording - 1 failure

| Test | Expected | Actual | Phân loại |
|---|---|---|---|
| `CustomerDebtUnresolvedMismatchWarningTest::unresolved_display_mismatch_still_returns_warning` | `Lịch sử công nợ đang lệch...` | `Timeline chứng từ lệch...` | Test expectation outdated |

Đây không phải backend debt bug nếu BA đã chấp nhận wording mới.

## Production Risk

| Rủi ro | Mức | Lý do |
|---|---|---|
| Customer legacy CSV export 500 | High | User thao tác export CSV có thể gặp HTTP 500 |
| Supplier debt API `summary.net = 0` ở default document mode | High | Màn NCC/API có thể hiển thị sai payable nếu frontend dùng `summary.net` |
| Missing legacy entry aliases (`supplier_effect`, `debt_remain`, `partner_effect`) | Medium/High | Tùy frontend hiện đang đọc key nào |
| Customer XLSX return label/business date | Medium | Ảnh hưởng export/report, không làm sai ledger nếu chỉ render sai |
| Warning wording test | Low | Không ảnh hưởng dữ liệu |
| Virtual opening legacy behavior | Medium | Cần BA chốt policy compatibility trước khi sửa |

## Data Safety

| Mục | Trạng thái |
|---|---|
| Sửa code nghiệp vụ | Không |
| Migration | Không |
| Backfill | Không |
| Cleanup legacy data | Không |
| Production DB touched | Không |
| Production command | Không |
| Production deploy/build/cache/restart | Không |

## Recommended Fix Plan

Đề xuất hotfix tiếp theo, tách khỏi report-only step này:

1. Fix Customer export mapper:
   - Legacy CSV không đọc trực tiếp `$e['type']`, `$e['amount']`, `$e['balance']` nếu key không tồn tại.
   - Dùng mapper chung từ document-first fields: `display_type/type_label`, `display_effect/amount`, `customer_display_running_balance/balance`, `display_time/time`.
   - Giữ business date cho CSV và XLSX thống nhất.

2. Fix Supplier debt API backward compatibility:
   - Trong default document mode, `summary.net` nên lấy `ledger.summary.net` hoặc `display_balance_final/display_balance_target` phù hợp, không fallback về top-level `closing_balance` không tồn tại.
   - Bổ sung alias read-only trên entries nếu BA muốn giữ contract cũ: `supplier_effect`, `debt_remain`, `type_label`, `partner_effect`, `supplier_partner_effect`, `source_ledger`.

3. Chốt Customer/Partner timeline compatibility:
   - BA quyết định giữ legacy alias trong response hay update tests/frontend sang document-first.
   - Nếu giữ alias, thêm adapter layer read-only, không ghi ledger mới và không đổi dữ liệu.

4. Update tests theo quyết định:
   - Wording warning: cập nhật expected string nếu wording mới được duyệt.
   - Virtual opening: giữ hay bỏ cần acceptance rõ.

## What Not To Fix Yet

Không nên làm trong hotfix nếu chưa có BA approval:

- Không backfill ledger.
- Không tạo opening balance từ legacy stored balances.
- Không rebuild customer/supplier debt.
- Không cleanup MERGE/legacy entries.
- Không đổi production data.
- Không đổi stock/costing/serial/IMEI.
- Không sửa payroll reconciliation trong cùng PR hotfix Customer/Supplier debt.

## Go / No-Go Recommendation

| Quyết định | Khuyến nghị |
|---|---|
| Merge/deploy PR #4 payroll hotfix | Không bị chặn bởi 50 failures nếu phạm vi là payroll reconciliation đã test riêng |
| Customer/Supplier debt production release confidence | PARTIAL / NO-GO cho phần export/API debt nếu chưa hotfix |
| Hotfix tiếp theo | Nên tạo PR riêng: Customer/Supplier debt export + API compatibility |
| Production commands | Vẫn chưa chạy |

## Final Conclusion

Kết quả triage: `PARTIAL`.

PR #4 payroll reconciliation không làm vỡ Sapo debt parity, nhưng Customer/Supplier debt regression suite vẫn còn 50 failures đã tồn tại ở vùng debt timeline/export/API compatibility. Cần hotfix riêng trước khi tuyên bố Customer/Supplier debt regression sạch, đặc biệt cho Customer CSV export HTTP 500 và Supplier debt API `summary.net`/legacy fields.
