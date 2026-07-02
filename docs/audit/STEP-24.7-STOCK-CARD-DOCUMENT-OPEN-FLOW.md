# STEP 24.7 — Stock Card Document Open Flow

## 1. Root cause

- `ProductController::inventoryCard` đã emit `doc_type` + `doc_id` cho mỗi giao dịch trong thẻ kho — đủ thông tin để resolve về phiếu gốc.
- Nhưng popup preview (`/products/document-detail`) không trả URL phiếu gốc, và nút "Mở phiếu" trong `Welcome.vue` chỉ gọi `closeDocPopup()` — không thực sự mở phiếu.
- Một số loại phiếu (`stock-transfers.show`, `damages.show`) chưa có route đăng ký, dù controller có/đang thiếu method tương ứng.

## 2. KiotViet behavior

| Hành động | Kết quả |
|---|---|
| Click dòng chứng từ trong thẻ kho | Mở popup preview (đã có sẵn) |
| Click "Mở phiếu" | Mở phiếu gốc đúng loại, ở tab mới |

## 3. Resolver mapping

| doc_type | Model | open_url | print_url | permission |
|---|---|---|---|---|
| `invoice` | `Invoice` | `route('invoices.show', $invoice)` | `route('invoices.print')` | `invoices.view` |
| `purchase` | `Purchase` | `route('purchases.show', $purchase)` | — | `purchases.view` |
| `return` | `OrderReturn` | `route('returns.show', $return)` | `route('returns.print')` | `returns.view` |
| `purchase_return` | `PurchaseReturn` | `route('purchase-returns.show', $pr)` | — | `purchases.view` |
| `stock_take` | `StockTake` | `route('stock-takes.show', $st)` | `route('stock_takes.print')` | `stock_takes.view` |
| `transfer` | `StockTransfer` | `route('stock-transfers.show', $tr)` | `route('stock_transfers.print')` | `stock_transfers.view` |
| `damage` | `Damage` | `route('damages.show', $d)` | `route('damages.print')` | `damages.view` |
| `repair_part` / `disassemble_part` | `Task` | `route('tasks.show', $task)` | — | `tasks.view` |

Nếu user thiếu permission: `open_url=null`, `can_open=false`, `missing_reason="Bạn không có quyền mở phiếu này."` — URL không leak ra response.
Nếu doc không tồn tại (đã xóa hoặc id sai): `can_open=false`, `missing_reason` cụ thể.

## 4. Routes added

| Route | Controller | Permission |
|---|---|---|
| `GET /stock-transfers/{stockTransfer}/show` | `StockTransferController@show` | `stock_transfers.view` |
| `GET /damages/{damage}/show` | `DamageController@show` (NEW) | `damages.view` |

`stock-transfers.show` và `damages.show` đều redirect về index page với `?search={code}` (Show.vue dedicated chưa có — pragmatic choice cho 24.7; user vẫn land trên đúng row). `stock-takes.show` cũng được simplify cùng pattern (dedicated Show.vue cũng chưa có).

## 5. Files changed

| File | Nội dung |
|---|---|
| `app/Services/DocumentLinkResolver.php` | NEW — single source of truth cho mapping `(doc_type, doc_id) → open_url/print_url/can_open/permission/missing_reason` |
| `app/Http/Controllers/ProductController.php` | `documentDetail` resolve `$source = DocumentLinkResolver::resolve(...)` 1 lần và inject `source_document` vào mọi response payload (8 doc types) |
| `app/Http/Controllers/StockTransferController.php` | `show()` redirect to `stock-transfers.index?search={code}` (dedicated Show.vue defer) |
| `app/Http/Controllers/StockTakeController.php` | `show()` redirect to `stock-takes.index?search={code}` |
| `app/Http/Controllers/DamageController.php` | NEW `show()` redirect to `damages.index?search={code}` |
| `routes/web.php` | NEW `stock-transfers.show` + NEW `damages.show` |
| `resources/js/Pages/Welcome.vue` | "Mở phiếu" button bind to `docDetail.source_document.open_url` (target=_blank); thêm "In phiếu" khi `can_print=true`; disabled state với `missing_reason` khi `can_open=false`; warning text khi không thể mở |
| `tests/Feature/Products/Step247StockCardDocumentResolverTest.php` | NEW — 13 cases |
| `docs/audit/STEP-24.7-STOCK-CARD-DOCUMENT-OPEN-FLOW.md` | NEW |

**Không sửa:** `MovingAvgCostingService`, `StockMovementService`, `CustomerDebtService`, schema, `inventoryCard` payload (chỉ đọc), business logic.

## 6. Tests

| Test | Result |
|---|---|
| TC-01 resolver returns invoice show url (no `/print`) | ✅ |
| TC-02 resolver returns purchase show url | ✅ |
| TC-03 resolver returns return show url | ✅ |
| TC-04 resolver returns stock_take show url | ✅ |
| TC-05 resolver returns transfer show url | ✅ |
| TC-06 resolver returns damage show url | ✅ |
| TC-07 unknown doc_type → can_open=false + missing_reason | ✅ |
| TC-08 missing doc → can_open=false + missing_reason "Không tìm thấy" | ✅ |
| TC-09 user lacks permission → URLs hidden, can_open=false | ✅ |
| TC-10 endpoint integration: source_document attached to invoice payload | ✅ |
| TC-11 inventory card emits doc_type + doc_id | ✅ |
| TC-12 multiple document-detail calls do NOT mutate stock | ✅ |
| TC-13 stock-transfers.show + damages.show redirect to index?search={code} | ✅ |

Cluster:
- Step247: ✅ **13 PASS** (43 assertions)
- Combined regression (Step247+Invoice+Purchase+OrderReturn+PurchaseReturn+StockTake+StockTransfer+Damage+Permission+Auth+Step246+ReturnFeeType+Step245+Step244A): ✅ **286 PASS** (1240 assertions), 2 pre-existing skipped, 0 fail
- `npm run build`: ✅ Built in 7.44s

## 7. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration không? | **Không** |
| Có mutate stock không? | **Không** — TC-12 verify document-detail không tạo stock_movement |
| Có sửa stock/cost/debt/serial không? | **Không** |
| Có ảnh hưởng POS không? | **Không** |
| Có kiểm quyền không? | **Có** — resolver gate by `permission`; URL hidden khi không có quyền |

## 8. Manual QA

- [ ] Vào Hàng hóa → mở 1 sản phẩm → tab Thẻ kho.
- [ ] Click dòng HD: popup hiện đúng hóa đơn → "Mở phiếu" mở `/invoices/{id}/show` ở tab mới.
- [ ] Click dòng PN (purchase): "Mở phiếu" mở `/purchases/{id}`.
- [ ] Click dòng TH (return): mở `/returns/{id}/show`.
- [ ] Click dòng KK (stock_take): redirect `/stock-takes?search={code}`.
- [ ] Click dòng CHK (transfer): redirect `/stock-transfers?search={code}`.
- [ ] Click dòng XH (damage): redirect `/damages?search={code}`.
- [ ] Click dòng repair/disassemble: mở `/tasks/{id}`.
- [ ] User không có permission → button disabled, hover thấy lý do.
- [ ] Doc đã xóa → can_open=false, message rõ.
- [ ] No stock mutation sau nhiều lần mở popup.
- [ ] /pos, /invoices, /customers vẫn OK.

## 9. Conclusion

- **Luồng mở phiếu đã giống KiotViet chưa:** Có — popup preview giữ nguyên, button "Mở phiếu" giờ resolve về đúng phiếu gốc theo từng `doc_type`. Permission gating + missing-reason UX rõ ràng.
- **Có an toàn production không:** Có — không migration, không mutation, URL hidden khi thiếu quyền, controller show route read-only (chỉ redirect).
- **Có thể deploy không:** Có — 13 hotfix + 286 regression test pass, 0 fail.
