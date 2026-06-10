# Audit Report — Hotfix Payroll Manual Attendance Downgrade Guard

## Bối cảnh user báo lỗi
- **Nhân viên**: Vũ Hồng Nhung (ID: 6)
- **Tháng lương**: 05/2026
- **Hiện tượng**: Trước khi chấm bù/sửa công, bảng lương hiển thị **14 công**. Sau khi sửa/chấm bù, tổng công tụt xuống **13.5 công**.
- **Mục tiêu**: Điều tra nguyên nhân làm giảm ngày công khi chấm công tay, sửa logic tính công và thiết lập cơ chế chặn (guards) ở cả frontend và backend để tránh silent downgrade công.

---

## Hiện trạng
- **Production branch đang chạy**: `prod-hotfix-attendance-payroll`
- **Production base commit**: `1cec76c`
- **Commit hotfix mới**: `0e38aec`

---

## Source đã kiểm tra
- **File**:
  - `app/Services/TimekeepingService.php` (Logic tính công)
  - `app/Http/Controllers/TimekeepingRecordController.php` (Xử lý lưu chấm công tay & guards)
  - `resources/js/Pages/Employees/Attendance.vue` (Prefill modal, cảnh báo toggle, hiển thị công dự kiến, xử lý requires_confirmation)
- **Models**:
  - `app/Models/TimekeepingRecord.php`
  - `app/Models/Setting.php`
- **Tests**:
  - `tests/Feature/Payroll/ManualTimekeepingTest.php`

---

## Root cause & Phân tích chi tiết

### 1. Phân tích Record của Vũ Hồng Nhung bị ảnh hưởng
- **Record ID**: 505
- **Ngày làm việc**: `2026-05-04` (Lưu dưới DB dạng UTC: `2026-05-03T17:00:00.000000Z`)
- **Thông tin check-in / check-out**:
  - `check_in_at`: null
  - `check_out_at`: `2026-05-04T09:30:16.000000Z`
  - `worked_minutes`: 480
  - `work_units` cũ: 1.0
  - `manual_override`: false
  - `source`: `device`

### 2. Thiết lập Settings nửa công (Half-day settings)
- `attendance_half_work_enabled` = 1 (Bật)
- `attendance_half_work_min_minutes` = 0
- `attendance_half_work_max_minutes` = 480
- `attendance_standard_work_minutes` = 480
- `late_half_day_enabled` = false

### 3. Nguyên nhân gây tụt công (Root Cause)
1. **Lỗi logic tính công 480 phút**:
   Trong `TimekeepingService.php`, logic tính công cũ:
   ```php
   if ($workedMinutes < $halfWorkMinMinutes) {
       $workUnits = 0;
   } elseif ($workedMinutes <= $halfWorkMaxMinutes) {
       $workUnits = 0.5;
   } else {
       $workUnits = 1.0;
   }
   ```
   Vì `worked_minutes` của Vũ Hồng Nhung ngày 2026-05-04 đúng bằng `480` phút, và `halfWorkMaxMinutes` cũng là `480` phút, điều kiện `$workedMinutes <= $halfWorkMaxMinutes` (480 <= 480) bị thỏa mãn. Dẫn đến việc ngày công bị tính thành `0.5` công.
   
2. **Kích hoạt do lưu chấm công tay**:
   Khi người dùng lưu chấm công tay ở bất kỳ ngày nào khác hoặc cập nhật chấm công của Vũ Hồng Nhung, hệ thống chạy lại logic tính toán và silent overwrite (ghi đè âm thầm) ngày `2026-05-04` từ `1.0` xuống `0.5` công do hàm `updateOrCreate` hoặc quy trình recalculate.

3. **Hiện tượng từ Frontend / Backend**:
   - Frontend không tự ý clear check-in/check-out.
   - Nhưng backend `TimekeepingRecordController@store` thiếu guard cảnh báo khi công bị tụt hoặc khi giờ vào/ra bị mất.

---

## Giải pháp triển khai (Hotfix)

### 1. Sửa logic tính công trong `TimekeepingService`
- Thêm helper `calculateWorkUnitsFromMinutes(...)`.
- Kiểm tra điều kiện `workedMinutes >= fullDayMinutes` (480 phút) trước tiên để trả về `1.0` công trực tiếp, tránh bị chặn bởi điều kiện `<= halfWorkMaxMinutes`.
- Sửa cả luồng tính công tự động (`recalculateForRange`), tính công cho ngày nghỉ tuần/ngày lễ, và chấm công tay (`buildManualRecordAttributes`).

### 2. Bổ sung Backend Guards (`TimekeepingRecordController@store`)
- Trước khi lưu record mới, tìm record cũ theo `employee_work_schedule_id`.
- So sánh các chỉ số cũ và mới:
  - Nếu `old_work_units > new_work_units` và chưa có flag `confirm_downgrade = true` -> Trả về lỗi `422` kèm `requires_confirmation = true` và loại xác nhận `downgrade`.
  - Nếu record cũ có giờ vào/ra mà payload mới thiếu một trong hai, và chưa có flag `confirm_clear_time = true` -> Trả về lỗi `422` yêu cầu xác nhận.
- Nếu người dùng xác nhận và gửi lại request với các flag trên -> Lưu thành công và ghi log rõ ràng.

### 3. Cập nhật Frontend UI (`Attendance.vue`)
- Prefill đầy đủ checkbox và giờ vào/ra cũ khi mở modal.
- Theo dõi sự kiện bỏ check giờ vào/ra đã có sẵn để hiển thị cảnh báo trực tiếp trên UI.
- Thêm mục **Công dự kiến** và **Thời gian làm dự kiến** hiển thị thời gian thực (real-time) khi người dùng nhập giờ trên modal.
- Bắt lỗi `requires_confirmation = true` từ API, hiển thị hộp thoại confirm chi tiết chỉ số cũ -> mới để người dùng xác nhận trước khi tiếp tục gửi lại request.

---

## Ảnh hưởng dữ liệu & Phương án an toàn

### 1. Ảnh hưởng dữ liệu hiện tại
- Việc sửa code sửa đổi logic tính công cho tương lai và các lần tính lại.
- Đối với dữ liệu của Vũ Hồng Nhung trên Production, ta **không tự động thay đổi trực tiếp DB** mà cần báo cáo để người dùng duyệt.
- Bản ghi bị ảnh hưởng:
  - Record ID: 505 (Ngày 2026-05-04)
  - Giá trị hiện tại dưới DB: `work_units = 1.0` (Do dữ liệu chưa bị lưu đè lại hoặc đã được sửa thủ công tạm thời). Sau khi cập nhật code hotfix, lưu chấm công tay sẽ không làm tụt công bản ghi này nữa.

### 2. Khôi phục dữ liệu Vũ Hồng Nhung (Sau khi xác nhận)
- **Bước 1 (Backup)**:
  `CREATE TABLE timekeeping_records_backup_nhung AS SELECT * FROM timekeeping_records WHERE employee_id = 6;`
- **Bước 2 (Khôi phục)**:
  Nếu record 505 hoặc bất kỳ ngày nào khác trong tháng 05/2026 của Vũ Hồng Nhung bị tụt xuống 0.5 sai lệch, ta sẽ chạy lệnh update trực tiếp hoặc duyệt tính lại bảng công tháng 05/2026.
- **Bước 3 (Rollback plan)**:
  Nếu việc cập nhật có sai sót, khôi phục lại dữ liệu từ bảng backup.

---

## Kết quả kiểm tra (Tests & Build)

### 1. Build Frontend thành công
- Lệnh chạy: `npm run build`
- Trạng thái: Thành công không lỗi, file `Attendance-CG_7uE7Z.js` được build chính xác.

### 2. Unit & Feature Tests
Chạy bộ test `ManualTimekeepingTest` bao gồm các ca kiểm thử:
1. `test_manual_attendance_durations_and_units`: Kiểm tra 600 phút -> 1.0, đúng 480 phút -> 1.0, 479 phút -> 0.5, 240 phút -> 0.5.
2. `test_controller_store_guards`: Kiểm tra hoạt động của downgrade guard (trả về 422 khi tụt công không xác nhận, cho phép lưu khi có flag confirm) và clear time guard.
3. `test_vu_hong_nhung_regression`: Giả lập Vũ Hồng Nhung có 14 công full 480 phút, chấm bù ngày thứ 15 và đảm bảo tổng công tăng lên 15.0 chứ không bị tụt.

**Kết quả test**:
`Tests: 9 passed (62 assertions), Duration: 1.31s`
Tất cả các test trong Payroll và Timekeeping (tổng cộng 48 tests) đều **PASS** 100%.

---

## Kết luận
Hotfix đã hoàn thành, kiểm thử đầy đủ và đạt yêu cầu, sẵn sàng để deploy lên production sau khi người dùng xác nhận.
