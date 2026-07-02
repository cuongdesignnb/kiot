# HOTFIX — Đồng bộ Loại thu/chi giữa bộ lọc và modal sửa Sổ quỹ

## Source đã kiểm tra

- `app/Http/Controllers/CashFlowController.php`
- `app/Models/CashFlow.php`
- `resources/js/Pages/CashFlows/Index.vue`
- `resources/js/Components/Filters/SidebarFilter.vue`
- `resources/js/Components/Filters/SelectFilter.vue`
- `resources/js/Components/DateTimePicker.vue`
- `resources/js/Components/MoneyInput.vue`
- `resources/js/utils/money.js`
- `resources/js/composables/useFilters.js`

## Root cause

- Dữ liệu nghiệp vụ chỉ có một field: `cash_flows.category`.
- UI trước hotfix hiển thị khác ngữ cảnh: sidebar filter gộp loại thu + loại chi thành một list không phân biệt, còn modal sửa cần chọn category theo `form.type`.
- Khi sidebar đã chọn `Loại chứng từ`, list `Loại thu/chi` vẫn không đổi theo ngữ cảnh nên user dễ nghĩ đây là hai nguồn dữ liệu khác nhau.

## Files changed

- `app/Http/Controllers/CashFlowController.php`
  - Giữ `filterOptions.categories` để backward compatibility.
  - Bổ sung `filterOptions.categoryGroups.receipt` và `filterOptions.categoryGroups.payment`, mỗi option có `value`, `label`, `type`, `group`.
- `resources/js/Pages/CashFlows/Index.vue`
  - Chuẩn hóa category option bằng `receiptCategoryOptions` và `paymentCategoryOptions`.
  - Modal create/edit dùng `currentCategoryOptions` theo `form.type`; phiếu thu chỉ loại thu, phiếu chi chỉ loại chi.
  - Sidebar category filter phụ thuộc `filters.type`:
    - Chưa chọn loại chứng từ: hiển thị cả `Thu - ...` và `Chi - ...`.
    - Chỉ chọn phiếu thu: chỉ hiển thị loại thu, label không prefix.
    - Chỉ chọn phiếu chi: chỉ hiển thị loại chi, label không prefix.
  - Khi đổi `filters.type`, nếu category đang chọn không còn thuộc danh sách hợp lệ thì reset `filters.category`.
  - Đổi label sidebar thành `Loại thu/chi`.
- `resources/js/Components/Filters/SelectFilter.vue`
  - Cho phép option truyền `key` riêng (`opt.key || opt.value`) để tránh trùng key khi cùng category xuất hiện ở cả nhóm Thu và Chi trong trạng thái chưa lọc chứng từ.
- `tests/Feature/CashFlows/CashFlowEditCategoryTest.php`
  - Thêm test backend xác nhận `savedReceiptCategories`, `savedPaymentCategories`, `filterOptions.categories`, `filterOptions.categoryGroups` đúng theo type.
- `docs/audit/HOTFIX-CASHFLOW-CATEGORY-SYNC.md`
  - Report này.

## Data safety

- Có migration không: **Không**.
- Có backfill không: **Không**.
- Có update dữ liệu cũ không: **Không update hàng loạt**.
- Chỉ update 1 phiếu khi user chủ động bấm **Lưu** trong modal.
- Có đổi Phiếu thu ↔ Phiếu chi không: **Không**.
- Có đổi prefix `PT/PC` không: **Không**.
- Có sửa core service sổ quỹ/công nợ/cashflow không: **Không**.
- Có sửa dữ liệu category cũ không: **Không**.

## Tests/build kết quả

| Lệnh | Kết quả |
|---|---|
| `php artisan test --filter=CashFlow` | PASS — 23 passed, 89 assertions. Có warning PHP startup do extension local thiếu: `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`; test vẫn pass. |
| `php artisan test tests/Feature/Damage/RR09DamageStockTest.php` | PASS — 5 passed, 12 assertions. Có cùng warning PHP startup extension local; test vẫn pass. |
| `npm run build` | PASS — Vite built successfully in 7.82s. |

## Manual QA

Chưa chạy manual QA bằng browser trong phiên này. Checklist cần tester xác nhận:

1. Vào `/cash-flows`.
2. Không chọn `Loại chứng từ`: bộ lọc `Loại thu/chi` hiển thị cả loại thu và loại chi với prefix `Thu -` / `Chi -`.
3. Chọn `Loại chứng từ = Phiếu thu`: bộ lọc `Loại thu/chi` chỉ còn loại thu.
4. Chọn `Loại chứng từ = Phiếu chi`: bộ lọc `Loại thu/chi` chỉ còn loại chi.
5. Mở phiếu chi → Chỉnh sửa: dropdown chỉ hiện loại chi.
6. Mở phiếu thu → Chỉnh sửa: dropdown chỉ hiện loại thu.
7. Tạo loại mới trong phiếu chi: loại mới chỉ xuất hiện trong nhóm chi.
8. Tạo loại mới trong phiếu thu: loại mới chỉ xuất hiện trong nhóm thu.
9. Không có chỗ đổi Phiếu thu thành Phiếu chi hoặc ngược lại.

## Ghi chú rủi ro

- Nếu một category text trùng giữa loại thu và loại chi, DB vẫn chỉ lọc theo `cash_flows.category`; prefix trên sidebar chỉ giúp hiển thị rõ ngữ cảnh. Khi cần phân biệt tuyệt đối ở sidebar, user nên chọn thêm `Loại chứng từ`.
- Không thay đổi schema nên không có rủi ro migration/backfill.