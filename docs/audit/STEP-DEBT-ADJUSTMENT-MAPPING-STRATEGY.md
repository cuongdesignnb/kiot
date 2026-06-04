# STEP — DebtAdjustment mapping/ledger strategy

## Phạm vi

- Partner: `KH177460073148` (`Anh Bảy` trong dữ liệu gốc, hiện DB đang mojibake tên hiển thị).
- Invoice: `HD177598589311`.
- Cashflow: `PT26042215161822`.
- Command đã chạy ở chế độ read-only: `php artisan debt:strategy-debt-adjustment --dry-run`.
- Báo cáo chi tiết: `docs/audit/debt-adjustment-strategy/KH177460073148-strategy.md`.
- JSON audit tạm: `storage/app/audits/debt-adjustment-strategy/KH177460073148-strategy.json` (không commit).

## Data safety

- Không migrate.
- Không backfill.
- Không update dữ liệu cũ.
- Không delete.
- Không recalculate.
- Không ghi DB.
- Command strategy có guard bắt buộc `--dry-run`; thiếu `--dry-run` sẽ fail.
- JSON/Markdown export là ghi file report, không phải ghi database.
- Mọi strategy có ghi DB chỉ là preview và bị đánh dấu `true_if_approved_later`.

## Current evidence

| Field | Value |
|---|---:|
| `stored_customer_debt` | `0` |
| `invoice_outstanding` | `15000000` |
| `cashflow_amount` | `15000000` |
| `invoice_customer_debt_rows` | `0` |
| `cashflow_customer_debt_rows` | `0` |
| `timeline_invoice_entry_found` | `true` |
| `timeline_cashflow_entry_found` | `false` |
| `timeline_final_balance` | `15000000` |
| `stored_balance` | `0` |
| `reconcile_mismatch` | `true` |

Diễn giải: hệ thống đang lưu công nợ khách hàng là `0`, nhưng timeline đang chỉ hiện hóa đơn `+15,000,000` và thiếu dòng cashflow DebtAdjustment `-15,000,000`. Vì vậy màn hình/lịch sử nhìn như còn nợ, trong khi stored balance đã về `0`.

## Strategy comparison

| Strategy | Write DB | Tables affected | Risk | Recommended | Expected effect |
|---|---|---|---|---|---:|
| `DISPLAY_ONLY_TIMELINE_FIX` | `false` | none | `LOW-MEDIUM` | yes | timeline thêm effect `-15000000`, final display về `0` |
| `LEDGER_PAIR_PREVIEW` | `true_if_approved_later` | `customer_debts` | `HIGH` | no | insert preview `+15000000` invoice và `-15000000` cashflow, net `0` |
| `LINKAGE_ONLY_PREVIEW` | `true_if_approved_later` | `cash_flows` | `HIGH` | no | update preview cashflow reference sang invoice |

## Recommended strategy

Khuyến nghị hiện tại: `DISPLAY_ONLY_TIMELINE_FIX`.

Lý do:

- Stored debt đang đúng là `0`.
- Hóa đơn `HD177598589311` còn outstanding `15,000,000`.
- Cashflow `PT26042215161822` là `DebtAdjustment`, cùng partner, cùng số tiền `15,000,000`, có mô tả điều chỉnh `15,000,000 -> 0`.
- Không có dòng `customer_debts` cho hóa đơn hoặc cashflow này.
- Timeline hiện thấy hóa đơn `+15,000,000` nhưng không thấy DebtAdjustment `-15,000,000`.
- Sửa hiển thị/code-only giải quyết lịch sử bị thiếu mà không can thiệp dữ liệu cũ.

Không nên insert riêng invoice ledger, vì chỉ thêm chiều `+15,000,000` có thể làm double-count và làm sai công nợ. Nếu ghi ledger thật thì phải là pair `+15,000,000` và `-15,000,000`, nhưng đây là thao tác DB cũ nên cần backup, rollback và xác nhận riêng.

Không nên update linkage cashflow ngay, vì cashflow hiện mang nghĩa nghiệp vụ `DebtAdjustment`; đổi reference sang invoice có thể làm mất nguồn gốc điều chỉnh công nợ và ảnh hưởng sổ quỹ/báo cáo.

## Code-only proposal

Chỉ đề xuất, chưa triển khai trong bước này:

- File mục tiêu: `app/Services/PartnerDebtLedgerService.php`.
- Khu vực: `buildStandaloneCustomerCashFlowEntries` / `buildCustomerNetLedger`.
- Thêm logic hiển thị virtual/reference entry cho cashflow `DebtAdjustment` cùng `target_id` khách hàng khi:
  - cashflow active/not cancelled;
  - amount > 0;
  - reference/type/note thể hiện `DebtAdjustment`;
  - không có dòng `customer_debts` thật đã đại diện cashflow đó qua `cash_flow_id`, `ref_code`, `reference_code`, hoặc code trong note.
- Expected display effect: `-15000000`.
- Double-count prevention: bỏ qua virtual/display entry nếu ledger thật đã đại diện cashflow.
- Test đề xuất nếu được duyệt sửa code hiển thị: `tests/Feature/Customers/DebtAdjustmentTimelineDisplayTest.php`.

## If later apply data fix

Nếu sau này bắt buộc ghi DB, hướng an toàn hơn là `LEDGER_PAIR_PREVIEW`, không phải insert một chiều:

- Insert customer debt invoice `+15,000,000`.
- Insert customer debt DebtAdjustment/cashflow `-15,000,000`.
- Net effect phải bằng `0`.
- Cần export backup trước khi chạy.
- Cần `fix_run_id`.
- Cần rollback plan.
- Cần allowlist đúng partner/invoice/cashflow.
- Cần xác nhận nghiệp vụ bằng văn bản trước khi chạy.

`LINKAGE_ONLY_PREVIEW` chỉ nên xem là phương án cuối sau khi xác nhận cashflow thực sự phải link invoice, vì nó sửa ý nghĩa dữ liệu gốc.

## Kết luận

Không phải lịch sử của khách hàng khác bị mất trong bước này; command chỉ đọc riêng case Anh Bảy và xuất report. Vấn đề của case này là nguồn gốc công nợ đang không được timeline hiển thị đủ: hóa đơn còn xuất hiện `+15,000,000`, còn cashflow `DebtAdjustment` làm về `0` không xuất hiện trong timeline.

Chiến lược nên đi tiếp là sửa code hiển thị có guard chống double-count. Chưa ghi DB và chưa triển khai data fix.

Có thể ghi DB chưa: Chưa.

Cần xác nhận trước khi triển khai nếu có bất kỳ thao tác ghi dữ liệu.
