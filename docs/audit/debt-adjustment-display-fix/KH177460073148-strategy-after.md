# DebtAdjustment mapping/ledger strategy - KH177460073148

## Scope

- Partner: `KH177460073148 - Anh Báº©y`.
- Invoice: `HD177598589311`.
- Cashflow: `PT26042215161822`.
- Dry-run: `true`.

## Current evidence

- Stored debt: `0`.
- Invoice outstanding: `15000000`.
- Cashflow amount: `15000000`.
- Cashflow DebtAdjustment: `yes`.
- Timeline invoice entry: `yes`.
- Timeline cashflow entry: `yes`.
- Timeline final balance: `0`.
- CustomerDebt rows: invoice `0`, cashflow `0`.
- Reconcile mismatch: `no`.

## Strategy comparison

| Strategy | Write DB | Tables affected | Risk | Pros | Cons | Recommended |
|---|---|---|---|---|---|---|
| DISPLAY_ONLY_TIMELINE_FIX | false |  | LOW-MEDIUM | Khong sua du lieu cu; Tranh double-count; Phu hop vi stored debt hien tai da la 0 | Khong tao ledger that; Chi giai thich hien thi/timeline; Can test de khong anh huong phieu thu thuong | no |
| LEDGER_PAIR_PREVIEW | true_if_approved_later | customer_debts | HIGH | Co ledger that; Giai thich du phat sinh tang va giam | Ghi du lieu cu; Can fix_run_id/rollback; Can xac nhan nghiep vu | no |
| LINKAGE_ONLY_PREVIEW | true_if_approved_later | cash_flows | HIGH | Giu nguyen so tien; Khong tao ledger moi | Co the lam mat y nghia DebtAdjustment goc; Sua du lieu cu; Co the anh huong so quy/bao cao | no |

## Recommended strategy

- Strategy: `MANUAL_REVIEW_REQUIRED`.
- Reason: Strategy guards were not satisfied..
- Why not insert invoice ledger only: It can double-count because DebtAdjustment cashflow already explains a 15M settlement to stored debt 0.
- Why not update cashflow linkage now: It changes old cashflow meaning from DebtAdjustment to Invoice linkage and can affect cashbook/report semantics.
- Why not create ledger pair now: It writes old data and requires backup, rollback, allowlist, and written approval.

## Code-only proposal

- Files: `app/Services/PartnerDebtLedgerService.php`.
- Function: `buildStandaloneCustomerCashFlowEntries` / `buildCustomerNetLedger`.
- Guard: DebtAdjustment cashflow, same customer target_id, active/not cancelled, amount > 0, no customer_debts cash_flow_id/ref_code already representing it..
- Expected display effect: `-15000000`.
- Double-count prevention: Skip virtual/display DebtAdjustment when a real customer_debts row already represents the cashflow..
- Tests: `tests/Feature/Customers/DebtAdjustmentTimelineDisplayTest.php` if code-only display fix is approved.

## Data safety

- Migration: no.
- Backfill: no.
- Update old data: no.
- Delete: no.
- Recalculate: no.
- Write DB: no.
- Code-only change: proposal only.
- Requires confirmation before production: yes.

