# HOTFIX 24.12B — Payroll Adjustment Override

## 1. Root cause

Trên `PaysheetEdit.vue`, popup **Phụ cấp / Thưởng / Giảm trừ** lưu mỗi dòng bằng N call (POST/PUT/DELETE) riêng lẻ. Khi user xoá hết các dòng → không còn `PayslipAdjustment` nào → `recalcSlipWithAdjustments()` fallback về giá trị auto từ `SalaryCalculationService` (tính từ `EmployeeSalarySetting`). Hệ quả: user nghĩ đã clear nhưng cột vẫn hiển thị số cũ. Bug ngữ nghĩa, không phải bug code path.

Nguyên nhân gốc: thiếu một **bit "user đã chọn override"**. Sự vắng mặt của dòng adjustment có thể nghĩa là (a) chưa từng đặt → dùng auto, hoặc (b) đã chủ động xoá → phải dùng 0. Không có cách nào phân biệt nếu không có cờ.

## 2. Fix

Lưu cờ `details.manual_overrides[type] = true` ngay trong JSON column `payslips.details` sẵn có (không cần migration). Khi cờ được set, code recalc dùng tổng adjustments (kể cả 0) làm giá trị cuối; khi không có cờ, fallback auto như cũ. OT giữ logic cũ (additive, không có cờ).

## 3. Backend

| File | Nội dung |
|---|---|
| `app/Http/Controllers/PaysheetController.php` | (a) `recalcSlipWithAdjustments()` đọc `details.manual_overrides` cho allowance/bonus/deduction; OT vẫn `autoOt + adjOt`. (b) NEW `bulkSaveAdjustments(Request, $id, $slipId, type)`: trong `DB::transaction` xoá toàn bộ rows theo type, re-insert items[], set cờ; sau transaction gọi `recalcSlipWithAdjustments()`. (c) NEW `resetDefaultAdjustments($id, $slipId, type)`: xoá rows + unset cờ + recalc. (d) `performRecalculation()` preserve `manual_overrides` qua các lần overwrite `$calc`. |
| `routes/api.php` | NEW `PUT /api/paysheets/{id}/payslips/{slipId}/adjustments/{type}/bulk`, NEW `POST /api/paysheets/{id}/payslips/{slipId}/adjustments/{type}/reset-default` |

**Không sửa:** migration (không thêm cột), `EmployeeSalarySetting`, `SalaryCalculationService`, các endpoint POST/PUT/DELETE per-row cũ (để tương thích lùi), payslip schema.

## 4. Frontend

| File | Nội dung |
|---|---|
| `resources/js/Pages/Employees/PaysheetEdit.vue` | (a) `deleteAdjustment()` chỉ splice local, bỏ `pendingDeletes` server-side. (b) `saveAdjustments()`: thay vòng lặp POST/PUT/DELETE bằng 1 call `PUT .../adjustments/{type}/bulk` với `{ items: [{ id, name, amount, notes, meta }] }`. Empty items array được phép. Sau response replace slip trong `localPaysheet.payslips` qua `splice(idx, 1, data.slip)`, fallback full reload nếu không tìm thấy slip. |

## 5. Formula sau fix

| Field | Logic |
|---|---|
| `allowances` | `manual_overrides.allowance` hoặc `count(adj allowance) > 0` → tổng adj. Ngược lại → auto từ service. |
| `bonus` | Cùng pattern. |
| `deductions` | Cùng pattern, **CỘNG `autoLatePenalty`** sau cùng (phạt đi muộn auto không bị override). |
| `ot_pay` | `autoOt + adjOt` (luôn additive — không có override flag). |
| `total_salary` | `base + bonus + commission + allowances + ot_pay - deductions` qua `recalculateTotals()`. |

## 6. Tests

`tests/Feature/Payroll/Step2412BPayrollAdjustmentOverrideTest.php` — 10 TC:

| TC | Mục đích |
|---|---|
| 01 | Empty bulk allowance → allowances = 0 + override flag set |
| 02 | Bulk thay thế hoàn toàn (không append) |
| 03 | reset-default xoá rows + clear flag |
| 04 | Empty bulk bonus → 0 + flag |
| 05 | Empty bulk deduction vẫn cộng late_penalty auto |
| 06 | OT bulk additive — không set flag, auto + manual cộng dồn |
| 07 | locked paysheet → 422 cho bulk |
| 08 | locked paysheet → 422 cho reset-default |
| 09 | bulk validate type ∈ {allowance,bonus,deduction,ot} → 422 nếu khác |
| 10 | Response chứa slip đã recompute đúng |

## 7. Production safety

| Mục | Trạng thái |
|---|---|
| Migration? | **Không** — flag nằm trong JSON column `payslips.details` đã có |
| Backfill? | Không cần — slip cũ không có flag → fallback auto (behaviour cũ) |
| Có sửa paysheet đã chốt không? | Không — cả 2 endpoint mới reject `status ∈ {locked, cancelled}` |
| Backend tự recalc không? | Có — `recalcSlipWithAdjustments()` chạy sau bulk/reset; FE không gửi `total_salary` |
| Endpoint cũ còn không? | Còn — POST/PUT/DELETE per-row giữ nguyên để tương thích lùi |
| Cross-module impact? | Không — không động đến POS/invoice/customer/task/cash-flow |

## 8. Manual QA

- [ ] Mở `/employees/paysheets/{id}/edit`, click "Phụ cấp" của 1 nhân viên có phụ cấp auto.
- [ ] Xoá toàn bộ dòng → "Xong" → cột "Phụ cấp" hiển thị `0 ₫`.
- [ ] Reload trang → vẫn `0 ₫` (persist OK).
- [ ] Mở lại popup → empty → "Thêm phụ cấp" → nhập 500,000 → "Xong" → cột = 500,000.
- [ ] Thưởng: làm tương tự, xoá hết → 0.
- [ ] Giảm trừ: xoá hết manual rows nhưng nếu có phạt đi muộn auto thì cột vẫn = số tiền phạt.
- [ ] OT: thêm 1 dòng manual 200,000 → cột OT = auto + 200,000 (additive).
- [ ] Chốt lương → popup disable nút "Xong" / API 422 nếu gọi trực tiếp.
- [ ] /pos, /invoices, /customers, /tasks không lỗi.

## 9. Conclusion

- **Đã giống KiotViet chưa:** Có — popup "Phụ cấp / Thưởng / Giảm trừ" giờ là semantic replace như expected; xoá hết = 0.
- **Production safety:** Cao — không migration, không backfill, không động endpoint cũ; flag là JSON additive trong column sẵn có.
- **Có thể deploy không:** Pending — chờ chạy test cluster trên MySQL local 3319. Build FE pass (`npm run build` 6.77s).
