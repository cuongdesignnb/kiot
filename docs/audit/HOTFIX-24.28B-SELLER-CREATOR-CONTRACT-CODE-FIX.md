# HOTFIX 24.28B — Seller / Creator Contract Code Fix

## 1. Vấn đề
- **Admin/tên cũ còn xuất hiện ở đâu?** Filter Người bán trong `/invoices` và `/reports/employees` trước đó hiện tên "Admin" vì SellerResolver dùng `created_by_name` (creator snapshot) làm fallback cho seller.
- **Vì sao báo cáo sai người bán?** `SellerResolver` cũ coi `created_by_name` = seller khi `created_by` NULL. Admin bấm tạo hóa đơn → `created_by_name = "Admin"` → SellerResolver gán "Admin" làm người bán → sai doanh số.
- **Vì sao đổi tên user vẫn còn Admin?** `created_by_name` là snapshot lịch sử, DB không tự cập nhật khi đổi tên user. Dropdown Người bán đọc aggregate từ `created_by_name` → hiện tên cũ.

## 2. Source đã kiểm tra
- `app/Services/InvoiceSaleService.php` — nơi set `created_by`, `seller_name`, `created_by_name`
- `app/Http/Controllers/PosController.php` — nơi truyền `seller_id`, `seller_name`, `created_by_name`
- `app/Http/Controllers/InvoiceController.php` — filter hóa đơn
- `app/Support/Reports/SellerResolver.php` — logic resolve người bán cho báo cáo
- `app/Http/Controllers/EmployeeReportController.php` — báo cáo nhân viên
- `app/Http/Controllers/SalesReportController.php` — kiểm tra, không dùng SellerResolver
- `resources/js/Pages/POS/Index.vue` — POS UI
- `resources/js/Pages/Invoices/Index.vue` — Hóa đơn UI
- `resources/js/Pages/Reports/EmployeeReport.vue` — Báo cáo nhân viên UI

## 3. Contract sau fix
| Field | Ý nghĩa | Dùng cho Seller? | Dùng cho Creator? |
|---|---|---|---|
| `invoices.created_by` | Seller employee ID | ✅ Chính | ❌ |
| `invoices.seller_name` | Seller name snapshot | ✅ Fallback | ❌ |
| `invoices.created_by_name` | Creator name snapshot | ❌ KHÔNG BAO GIỜ | ✅ Duy nhất |

## 4. Root cause
- **Vì sao `created_by_name` bị dùng sai làm seller?** Các HOTFIX trước (24.22, 24.26) hiểu nhầm tên cột `created_by` là "người tạo", nhưng thực tế nó lưu `seller_id` (employee ID). Khi `created_by` NULL, code fallback về `created_by_name` (tên auth user) → gán creator làm seller.
- **Vì sao đổi tên user vẫn còn Admin?** `created_by_name` là snapshot lúc tạo hóa đơn, DB lưu "Admin" vĩnh viễn. Dropdown Người bán aggregate từ `created_by_name` → hiện "Admin" mặc dù user đã đổi tên.
- **Vì sao report bị gán sai doanh số?** SellerResolver cũ: `created_by_name = "Admin"` → lookup User name → tìm thấy User "Admin" (nay là "Trần Văn Tiến") → gán key `user:<id>` → tính doanh số cho Admin. Nhưng Admin chỉ là người bấm nút, không phải người bán.

## 5. File đã sửa
| File | Nội dung |
|---|---|
| `app/Support/Reports/SellerResolver.php` | Viết lại hoàn toàn. Chỉ dùng `created_by` + `seller_name` cho seller. Thêm `filterByCreator()`, `buildCreatorFilterOptions()`. |
| `app/Http/Controllers/InvoiceController.php` | Thêm `creator_key` filter, creator options từ snapshot, tách riêng seller/creator. |
| `resources/js/Pages/Invoices/Index.vue` | Đổi filter Người tạo dùng `creator_key` với snapshot options. |
| `tests/Feature/Reports/HOTFIX2422*.php` | Cập nhật: admin creator không còn là seller, invoice thiếu seller → unknown. |
| `tests/Feature/Reports/HOTFIX2426*.php` | Cập nhật: test dùng employee seller thay vì admin creator. |
| `tests/Feature/Reports/HOTFIX2427*.php` | Cập nhật: thêm `sellerName` param, test đúng contract mới. |
| `tests/Feature/Reports/HOTFIX2428*.php` | **MỚI**: 12 test cases, 70 assertions cho contract seller/creator. |

## 6. SellerResolver mới
- **`created_by` có giá trị**: → `employee:<id>` nếu employee tồn tại, hoặc `snapshot:<seller_name>`, hoặc `unknown`
- **`created_by` NULL + `seller_name` có giá trị**: → `employee:<id>` nếu match đúng 1 employee active, hoặc `snapshot:<seller_name>`
- **`created_by` NULL + `seller_name` NULL**: → `unknown` ("Chưa xác định người bán")
- **`created_by_name`**: **KHÔNG BAO GIỜ** dùng cho seller. Chỉ dùng cho creator filter.

## 7. Invoice filters
- **Người bán** (`seller_key`): Dùng SellerResolver. Options: employees active + snapshot sellers + unknown bucket.
- **Người tạo snapshot** (`creator_key`): Dùng distinct `created_by_name`. Options: `creator_snapshot:<name>`.
- Hai filter hoàn toàn độc lập, không ảnh hưởng lẫn nhau.

## 8. Report employee
- **Seller thật**: Tính doanh số/lợi nhuận theo `employee:<id>` hoặc `snapshot:<name>`.
- **Unknown seller**: Hóa đơn thiếu seller → bucket "Chưa xác định người bán", có doanh số riêng.
- **Admin creator không còn bị tính là seller**: Admin chỉ xuất hiện nếu thật sự được chọn làm nhân viên bán ở POS.

## 9. POS
- **Seller được lưu thế nào**: `seller_id = employee.id` → `invoices.created_by`, `seller_name = employee.name` → `invoices.seller_name`
- **Creator được lưu thế nào**: `auth()->user()->name` → `invoices.created_by_name`
- **TVT không xuất hiện trong dropdown seller nếu không có employee**: Đúng. TVT là user account, không phải employee. Nếu muốn TVT là người bán thì cần tạo Employee riêng, không tự thêm user vào seller.

## 10. Test đã chạy
| Lệnh | Kết quả |
|---|---|
| `php artisan test --filter=HOTFIX2428SellerCreatorContractTest` | ✅ 12 passed (70 assertions) |
| `php artisan test --filter=HOTFIX2427SellerDuplicateAndInvoiceFilterTest` | ✅ 12 passed |
| `php artisan test --filter=HOTFIX2426ReportSellerResolverAdminTest` | ✅ 11 passed |
| `php artisan test --filter=HOTFIX2422EmployeeReportIncludesAdminTest` | ✅ 6 passed |
| `php artisan test --filter="HOTFIX2422\|HOTFIX2426\|HOTFIX2427\|HOTFIX2428"` | ✅ 41 passed (174 assertions) |
| `php artisan test --filter="EmployeeReport\|Invoice\|Report\|Return\|CashFlow"` | ✅ 213 passed, 2 skipped, 1 unrelated fail (ExampleTest redirect) |
| `npm run build` | ✅ Built in 7.69s |

## 11. Manual QA
- **Đổi tên Admin**: Cần kiểm tra thủ công. Contract đã đúng: tên cũ nằm trong Creator snapshot, không ảnh hưởng Seller.
- **POS**: Không thay đổi logic POS. Seller = employee chọn, Creator = auth user.
- **Hóa đơn**: Filter Người bán dùng `seller_key`, Filter Người tạo dùng `creator_key`. Hai filter tách biệt.
- **Báo cáo nhân viên**: Tính doanh số theo seller thật. Admin chỉ là creator → không xuất hiện trong báo cáo seller.
- **Đối soát invoice codes**: Test 7 (HOTFIX2428) xác nhận report và invoice filter khớp invoice codes + tổng doanh thu.

## 12. Data safety
- **Migration**: Không
- **Backfill**: Không
- **Update dữ liệu cũ**: Không
- **Recalculate tồn kho/giá vốn/công nợ/cashflow**: Không
- **Ảnh hưởng serial/IMEI**: Không
- **Ảnh hưởng công nợ**: Không

## 13. Kết luận
- **Ai bán đã rõ chưa?** ✅ Rõ ràng. Seller = `invoices.created_by` (employee ID) + `invoices.seller_name` (snapshot).
- **Ai tạo hóa đơn đã rõ chưa?** ✅ Rõ ràng. Creator = `invoices.created_by_name` (auth user snapshot).
- **Báo cáo còn gán Admin creator thành seller không?** ❌ Không. SellerResolver không bao giờ dùng `created_by_name`.
- **Hóa đơn và báo cáo đã khớp chưa?** ✅ Đã khớp. Cùng SellerResolver, cùng seller key.
- **Có thể deploy chưa?** ✅ Có. 41 HOTFIX tests pass, 213+ regression tests pass, build OK.
- **Commit SHA**: `e6e1fa1`
