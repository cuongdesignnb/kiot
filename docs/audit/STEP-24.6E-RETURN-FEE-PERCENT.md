# STEP 24.6E — Return Fee VND/%

## 1. Root cause

- Trước đây `OrderReturnController@store` chỉ chấp nhận `fee` numeric (VND amount). Không có cách diễn đạt "phí 10% của tổng tiền hàng trả" giống KiotViet.
- Frontend POS tab Trả hàng lại tin `total` mà nó tự tính rồi gửi backend → nếu user/JS sai, công nợ + cashflow sai theo.
- Ví dụ KiotViet: subtotal 7.000.000, phí 10% ⇒ phí 700.000 ⇒ cần trả khách 6.300.000. Hệ thống cần tự quy đổi ở backend, không phụ thuộc payload `total` từ FE.

## 2. Formula (single source of truth)

| Field | Formula |
|---|---|
| `subtotal` | `Σ(qty * price − item.discount)` (recompute từ items, không tin `subtotal` FE gửi) |
| `discount` | clamp `[0, subtotal]` |
| `fee_amount` (amount) | `min(subtotal − discount, fee_value)` |
| `fee_amount` (percent) | `round((subtotal − discount) * fee_value / 100)` với `fee_value ∈ [0, 100]` |
| `total_refund` | `max(0, subtotal − discount − fee_amount + refund_other)` |
| `paid_to_customer` | clamp `[0, total_refund]` |

Implemented in `app/Services/ReturnTotalCalculator.php`. POS UI mirrors công thức để hiển thị cho user, nhưng backend luôn tự recompute.

## 3. Schema

| Field (`returns` table) | Meaning |
|---|---|
| `fee_type` (NEW, nullable string 16) | `'amount'` (legacy default) hoặc `'percent'` |
| `fee_value` (NEW, nullable decimal 15,2) | Raw user input — VND khi amount, % khi percent |
| `fee` (existing decimal) | **Resolved** VND amount sau khi backend quy đổi |
| `total` (existing decimal) | **Resolved** net refund sau phí (= cần trả khách) |
| `paid_to_customer` (existing) | Số tiền thực trả khách qua quỹ (≤ total) |

Legacy rows: `fee_type` NULL ⇒ treated as `'amount'`; `fee_value` NULL ⇒ fallback to `fee` ở display time.

## 4. Backend calculation

| Step | Result |
|---|---|
| 1. Validate raw inputs (`fee_type`, `fee_value`, `fee`, items, paid_to_customer) | numeric + bounds |
| 2. `ReturnTotalCalculator::calculate()` | recompute subtotal từ items → resolve fee_amount → total_refund → clamp paid |
| 3. Override `$validated['fee']`, `$validated['fee_type']`, `$validated['fee_value']`, `$validated['total']`, `$validated['paid_to_customer']` | dùng giá trị canonical xuyên suốt flow |
| 4. `OrderReturn::create([...])` | persist fee_type/fee_value khi schema có column |
| 5. `CustomerDebtService::recordSaleReversal($customer, $total, ...)` | dùng net total |
| 6. `CashFlow` chi `paid_to_customer` | đúng số thực trả khách |

Frontend `total` từ payload bị **ignore** — nếu FE gửi sai vẫn không ảnh hưởng DB.

## 5. POS UI

| Component | Result |
|---|---|
| Toggle VND/% | 2-button pill, click set `feeType` |
| Fee input — amount | `<MoneyInput>` (Step 24.6D) |
| Fee input — percent | `<input type="number" min=0 max=100 step=0.01>` + suffix `%` |
| Converted fee display | `= 700.000đ` line dưới input khi mode = percent |
| Cần trả khách | `formatCurrency(activeReturnTotal)` |
| Tiền trả khách | `<MoneyInput>` + auto-track `paidToCustomerTouched` để không override khi user đã sửa tay |

Computeds:
- `activeReturnSubtotal` = sum của line items
- `activeReturnFeeAmount` = công thức 24.6E (mirror backend)
- `activeReturnTotal` = subtotal − discount − feeAmount + refundOther
- `watch(activeReturnTotal)` → auto-fill `paidToCustomer` khi user chưa sửa tay

## 6. Files changed

| File | Nội dung |
|---|---|
| `database/migrations/2026_05_09_100000_add_fee_type_value_to_returns_table.php` | NEW — `fee_type` (varchar 16 nullable) + `fee_value` (decimal 15,2 nullable) |
| `app/Services/ReturnTotalCalculator.php` | NEW — single source of truth cho công thức return total |
| `app/Http/Controllers/OrderReturnController.php` | Validation `fee_type`/`fee_value`; gọi `ReturnTotalCalculator` để recompute; override `$validated`; persist fee_type/value khi schema có column |
| `resources/js/Pages/POS/Index.vue` | `emptyReturnState()` thêm `feeType`/`feeValue`/`paidToCustomerTouched`; computed `activeReturnFeeAmount`; UI toggle VND/%; converted fee display; `submitReturnTab` payload thêm `fee_type`/`fee_value`; watch auto-fill paidToCustomer |
| `tests/Feature/OrderReturn/Step246EReturnFeeTypeTest.php` | NEW — 13 test cases (5 calculator unit + 8 integration) |
| `docs/audit/STEP-24.6E-RETURN-FEE-PERCENT.md` | NEW — file này |

**Không sửa:** `MovingAvgCostingService`, `StockMovementService`, `CustomerDebtService`, `OrderReturn` model (`$guarded = ['id']`), schema khác, F7 exchange.

## 7. Tests

| Test | Result |
|---|---|
| TC-01 calculator amount fee → net total | ✅ |
| TC-02 calculator percent fee → net total | ✅ |
| TC-03 calculator legacy payload (no fee_type) | ✅ |
| TC-04 calculator percent > 100 fails | ✅ |
| TC-05 calculator paid > total fails | ✅ |
| TC-06 integration amount fee persists net total (FE total bị ignore) | ✅ |
| TC-07 integration percent fee persists | ✅ |
| TC-08 percent > 100 rejected (no DB mutation) | ✅ |
| TC-09 paid > net total rejected | ✅ |
| TC-10 percent fee reduces customer debt by net total (6.3M, not 7M) | ✅ |
| TC-11 percent fee cashflow uses paid_to_customer | ✅ |
| TC-12 legacy payload without fee_type treated as amount | ✅ |
| TC-13 cancel return with percent fee restores debt by net total | ✅ |

Cluster results:
- `Step246E + ReturnFeeType`: ✅ **13 PASS** (44 assertions)
- Combined `Step246* + OrderReturn + RR08 + RR11 + InvoiceUpdateEngine + Step243 + Step244A + CustomerGroupUiFlow + RR02`: ✅ **114 PASS** (713 assertions), 2 pre-existing skipped, **0 fail**
- Other regression (`RR06/09/12/13 + Serial* + Auth + Permission + ActivityLog + Purchase* + StockTake/Transfer + Damage + Warranty + Step245`): ✅ **196 PASS** (654 assertions), 2 pre-existing skipped, **0 fail**
- `npm run build`: ✅ Built in 7.43s

## 8. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration không? | **Có** — 2 column nullable thêm vào `returns`, không phá row cũ |
| Có backfill không? | **Không** — legacy rows `fee_type`=NULL được hiểu là `'amount'` |
| Có sửa stock/cost/serial không? | **Không** |
| Có sửa debt service không? | **Không** — vẫn `recordSaleReversal($customer, $total, ...)`, chỉ là `$total` giờ là net |
| Backend recompute total không? | **Có** — frontend `total` bị ignore |
| Payload numeric không? | **Có** — `fee_type` string enum; `fee_value`/`fee`/`total`/`paid_to_customer` đều numeric |
| Có ảnh hưởng F7 exchange không? | **Không** — vẫn disabled placeholder |

## 9. Manual QA

- [ ] Fee VND OK — nhập 700.000 → cần trả khách 6.300.000.
- [ ] Fee % OK — nhập 10% → hiển thị `= 700.000đ` → cần trả khách 6.300.000.
- [ ] Percent > 100 blocked — input max=100, backend trả 422.
- [ ] paid_to_customer > total blocked — backend 422.
- [ ] Customer debt = net total (6.3M) sau return fee 10%.
- [ ] CashFlow amount = paid_to_customer.
- [ ] Serial return OK với fee % — serial về `in_stock`.
- [ ] Cancel return rollback OK — debt khôi phục đúng.
- [ ] POS Hóa đơn / Đặt hàng không bị ảnh hưởng.
- [ ] /invoices, /customers OK.

## 10. Conclusion

- **Phí trả hàng % đã đúng KiotViet chưa:** Có — backend recompute đúng formula `(subtotal − discount) × % / 100`, persist `fee_type` + `fee_value`, total = net refund.
- **Có an toàn công nợ/cashflow không:** Có — debt giảm theo net total (6.3M, không phải 7M); cashflow = paid_to_customer; tests TC-10/TC-11/TC-13 verify.
- **Có thể deploy không:** Có — migration safe (nullable columns), legacy compat preserved (TC-12), 13 hotfix + 310 regression test pass, 0 fail.
