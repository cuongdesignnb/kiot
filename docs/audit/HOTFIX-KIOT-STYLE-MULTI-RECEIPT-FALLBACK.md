# HOTFIX STEP 10B — Kiot-style multi receipt + fallback non-clickable

## Phạm vi
- Customer timeline: 1 hóa đơn nhiều phiếu thu thật → mỗi phiếu thu 1 dòng.
- Supplier timeline: fallback non-clickable + badge.
- Invoice receipt: ưu tiên chứng từ thật, mỗi cashflow dùng amount của chính nó.
- Virtual fallback: chỉ khi không có phiếu thu thật; **không click được**.

## Source đã kiểm tra
- `app/Services/PartnerDebtLedgerService.php` — `realInvoiceReceiptsByCode`, `buildCustomerLegacyEntries`, helpers mới.
- `resources/js/Pages/Customers/Index.vue` — code cell click guard + `openDebtVoucherDetail`.
- `resources/js/Pages/Suppliers/Index.vue` — code cell + `openSupplierVoucherDetail`.
- `app/Console/Commands/AuditKiotStyleDebtVoucherCommand.php`.
- Tests KiotStyle + Audit.

## Root cause
- `keyBy(reference_code)` chỉ giữ 1 phiếu thu/hóa đơn → mất phiếu khi 1 HĐ có nhiều phiếu thu; dòng payment có thể hiện mã PT đầu nhưng amount lấy `invoice.customer_paid` (sai).
- Fallback `TTHD...` tuy `is_virtual_fallback=true` nhưng `detail_available=true` + `detail_modal_type='cash_flow'` → vẫn mở modal "fake".
- Rủi ro nếu deploy: timeline sai số tiền dòng thanh toán khi multi-receipt; click mở chứng từ ảo gây hiểu nhầm.

## Thay đổi code
- **groupBy real receipts**: `realInvoiceReceiptsByCode()` đổi `keyBy` → `groupBy(reference_code)`, sort `time, created_at, id`. Trả `Collection<string, Collection<CashFlow>>`.
- **Multi receipt entries**: trong `buildCustomerLegacyEntries`, nếu có ≥1 phiếu thu thật → `foreach` tạo **mỗi cashflow một dòng** (`mapInvoiceReceiptEntry`, amount = `$receipt->amount`, clickable cash_flow). Nếu không có → `mapVirtualInvoicePaymentFallbackEntry` (TTHD, amount = `invoice.customer_paid`, **non-clickable**).
- **Fallback non-clickable**: `detail_available=false`, `detail_modal_type='none'`, `is_real_voucher=false`, `is_virtual_fallback=true`, badge_title rõ.
- **Mismatch guard (Case C)**: nếu `abs(Σ receipts − customer_paid) > 0.01` → render từng phiếu thu thật với amount thật + `receipt_allocation_mismatch=true`, `needs_manual_review=true`, badge "Cần đối soát". **Không** tạo residual fallback tự động, **không** che giấu mismatch.
- **Helpers**: `mapInvoiceReceiptEntry()` (real), `mapVirtualInvoicePaymentFallbackEntry()` (fallback) — tránh copy-paste.
- **Suppression**: giữ nguyên (line 1080 lọc receipt `reference_type=Invoice` theo invoiceCodes) → multi-receipt không bị hiện lại standalone; không suppress DebtPayment/DebtAdjustment.
- **Audit command**: thêm `invoice_receipt_groups` (invoice_code, real_receipt_count, real_receipt_total, receipt_codes, is_mismatch) + summary `fallback_rows / non_clickable_fallback_rows / clickable_fallback_rows / receipt_allocation_mismatches`; risk `clickable_fallback` để fail test nếu fallback click được.
- **Frontend click guard**: customer + supplier không gọi mở modal khi `is_virtual_fallback`/`detail_available=false`/`detail_modal_type='none'`; render `cursor-help` + tooltip; alert nhẹ nếu lỡ click.
- **Supplier TTNH fallback** đã gắn `is_virtual_fallback` (STEP 10) → badge + non-clickable áp dụng.

## Nguyên tắc bất biến đã giữ
- Balance machinery KHÔNG đổi: `ledgerSortKey()` == sort cũ; helpers giữ y nguyên các ternary `$hasCustomerLedger ? 0.0 : -amount`. Mỗi dòng payment dùng amount riêng nhưng tổng = `customer_paid` ở Case A → running balance không đổi.

## Data safety
| | |
|---|---|
| Migration | Không |
| Backfill | Không |
| Update dữ liệu cũ | Không |
| Delete | Không |
| Recalculate DB | Không |
| Ghi `cash_flows`/`invoices`/`customers`/`customer_debts`/`purchases` | Không |
| migrate:fresh | Không |
| Commit JSON/CSV (storage/app/audits) | Không |

## Tests
| Suite | Kết quả |
|---|---|
| `KiotStyleCustomerDebtTimelineTest` (9: +multi-receipt, +fallback non-clickable, +mismatch) | ✅ pass |
| `KiotStyleSupplierDebtTimelineTest` (3) | ✅ pass |
| `AuditKiotStyleDebtVoucherCommandTest` (3: +multi-receipt JSON) | ✅ pass |
| Regression (KiotStyle\|DebtAdjustmentTimeline\|AnhThanhThienPhu\|DualRolePartner\|PartnerFinancialTimeline\|CustomerDebtHistoryDoubleCount\|SupplierPayableLedger\|SupplierDualRole\|HOTFIXFollowUp\|Audit*Debt*\|Inspect/Plan/Apply/Diff Debt\|DebtEvidenceMatcher\|CancelInvoice\|InvoiceTransactionDateTime\|Step243InvoiceUpdate) | ✅ **174 passed / 1 skipped** |
| `npm run build` | ✅ built 6.89s |

**Pre-existing failures (xác nhận fail trên clean HEAD trước patch bằng `git stash`, KHÔNG do STEP 10B, không phát sinh fail mới):**
- `HOTFIXFollowUpDebtOffsetMirrorTest::test_customer_net_view_mirrors_cb_to_positive_effect`
- `CancelInvoicePaymentDebtFlowTest::test_debt_history_maps_cancel_label_and_excludes_cancelled_legacy_invoices`

## Manual QA (cần tester trên data local)
- Multi receipt invoice: 2 phiếu thu hiện đủ, đúng amount, click mở từng phiếu, không còn TTHD, dư nợ = 0.
- Single receipt paid invoice: hiện mã PT thật, click mở phiếu thu.
- Fallback: TTHD, badge "Tạm tính", không click mở modal (alert nhẹ).
- Anh Bảy DebtAdjustment: vẫn hiện, final = 0, không duplicate.
- Supplier: PN/PCPN/CB click mở; TTNH fallback non-clickable.

## Kết luận
- Đạt cho STEP 10B code-only. Không ghi DB.
- Có thể deploy sau khi Senior Auditor kiểm tra commit trên origin/main.
- Cần bước riêng (xác nhận trước) nếu muốn: `cash_flows.reference_id`, bảng `cash_flow_allocations`, backfill phiếu thu cho data cũ.
- Commit SHA: `36672eb` — `fix(debt): support multi-receipt vouchers and non-clickable fallbacks`.
