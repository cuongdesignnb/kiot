# STEP — KiotViet-style debt voucher timeline (bản thực dụng, code-only)

## Phạm vi
- Customer timeline: ưu tiên phiếu thu thật cho dòng thanh toán hóa đơn; fallback rõ ràng khi chưa có.
- Supplier timeline: phiếu chi thật đã được ưu tiên sẵn; bổ sung click-to-detail (gap thật).
- Dual-role: dùng chung `customers` record; click hoạt động cả 2 chiều.
- Cashflow / Invoice / Purchase: read-only, không ghi.

## Source đã kiểm tra
- `app/Services/PartnerDebtLedgerService.php` — `buildCustomerLegacyEntries` (TTHD block), `buildSupplierPayableLedger` (TTNH fallback), `entry()` defaults, các sort.
- `app/Http/Controllers/CustomerController.php` — `debtVoucherDetail` (click đã chạy qua prefix).
- `app/Http/Controllers/SupplierController.php` — `debtTransactions`, thêm `debtVoucherDetail`.
- `app/Models/CashFlow.php` — `scopeActive()`.
- `resources/js/Pages/Customers/Index.vue`, `resources/js/Pages/Suppliers/Index.vue`.
- `routes/api.php`.

## Hiện trạng trước fix
- Invoice paid tạo CashFlow receipt thật (InvoiceSaleService) ✓.
- Purchase paid tạo CashFlow payment thật (PurchaseController) ✓.
- Timeline **luôn** dựng dòng ảo `TTHD...` từ `invoice.customer_paid` — **kể cả khi phiếu thu thật tồn tại**.
- Phiếu thu thật `reference_type=Invoice` bị **lọc bỏ** khỏi standalone list (chống double-count với CustomerDebt ledger).
- Click: đã hoạt động (customer); supplier **chưa** click được.

## Thay đổi code (bản thực dụng — KHÔNG đụng balance)
1. **Ưu tiên phiếu thu thật**: `realInvoiceReceiptsByCode()` preload receipt theo mã hóa đơn. Dòng thanh toán:
   - Có phiếu thu thật → hiện **mã PT thật**, `is_real_voucher=true`, `detail_modal_type=cash_flow`, click mở phiếu thu thật.
   - Không có → giữ `TTHD...`, `is_virtual_fallback=true`, `badge_title` giải thích "tạm tính, chưa có phiếu thu thật".
   - `badge_label` giữ nguyên `'Thanh toán'` (không churn assertion cũ); phân biệt qua cờ + badge_title.
2. **Suppression giữ nguyên** (line 1080) → phiếu thu thật không hiện 2 lần.
3. **Metadata click**: thêm `detail_modal_type / detail_reference_type / detail_reference_id / detail_reference_code` cho invoice + payment.
4. **Sequence**: `ledger_sequence` / `display_sequence` (sale=10, payment=20) là metadata; frontend có thể dùng `display_sequence` sắp xếp client-side. **KHÔNG** dùng để đổi sort backend (tránh lệch running balance).
5. **Supplier click**: `SupplierController::debtVoucherDetail` (route `GET /api/suppliers/{id}/debt-voucher-detail`) tra cứu generic theo code: Purchase / PurchaseReturn / CashFlow / DebtOffset / Invoice; virtual TTNH/TTHD → 404 "tạm tính". Supplier TTNH fallback gắn `is_virtual_fallback`.
6. **Audit command read-only**: `debt:audit-kiot-vouchers --dry-run` (bắt buộc `--dry-run`, thiếu → FAILURE) phân loại real vs fallback + risk, export JSON/MD.
7. **Frontend**: badge "Tạm tính" cho `is_virtual_fallback` (2 màn); cột "Công nợ" → "Dư nợ khách hàng"; supplier code clickable + modal chi tiết.

## Nguyên tắc bất biến đã giữ
- `ledgerSortKey()` byte-equivalent với sort cũ (`timestamp-id`) → balance machinery (virtual opening, dual running balance) KHÔNG đổi.
- Không đổi `balance_effect` / `customer_effect` / `affects_debt_balance` / `is_reference_only` của bất kỳ entry nào.

## Data safety
- Migration: Không. Backfill: Không. Update/Delete/Recalculate: Không.
- Ghi `cash_flows`/`invoices`/`purchases`/`customers`/`customer_debts`: Không.
- migrate:fresh: Không. Commit JSON/dump: Không.
- Toàn bộ là read/display + 1 read-only command.

## Test results
| Suite | Kết quả |
|---|---|
| `KiotStyleCustomerDebtTimelineTest` | ✅ 6 passed |
| `KiotStyleSupplierDebtTimelineTest` | ✅ 3 passed |
| `AuditKiotStyleDebtVoucherCommandTest` | ✅ 2 passed |
| Regression (KiotStyle\|DebtAdjustmentTimeline\|AnhThanhThienPhu\|DualRolePartner\|PartnerFinancialTimeline\|CustomerDebtHistoryDoubleCount\|SupplierPayableLedger\|SupplierDualRole\|HOTFIXFollowUp\|CancelInvoice\|InvoiceTransactionDateTime\|Step243InvoiceUpdate) | ✅ 111 passed / 1 skipped |
| `npm run build` | ✅ built 7.58s |

**2 fail tồn tại NHƯNG là pre-existing trên HEAD sạch (xác nhận bằng `git stash`), KHÔNG do STEP 10:**
- `HOTFIXFollowUpDebtOffsetMirrorTest::test_customer_net_view_mirrors_cb_to_positive_effect` — assert `balance==0` (test đã được sửa tay từ trước, fail sẵn).
- `CancelInvoicePaymentDebtFlowTest::test_debt_history_maps_cancel_label_...` — tìm `invoice_cancel_reversal`, fail sẵn trên HEAD.

## Manual QA (local — data đã import)
- KH có hóa đơn trả đủ + có phiếu thu thật → dòng thanh toán hiện mã PT thật, bấm mở phiếu thu.
- KH có hóa đơn trả nhưng không có phiếu thu → dòng TTHD + badge "Tạm tính".
- NCC: bấm PN mở phiếu nhập, PCPN mở phiếu chi, CB mở cấn trừ; TTNH báo "tạm tính".
- `php artisan debt:audit-kiot-vouchers --dry-run --customer-code=...` liệt kê real vs fallback, không ghi DB.

## Known limitations
- Chưa có `cash_flows.reference_id` / `cash_flow_allocations` → DebtPayment allocation vẫn parse từ `reference_code` string (giữ nguyên).
- Fallback (no real receipt): số tiền đúng (`invoice.customer_paid`) nhưng không có mã chứng từ thật + giờ chính xác. Muốn xóa hẳn fallback cần bước backfill tạo phiếu thu (ghi DB, cần xác nhận riêng).
- Sắp xếp "thanh toán trên hóa đơn cùng giờ" để frontend làm client-side qua `display_sequence` (backend không reorder để bảo toàn balance).

## Kết luận
- Đạt cho KiotViet-style debt timeline code-only: timeline ưu tiên chứng từ thật, fallback rõ ràng, click 2 màn, audit read-only.
- Không ghi DB.
- Có thể deploy sau pull/build/cache.
- Dữ liệu chuẩn hóa reference_id/allocation/backfill phiếu thu cần bước riêng và phải xác nhận trước.
- Commit SHA: pending.
