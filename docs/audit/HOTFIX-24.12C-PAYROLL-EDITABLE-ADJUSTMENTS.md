# HOTFIX 24.12C — Payroll Editable Adjustments

## Root cause

- Commission popup chỉ hiển thị các dòng auto từ doanh thu — không có UI thêm/sửa/xóa và không có endpoint backend, trong khi allowance / bonus / deduction đã editable từ 24.12B.
- Khi user xóa hết giảm trừ thủ công, recalc **vẫn cộng `late_penalty`** từ `details.late_penalty` → user nghĩ "đã về 0" nhưng dòng deduction vẫn có số. Spec §C Mode 1 nói rõ: override = số cuối, không tự cộng late_penalty.
- `performRecalculation()` (chạy khi đổi Ngày công chuẩn) ghi đè `slip->commission` về `$calc['commission']` — manual override commission bị mất ngay khi user bấm Tính lại.

## Policy (cập nhật từ 24.12B)

| Type | Auto source | Manual behavior | Reset default | Override → 0 |
|---|---|---|---|---|
| commission | salary setting / template + doanh thu cá nhân | **replace** auto (24.12C: mới editable) | ✓ | ✓ |
| bonus | salary setting / template | replace auto | ✓ | ✓ |
| allowance | salary setting / template | replace auto | ✓ | ✓ |
| deduction | salary setting / template + PayrollSetting.late_penalty | replace auto, **late_penalty ALSO wiped khi override** (24.12C đổi từ 24.12B) | ✓ → khôi phục cả late_penalty | ✓ |
| ot | timekeeping auto | additive (không có flag) | xóa rows | n/a |

## Files changed

| File | Nội dung |
|---|---|
| `app/Http/Controllers/PaysheetController.php` | (a) `bulkSaveAdjustments` + `resetDefaultAdjustments` whitelist thêm `commission`. (b) `recalcSlipWithAdjustments` thêm `commissionOverride` branch; deduction override không cộng `late_penalty` nữa. (c) `performRecalculation` thêm `commissionOverride` (giữ qua mỗi lần đổi standard_working_days); deduction override path không add `autoLatePenalty`. |
| `resources/js/Pages/Employees/PaysheetEdit.vue` | (a) Commission popup chuyển sang pattern editable (auto block read-only + manual rows + nút "+ Thêm hoa hồng" + nút "Khôi phục mặc định"). (b) `isOverridden(type)` + `resetAdjustments()` helper. (c) `popupTotal` cho commission tính từ `popupAdjustments`. (d) Reset button thêm cho cả allowance/bonus/deduction khi `isOverridden(type)`. |
| `tests/Feature/Payroll/Step2412CPayrollEditableAdjustmentsTest.php` | NEW — 12 TC theo spec §I. |
| `tests/Feature/Payroll/Step2412BPayrollAdjustmentOverrideTest.php` | Update TC-05 theo policy mới: empty deduction = 0 (không cộng late_penalty). |

**Không sửa:** `SalaryCalculationService`, `PayrollSetting`, `SalaryTemplate`, `EmployeeSalarySetting`, schema, migrations, POS/Invoice/Customer/Supplier/Task code.

## Tests

`tests/Feature/Payroll/Step2412CPayrollEditableAdjustmentsTest.php` — 12/12 PASS / 45 assertions trên MySQL:3319 thực:

| TC | Mục đích | Kết quả |
|---|---|---|
| 01 | Commission override custom rows → slip.commission = 500k, total_salary giảm đúng | ✓ |
| 02 | Commission empty bulk → slip.commission = 0, manual_overrides.commission = true | ✓ |
| 03 | Allowance empty bulk → allowance = 0 | ✓ |
| 04 | Bonus empty bulk → bonus = 0 | ✓ |
| 05 | Deduction empty bulk → 0 dù `details.late_penalty` có 90k (late_penalty bị wipe) | ✓ |
| 06 | Reset default deduction → khôi phục auto từ `details.deductions` | ✓ |
| 07 | Reset default commission → khôi phục 1m auto | ✓ |
| 08 | Sau khi đổi standard_working_days, override allowance + commission vẫn còn | ✓ |
| 09 | Locked paysheet → bulk + reset đều 422 | ✓ |
| 10 | OT additive: auto 200k + manual 100k = 300k; empty bulk → 200k (no override flag) | ✓ |
| 11 | Backend không tin field `total_salary` từ FE, tự recompute | ✓ |
| 12 | Sau khi save adjustment, `paysheet.total_salary` cập nhật đúng | ✓ |

Regression đầy đủ trên MySQL:3319 thực:

| Suite | Kết quả |
|---|---|
| `Step2412C\|PayrollEditableAdjustments\|Paysheet\|PayslipAdjustment` | 17 PASS / 57 assertions |
| `Step2412\|PayrollStandardWorkingDays\|PayrollAdjustmentOverride\|Salary\|Employee\|Attendance` | 30 PASS / 98 assertions |
| `POS\|Invoice\|Customer\|Supplier\|Task\|Permission` | 236 PASS, 2 skipped / 1053 assertions |

## Production safety

| Mục | Trạng thái |
|---|---|
| Có migration không? | **Không** — `manual_overrides.commission` nằm trong JSON column `payslips.details` sẵn có |
| Có sửa setting gốc không? | **Không** — không động `SalaryTemplate`, `EmployeeSalarySetting`, `PayrollSetting` ở mọi nhánh code |
| Override survive recalc không? | **Có** — `performRecalculation` merge `manual_overrides` cũ vào `$calc` trước khi apply |
| Deduction về 0 được không? | **Có** — TC-05 confirm, kể cả khi `late_penalty` > 0 |
| Locked paysheet bị chặn không? | **Có** — TC-09 confirm 422 cho cả bulk + reset |
| Có ảnh hưởng POS / Invoice / Customer / Supplier / Task không? | **Không** — 236 PASS regression |

## Conclusion

- **Hoa hồng thêm/sửa/xóa được chưa:** Có — popup editable hoàn toàn, có auto block read-only + manual rows editable + nút "+ Thêm hoa hồng" + Reset.
- **Phụ cấp thêm/sửa/xóa được chưa:** Có (giữ nguyên 24.12B, thêm nút Reset).
- **Thưởng thêm/sửa/xóa được chưa:** Có (giữ nguyên 24.12B, thêm nút Reset).
- **Giảm trừ về 0 được chưa:** Có — kể cả khi có `late_penalty` auto, override wipe sạch (24.12C policy change vs. 24.12B).
- **Có an toàn production không:** Có — không migration, không động setting gốc, override-aware preserve qua recalc, locked sheets đều chặn.
