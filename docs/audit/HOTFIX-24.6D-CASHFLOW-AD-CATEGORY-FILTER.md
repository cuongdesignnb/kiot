# HOTFIX 24.6D - Cashflow Category Filter & Advertising Expense Classification

## Phạm vi audit
- Module: Cashflow / Sổ quỹ tiền mặt và CĐNH.
- Màn hình: `/cash-flows`.
- Nghiệp vụ: lọc Loại thu chi và phân loại chi phí quảng cáo.
- Rủi ro chính: phiếu quảng cáo lưu nhầm category sẽ không xuất hiện khi lọc `Quảng cáo`.

## Source đã kiểm tra
- `app/Http/Controllers/CashFlowController.php`
- `resources/js/Pages/CashFlows/Index.vue`
- `app/Support/Filters/FilterableIndex.php`
- `app/Models/CashFlow.php`
- `routes/web.php`
- `tests/Feature/CashFlow/RR10CashFlowDeletionTest.php`

## Hiện trạng
- Backend: `CashFlowController::configureCashFlowFilters()` có scalar filter `category`.
- Filter engine: `FilterableIndex::applyScalarFilters()` lọc exact bằng `where(category, value)`.
- Frontend: loại chi mặc định thiếu `Quảng cáo`, nên người dùng dễ chọn `Chi khác`.
- Database local: đã kiểm tra read-only trên container `sales_mysql_test`, DB `kiot_db`.
- Permission: route `/cash-flows` vẫn dùng `cash_flows.view`, các route mutation giữ nguyên quyền hiện có.

## SQL diagnostic local
Read-only diagnostic theo keyword quảng cáo phát hiện:

| Nhóm | Số phiếu | Tổng tiền |
|---|---:|---:|
| `category = Quảng cáo` | 3 | 6,380,589 |
| `category blank/NULL` | 1 | 1,140,630 |

Phiếu nghi sai category:

| id | code | time | category hiện tại | amount | description |
|---:|---|---|---|---:|---|
| 370 | PC26051208435144 | 2026-05-11 18:43:00 | blank/NULL | 1,140,630 | FB Ads |

## Root cause
- Filter `Loại thu chi` đang lọc exact theo `cash_flows.category`, đây là đúng nghiệp vụ.
- Một số phiếu có mô tả quảng cáo như `FB Ads` nhưng category không phải `Quảng cáo`, nên không xuất hiện khi lọc `Quảng cáo`.

## Có ảnh hưởng dữ liệu đang có không?
- Có nếu sửa phiếu cũ.
- Hotfix hiện tại không update dữ liệu cũ.
- Command dry-run chỉ đọc dữ liệu.
- Không migration, không backfill, không xóa dữ liệu.

## Phương án an toàn
- Thêm `Quảng cáo`, `Nạp tiền chợ tốt`, `Tiền Internet` vào default payment categories.
- Normalize category option bằng trim + lowercase key để tránh trùng do khoảng trắng/hoa thường.
- Giữ filter exact, không gộp description vào filter category.
- Thêm cảnh báo khi tạo/sửa phiếu chi có ghi chú giống quảng cáo nhưng category chưa phải `Quảng cáo`.
- Thêm badge trên danh sách cho phiếu nghi sai loại.
- Thêm note khi đang lọc `Quảng cáo`.
- Thêm command dry-run: `php artisan cashflows:audit-ad-category --dry-run`.

## Không được làm
- Không bulk update.
- Không sửa `amount`, `time`, `type`, `payment_method`, `target_name`.
- Không đổi filter exact thành search ghi chú.
- Không tự sửa phiếu cũ khi chưa có xác nhận owner.

## Tests bắt buộc
- `php artisan cashflows:audit-ad-category --dry-run`: pass, phát hiện 1 phiếu nghi sai loại, tổng 1,140,630.
- `php artisan test tests/Feature/CashFlows/CashFlowAdvertisingCategoryAuditTest.php`: pass, 6 tests / 23 assertions.
- `php artisan test tests/Feature/CashFlows`: pass, 6 tests / 23 assertions.
- `php artisan test tests/Feature/CashFlow`: pass, 5 tests / 12 assertions.
- `php artisan test tests/Feature/Filters`: pass, 36 tests / 422 assertions.
- `npm run build`: pass.

## Manual QA
- Browser QA chưa chạy trong phiên này.
- Cần kiểm tra:
  - Tạo phiếu chi thấy option `Quảng cáo`.
  - Ghi chú `FB Ads` + category `Chi khác` hiện cảnh báo và nút `Chọn Quảng cáo`.
  - Phiếu cũ nghi sai hiện badge `Có vẻ là quảng cáo`.
  - Filter `Quảng cáo` chỉ hiện phiếu có category đúng là `Quảng cáo`.

## Kết luận
- Đạt cho code/test local.
- Có thể deploy code sau khi browser QA.
- Chưa được tự sửa dữ liệu cũ trên production.
- Nếu owner muốn sửa phiếu cũ, bước tiếp theo là backup DB, chạy dry-run production, xuất danh sách phiếu, xác nhận rõ, rồi tạo hotfix apply/rollback riêng.
