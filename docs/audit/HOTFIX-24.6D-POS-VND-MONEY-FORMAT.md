# HOTFIX 24.6D — POS VND Money Format

## 1. Root cause

- Sau refactor POS workspace tabs (Step 24.6 final), khu Return tab dùng inline `Number(value).toLocaleString('vi-VN') + ' ₫'` thay vì `formatCurrency` (utils/money.js) → format không nhất quán với phần Sale tab cũ.
- Các input tiền (Giảm giá / Phí trả hàng / Hoàn trả thu khác / Tiền trả khách trong return panel; đơn giá / discount / customerPaid trong sale cart) dùng raw `<input type="number">` không phân tách hàng nghìn → user thấy `210000` thay vì `210.000`.
- Hệ thống đã có chuẩn (Step 23.9 + `MoneyInput` component); chỉ cần áp dụng đúng.

## 2. Existing standard

| Helper | Purpose |
|---|---|
| `formatVND(value)` (alias `formatCurrency`) | Display text — output `1.000.000đ` |
| `formatMoneyInput(value)` | Display trong input — output `1.000.000` (không có đ) |
| `parseVND(value)` | Convert input string → number |
| `<MoneyInput v-model="x" :min=0>` | Component bọc input + display formatting + emit number |

## 3. POS audit

| Khu vực | Field | Before | After |
|---|---|---|---|
| Sale cart row | `item.price` (input) | `<input type="number" v-model="item.price">` | `<MoneyInput v-model="item.price" :min=0>` |
| Sale cart row | `item.discount` (input) | raw number input | `<MoneyInput v-model="item.discount" :min=0>` |
| Sale right panel | `discount` (input) | raw number input | `<MoneyInput v-model="discount" :min=0>` |
| Sale right panel | `customerPaid` (input) | raw number input | `<MoneyInput v-model="customerPaid" :min=0>` |
| Return search list | `inv.total` | `Number(inv.total).toLocaleString('vi-VN') ₫` | `formatCurrency(inv.total)` |
| Return items table | `item.price` | `Number(item.price).toLocaleString('vi-VN')` | `formatCurrency(item.price)` |
| Return panel | `sourceInvoice.total` | raw `Number().toLocaleString` | `formatCurrency(...)` |
| Return panel | `activeReturnSubtotal` | raw `Number().toLocaleString` | `formatCurrency(...)` |
| Return panel | `activeReturnTotal` | raw `Number().toLocaleString` | `formatCurrency(...)` |
| Return panel | `discount` (input) | raw number input | `<MoneyInput v-model="...returnState.discount">` |
| Return panel | `fee` (input) | raw number input | `<MoneyInput v-model="...returnState.fee">` |
| Return panel | `refundOther` (input) | raw number input | `<MoneyInput v-model="...returnState.refundOther">` |
| Return panel | `paidToCustomer` (input) | raw number input | `<MoneyInput v-model="...returnState.paidToCustomer">` |

Sale tab existing: `formatCurrency(product.retail_price)`, `formatCurrency(item.price * item.quantity - discount)`, `formatCurrency(subtotal)`, `formatCurrency(totalAmount)`, `formatCurrency(changeDue)`, `formatCurrency(selectedCustomer.debt_amount)` — **đã đúng từ trước** (Step 23.9).

## 4. Files changed

| File | Nội dung |
|---|---|
| `resources/js/Pages/POS/Index.vue` | (a) import MoneyInput; (b) 4 sale/order tab money inputs swap → `<MoneyInput>`; (c) 5 return tab raw displays swap → `formatCurrency()`; (d) 4 return tab money inputs swap → `<MoneyInput>` |
| `tests/Feature/POS/Step246DPosMoneyFormatTest.php` | NEW — 4 backend payload guard cases |
| `docs/audit/HOTFIX-24.6D-POS-VND-MONEY-FORMAT.md` | NEW — file này |

**Không sửa:** `MoneyInput.vue` (đã sẵn), `money.js` utility, `PosController`, `OrderReturnController`, `InvoiceController`, business logic, schema, tồn kho, công nợ, serial, datetime, cancel-modal hotfix.

## 5. Payload policy

| Field | Sent as |
|---|---|
| `subtotal` | number |
| `discount` | number |
| `total` | number |
| `customer_paid` | number |
| `paid_to_customer` (return) | number |
| `fee` (return) | number |
| `items[].price` | number |
| `items[].discount` | number |
| `items[].quantity` (qty) | number |

`MoneyInput` luôn `emit('update:modelValue', number)` (qua `parseVND`) — UI hiển thị `210.000` nhưng state vẫn là `210000`. Backend validation `numeric|min:0` reject formatted strings (TC-02 chứng minh `"210.000đ"` → 422).

## 6. Tests

| Test | Result |
|---|---|
| TC-01 `pos_checkout_still_accepts_numeric_money_payload` | ✅ — invoice.total = 210000 |
| TC-02 `pos_checkout_rejects_formatted_money_strings` | ✅ — backend trả 422 nếu nhận `"210.000đ"` |
| TC-03 `pos_quick_order_still_accepts_numeric_money_payload` | ✅ |
| TC-04 `pos_return_payload_still_numeric` | ✅ — OrderReturn.total = 195000, paid_to_customer = 195000 |

Cluster check:
- Step246D: ✅ **4 PASS** (12 assertions)
- Combined Step246 + OrderReturn + RR08 + RR11 + InvoiceUpdateEngine + Step243 + Step244A + CustomerGroupUiFlow + RR02: ✅ **101 PASS** (669 assertions), 2 pre-existing skipped, **0 fail**
- `npm run build`: ✅ 6.93s

## 7. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration không? | **Không** |
| Có sửa nghiệp vụ tính tiền không? | **Không** — totalAmount/subtotal/changeDue computeds y nguyên |
| Có sửa stock/debt/serial/cost không? | **Không** |
| Payload vẫn numeric không? | **Có** — MoneyInput emit number; backend validation `numeric` reject string |
| Có ảnh hưởng POS note/datetime không? | **Không** — Step 24.6C note + Step 24.5 datetime giữ nguyên |
| Có bật F7 đổi hàng không? | **Không** — vẫn disabled placeholder |

## 8. Manual QA

- [ ] Sale tab: đơn giá nhập 210000 → hiển thị `210.000`; thành tiền `210.000đ`.
- [ ] Sale tab: discount nhập 10000 → hiển thị `10.000`; tổng cập nhật.
- [ ] Sale tab: customerPaid nhập 500000 → hiển thị `500.000`; tiền thừa `5.000đ`.
- [ ] Order tab: tương tự sale tab.
- [ ] Return tab: search result hiển thị `505.000đ` (không còn `505000 ₫`).
- [ ] Return tab: items table cột Đơn giá hiển thị `210.000đ`.
- [ ] Return tab right panel: tất cả 4 input (giảm/phí/hoàn/trả) hiển thị có dấu chấm khi blur.
- [ ] Return tab: Tổng tiền hàng trả / Cần trả khách hiển thị `500.000đ`.
- [ ] Submit invoice POS: DB total đúng số (verify `/invoices`).
- [ ] Submit return POS: DB return.total đúng số (verify `/returns`).
- [ ] POS note vẫn lưu (Step 24.6C giữ).
- [ ] POS datetime vẫn `dd/MM/yyyy HH:mm` (Step 24.5 giữ).
- [ ] `/customers` OK.
- [ ] `/invoices` OK.

## 9. Conclusion

- **Đã khôi phục chuẩn VND chưa:** Có — toàn bộ POS workspace (sale/order/return tab) đã dùng `formatCurrency` cho display và `<MoneyInput>` cho input. Backend payload contract giữ nguyên numeric (TC-01/TC-02/TC-03/TC-04 verify).
- **Có thể deploy không:** Có — không migration, không mutation, build pass, 4 hotfix + 101 regression test pass, 0 fail.
