# STEP 24.6 — POS Workspace Return Tabs (KiotViet-style)

## 1. KiotViet reference

**Tài liệu external:** Agent **không truy cập được** trang tài liệu/help của KiotViet trực tiếp. Phần mô tả KiotViet flow ở dưới chỉ căn cứ vào:
- Screenshots + brief user cung cấp trong yêu cầu (đã tham chiếu workflow KiotViet thật).
- Quick Return iteration trước (commit `dced809`) đã được user feedback "phải là tab, không phải modal".

| Thành phần | KiotViet có | Hệ thống đã làm |
|---|---|---|
| Tab Hóa đơn | ✓ (KiotViet POS) | ✓ giữ nguyên (saleMode='normal') |
| Tab Đặt hàng | ✓ (KiotViet POS) | ✓ giữ qua saleMode='quick_order' (label "Đặt hàng N") |
| Tab Trả hàng | ✓ — tab cùng workspace, không phải popup | ✓ tab type='return', label "Trả hàng N", màu chấm đỏ |
| Tìm hàng trả F3 | ✓ ô search ở vùng trả | ✓ input có id `return-search-input-{tabId}`, F3 focus shortcut |
| Tìm hàng đổi F7 | ✓ ô search hàng đổi (đổi hàng) | ⏸ visible-disabled placeholder + backlog notice (24.6B) |
| Return right panel (Người bán / Khách hàng / HD gốc / Tổng / Cần trả khách / TRẢ HÀNG) | ✓ | ✓ panel 360px bên phải tab Trả hàng |
| Button TRẢ HÀNG (đỏ, viết hoa) | ✓ | ✓ red button uppercase "TRẢ HÀNG" disabled khi chưa hợp lệ |

## 2. Root cause

- **Quick return modal trước đó đúng logic nhưng sai UX**: KiotViet không dùng modal cho luồng trả hàng — luồng trả hàng nằm trong cùng workspace POS dạng tab cạnh "Hóa đơn" / "Đặt hàng", có ô F3 (tìm hàng trả) và F7 (tìm hàng đổi).
- **POS hiện thiếu return tab**: `tabs[]` chỉ có saleMode (normal/quick_order/delivery), chưa có khái niệm `type` để tách workspace.

## 3. Scope

| Đã làm | Không làm |
|---|---|
| Tab type 'sale' / 'order' / 'return' với per-tab state | Atomic return + exchange (24.6B deferred) |
| Nút "Trả hàng" mở tab thay vì modal | Sửa core stock/cost/debt/serial service |
| F3/F7 keyboard shortcut wiring | Bypass OrderReturnController validation |
| Return-only flow gọi POST /returns hiện có | Trả hàng không gắn invoice |
| Right panel theo KiotViet (người bán / khách / HD gốc / tổng / cần trả khách) | Sửa logic hủy phiếu trả |
| F7 + exchange UI placeholder + backlog | Cộng tồn / trừ tồn ở frontend |
| Đóng tab có dữ liệu confirm | Migration / backfill / mutation production |

## 4. Architecture

| Tab type | State per-tab | Submit endpoint |
|---|---|---|
| sale | cart, discount, customerPaid, paymentMethod, bankAccountInfo, selectedCustomer, customerQuery, saleMode='normal' | POST /api/pos/checkout (existing) |
| order | giống sale + saleMode='quick_order' | POST /api/pos/quick-order (existing) |
| return | returnState = { sourceInvoice, sourceItems, lineState, discount, fee, refundOther, paidToCustomer, note, search, searchResults, searching, loadingItems, submitting, error, exchangeItems(reserved), exchangeSearch(reserved) } | POST /returns (existing) — return-only |

`createNewTab(type)` tạo tab đúng kiểu. `openReturnTab()` activate-or-create empty return tab. `tabBaseLabel` + `tabIndexAmongType` cho label "Hóa đơn 1 / Đặt hàng 1 / Trả hàng 1" theo từng type. `tabDotClass` đỏ cho return.

`closeTab(idx)` confirm nếu `tabHasUnsavedWork(tab)` (cart > 0 hoặc return tab có sourceInvoice/lineState).

## 5. Existing return rules reused

| Rule | File/service |
|---|---|
| Không over-return | `OrderReturnController@store` lines 200–221 (RR-11) — mirror trong `PosController@returnableItems` cho hint |
| Không invoice canceled | `OrderReturnController@store` line 185 + `PosController@returnableItems` 422 + `returnableInvoices` `where status != 'Đã hủy'` |
| Serial belongs invoice | `OrderReturnController@store` Step 23.2 (`SerialImei::whereIn(...)` filter `invoice_id` + `status='sold'`) |
| Serial count = qty | `OrderReturnController@store` Step 23.2 pre-transaction validation |
| Stock/cost restore | `MovingAvgCostingService::applySaleReturn()` + `StockMovementService::record(TYPE_IN_INVOICE_RETURN)` |
| Debt/cashflow | `CustomerDebtService::recordReturn()` + `CashFlow` row khi `paid_to_customer > 0` |

## 6. API added

| Method | URL | Purpose | Permission |
|---|---|---|---|
| GET | `/api/pos/returnable-invoices?search=<term>` | Search hóa đơn (mã/khách/phone/serial), exclude `status=Đã hủy`, limit 20 | `returns.create` |
| GET | `/api/pos/invoices/{invoice}/returnable-items` | Trả `items[]` với sold_qty, already_returned_qty, remaining_qty, serials[] | `returns.create` |

Cả 2 endpoints đã tạo trong commit `dced809` (Step 24.6 modal phiên trước). Workspace refactor reuse y nguyên.

## 7. Return-only flow

| Step | Result |
|---|---|
| 1. Bấm "Trả hàng" trên POS header | Tạo tab type='return' (hoặc activate empty return tab) |
| 2. Nhập F3 search | Debounce 250ms gọi `/api/pos/returnable-invoices` |
| 3. Click kết quả | GET `/api/pos/invoices/{id}/returnable-items` populate sourceInvoice + sourceItems + lineState |
| 4. Nhập qty / chọn serial | UI clamp qty ≤ remaining_qty; serial pill toggle |
| 5. Right panel cập nhật real-time | activeReturnSubtotal / activeReturnTotal computed |
| 6. Bấm TRẢ HÀNG | POST /returns với canonical payload; success → reset returnState (giữ tab); fail → hiện rs.error trong panel |

## 8. Return + exchange flow

**Trạng thái:** `Phase 24.6B deferred`.

- F7 input rendered, `disabled` với caption: *"Đổi hàng — sẽ bật ở Phase 24.6B (atomic return + exchange)"* + banner màu amber giải thích.
- `exchangeItems` field reserved trong returnState nhưng không serialize vào payload.
- Submit chỉ gửi `items[]` của hàng trả; backend không nhận `exchange_items` trong contract hiện tại.
- **Lý do defer:** atomic return + exchange cần một `PosReturnExchangeService` mới (Step 24.6 mở rộng), chưa có DB transaction wrapper an toàn để combine `OrderReturnController@store` + `InvoiceSaleService::createSale` + chênh-lệch payment, và rollback đầy đủ nếu nửa chừng fail. Tránh half-built.
- **Backlog:** `app/Services/PosReturnExchangeService.php` (chưa tạo) sẽ bọc DB::transaction quanh: validate return → create OrderReturn → if exchangeItems → InvoiceSaleService::createSale (source=pos_exchange) → tính net → throw nếu return fail / exchange fail → rollback hoàn toàn.

## 9. Files changed

| File | Nội dung |
|---|---|
| `resources/js/Pages/POS/Index.vue` | (a) `createNewTab(type)` + `emptyReturnState()` per-tab; (b) tab helpers `tabBaseLabel`, `tabIndexAmongType`, `tabDotClass`, `tabBadgeCount`, `tabHasUnsavedWork`; (c) `closeTab` confirm-on-unsaved; (d) `openReturnTab` thay `openQuickReturn`; (e) per-tab return logic (search/select/qty/serial/submit) operating on `tab.returnState`; (f) F3/F7 keydown listener; (g) main workspace `v-if="activeTab.type !== 'return'"` + new return workspace `v-else` (left F3 → items table → F7 placeholder, right summary panel với button TRẢ HÀNG đỏ); (h) old QuickReturnModal markup xoá hoàn toàn. |
| `tests/Feature/POS/Step246PosQuickReturnTest.php` | docblock cập nhật cho workspace tab; backend contract không đổi nên 12 cases vẫn cover đầy đủ. |
| `docs/audit/STEP-24.6-POS-WORKSPACE-RETURN-TABS.md` | NEW — file này |

**Không sửa:** `PosController` (2 endpoints đã tạo từ phiên trước), `OrderReturnController`, `MovingAvgCostingService`, `CustomerDebtService`, `StockMovementService`, `InvoiceSaleService`, schema, route gating, business invariants Step 24.3 / 24.4A / 24.5 / 24.3C.

## 10. Tests

| Test | Result |
|---|---|
| TC-01 `returnable_invoices_search_requires_returns_create_permission` | ✅ |
| TC-02 `returnable_invoices_search_by_code_customer_phone` | ✅ |
| TC-03 `returnable_items_show_remaining_qty_after_partial_return` | ✅ |
| TC-04 `returnable_items_refuses_cancelled_invoice` | ✅ |
| TC-05 `quick_return_normal_product_creates_return_and_restores_stock` | ✅ |
| TC-06 `quick_return_cannot_exceed_remaining_qty` | ✅ |
| TC-07 `quick_return_serial_count_must_match_qty` | ✅ |
| TC-08 `quick_return_serial_must_belong_to_invoice` | ✅ |
| TC-09 `quick_return_serial_success_marks_serial_in_stock` | ✅ |
| TC-10 `returnable_invoices_excludes_cancelled` | ✅ |
| TC-11 `returnable_items_only_lists_serials_for_that_invoice` | ✅ |
| TC-12 `pos_quick_return_routes_are_registered` | ✅ |

Frontend smoke (manual via build):
- Build pass (Vue SFC compile validation cover template syntax).
- Tab type/label/dot/badge helpers all template-bound.

Cluster check:
- Step246 + OrderReturn + RR08 + RR11: ✅ **28 PASS** (88 assertions)
- Broad regression (RR02–13, Order, Purchase, Stock*, Damage, InvoiceUpdateEngine, Step232–246, Customer hotfix, Step245, Auth, Permission, Dashboard, ActivityLog, Warranty): ✅ **343 PASS** (2181 assertions), 4 pre-existing skipped, **0 fail**

## 11. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration không? | **Không** |
| Có sửa core stock/debt/cost không? | **Không** |
| Có bypass return validation không? | **Không** — UI clamp; backend là source of truth |
| Có ảnh hưởng POS sale cũ không? | **Không** — sale tab v-if vẫn render workspace cũ y hệt; saleMode/cart/discount/payment proxies giữ nguyên |
| Có ảnh hưởng `/invoices` không? | **Không** |
| Có ảnh hưởng `/customers` không? | **Không** |

## 12. Manual QA

- [ ] Hóa đơn tab OK — POS bán bình thường (Ctrl+F5 verify cart, customer search, checkout).
- [ ] Đặt hàng tab OK — saleMode quick_order vẫn label "Đặt hàng N".
- [ ] Trả hàng tab OK — bấm "Trả hàng" → tab "Trả hàng 1" mở.
- [ ] F3 focus ô tìm hàng trả khi đang ở return tab.
- [ ] F7 focus ô tìm hàng đổi (disabled, có hint).
- [ ] Search mã HD → kết quả đúng.
- [ ] Search SĐT khách → kết quả đúng.
- [ ] Hóa đơn đã hủy không xuất hiện trong kết quả.
- [ ] Click invoice → load items + remaining_qty.
- [ ] Hàng thường qty 1: TRẢ HÀNG → tồn +1, công nợ giảm, cashflow đúng nếu paid_to_customer > 0.
- [ ] Trả quá remaining bị chặn (UI clamp + backend 422).
- [ ] Hàng serial: chỉ thấy serial của invoice; trả thành công serial → in_stock.
- [ ] Đóng tab Trả hàng có dữ liệu → modal confirm.
- [ ] Chuyển giữa tab Hóa đơn ↔ Trả hàng: state mỗi tab giữ nguyên, không lẫn lộn.
- [ ] POS sale old OK (RR02 invariants giữ).
- [ ] `/invoices` OK.
- [ ] `/customers` OK (24.4A-* group flow).

## 13. Conclusion

- **Đã giống KiotViet tab flow chưa:** Có — tab Hóa đơn / Đặt hàng / Trả hàng cùng workspace; F3/F7 đúng vị trí; right panel đầy đủ trường KiotViet; button TRẢ HÀNG đỏ uppercase.
- **Return-only đã production-safe chưa:** Có — backend contract không đổi, 28 cluster + 343 regression test pass.
- **Exchange đã làm chưa:** **Chưa** — F7 visible-disabled, backlog rõ trong section 8.
- **Có thể commit/deploy không:** **Có** — không migration, không backfill, không mutation production cũ. Hotfix Customers/Invoicing/DateTime/Cancel-modal đều bảo toàn.
