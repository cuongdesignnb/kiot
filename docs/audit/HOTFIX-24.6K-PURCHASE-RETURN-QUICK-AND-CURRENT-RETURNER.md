# HOTFIX 24.6K — Purchase Return Quick Flow And Current Returner

## Phạm vi audit
- Module: Trả hàng nhập.
- Màn hình: `PurchaseReturns/Create.vue`, `PurchaseReturns/CreateQuick.vue`, `PurchaseReturns/Index.vue`.
- Nghiệp vụ: Trả hàng nhập theo phiếu nhập và trả hàng nhập nhanh.
- Rủi ro chính: người dùng hiện tại không xuất hiện ở dropdown Người trả hàng; menu Trả nhanh trỏ tới route chưa đăng ký; trả nhanh hàng serial không có picker serial có thể làm sai serial.

## Source đã kiểm tra
- `resources/js/Pages/PurchaseReturns/Create.vue`
- `resources/js/Pages/PurchaseReturns/CreateQuick.vue`
- `resources/js/Pages/PurchaseReturns/Index.vue`
- `resources/js/Pages/PurchaseReturns/Show.vue`
- `app/Http/Controllers/PurchaseReturnController.php`
- `app/Models/PurchaseReturn.php`
- `app/Models/Employee.php`
- `app/Models/User.php`
- `routes/web.php`
- `tests/Feature/Purchase/Step233PurchaseReturnFlowTest.php`
- Tài liệu KiotViet: `https://www.kiotviet.vn/huong-dan-su-dung-kiotviet/retail-giao-dich/tra-hang-nhap/`

## Đối chiếu KiotViet
- KiotViet có 2 luồng trả hàng nhập:
  - Trả theo phiếu nhập hàng.
  - Trả hàng nhập nhanh.
- Trả hàng nhập làm giảm tồn kho và cập nhật công nợ nhà cung cấp.
- Trả nhanh không cần phiếu nhập gốc nhưng vẫn phải chọn NCC, hàng hóa và tiền NCC trả.

## Hiện trạng
- Backend: `PurchaseReturnController::createQuick()` và `quickStore()` đã tồn tại nhưng route web chưa đăng ký.
- Frontend: `Index.vue` có link `/purchase-returns/create-quick`, `CreateQuick.vue` post `/purchase-returns/quick`.
- Dropdown Người trả hàng chỉ lấy `employees`, nên admin/current user không có employee không xuất hiện.
- Trả nhanh chưa có UI chọn serial/IMEI.

## Root cause
- Thiếu route `GET /purchase-returns/create-quick`.
- Thiếu route `POST /purchase-returns/quick`.
- Props tạo phiếu chỉ truyền employee active, không truyền current user fallback.
- Quick return không chặn sản phẩm serial ở cả frontend và backend.

## Có ảnh hưởng dữ liệu đang có không?
- Không migration.
- Không backfill.
- Không update dữ liệu cũ.
- Không sửa tồn kho/serial/công nợ/cashflow cũ.
- Logic mới chỉ áp dụng giao dịch mới.

## Phương án an toàn
- Đăng ký route trả nhanh đúng middleware `purchases.return.create`.
- Truyền `currentReturner` từ backend cho cả màn trả theo phiếu và trả nhanh.
- Frontend hiển thị current user/admin trong dropdown nếu user không có employee active.
- Nếu current user không có employee thì gửi `employee_id = null`, vẫn lưu `user_id = auth()->id()` như hiện tại.
- Trả nhanh chặn sản phẩm serial/IMEI vì chưa có picker serial.
- Backend lock và validate lại sản phẩm trả nhanh trước khi tạo phiếu.

## Không được làm
- Không tự sửa dữ liệu cũ.
- Không tạo employee tự động cho admin.
- Không xử lý serial thủ công trong trả nhanh khi chưa có UI chọn serial.
- Không sửa logic trả theo phiếu nhập đã có serial guard.
- Không sửa công nợ/cashflow ngoài flow sẵn có.

## Tests bắt buộc
- `php artisan test tests/Feature/Purchase/HOTFIX246KPurchaseReturnQuickAndReturnerTest.php`
- `php artisan test tests/Feature/Purchase/Step233PurchaseReturnFlowTest.php`
- `php artisan test tests/Feature/Purchases`
- `php artisan test tests/Feature/Supplier`
- `npm run build`

## Manual QA
- Chưa chạy browser QA trong phiên này.
- Cần kiểm tra:
  - Màn trả theo phiếu nhập hiển thị `Trần Văn Tiến (hiện tại)` nếu user không có employee.
  - Menu Trả hàng nhập > Trả nhanh mở được màn tạo nhanh.
  - Trả nhanh hàng thường lưu được và trừ tồn/công nợ NCC đúng.
  - Trả nhanh hàng serial bị chặn, yêu cầu dùng trả theo phiếu nhập.

## Kết luận
- Đạt về source/test nếu regression pass.
- Chưa kết luận production-ready nếu chưa browser QA.
