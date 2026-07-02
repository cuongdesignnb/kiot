# STEP 24.13 — Unified Quick Create Flow

## 1. Root cause

- Nút "+ hàng hóa" trong tab Nhập hàng và POS dùng **2 inline modal khác nhau**, nội dung script lặp lại, không ai dùng formatter `formatMoneyInput / parseVND` → user nhập "1.000.000" nhưng input là `<input type=number>` chỉ chấp nhận số thuần, không thấy tách hàng nghìn → khó nhìn.
- Nút "+ NCC" trong Nhập hàng dùng inline modal 4 field hardcode.
- `SupplierController::store` (full form) chỉ trả redirect, **không có path JSON** → khó dùng cho quick-add từ phía in-context.
- Component dùng chung tồn tại sẵn cho khách hàng (`QuickCreateCustomerModal.vue`) — Product và Supplier vẫn copy-paste.

## 2. Audit all buttons

| Screen | Entity | Before | After |
|---|---|---|---|
| Purchases/Create | Product | Inline modal 110 dòng, raw `<input type=number>` không tách hàng nghìn | `<QuickCreateProductModal>` với `formatMoneyInput` + `parseVND` |
| Purchases/Create | Supplier | Inline modal 4 field, hardcode `axios.post('/api/suppliers/quick-store')` | `<QuickCreateSupplierModal>` (gồm cả tax_code + note) |
| POS/Index | Customer | `<QuickCreateCustomerModal>` (đã tốt) | Giữ nguyên |
| POS/Index | Product | Inline modal 60 dòng, raw `<input type=number>` | `<QuickCreateProductModal>` (same shared component) |
| Purchases/Edit | Customer | `<QuickCreateCustomerModal>` (đã tốt) | Giữ nguyên |
| Orders/Create | Customer | Chỉ có typeahead `/api/customers/search`, không có nút + quick add | Không thay đổi — chưa có yêu cầu cụ thể |
| PurchaseOrders/Create | Supplier/Product | Không có quick add | Không thay đổi |
| PurchaseReturns/Create | Supplier/Product | Không có quick add | Không thay đổi |

Các trang khác (Products, Customers, Suppliers list page) đã có form đầy đủ riêng — không thuộc phạm vi STEP 24.13.

## 3. Components

| Component | Used by |
|---|---|
| `Components/QuickCreateProductModal.vue` | Purchases/Create, POS/Index |
| `Components/QuickCreateSupplierModal.vue` | Purchases/Create |
| `Components/QuickCreateCustomerModal.vue` (đã có từ trước) | POS/Index, Purchases/Edit |

## 4. Backend APIs

| Entity | Endpoint | JSON response | Mutates stock |
|---|---|---|---|
| Product | `POST /products/quick-store` | `{ success, product }` (đã có) | **Không** — `stock_quantity = 0`, không insert `stock_movements` |
| Supplier | `POST /api/suppliers/quick-store` | `{ success, supplier }` (đã có) | n/a |
| Supplier (full) | `POST /suppliers` | **MỚI: JSON path khi `wantsJson()`** — `{ success, supplier }`. Web fallback giữ nguyên redirect. | n/a |
| Customer | `POST /customers` | JSON path đã có sẵn (`wantsJson()` → `{ customer }`) | n/a |

## 5. Context behavior

| Context | After created |
|---|---|
| Purchase product | `allProducts.push(p)` → `selectProduct(p)` (auto-add vào dòng nhập, giá nhập = `cost_price`) |
| Purchase supplier | `localSuppliers.push(s)` → `selectedSupplierId = s.id` |
| POS customer | `onCustomerCreated(c)` — set tab.selectedCustomer = c |
| POS product | `addToCart(p)` → reset `query`, re-fetch search |

Trong cả 4 case, **draft của phiếu hiện tại không bị reset** — items, supplier đã chọn, số tiền đã nhập, ghi chú đều preserve vì modal là overlay, không phải route navigation.

## 6. Money format

| Field | UI display | API payload |
|---|---|---|
| `cost_price` | `1.000.000` (Intl.NumberFormat vi-VN, không có "đ" để input gọn) | `1000000` numeric |
| `retail_price` | `1.500.000` | `1500000` |
| `technician_price` | `1.200.000` (nếu price book bật) | `1200000` |

Implementation: input là `type="text"` + `:value="formatMoneyInput(form.cost_price)"`; on focus hiển thị raw integer, on blur format lại + cập nhật state. Submit qua `parseVND(form.cost_price)` để chắc chắn payload numeric. Backend `quickStore` validate `numeric|min:0` — payload "1.000.000đ" bị 422 (TC-02).

## 7. Tests

`tests/Feature/QuickCreate/Step2413QuickCreateEntityFlowTest.php` — 7 TC, tất cả PASS trên MySQL:3319 thực:

| TC | Mục đích | Kết quả |
|---|---|---|
| 01 | `/products/quick-store` trả JSON + không động `stock_movements` | ✓ |
| 02 | Payload tiền dạng "1.000.000đ" bị 422 (backend numeric only) | ✓ |
| 03 | `/api/suppliers/quick-store` trả `is_supplier=true` | ✓ |
| 04 | `/suppliers` full store trả JSON khi `wantsJson()` | ✓ |
| 05 | `/customers` trả JSON khi `wantsJson()` | ✓ |
| 06 | Product quick-store require name | ✓ |
| 07 | Supplier quick-store require name | ✓ |

Regression đầy đủ:

| Suite | Kết quả |
|---|---|
| `Step2413|QuickCreateEntityFlow|Product|Customer|Supplier` | 160 PASS / 1534 assertions |
| `Purchase|POS|Invoice|Order|CustomerGroup|Supplier` | 191 PASS, 2 skipped / 743 assertions |
| `StockMovement|MovingAvg|SerialAvailability|RequireSerial` | 21 PASS, 2 skipped / 71 assertions |

## 8. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration không? | **Không** |
| Có stock movement khi tạo product không? | **Không** — `quickStore` set `stock_quantity = 0`, không gọi `StockMovement::create`. TC-01 xác nhận. |
| Có mất draft khi mở modal không? | **Không** — modal là overlay; không có `router.visit` ở nút "+". |
| Có ảnh hưởng POS / Purchase / Invoice không? | **Không** — 191/191 regression test xanh, không động checkout, debt, cashflow, moving-average cost. |
| Có gửi tiền dạng "1.000.000đ" về backend không? | **Không** — FE parse qua `parseVND` trước submit; BE từ chối non-numeric (TC-02). |
| Có giữ backward-compat với endpoint cũ không? | **Có** — `/suppliers` HTML redirect path giữ nguyên; chỉ thêm JSON branch khi `wantsJson()`. |

## 9. Manual QA

- [ ] Nhập hàng > "+ Hàng hóa" → modal mở, nhập giá vốn 1000000 → on blur hiển thị "1.000.000" → Lưu → dòng nhập có sản phẩm, đơn giá = 1.000.000.
- [ ] Nhập hàng > "+ NCC" → modal mở, nhập tên + phone + tax_code → Lưu → ô NCC tự chọn supplier mới, dropdown supplier có dòng mới.
- [ ] POS > "+ Hàng hóa" → modal mở, query trước đó được truyền vào tên (initial-name) → Lưu → sản phẩm có trong giỏ.
- [ ] POS > "+ Khách hàng" → modal lớn 4-tab mở (đã có sẵn) → Lưu → tab POS hiện active có customer mới.
- [ ] Sau khi tạo, giỏ hàng / dòng nhập / ghi chú / phương thức thanh toán cũ vẫn còn.
- [ ] Backend chỉ tăng tồn khi lưu phiếu nhập, không phải khi tạo sản phẩm.

## 10. Conclusion

- **Đã giống KiotViet chưa:** Có — quick add tại chỗ, modal overlay, auto-select, money format đúng vi-VN, không rời context.
- **Có an toàn production không:** Có — không migration, không backfill, backend mới thêm JSON branch (additive), tồn kho/giá vốn không bị động.
- **Có thể deploy không:** Có — build `npm run build` 8.07s pass, 372 PASS / 4 skipped trong 3 suite regression trên MySQL:3319 thực.
