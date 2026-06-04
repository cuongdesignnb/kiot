# STEP - Debt evidence matching v2

## Pham vi

- Module: cong no khach hang, nha cung cap, dual-role partner.
- Command nang cap: `debt:diff-partner`, `debt:plan-fix`.
- Evidence version: `v2`.
- Snapshot plan: `storage/app/audits/20260604-113305/debt-fix-plan.json`.
- Evidence JSON: `storage/app/audits/manual-diff-v2` (khong commit).
- Evidence Markdown: `docs/audit/diff-v2`.

## Data safety

- Migration: khong chay.
- Backfill: khong chay.
- Update du lieu cu: khong chay.
- Delete: khong chay.
- Recalculate: khong chay.
- Ghi DB: khong.
- `debt:diff-partner`: bat buoc `--dry-run`.
- `debt:plan-fix --evidence-dir`: chi doc JSON evidence va merge preview vao plan output.
- `debt:apply-fix-plan`: van `apply_enabled=false`; `--apply` van failsafe neu chua co confirmation that.

## Evidence matching v2

`DebtEvidenceMatcher` tao cac output moi:

- `evidence_matching_version: v2`.
- `possible_matches`.
- `candidate_preview`.
- `matching_summary`.
- Matrix co them `source_base_code`, `candidate_ledger_codes`, `candidate_cashflow_codes`, `best_match_type`, `best_match_score`, `best_match_confidence`, `severity`, `can_auto_candidate`.

Matching strategy chinh:

- Exact code/reference.
- Normalized base code, gom mapping payment code nhu `PCPN...` ve purchase base `PN...`.
- Direct FK/reference id.
- Amount/date near trong khoang 3 ngay.
- Zero-effect document de khong tao false high missing-ledger issue.
- Manual/merge ledger nhu `MERGE-CUSTOMER-*` bi chan va yeu cau authority.

## Results

| Code | File | Group | Documents | Ledger | Cashflow | Issues | Critical | Possible matches | Candidate ready | Preview ops | Status summary |
|---|---|---|---:|---:|---:|---:|---:|---:|---|---:|---|
| `NCC177624592772` | `docs/audit/diff-v2/NCC177624592772-Trong-Hung.md` | `C_LEDGER_DOCUMENT_MISMATCH` | 17 | 12 | 7 | 34 | 1 | 0 | No | 0 | `MANUAL_LEDGER_REQUIRES_AUTHORITY=4; MISSING_DOCUMENT=8; MISSING_LEDGER=15; UNMATCHED=7; ZERO_EFFECT_DOCUMENT=2` |
| `NCC177354084249` | `docs/audit/diff-v2/NCC177354084249-Hung-Hoa-Mai.md` | `C_LEDGER_DOCUMENT_MISMATCH` | 22 | 15 | 19 | 11 | 1 | 33 | No | 0 | `MANUAL_LEDGER_REQUIRES_AUTHORITY=1; MISSING_DOCUMENT=7; MISSING_LEDGER=3; ZERO_EFFECT_DOCUMENT=19` |
| `NCC177950763826` | `docs/audit/diff-v2/NCC177950763826-Anh-Thanh-Thien-Phu.md` | `C_LEDGER_DOCUMENT_MISMATCH` | 12 | 4 | 1 | 11 | 1 | 3 | No | 0 | `MANUAL_LEDGER_REQUIRES_AUTHORITY=1; MATCHED_EXACT=2; MISSING_DOCUMENT=1; MISSING_LEDGER=9; ZERO_EFFECT_DOCUMENT=1` |
| `KH177518347435` | `docs/audit/diff-v2/KH177518347435-An-Le-Duc-Tho.md` | `D_CUSTOMER_ONLY_REVIEW` | 3 | 2 | 3 | 2 | 1 | 5 | No | 0 | `DUPLICATE_LEDGER=1; MISSING_DOCUMENT=1; ZERO_EFFECT_DOCUMENT=2` |
| `KH177460073148` | `docs/audit/diff-v2/KH177460073148.md` | `B_DOCUMENTS_NO_LEDGER` | 2 | 0 | 2 | 2 | 0 | 1 | Yes | 1 | `MISSING_LEDGER=1; UNMATCHED=1; ZERO_EFFECT_DOCUMENT=1` |

## Candidate preview

- Group C/D: blocked mac dinh, khong tao write operation preview.
- Group B sample `KH177460073148`: `candidate_ready=true`, co 1 operation preview.
- Plan dry-run voi `--evidence-dir=storage/app/audits/manual-diff-v2` merge dung 1 case: `KH177460073148`.
- Merge condition da ap dung: partner code khop, group allowlist, evidence candidate ready, khong co critical issue, snapshot khop neu co.
- Output merge chi nam trong preview plan export `storage/app/audits/manual-diff-v2/debt-fix-plan-v2.json`; khong commit storage JSON/CSV.

## Reports

- `docs/audit/diff-v2/NCC177624592772-Trong-Hung.md`
- `docs/audit/diff-v2/NCC177354084249-Hung-Hoa-Mai.md`
- `docs/audit/diff-v2/NCC177950763826-Anh-Thanh-Thien-Phu.md`
- `docs/audit/diff-v2/KH177518347435-An-Le-Duc-Tho.md`
- `docs/audit/diff-v2/KH177460073148.md`

## Tests

- `php artisan test tests/Unit/Services/DebtEvidenceMatcherTest.php`
- `php artisan test tests/Feature/Console/DiffDebtPartnerCommandV2Test.php`
- `php artisan test tests/Feature/Console/DiffDebtPartnerCommandTest.php`
- `php artisan test tests/Feature/Console/ApplyDebtFixPlanCommandTest.php`
- `php artisan test tests/Feature/Console/PlanDebtFixCommandTest.php`
- `php artisan test tests/Feature/Console/InspectDebtPartnerCommandTest.php`
- `php artisan test tests/Feature/Console/AuditDebtLedgerCommandTest.php`
- `php artisan test tests/Feature/Customers/AnhThanhThienPhuDebtReconcileTest.php`
- `php artisan test tests/Feature/Suppliers/SupplierDualRoleTimelineNoDashTest.php`
- `npm run build`

## Ket luan

- Dat/chua dat: dat cho evidence matching v2. Full regression va `npm run build` PASS.
- Có thể chạy fix thật chưa: Chưa.
- Cần xác nhận trước khi triển khai.
