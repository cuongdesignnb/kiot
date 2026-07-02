# HOTFIX 24.8 — Supplier Update + Deactivate

## 1. Root cause

- Trang `/suppliers` có 2 nút "Cập nhật" và "Ngừng hoạt động" trong vùng action ngay sau khi expand row, nhưng:
  - Nút **Cập nhật** chỉ là static button không bind handler.
  - Nút **Ngừng hoạt động** cũng static, không gọi API.
- Backend `SupplierController` chỉ có `index/store/quickStore/export/import` + 4 API debt — **không có** `update/deactivate/activate`.
- `routes/web.php` không có `PUT /suppliers/{id}`, không có deactivate/activate routes.
- Permission `suppliers.edit` chưa được seed trong `Role::getPermissionsMap()`.

## 2. Supplier model policy

| Field | Policy |
|---|---|
| `is_supplier` | **Always true** — controller force-set; validation strip mọi cố gắng đổi |
| `is_customer` | Update chỉ qua toggle trong edit form |
| `supplier_debt_amount` | **Do not mutate** — chỉ flow purchase / payment / debt-adjust thay đổi |
| `total_bought` | **Do not mutate** — flow purchase quản |
| `debt_amount` (customer-side) | Do not mutate qua endpoint suppliers |
| `status` | `active` / `inactive` — toggle qua deactivate/activate endpoint |

Tất cả phiếu nhập / phiếu trả nhập / supplier_debt_transactions / cashflows giữ nguyên khi NCC inactive.

## 3. Routes

| Method | URL | Permission |
|---|---|---|
| PUT | `/suppliers/{supplier}` | `suppliers.edit` |
| POST | `/suppliers/{supplier}/deactivate` | `suppliers.edit` |
| POST | `/suppliers/{supplier}/activate` | `suppliers.edit` |

`suppliers.edit` mới được thêm vào `Role::getPermissionsMap()` nhóm "Nhập hàng > Nhà cung cấp" — admin (`*`) tự động có; role thường cần được cấp thủ công.

Tất cả 3 routes đều có guard `if (!$supplier->is_supplier) abort(404)` ở đầu method để đối tượng `is_customer=true, is_supplier=false` không bị edit qua endpoint NCC.

## 4. Backend changes

| Method | Result |
|---|---|
| `update(Request, Customer $supplier)` | Validate basic info (name/code/phone/...); whitelist; force `is_supplier=true`; persist; return `back()->with('success')`. Code/phone unique ignore current supplier id. |
| `deactivate(Customer $supplier)` | `$supplier->update(['status' => 'inactive'])`. Không xóa, không đụng debt/purchase/cashflow. |
| `activate(Customer $supplier)` | `$supplier->update(['status' => 'active'])`. |

## 5. Frontend changes

| Button | Result |
|---|---|
| Cập nhật | `@click="openEditModal(supplier)"` → modal Cập nhật NCC với prefill từ supplier; `editForm.put('/suppliers/{id}')` → `preserveScroll`; success → đóng modal |
| Ngừng hoạt động (khi `status !== 'inactive'`) | `@click="openDeactivateConfirm(supplier)"` → confirm modal có cảnh báo + show debt còn nợ nếu > 0; "Xác nhận ngừng" → `router.post('/suppliers/{id}/deactivate')` |
| Hoạt động lại (khi `status === 'inactive'`) | `@click="submitActivate(supplier)"` → `router.post('/suppliers/{id}/activate')` |
| Confirm modal | "Hủy NCC sẽ KHÔNG xóa lịch sử nhập/trả & công nợ. NCC không xuất hiện cho giao dịch mới." + warning vàng nếu `supplier_debt_amount > 0` |

Edit modal có 14 fields (mã/tên/SĐT/SĐT2/email/MST/địa chỉ/tỉnh/quận/phường/nhóm/ghi chú/checkbox vừa-là-KH); **không** có field `supplier_debt_amount`/`total_bought`/`debt_amount` để user không thể tay sửa.

## 6. Tests

| Test | Result |
|---|---|
| TC-01 update basic info persists name/address/group/note | ✅ |
| TC-02 update does NOT mutate supplier_debt_amount / total_bought | ✅ — even if FE sends them, backend strips |
| TC-03 update code unique ignores current supplier (self), rejects collision với supplier khác | ✅ |
| TC-04 deactivate does NOT delete record (still findable, status='inactive', is_supplier=true) | ✅ |
| TC-05 deactivate keeps purchase history + debt amount | ✅ |
| TC-06 activate restores status='active' | ✅ |
| TC-07 dual-role (is_customer=true & is_supplier=true): deactivate giữ cả 2 vai trò | ✅ — chỉ flip status |
| TC-08 non-supplier (is_supplier=false) → PUT /suppliers/{id} returns 404 | ✅ |
| TC-09 non-supplier → POST deactivate returns 404 | ✅ |
| TC-10 status filter `?status=inactive` returns inactive only | ✅ |
| TC-11 user without `suppliers.edit` permission → middleware blocks (redirect /) | ✅ |

Cluster:
- Step248 + SupplierActions: ✅ **11 PASS** (41 assertions)
- Combined regression (Supplier + Purchase + PurchaseReturn + SupplierDebt + CustomerGroup + CustomerFiltersHotfix + Step244A + Auth + Permission + Step246E + ReturnFeeType + Step246D + PosMoneyFormat + Step247): ✅ **122 PASS** (676 assertions), 0 fail
- `npm run build`: ✅ Built in 6.25s

## 7. Production safety

| Mục | Trạng thái |
|---|---|
| Có xóa NCC không? | **Không** — record `customers` giữ nguyên |
| Có reset công nợ không? | **Không** — TC-02 + TC-05 verify |
| Có xóa lịch sử nhập/trả không? | **Không** — TC-05 verify Purchase row vẫn còn |
| Có ảnh hưởng KH kiêm NCC không? | **Có cảnh báo** — `status` là cột chung cho cả vai trò; deactivate NCC sẽ làm record có `status='inactive'`. Vai trò `is_customer` vẫn `true` (TC-07). Trong scope hotfix này chấp nhận trade-off; nếu cần `supplier_status` riêng → backlog 24.8B |
| Có ảnh hưởng POS/invoice/return không? | **Không** |

## 8. Manual QA

- [ ] Vào `/suppliers` → expand 1 NCC → click "Cập nhật" → modal mở prefill đúng.
- [ ] Sửa tên/SĐT/ghi chú → "Lưu" → toast success, modal đóng, list reload.
- [ ] supplier_debt_amount + total_bought không đổi sau khi update.
- [ ] Lịch sử nhập/trả hàng + công nợ tab vẫn còn nguyên.
- [ ] Click "Ngừng hoạt động" → confirm modal hiện cảnh báo (nếu có nợ thì show).
- [ ] Xác nhận → status đổi inactive, button đổi thành "Hoạt động lại" (xanh).
- [ ] Click "Hoạt động lại" → status về active.
- [ ] Filter sidebar `status=inactive` → list chỉ hiển thị NCC inactive.
- [ ] NCC kiêm KH (is_customer=true): sau deactivate vẫn xuất hiện ở `/customers`.
- [ ] User không có `suppliers.edit`: nhấn nút bị middleware redirect về `/`.
- [ ] `/customers`, `/purchases`, `/pos`, `/invoices` không lỗi.

## 9. Conclusion

- **2 nút đã dùng được chưa:** Có — Cập nhật mở modal đầy đủ, Ngừng/Hoạt động lại toggle status đúng.
- **Có an toàn production không:** Có — không xóa record, không mutate debt/total_bought/purchase. `is_supplier` luôn được force `true`. Permission gating qua `suppliers.edit` mới seed.
- **Có thể deploy không:** Có — không migration (status + is_active cột đã có sẵn). 11 hotfix + 122 regression test pass, 0 fail.
