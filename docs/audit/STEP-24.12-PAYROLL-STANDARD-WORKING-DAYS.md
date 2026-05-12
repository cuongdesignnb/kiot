# STEP 24.12 — Payroll Standard Working Days

## 1. Root cause

- Form "Cập nhật bảng tính lương" trên KiotViet có right side panel với field **Ngày công chuẩn** mà user có thể chỉnh (26 → 25, …). Khi thay đổi, mọi dòng lương trong bảng tự tính lại theo mẫu số mới.
- Hệ thống mình: chỉ tính `standardWorkUnits` từ `WorkdaySetting + Holiday` mỗi lần `SalaryCalculationService::calculateForEmployee()` chạy — **không lưu trên paysheet, không cho user override**. Mặt khác layout `PaysheetEdit.vue` chưa có panel phải; thông tin paysheet rải rác ở header + summary bar.

## 2. KiotViet reference

| Thành phần | KiotViet | Hệ thống đã làm |
|---|---|---|
| Right panel hiển thị thông tin bảng lương | ✓ | ✓ collapsible aside `w-80` |
| Collapse/expand arrow ở mép panel | ✓ | ✓ nút mũi tên xanh, click toggle `panelCollapsed` |
| Standard working days field | ✓ chỉnh được | ✓ `<input type="number" min=1 max=31 step=0.5>` |
| Recalculate salary khi đổi ngày công chuẩn | ✓ | ✓ Lưu tạm → backend recompute toàn bộ payslips |
| Save draft (Lưu tạm) | ✓ | ✓ button trong panel |
| Finalize payroll (Chốt lương) | ✓ | ✓ button có sẵn (giữ nguyên endpoint cũ) |

## 3. Formula

| Field | Formula |
|---|---|
| `standardWorkUnits` (denominator) | `paysheet.standard_working_days` nếu set, fallback `getStandardWorkUnits(branch, from, to)` từ `WorkdaySetting + Holiday` |
| `salary_main` | `SalaryCalculationService` công thức hiện có — nhưng dùng denominator override |
| `total_salary` (payslip) | `base + bonus + commission + allowances + ot_pay − deductions` |
| `total_salary` (paysheet) | `sum(payslips.total_salary)` qua `recalculateTotals()` |

## 4. Backend

| File | Nội dung |
|---|---|
| `database/migrations/2026_05_12_100000_add_standard_working_days_to_paysheets_table.php` | NEW — `paysheets.standard_working_days decimal(5,2) nullable`, idempotent (`Schema::hasColumn`), không backfill |
| `app/Models/Paysheet.php` | Add `standard_working_days` to `$fillable` + cast `float` |
| `app/Services/SalaryCalculationService.php` | `calculateForEmployee(...$standardWorkingDaysOverride = null)`: nếu override > 0, dùng làm `standardWorkUnits`, fallback `getStandardWorkUnits()`. NEW public wrapper `standardWorkingDaysForBranch(branchId, from, to)` cho controller seed default. |
| `app/Http/Controllers/PaysheetController.php` | (a) `store()`: tính `calendarStandard` từ service và persist `standard_working_days` ngay khi tạo paysheet. (b) `performRecalculation()`: pass `$paysheet->standard_working_days` qua service. (c) NEW `updateStandardWorkingDays(Request, $id)` validate `numeric|min:1|max:31`, refuse locked/cancelled, update `standard_working_days + name + notes`, gọi `performRecalculation()`, trả lại sheet đã recompute. |
| `routes/api.php` | NEW `PUT /api/paysheets/{id}/standard-working-days` |

**Không sửa:** `getStandardWorkUnits()` (giữ logic calendar cũ), `WorkdaySetting`, `TimekeepingService`, payslip schema, `Payslip` model, lock/cancel/pay endpoints, CashFlow integration, POS, invoices.

## 5. Frontend

| File | Nội dung |
|---|---|
| `resources/js/Pages/Employees/PaysheetEdit.vue` | (a) Wrap main table + new side panel trong flex row `<div class="flex-1 flex overflow-hidden">`. (b) `<aside class="w-80">` aside chứa: Người tạo / Mã / Tên (input) / Kỳ hạn trả / Kỳ làm việc / **Ngày công chuẩn** (input number 1–31 step 0.5) / Trạng thái / Chi nhánh / Ghi chú (textarea) / nút "Lưu tạm" + "Chốt lương". (c) Collapse: khi `panelCollapsed=true`, aside ẩn, hiển thị nút mũi tên trái mép phải để mở lại. (d) State `panelForm` + `savePanelDraft()` gọi `PUT /api/paysheets/{id}/standard-working-days`, replace `localPaysheet` với data trả về (backend recomputed). |

## 6. Tests

| Test | Mục đích |
|---|---|
| TC-01 `payroll_has_standard_working_days_column` | Persist OK |
| TC-02 `standard_working_days_endpoint_persists_value` | PUT endpoint update đúng |
| TC-03 `standard_working_days_validation_rejects_out_of_range` | 0, 32, -1 đều 422; value gốc không đổi |
| TC-04 `cannot_update_standard_working_days_when_paysheet_locked` | locked → 422 |
| TC-05 `endpoint_also_accepts_name_and_notes_optional` | name + notes có thể update cùng |
| TC-06 `salary_calculation_service_uses_override_as_denominator` | Service nhận override; `standard_work_units` trong result = override |
| TC-07 `endpoint_returns_recomputed_paysheet_data` | Response chứa data đã recompute |

## 7. Production safety

| Mục | Trạng thái |
|---|---|
| Có migration không? | **Có** — 1 cột nullable `decimal(5,2)` thêm vào `paysheets`, idempotent |
| Có backfill không? | **Không** — legacy rows null → fallback `getStandardWorkUnits()` (logic cũ) |
| Có sửa payroll finalized không? | **Không** — endpoint refuse `status ∈ {locked, cancelled}` |
| Backend tự recalc không? | **Có** — `performRecalculation()` được gọi sau khi update; FE-sent `net_pay`/`total_salary` không được dùng |
| Có ảnh hưởng POS/invoice/customer/task không? | **Không** |

## 8. Manual QA

- [ ] Mở `/employees/paysheets/{id}/edit` → right panel xuất hiện bên phải.
- [ ] Bấm mũi tên ở mép aside → panel thu lại, hiển thị 1 nút mũi tên ngược để mở.
- [ ] Field "Ngày công chuẩn" hiện default từ calendar (ví dụ 27 trong tháng 4/2026).
- [ ] Đổi sang 26 → "Lưu tạm" → toast/notification + bảng lương cập nhật.
- [ ] Đổi sang 25 → "Lưu tạm" → lương chính các nhân viên giảm/tăng tương ứng.
- [ ] Đổi sang 0 → backend trả 422 "phải trong 1–31".
- [ ] Đổi sang 32 → 422.
- [ ] Sau "Chốt lương" → field bị disabled, save trả 422.
- [ ] Reload trang → standard_working_days persist đúng.
- [ ] /pos, /invoices, /customers, /tasks không lỗi.

## 9. Conclusion

- **Đã giống KiotViet chưa:** Có cho phần right panel + standard_working_days editable + auto-recalc. Backlog 24.12B nếu muốn:
  - Per-line `salary_main_manual` flag (giữ dòng đã sửa tay khi recalc)
  - Recalc preview ngay trên FE trước khi gọi backend (live preview while typing)
- **Có an toàn production không:** Có — migration nullable, legacy compat preserved, locked sheets refuse, backend là source of truth.
- **Có thể deploy không:** Pending — chờ MySQL local 3319 khôi phục để chạy test cluster. Code đã build pass (`npm run build` 9.52s), migration ready, test file gồm 7 cases sẵn sàng.

## 10. STEP 24.12-FIX — effective_standard_working_days + no-26-fallback

Sau 24.12, còn 2 rủi ro thực tế chưa khoá:

1. Frontend đang fallback hardcoded `26` khi `standard_working_days` null → bảng lương cũ có thể bị persist sai 26 khi user Lưu tạm, mặc dù calendar thực tế là 25 hoặc 27.
2. Báo cáo chưa xác nhận test DB chạy thật (MySQL 3319 vẫn DOWN).

Fix:
- **Backend:** `PaysheetController` thêm helper `withEffectiveStandard(Paysheet)` inject `effective_standard_working_days` vào response của `show()` và `edit()`. Logic: `paysheet.standard_working_days` nếu set, fallback `SalaryCalculationService::standardWorkingDaysForBranch()`. **Không persist khi chỉ mở form** — chỉ thêm vào response payload.
- **Frontend:** `PaysheetEdit.vue` đổi `initialStandard` từ hardcoded `26` thành chain `paysheet.standard_working_days ?? effective_standard_working_days ?? payslips[0].details.standard_work_units ?? 0`. Khi `= 0`, `savePanelDraft()` từ chối submit với message "Ngày công chuẩn phải nằm trong khoảng 1–31. Vui lòng kiểm tra cấu hình lịch làm việc / chi nhánh."
- **Tests bổ sung:**
  - TC `legacy_paysheet_null_standard_days_returns_effective_calendar_value`: paysheet null → GET `/api/paysheets/{id}` trả `data.effective_standard_working_days > 0`; DB `standard_working_days` vẫn null sau khi mở form (không bị side-effect).

| Mục | Trạng thái |
|---|---|
| Backend effective field | ✓ inject vào show() + edit() |
| FE 26 fallback removed | ✓ — chain 4 cấp; 0 → block submit |
| Live preview | ⏸ defer 24.12B; UI hint "Khi đổi và Lưu, hệ thống sẽ tính lại..." |
| Test DB chạy thật | ⏸ vẫn block bởi MySQL 3319 DOWN |
| Legacy paysheets không bị persist 26 | ✓ — backend không persist khi chỉ mở; FE từ chối submit khi initial = 0 |
