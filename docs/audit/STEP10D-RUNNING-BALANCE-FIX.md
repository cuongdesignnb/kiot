# STEP 10D — Running balance fix for document-first debt timeline

## Phạm vi
- Customer debt-history API: Trả về đầy đủ `customer_display_running_balance` và `running_balance` là số thực (float/numeric), không bị null.
- Customer debt tab UI: Hiển thị đúng giá trị Dư nợ khách hàng dạng số / tiền tệ (hoặc `0đ` nếu bằng 0), không bị ẩn thành dấu `—` khi có cảnh báo lệch đối soát.
- Running balance: Tính toán theo thứ tự thời gian tăng dần (ASC) từ chứng từ gốc, sau đó đảo ngược để hiển thị (DESC) nhưng giữ nguyên số dư của từng dòng.
- Reconcile warning: Vẫn hiển thị banner cảnh báo lệch đối soát giữa tổng chứng từ timeline với nợ thực tế trong DB, nhưng không được ẩn cột Dư nợ.

## Root cause
- Backend có thiếu customer_display_running_balance không: Có, do vòng lặp `foreach ($sorted as &$entry)` trên Laravel Collection không ghi đè giá trị tham chiếu ngược vào các phần tử của collection.
- Collection foreach/map issue: Laravel Collection lưu trữ bản sao dữ liệu bên dưới, việc dùng tham chiếu `&` trong `foreach` truyền thống của PHP không đồng bộ ngược lại các phần tử của Collection. Chuyển sang `map()` đã giải quyết triệt để lỗi này.
- Frontend có ẩn balance khi warning không: Có, do helper cũ `customerDebtEntryRunningBalance` và logic trong template Vue 3 kiểm tra điều kiện không chặt chẽ dẫn đến cột Dư nợ hiển thị toàn dấu `—`.
- Vì sao UI hiển thị toàn dấu —: Do backend trả về trường `customer_display_running_balance` bằng `null` (vì lỗi foreach reference) kết hợp với logic template hiển thị dấu `—` khi helper trả về null.

## Source đã kiểm tra
- CustomerDebtDocumentTimelineService: [CustomerDebtDocumentTimelineService.php](file:///d:/Kiot/kiotviet-clone/app/Services/CustomerDebtDocumentTimelineService.php)
- CustomerController: [CustomerController.php](file:///d:/Kiot/kiotviet-clone/app/Http/Controllers/CustomerController.php)
- Customers/Index.vue: [Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Customers/Index.vue)
- Tests: [CustomerDebtDocumentTimelineTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Customers/CustomerDebtDocumentTimelineTest.php)

## Fix đã làm
- Backend running balance: Sửa hàm `build()` trong [CustomerDebtDocumentTimelineService.php](file:///d:/Kiot/kiotviet-clone/app/Services/CustomerDebtDocumentTimelineService.php) sử dụng `map()` để cập nhật dữ liệu của Collection một cách an toàn và nhất quán.
- API fields: Đảm bảo trường `customer_display_running_balance` và `running_balance` luôn được ghi nhận dạng số thực (float) cho tất cả chứng từ.
- Frontend helper: Đổi tên và chuẩn hóa helper thành `getDebtEntryRunningBalance` trong [Index.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Customers/Index.vue).
- Warning banner: Giữ nguyên banner cảnh báo đối soát công nợ nhưng không làm ảnh hưởng đến hiển thị của từng dòng trong bảng lịch sử công nợ.
- Tests: Sửa lỗi cú pháp trong lệnh debug command [DebugDocumentTimelineCommand.php](file:///d:/Kiot/kiotviet-clone/app/Console/Commands/DebugDocumentTimelineCommand.php) bằng cách định nghĩa các biến `$isPass` và `$statusStr`.

## Data safety
- Migration: Không (Không có file migration nào được tạo/chạy).
- Backfill: Không (Không đồng bộ dữ liệu cũ).
- Update DB: Không.
- Delete: Không.
- Recalculate: Không.
- DB writes: Không.
- migrate:fresh: Không chạy.

## API evidence sau fix
Kết quả lệnh `php artisan debt:debug-document-timeline --customer-code=NCC178090885683 --document-code=HD178090993527 --json`:
```json
{
    "customer_code": "NCC178090885683",
    "document_code": "HD178090993527",
    "api_mode_document_effect": 800000,
    "api_default_effect": 800000,
    "source": "document_first",
    "badge_label": "null",
    "invoice_total": 800000,
    "invoice_customer_paid": 500000,
    "cashflow_total": 500000,
    "expected_invoice_effect": 800000,
    "pass_fail": "PASS",
    "entry_count": 7,
    "all_entries_have_running_balance": true,
    "missing_running_balance_codes": [],
    "document_final_balance": 3300000,
    "stored_net": 1300000,
    "reconcile_severity": "warning"
}
```
- HD178090993527: `customer_display_running_balance` và `running_balance` được gán đầy đủ giá trị số.
- PT2026060816121654: `customer_display_running_balance` và `running_balance` được gán đầy đủ giá trị số.
- TH2026060816051522: `customer_display_running_balance` và `running_balance` được gán đầy đủ giá trị số.
- Có customer_display_running_balance không: Có (tất cả các dòng chứng từ đều có).
- Có running_balance không: Có (tất cả các dòng chứng từ đều có).
- Reconcile severity: `warning` (do dữ liệu lịch sử lệch nhưng vẫn giữ nguyên hiển thị).

## Manual QA local
- Test Phần Mềm: Đã hiển thị đúng giá trị Dư nợ khách hàng (cột Dư nợ có số đầy đủ).
- Nguyễn Đình Hoan: Không lỗi regression, các dòng hiển thị số dư bình thường.
- Anh Bẩy: Không lỗi regression, các dòng hiển thị số dư bình thường.
- Warning banner: Vẫn hiển thị bình thường khi có sự sai lệch.
- Cột Dư nợ còn dấu — không: Không còn dấu `—` cho các dòng giao dịch ảnh hưởng công nợ.

## Tests
- CustomerDebtDocumentTimelineTest: PASS (19/19 tests)
- Regression:
  - `KiotStyleCustomerDebtTimelineTest.php` PASS (9/9 tests)
  - `KiotStyleSupplierDebtTimelineTest.php` PASS (3/3 tests)
  - `DebtAdjustmentTimelineDisplayTest.php` PASS (5/5 tests)
  - `AnhThanhThienPhuDebtReconcileTest.php` PASS (1/1 test)
- npm run build: PASS (Vite compiles all assets successfully).

## Kết luận
- Đạt/chưa đạt: ĐẠT.
- Giá trị chứng từ còn đúng không: Có (HD +800.000đ, PT -500.000đ, TH -1.000.000đ).
- Running balance đã hiển thị chưa: Đã hiển thị dạng số đầy đủ cho mọi dòng chứng từ.
- Warning có còn làm ẩn balance không: Không. Cột dư nợ hiển thị bình thường dù có banner warning.
- Có thể tiếp tục test local không: Có, mọi thứ đã ổn định ở local.
- Có thể deploy code-only chưa: Có thể deploy code-only do hoàn toàn không làm thay đổi DB hay chạy migration.
- Có cần đồng bộ dữ liệu cũ không: Không cần ở bước này.
