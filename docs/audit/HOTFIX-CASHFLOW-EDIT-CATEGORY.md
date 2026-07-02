# HOTFIX — Sổ quỹ: cho sửa Loại thu/chi trong modal chỉnh sửa

## Source đã kiểm tra

- `app/Http/Controllers/CashFlowController.php`
- `app/Models/CashFlow.php`
- `resources/js/Pages/CashFlows/Index.vue`
- `resources/js/Components/DateTimePicker.vue`
- `resources/js/Components/MoneyInput.vue`
- `resources/js/utils/money.js`
- `app/Services/LockPeriodService.php`
- `routes/web.php`

## Root cause

- Backend `CashFlowController@update()` đã lưu `category`, nhưng validation chưa giới hạn `max:255`, chưa check kỳ khóa sổ cho update, và chưa log riêng khi đổi loại thu/chi.
- Frontend modal tạo phiếu đã có chọn loại thu/chi, nhưng modal chỉnh sửa phiếu (`isModalOpen && form.id`) chưa render field `category`, nên user không đổi được `cash_flows.category` khi sửa phiếu.
- State tạo loại mới đang bám `modalType`; khi edit cần dùng `form.type` để đảm bảo phiếu thu chỉ thêm/chọn loại thu, phiếu chi chỉ thêm/chọn loại chi.

## Files changed

- `app/Http/Controllers/CashFlowController.php`
  - Update validation: `category` thành `nullable|string|max:255`.
  - Thêm lock period trước update: action `cashflow_update`.
  - Lưu `$oldCategory` và ghi `ActivityLog::log('cashflow_update_category', ...)` khi category thay đổi.
  - Không thêm `type` vào validation/update payload.
- `resources/js/Pages/CashFlows/Index.vue`
  - Thêm `currentCategoryOptions`, `categoryDropdownOpen`, `categorySearch`, `filteredCategoryOptions`, `selectCategory`, `openInlineCreateCategory`.
  - Reset dropdown/search khi mở modal create/edit.
  - Sửa `submitCategory()` để thêm vào đúng danh sách theo `form.type || modalType`, merge unique, rồi set `form.category`.
  - Thêm field `Loại thu/Loại chi` trong modal edit với dropdown có tìm kiếm và `+ Tạo mới`.
  - Không thêm UI đổi `type` trong edit.
- `tests/Feature/CashFlows/CashFlowEditCategoryTest.php`
  - Thêm regression tests cho update category phiếu chi và đảm bảo payload `type=receipt` không đổi phiếu chi thành phiếu thu.
- `docs/audit/HOTFIX-CASHFLOW-EDIT-CATEGORY.md`
  - Report này.

## Data safety

- Có migration không: **Không**.
- Có backfill không: **Không**.
- Có update dữ liệu cũ không: **Không update hàng loạt**. Chỉ update 1 phiếu khi user bấm **Lưu** trong modal chỉnh sửa.
- Có đổi type thu/chi không: **Không**. Backend update không validate/assign `type`; frontend edit không có dropdown đổi `type`.
- Có đổi prefix mã phiếu `PT/PC` không: **Không**.
- Có sửa cashflow core service/sổ quỹ/công nợ không: **Không**.

## Lock period

- `LockPeriodService::assertNotLocked($txDate, 'cashflow_update')` được thêm trước khi update.
- `LockPeriodService` hiện nhận context string tự do và không có danh sách action cố định, nên dùng được action `cashflow_update` đúng theo brief.

## Tests/build kết quả

| Lệnh | Kết quả |
|---|---|
| `php artisan test --filter=CashFlow` | PASS — 22 passed, 70 assertions. Có warning PHP startup về extension local thiếu: `oci8_12c`, `oci8_19`, `pdo_firebird`, `pdo_oci`; test vẫn pass. |
| `php artisan test tests/Feature/Damage/RR09DamageStockTest.php` | PASS — 5 passed, 12 assertions. Có cùng warning PHP startup extension local; test vẫn pass. |
| `npm run build` | PASS — Vite built successfully in 8.08s. |

Ghi chú: đã thử `npx prettier --write resources/js/Pages/CashFlows/Index.vue`, nhưng môi trường sandbox chặn fetch registry/npm cache (`EACCES`). Không cần để hoàn thành hotfix; `npm run build` đã xác nhận Vue compile OK.

## Manual QA

Chưa chạy manual QA trên browser trong phiên này. Checklist cần tester xác nhận:

1. Vào `/cash-flows`.
2. Mở một phiếu chi.
3. Bấm `Chỉnh sửa`.
4. Thấy field `Loại chi`.
5. Mở dropdown, gõ tìm `Bảo hiểm`, chọn được.
6. Bấm `Lưu`.
7. Dòng danh sách cập nhật category.
8. Bộ lọc loại thu chi lọc đúng category mới.
9. Tạo loại mới bằng `+ Tạo mới`, lưu xong category mới được chọn.
10. Mở phiếu thu, dropdown hiển thị loại thu, không lẫn loại chi.
11. Không đổi Phiếu thu thành Phiếu chi hoặc ngược lại.

## Rủi ro còn lại nếu sau này muốn đổi Phiếu thu ↔ Phiếu chi

- Cần thiết kế riêng vì đổi `type` không chỉ là đổi label UI: có thể ảnh hưởng hướng dòng tiền, báo cáo thu/chi, công nợ, reference liên kết hóa đơn/trả hàng/nhập hàng, trạng thái thanh toán và mã phiếu `PT/PC`.
- Nếu cho đổi type, cần guard các phiếu có `reference_type/reference_code` liên kết chứng từ nghiệp vụ; cần quyết định có regenerate code prefix hay không; cần audit log rõ ràng và test hồi quy cho báo cáo/công nợ/cashflow.
- Phase hiện tại cố ý không hỗ trợ đổi type để tránh đảo chiều dòng tiền ngoài ý muốn.