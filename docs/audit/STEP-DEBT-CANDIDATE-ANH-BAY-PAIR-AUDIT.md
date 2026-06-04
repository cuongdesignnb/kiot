# STEP - Debt candidate pair audit: Anh Bay

## Pham vi

- Partner: `KH177460073148 - Anh Bay`.
- Invoice: `HD177598589311`.
- Cashflow: `PT26042215161822`.
- Snapshot: `20260604-113305`.
- Rui ro: candidate v2 cu de xuat insert ledger cho invoice 15,000,000 trong khi cashflow DebtAdjustment 15,000,000 co the da xu ly cong no ve 0.

## Data safety

- Migration: khong chay.
- Backfill: khong chay.
- Update du lieu cu: khong chay.
- Delete: khong chay.
- Recalculate: khong chay.
- Ghi DB: khong.
- Co chay migrate:fresh khong: khong.
- Export JSON: co, chi vao `storage/app/audits/anh-bay-recheck` va khong commit.
- Export Markdown: co, commit trong `docs/audit/anh-bay-recheck`.

## Source da kiem tra

- Command: `app/Console/Commands/AuditDebtCandidatePairCommand.php`, `debt:inspect-partner`, `debt:diff-partner`.
- Service: `app/Services/DebtEvidenceMatcher.php`, `app/Services/PartnerDebtLedgerService.php`, `app/Services/DebtPartnerInspectionService.php`.
- Models: `Customer`, `Invoice`, `CashFlow`, `CustomerDebt`.
- Tests: `tests/Feature/Console/AuditDebtCandidatePairCommandTest.php`, `tests/Unit/Services/DebtEvidenceMatcherTest.php`.
- Commit: pending in this report.

## Evidence

### Partner

- Code: `KH177460073148`.
- Name: `Anh Bay` (DB text may be mojibake in exported JSON).
- `customers.id`: `35`.
- `customers.debt_amount`: `0`.
- `customers.supplier_debt_amount`: `0`.
- Current stored customer debt is settled at `0`.

### Invoice HD177598589311

- `invoices.id`: `57`.
- `customer_id`: `35`.
- `status`: completed text in DB export.
- `created_at`: `2026-03-27 15:09:00`.
- `total`: `15,000,000`.
- `customer_paid`: `0`.
- Computed outstanding: `15,000,000`.
- Related `customer_debts` for invoice: `0`.

### Cashflow PT26042215161822

- `cash_flows.id`: `290`.
- `type`: `receipt`.
- `amount`: `15,000,000`.
- `status`: `active`.
- `time`: `2026-04-22 15:16:00`.
- `target_id`: `35`.
- `target_type`: customer text in DB export.
- `target_name`: `Anh Bay`.
- `reference_type`: `DebtAdjustment`.
- `reference_code`: `null`.
- `payment_method`: `cash`.
- Note/description: `Dieu chinh cong no | 15,000,000 -> 0` (export text may be mojibake, numeric transition remains visible).
- Debt adjustment signal: `true`.

### Related ledger

- Customer debt rows for invoice `HD177598589311`: `0`.
- Customer debt rows for cashflow `PT26042215161822`: `0`.
- All customer debt rows for partner: `0`.
- Related cashflows for partner/pair: `2`.
- Invoice reference cashflows: `0` for `HD177598589311`.

### Timeline

- Customer net timeline matched entries: `1`.
- Invoice `HD177598589311` appears with effect `15,000,000`.
- Cashflow `PT26042215161822` is not present as a timeline entry.
- Reconcile says ledger/display mismatch remains: stored debt `0`, ledger/computed/display final `15,000,000`.
- This means the display/timeline layer is not enough authority to insert invoice ledger alone, because raw cashflow evidence says there is a debt adjustment settlement.

## Business questions

| Question | Answer | Evidence |
|---|---|---|
| Invoice co tao cong no 15M khong? | Co. | Invoice total `15,000,000`, customer_paid `0`, computed outstanding `15,000,000`, no customer_debt row found. |
| Cashflow co phai dieu chinh cong no cua Anh Bay khong? | Co dau hieu ro. | Cashflow same partner `target_id=35`, amount `15,000,000`, `reference_type=DebtAdjustment`, note says `15,000,000 -> 0`. |
| Cashflow co lam cong no 15M ve 0 khong? | Co kha nang giai thich settlement 15M ve 0, nhung can manual decision vi timeline khong show cashflow entry. | Stored debt is `0`, cashflow amount matches invoice outstanding, cashflow date is after invoice date, note/reference indicate debt adjustment. |
| Insert ledger cho invoice co double-count khong? | Co nguy co double-count. | If only invoice ledger `+15,000,000` is inserted while cashflow adjustment/payment already settled debt to `0`, debt can be counted incorrectly. |
| Fix dung la gi? | `MANUAL_REVIEW_REQUIRED`. | Need manual mapping/ledger strategy: decide whether this is missing linkage only, missing cashflow ledger, or another display/timeline issue. |

## Candidate decision

- Status: `blocked`.
- Recommended fix type: `MANUAL_REVIEW_REQUIRED`.
- Write operations preview: `0`.
- Blocked reason: `possible settlement cashflow exists; manual decision required`.
- Required confirmation: yes.
- Backup required: yes.
- Rollback required: yes.

## Ket luan

- Dat/chua dat: dat cho audit pair read-only.
- Candidate con hop le khong: khong hop le de auto-preview insert invoice ledger rieng.
- Candidate insert ledger cho invoice chua duoc duyet.
- Co nguy co double-count.
- Co the chay fix that chua: Chua.
- Can manual decision ve mapping/ledger strategy.
