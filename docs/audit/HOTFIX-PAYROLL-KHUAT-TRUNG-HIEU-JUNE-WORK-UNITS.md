# HOTFIX/AUDIT — Khuất Trung Hiếu tháng 06/2026 hiển thị 25/26 công

## Mã việc

HOTFIX-PAYROLL-KHUAT-TRUNG-HIEU-JUNE-WORK-UNITS

## Kết luận

- Dump mới nhất cho thấy tổng công chấm công, runtime payroll và payslip đều là **25/26** trước khi sửa bản sao audit.
- Ngày làm thiếu đúng 1 công là **01/06/2026**, record `timekeeping_records.id = 654`: vào `08:30`, ra `18:30`, nhưng dữ liệu manual đang lưu `worked_minutes = -600`, `work_units = 0`.
- Bốn ngày 0 công còn lại (07, 14, 21, 28/06) là Chủ nhật, không có log và được đánh dấu ngày nghỉ; không phải nguyên nhân thiếu công.
- Không có ngày 0.5 công, duplicate schedule/record, schedule thiếu, paid leave, holiday chính thức hay late-half-day làm giảm công.
- Source hiện tại đã dùng `abs(diffInMinutes)` và tính `600 phút = 1 công`. Recalculate tự động cố ý skip manual override, nên không thể tự chữa record 654.
- Payslip không phải snapshot stale ở trạng thái dump: stored và runtime cùng bằng 25. Sau khi sửa record trên audit DB, runtime thành 26 còn payslip vẫn 25; recalculate paysheet audit mới cập nhật payslip thành 26.
- Có lỗi hiển thị độc lập: API lịch làm việc chưa trả `work_units`, còn chấm màu tháng chỉ nhìn giờ vào/ra và muộn/sớm. Vì vậy record 0/0.5 công có thể vẫn hiện xanh/tím. Hotfix bổ sung field API, tooltip và badge `0`/`½`; không thay đổi công thức payroll.

## Database audit source

| Mục | Giá trị |
|---|---|
| File dump | `D:\Kiot\kiot.sql.zip` |
| ZIP size | 525,571 bytes |
| Modified time | 2026-07-10 09:54:32 +07:00 |
| SHA256 | `80D003E6196FC9E6E56A2DB1E5CEEF8FF2AF2343F7DE852DCF8F01B1783FCFDC` |
| SQL entry | `kiot_db_2026-07-10_09-54-17_mysql_data_3X9jm.sql` |
| SQL size | 4,434,129 bytes |
| SQL modified | 2026-07-10 09:54:18 +07:00 |
| Dump header | MariaDB 10.11.10, database `kiot_db` |
| CREATE/USE/DROP DATABASE | Không |
| DROP TABLE | Có |
| Container | `kiot-audit-db` |
| Image | `mariadb:10.11` |
| Port | `127.0.0.1:3315` |
| Database | `kiot_audit` |
| Tables | 106 |
| Import result | Thành công |

Project Compose dùng `mysql:8.0`, nhưng import nguyên trạng vào MySQL 8 dừng ở lỗi 1101 do MariaDB cho phép default trên cột `TEXT`. Bản import dở đã bị xóa cùng volume; container sạch được dựng lại bằng đúng major engine của dump (`mariadb:10.11`). Dump gốc không bị chỉnh sửa.

Import verification:

| Bảng | Số record |
|---|---:|
| employees | 7 |
| timekeeping_records | 679 |
| paysheets | 11 |
| payslips | 51 |

Snapshot trước mọi mutation audit được lưu ngoài repo tại `/tmp/kiot-audit-before-recalc.sql` trong container audit, size khoảng 966 KiB, SHA256 `1684e033566c3fafaedbb47c8914db386c54ed6ce3c55317fffab16c2321625d`.

## Git scope

| Mục | Giá trị |
|---|---|
| Base branch | `fix/stocktake-inventory-invoice-safe` |
| Base commit | `0740c1e` — `fix: repair stocktake inspector panel and category tree` |
| Branch | `hotfix/payroll-khuat-trung-hieu-june-work-units` |
| Working tree ban đầu | Không có tracked changes |
| Untracked ban đầu | `.git-safe-backups/` (giữ nguyên, không commit) |

Các hotfix timekeeping/payroll liên quan (`39e60dc`, `e94baf2`) đều là ancestor của base commit.

## Employee và paysheet

Dump thực tế dùng mã `NV00009` (không phải `NV000009` như ticket), nên audit định danh bằng kết quả query, không hardcode mã từ ticket.

| Mục | Trước sửa audit |
|---|---:|
| Employee | `id=11`, `NV00009`, Khuất Trung Hiếu |
| Paysheet | `id=11`, `BL000011`, `calculated` |
| Kỳ | 2026-06-01 → 2026-06-30 |
| Standard working days | 26 |
| needs_recalc | 0 |
| Payslip | `id=66`, `PL000065` |
| Stored work_units | 25 |
| Stored details.normal_work_units | 25 |
| Stored paid_leave_units | 0 |
| Stored base salary | 7,692,308 |
| Stored total salary | 8,182,328 |
| Runtime work_units | 25 |
| Runtime normal_work_units | 25 |
| Runtime base | 7,692,308 |
| Runtime total | 8,182,328 |

## Daily analysis (snapshot gốc)

| Ngày | Vào | Ra | Phút | Công | Muộn | Sớm | Source | Manual | Lý do |
|---|---:|---:|---:|---:|---:|---:|---|---|---|
| **01/06** | 08:30 | 18:30 | **-600** | **0** | 0 | 0 | manual | Có | **Record sai làm thiếu đúng 1 công** |
| 02/06 | 08:26 | 18:07 | 581 | 1 | 0 | 0 | device | Không | Đủ công |
| 03/06 | 08:26 | 17:50 | 564 | 1 | 0 | 10 | device | Không | Đủ công |
| 04/06 | 09:31 | 18:04 | 513 | 1 | 51 | 0 | device | Không | Muộn dưới threshold 180, vẫn đủ công |
| 05/06 | 08:38 | 18:02 | 564 | 1 | 0 | 0 | device | Không | Đủ công |
| 06/06 | 08:35 | 18:02 | 567 | 1 | 0 | 0 | device | Không | Đủ công |
| 07/06 | — | — | 0 | 0 | 0 | 0 | none | Không | Chủ nhật/ngày nghỉ |
| 08/06 | 08:40 | 17:13 | 513 | 1 | 1 | 46 | device | Không | Đủ công |
| 09/06 | 08:42 | 18:05 | 563 | 1 | 3 | 0 | device | Không | Đủ công |
| 10/06 | 08:39 | 18:00 | 561 | 1 | 0 | 0 | device | Không | Đủ công |
| 11/06 | 08:35 | 18:03 | 568 | 1 | 0 | 0 | device | Không | Đủ công |
| 12/06 | 08:25 | 17:45 | 559 | 1 | 0 | 15 | device | Không | Đủ công |
| 13/06 | 08:27 | 17:41 | 554 | 1 | 0 | 19 | device | Không | Đủ công |
| 14/06 | — | — | 0 | 0 | 0 | 0 | none | Không | Chủ nhật/ngày nghỉ |
| 15/06 | 08:48 | 18:04 | 556 | 1 | 9 | 0 | device | Không | Đủ công |
| 16/06 | 08:38 | 18:20 | 582 | 1 | 0 | 0 | device | Không | Đủ công |
| 17/06 | 08:07 | 18:00 | 593 | 1 | 0 | 0 | device | Không | Đủ công |
| 18/06 | 08:38 | 18:19 | 581 | 1 | 0 | 0 | device | Không | Đủ công |
| 19/06 | 08:30 | 18:05 | 575 | 1 | 0 | 0 | manual | Có | Khớp runtime |
| 20/06 | 08:32 | 17:58 | 565 | 1 | 0 | 2 | device | Không | Đủ công |
| 21/06 | — | — | 0 | 0 | 0 | 0 | none | Không | Chủ nhật/ngày nghỉ |
| 22/06 | 08:46 | 18:01 | 555 | 1 | 7 | 0 | device | Không | Đủ công |
| 23/06 | 08:38 | 18:06 | 568 | 1 | 0 | 0 | device | Không | Đủ công |
| 24/06 | 08:30 | 18:09 | 579 | 1 | 0 | 0 | manual | Có | Khớp runtime |
| 25/06 | 08:31 | 18:37 | 606 | 1 | 0 | 0 | device | Không | Đủ công |
| 26/06 | 08:47 | 18:01 | 554 | 1 | 7 | 0 | device | Không | Đủ công |
| 27/06 | 08:33 | 18:17 | 583 | 1 | 0 | 0 | device | Không | Đủ công |
| 28/06 | — | — | 0 | 0 | 0 | 0 | none | Không | Chủ nhật/ngày nghỉ |
| 29/06 | 08:36 | 18:09 | 573 | 1 | 0 | 0 | device | Không | Đủ công |
| 30/06 | 08:30 | 18:03 | 573 | 1 | 0 | 0 | manual | Có | Khớp runtime |

Tổng snapshot: work records `25`, paid leave `0`, normal work units `25`, payroll total units `25`, chênh chuẩn `-1`. Không có duplicate record/schedule và không có schedule thiếu record. Không có attendance device log trong cửa sổ 31/05 18:00 → 02/06 06:00 cho nhân viên; record 01/06 là manual thuần túy.

## Rule và source audit

- `SalaryCalculationService` cộng `timekeeping_records.work_units`; `normal_work_units` không nhân hệ số ngày nghỉ/lễ. Paid leave được cộng riêng. `standard_working_days=26` chỉ là mẫu số; lương ngày công dùng `base_salary × totalUnits / standardWorkUnits`.
- `TimekeepingService::resolveFullDayMinutes()` dùng `attendance_standard_work_minutes` mặc định 480 và lấy min với độ dài ca. `worked_minutes >= fullDayMinutes` trả 1; 479 trả 0.5 theo rule hiện hành.
- Settings dump: half-work bật, max 480; late-half-day bật với threshold 180. Ngày 01/06 có late 0 nên rule đi muộn không tác động.
- `recalculateForRange()` skip record `manual_override=true`. Đây là guard đúng nghiệp vụ, không phải bug.
- Current source dùng `abs()` ở cả device và manual path. Commit liên quan: `39e60dc` (Carbon 3 signed diff) và `e94baf2` (standard work minutes). Dump không có bằng chứng deployment commit nào đã chạy lúc record 654 được tạo/cập nhật ngày 09/06, nên không suy đoán deployment lineage.
- Không có bằng chứng timezone, rounding giây, lịch ca, device mapping hay 479/480 gây ra chênh lệch này.

## Reproduce trên audit DB cô lập

1. Recalculate timekeeping riêng employee sau snapshot: `created=0`, `updated=26`; record 654 vẫn `-600/0` vì manual override, tổng vẫn 25. Observer đánh dấu `BL000011.needs_recalc=1` khi các record tự động được lưu lại.
2. Dry-run `timekeeping:audit-manual-work-units` đề xuất duy nhất cho record 654: `-600 → 600 phút`, `0 → 1 công`. Ba manual records còn lại khớp.
3. Chạy command với `--apply` **chỉ trên audit DB**: record 654 thành `600 phút/1 công`, vẫn giữ `manual_override=true`, tổng timekeeping thành 26.
4. Runtime sau sửa record: 26 công, base 8,000,000, total 8,490,020; payslip stored vẫn 25 cho tới khi recalculate.
5. Recalculate `BL000011` trên audit DB trả HTTP 200: payslip 66 thành 26 công, base 8,000,000, total/remaining 8,490,020; `details.normal_work_units=26`; paysheet total 23,257,266 → 23,564,958 và `needs_recalc=0`.

## Source fix

Files changed:

- `app/Http/Controllers/EmployeeWorkScheduleController.php`: trả thêm `work_units` trong relation API.
- `resources/js/Pages/Employees/Attendance.vue`: tooltip hiển thị vào/ra, phút, công, muộn/sớm, nguồn; badge `0`/`½`; modal phân biệt dữ liệu đang lưu với công dự kiến.
- `tests/Feature/Payroll/ManualTimekeepingTest.php`: contract test API trả cả `worked_minutes` và `work_units`.

Không sửa `TimekeepingService`, `SalaryCalculationService`, rule 480/479, standard working days, manual override, migration hay dữ liệu dump.

## Tests/build

Tests được chạy tuần tự trên MySQL test schema mới `kiot_hotfix_test` tại port 3319, không chạy trên audit DB. Lần thử chạy ba filter song song trên `sales_test` bị tranh chấp schema; kết quả đó bị loại bỏ. Không dùng `migrate:fresh`.

| Command | Kết quả |
|---|---|
| `php artisan test --filter=ManualTimekeepingTest` | PASS — 22 tests, 98 assertions |
| `php artisan test --filter=Payroll` | PASS — 128 tests, 751 assertions |
| `php artisan test --filter=Paysheet` | PASS — 44 tests, 373 assertions |
| `npm run build` | PASS — 921 modules transformed |
| `git diff --check` | PASS |

PHP CLI phát cảnh báo extension OCI/Firebird không được cài, nhưng không ảnh hưởng MySQL tests hoặc kết quả pass.

## Data safety và production action

```text
Database dump đã import vào Docker riêng: Có
Container: kiot-audit-db
Image: mariadb:10.11
Port: 127.0.0.1:3315
Database: kiot_audit
SHA256 dump: 80D003E6196FC9E6E56A2DB1E5CEEF8FF2AF2343F7DE852DCF8F01B1783FCFDC

Có dùng database production trực tiếp: Không
Có ghi dữ liệu production: Không
Có migration production/audit: Không
Có backfill production: Không
Có update dữ liệu cũ trên audit DB: Có, sau snapshot
Có recalculate audit DB: Có
Có recalculate production: Không
Có cần backup production: Có, nếu được duyệt apply
Có cần user xác nhận trước production update: Có
```

### Cần xác nhận trước khi triển khai

Nếu dump phản ánh đúng production hiện tại, action production dự kiến:

1. Chạy dry-run command cho đúng kỳ/employee và đối chiếu record ID thực tế; không hardcode ID từ dump nếu production khác.
2. Backup tối thiểu các record liên quan trong `timekeeping_records`, `paysheets`, `payslips`; ưu tiên snapshot DB đầy đủ trước thao tác.
3. Apply command chỉ cho manual record được duyệt. Giá trị dự kiến theo dump:
   - `timekeeping_records.id=654.worked_minutes`: `-600 → 600`.
   - `timekeeping_records.id=654.work_units`: `0 → 1`.
   - Giữ `manual_override=1`, `source=manual`, check-in/out và các field khác.
4. Xác minh observer đặt `paysheets.id=11.needs_recalc=1`.
5. Chỉ sau khi user xác nhận số liệu dry-run/preview, bấm Tính lại `BL000011`. Dự kiến payslip 66: work units `25 → 26`, base `7,692,308 → 8,000,000`, total/remaining `8,182,328 → 8,490,020`.

Rủi ro: recalculate bảng lương tính lại toàn bộ payslip trong bảng theo dữ liệu hiện tại và có thể thay đổi các component khác; phải chụp diff toàn bảng trước khi chấp nhận. Không chạy nếu bảng đã locked/cancelled hoặc production khác dump.

Rollback data nếu action đã được duyệt nhưng kết quả sai: restore row-level backup của record 654, payslip 66 và paysheet 11 trong transaction, rồi đối chiếu tổng; với thay đổi rộng hơn phải restore snapshot DB. Rollback code bằng revert commit hotfix UI/API. Container audit là disposable và có thể xóa cùng volume.

## Trạng thái

- Root cause dữ liệu: xác định chính xác.
- Source payroll hiện tại: không cần sửa thêm.
- Source API/UI: đã sửa tối thiểu để phản ánh số công lưu thực tế.
- Migration/backfill: không có.
- Có thể deploy code UI/API sau review và test pass.
- **Chưa được sửa/recalculate production. Cần user xác nhận dry-run và preview trước mọi data action production.**
