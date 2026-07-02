# HOTFIX — DebtAdjustment timeline display

## Phạm vi

- Partner: `KH177460073148` (`Anh Bay` / `Anh Bảy` trong dữ liệu gốc).
- Invoice: `HD177598589311`.
- Cashflow: `PT26042215161822`.
- Màn hình: customer debt timeline / debt-history.
- Rủi ro: medium nếu duplicate phiếu thu; hotfix có guard chống double-count và không ghi DB.

## Source đã kiểm tra

- Service: `app/Services/PartnerDebtLedgerService.php`.
- Tests: `tests/Feature/Customers/DebtAdjustmentTimelineDisplayTest.php`.
- Command: `php artisan debt:strategy-debt-adjustment --dry-run`.
- Report: `docs/audit/debt-adjustment-display-fix/KH177460073148-strategy-after.md`, `docs/audit/debt-adjustment-display-fix/KH177460073148-diff-after.md`.
- Commit: `fix(debt): display debt adjustment cashflows in customer timeline`.

## Hiện trạng trước fix

- Stored debt: `0`.
- Invoice outstanding: `15,000,000`.
- Timeline invoice entry: `true`.
- Timeline cashflow entry: `false`.
- Display final: `15,000,000`.
- Reconcile mismatch: `true`.

## Thay đổi code

- File: `app/Services/PartnerDebtLedgerService.php`.
- Helper:
  - `buildDebtAdjustmentCashFlowDisplayEntries`.
  - `mapDebtAdjustmentCashFlowEntry`.
  - `isDebtAdjustmentCashFlow`.
  - `hasCustomerDebtRepresentingCashFlow`.
  - `isSameCustomerCashFlow`.
  - `normalizeDebtAdjustmentSignalText`.
- Guard:
  - `reference_type = DebtAdjustment` hoặc description/note có tín hiệu điều chỉnh công nợ.
  - `status` không cancelled.
  - `amount > 0`.
  - cùng customer qua `target_id` hoặc `customer_id` nếu schema có.
  - không có `customer_debts` đại diện cashflow qua `cash_flow_id`, `ref_code`, `reference_code`, hoặc note chứa cashflow code.
- Entry fields:
  - `type = debt_adjustment`.
  - `event_kind = debt_adjustment`.
  - `source = cash_flow`.
  - `display_effect = -amount`.
  - `customer_display_effect = -amount`.
  - `customer_display_balance_effect = -amount`.
  - `balance_effect = 0`.
  - `customer_balance_effect = 0`.
  - `affects_debt_balance = false`.
  - `is_virtual_display_adjustment = true`.
  - `is_debt_adjustment_cashflow = true`.
- Double-count prevention: DebtAdjustment không đi qua nhánh phiếu thu thường và không tạo virtual entry nếu đã có ledger thật trong `customer_debts`.

## Data safety

- Migration: no.
- Backfill: no.
- Update dữ liệu cũ: no.
- Delete: no.
- Recalculate: no.
- Ghi DB: no.
- Code-only: yes.
- Có chạy migrate:fresh không: no.

## Kết quả sau fix

- Timeline invoice entry: `true`.
- Timeline DebtAdjustment entry: `true`.
- Display effect: `-15,000,000`.
- Display final: `0`.
- Stored debt: `0`.
- DB row counts before/after: test hotfix giữ nguyên `customer_debts`, `cash_flows`, `invoices`; inspect dữ liệu thật sau fix vẫn có `customer_debt_count = 0`, `cash_flow_count = 2`, `invoice_count = 2`.
- Reconcile: `has_mismatch = false`, severity `ok`.

## Regression

- AnhThanhThienPhuDebtReconcileTest: pass.
- SupplierDualRoleTimelineNoDashTest: pass.
- AuditDebtCandidatePairCommandTest: pass.
- PlanDebtAdjustmentStrategyCommandTest: pass.
- DebtEvidenceMatcherTest: pass.
- DiffDebtPartnerCommandV2Test: pass.
- ApplyDebtFixPlanCommandTest: pass.
- PlanDebtFixCommandTest: pass.
- InspectDebtPartnerCommandTest: pass.
- AuditDebtLedgerCommandTest: pass.
- npm run build: pass.

## Manual QA

- Customer debt tab Anh Bảy: cần mở màn hình customer debt-history và xác nhận thấy invoice `+15,000,000`, DebtAdjustment `-15,000,000`, final display `0`.
- Sổ quỹ phiếu `PT26042215161822`: cần mở chi tiết phiếu để xác nhận vẫn là phiếu `DebtAdjustment`, không bị update reference.
- Không duplicate phiếu thu thường: đã có test `regular receipt is not marked as debt adjustment`.
- Không ảnh hưởng supplier timeline: đã chạy `SupplierDualRoleTimelineNoDashTest`.

## Kết luận

- Đạt.
- Có thể deploy code-only sau khi pull/build/cache.
- Có ghi DB không: không.
- Cần bước tiếp: manual QA trên màn hình Anh Bảy và sổ quỹ sau deploy.

Đạt cho code-only DebtAdjustment timeline display fix.
Có thể deploy code-only sau khi pull/build/cache.
Có thể ghi DB chưa: Chưa, không cần ghi DB cho hotfix này.
