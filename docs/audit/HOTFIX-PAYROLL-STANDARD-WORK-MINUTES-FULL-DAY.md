# HOTFIX/AUDIT REPORT — Standard Work Minutes Full-Day Attendance Fix

Documenting the audit, root cause, code changes, and verification details for resolving the 480-minute full day calculation issue under 10-hour shift schedules.

---

## Bối cảnh
- **Lỗi báo cáo**: Nhân viên **Vũ Hồng Nhung** (bảng lương tháng 05/2026) có số công bị tụt từ **14 công** xuống **13.5 công** sau khi chấm công tay bổ sung.
- **Hiện trạng trên Production**:
  - Nhánh đang chạy: `prod-hotfix-attendance-payroll`
  - Các commit hotfix đã cherry-pick trên production:
    - `8a590d0 fix(attendance): align timesheet colors with KiotViet UI`
    - `18f1a12 fix(payroll): calculate work units for manual timekeeping records`
    - `2701c5a fix(payroll): prevent manual attendance from silently downgrading work units`
    - `31d5a15 docs(audit): add manual attendance downgrade guard report`
  - Kết quả Dry-run trước khi sửa:
    ```
    Record ID: 505
    Nhân viên: Vũ Hồng Nhung
    Ngày: 2026-05-04
    Vào/Ra: 08:30 - 16:30
    Min Cũ/Mới: 480 -> 480
    Unit Cũ/Mới: 0.50 -> 0.5
    Trạng thái: Khớp
    Lý do: Không có thay đổi
    ```
    (Lỗi vẫn tính 480 phút = 0.5 công, không đề xuất sửa lên 1.0 công).

---

## Phân tích & Nguyên nhân gốc (Root Cause)
1. **Root cause chính**: Logic cũ xác định thời lượng một ngày công đầy đủ (`fullDayMinutes`) trực tiếp bằng độ dài của ca làm việc (Shift duration, ví dụ: 08:30 - 18:30 = 600 phút).
2. **Setting hiện tại**:
   - `attendance_standard_work_minutes = 480` (Chuẩn ngày công chuẩn là 8 tiếng = 480 phút).
   - `attendance_half_work_max_minutes = 480`
3. **Ảnh hưởng**:
   - Khi Vũ Hồng Nhung làm việc từ 08:30 đến 16:30 = 480 phút. Ca làm việc (schedule) yêu cầu 08:30 đến 18:30 = 600 phút.
   - Code cũ tính `fullDayMinutes = 600`. Vì `workedMinutes (480) < fullDayMinutes (600)` nên nó bị đẩy xuống khung tính công nửa ngày.
   - Tiếp đó, `workedMinutes (480) <= halfWorkMaxMinutes (480)` nên trả về `0.5 công` thay vì `1.0 công`.
4. **Bản ghi bị ảnh hưởng**:
   - Bản ghi **505** (manual): làm 480 phút, đang bị tính 0.5 công.
   - Bản ghi **617** (device): làm 480 phút, đang bị tính 0.5 công.

---

## Phương án khắc phục
1. **Backend**:
   - Cập nhật [TimekeepingService.php](file:///d:/Kiot/kiotviet-clone/app/Services/TimekeepingService.php) để tạo helper `resolveFullDayMinutes`. Helper này ưu tiên sử dụng trị số chuẩn ngày công từ setting (`attendance_standard_work_minutes`, mặc định 480) làm chuẩn.
   - Công thức xác định `fullDayMinutes`:
     - Nếu có cả ngày công chuẩn (`standardWorkMinutes`) và độ dài ca (`scheduleMinutes`), dùng `min(standardWorkMinutes, scheduleMinutes)`.
     - Nếu chỉ có `standardWorkMinutes`, dùng `standardWorkMinutes`.
     - Nếu chỉ có `scheduleMinutes`, dùng `scheduleMinutes`.
     - Ngược lại fallback về `480`.
   - Áp dụng thống nhất cho cả chấm công tay (`buildManualRecordAttributes`) và chấm công máy (`recalculateForRange`).
   - Đảm bảo `calculateWorkUnitsFromMinutes()` check sớm điều kiện `workedMinutes >= fullDayMinutes` để trả về `1.0` ngay lập tức, không để lọt vào khung tính half-day.
2. **Frontend**:
   - Đồng bộ logic tính công dự kiến tại computed property `estimatedWorkInfo` trong [Attendance.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Employees/Attendance.vue) sử dụng `Math.min(480, scheduleMinutes)`.

---

## Chi tiết thay đổi Code (Files Changed)
- [app/Services/TimekeepingService.php](file:///d:/Kiot/kiotviet-clone/app/Services/TimekeepingService.php)
- [resources/js/Pages/Employees/Attendance.vue](file:///d:/Kiot/kiotviet-clone/resources/js/Pages/Employees/Attendance.vue)
- [tests/Feature/Payroll/ManualTimekeepingTest.php](file:///d:/Kiot/kiotviet-clone/tests/Feature/Payroll/ManualTimekeepingTest.php)

---

## Kế hoạch kiểm thử & Kết quả
### 1. Automated Tests
Đã bổ sung 7 Feature/Unit tests bắt buộc vào `ManualTimekeepingTest.php`:
- **Case 1 — Vũ Hồng Nhung regression**: Ca 10 tiếng, chấm 8 tiếng (480 phút) -> Đạt 1.0 công.
- **Case 2 — Khuất Trung Hiếu regression**: Ca 10 tiếng, chấm 10 tiếng (600 phút) -> Đạt 1.0 công.
- **Case 3 — Nửa ngày thật**: Ca 10 tiếng, chấm 4 tiếng (240 phút) -> Đạt 0.5 công.
- **Case 4 — 479 phút**: Ca 10 tiếng, chấm 479 phút -> Đạt 0.5 công (không tự nhảy lên 1).
- **Case 5 — Device recalculate**: Log chấm công 480 phút -> Recalculate ra 1.0 công.
- **Case 6 — Manual override không bị auto overwrite**: Bản ghi có `manual_override = true` không bị recalculate ghi đè.
- **Case 7 — Audit command**: Chạy dry-run đề xuất đổi công từ `0.50 -> 1` cho record 480 phút, và chỉ update DB khi có flag `--apply`.

**Kết quả chạy test local**:
```bash
php artisan test --filter=ManualTimekeepingTest
```
-> **PASS** (16 tests, 84 assertions).
```bash
php artisan test --filter=Payroll
```
-> **PASS** (55 tests, 306 assertions).

### 2. Build Frontend Compilation
```bash
npm run build
```
-> **SUCCESS** (Built successfully in 7.28s).

---

## Quy trình triển khai & An toàn dữ liệu
- **Migration**: Không có (Không thay đổi cấu trúc bảng).
- **Backfill**: Không tự động cập nhật dữ liệu.
- **Quy trình áp dụng dữ liệu thật trên Production**:
  1. Deploy hotfix code mới lên production bằng cách cherry-pick commit mới nhất vào branch `prod-hotfix-attendance-payroll`.
  2. Chạy dry-run kiểm tra:
     ```bash
     php artisan timekeeping:audit-manual-work-units --from=2026-05-01 --to=2026-05-31 --employee="Vũ Hồng Nhung"
     ```
     Kỳ vọng record **505** xuất hiện dòng đề xuất: `0.50 -> 1` (`Sửa?: Có thể sửa`).
  3. Sau khi user duyệt danh sách đề xuất, chạy apply để cập nhật chấm công tay:
     ```bash
     php artisan timekeeping:audit-manual-work-units --from=2026-05-01 --to=2026-05-31 --employee="Vũ Hồng Nhung" --apply
     ```
  4. Recalculate lại record máy chấm công **617** cho Vũ Hồng Nhung qua tinker:
     ```php
     $emp = \App\Models\Employee::where('name','like','%Vũ Hồng Nhung%')->firstOrFail();
     app(\App\Services\TimekeepingService::class)->recalculateForRange(\Carbon\Carbon::parse('2026-05-01'), \Carbon\Carbon::parse('2026-05-31'), $emp->id);
     ```
  5. Kiểm tra lại record 505 và 617 trong DB, kỳ vọng đều đạt `work_units = 1.00`.
  6. Vào màn hình bảng lương tháng 05/2026 trên giao diện Web bấm **Tính lại** để đồng bộ payslip/paysheet.

---

## Kế hoạch Rollback
Nếu xảy ra lỗi không mong muốn, thực hiện rollback code trên production về commit trước đó:
```bash
git checkout prod-hotfix-attendance-payroll
git reset --hard HEAD~1
npm run build
```
Dữ liệu cũ vẫn được bảo toàn và không bị thay đổi tự động.
