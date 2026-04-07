<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Paysheet;
use App\Models\Payslip;
use App\Models\Employee;
use App\Models\EmployeeSalarySetting;
use App\Models\AttendanceLog;
use App\Models\EmployeeWorkSchedule;
use App\Models\TimekeepingRecord;
use App\Services\SalaryCalculationService;
use Carbon\Carbon;

class DiagnoseSalary extends Command
{
    protected $signature = 'salary:diagnose
        {--paysheet= : ID bảng lương cần chẩn đoán}
        {--employee= : ID nhân viên cụ thể}
        {--from= : Ngày bắt đầu (Y-m-d)}
        {--to= : Ngày kết thúc (Y-m-d)}';

    protected $description = 'Chẩn đoán chi tiết tính lương cho từng nhân viên - tìm nguyên nhân sai số';

    public function handle()
    {
        $paysheetId = $this->option('paysheet');
        $employeeId = $this->option('employee');
        $from = $this->option('from');
        $to = $this->option('to');

        // Nếu có paysheet, lấy thông tin từ đó
        if ($paysheetId) {
            return $this->diagnosePaysheet((int) $paysheetId);
        }

        // Nếu có employee + khoảng thời gian
        if ($employeeId && $from && $to) {
            return $this->diagnoseEmployee(
                (int) $employeeId,
                Carbon::parse($from),
                Carbon::parse($to)
            );
        }

        // Nếu chỉ có khoảng thời gian → tính tất cả NV
        if ($from && $to) {
            return $this->diagnoseAllEmployees(Carbon::parse($from), Carbon::parse($to));
        }

        // Mặc định: hiện danh sách paysheets gần nhất
        $this->showRecentPaysheets();
        return 0;
    }

    private function diagnosePaysheet(int $paysheetId): int
    {
        $paysheet = Paysheet::with(['payslips.employee:id,code,name', 'branch:id,name'])->find($paysheetId);
        if (!$paysheet) {
            $this->error("Không tìm thấy bảng lương ID: {$paysheetId}");
            return 1;
        }

        $this->info("╔══════════════════════════════════════════════════════════╗");
        $this->info("║  CHẨN ĐOÁN BẢNG LƯƠNG: {$paysheet->code}");
        $this->info("╚══════════════════════════════════════════════════════════╝");
        $this->newLine();

        $this->table(['Thuộc tính', 'Giá trị'], [
            ['Mã', $paysheet->code],
            ['Tên', $paysheet->name],
            ['Kỳ lương', $paysheet->period_start->format('d/m/Y') . ' → ' . $paysheet->period_end->format('d/m/Y')],
            ['Chi nhánh', $paysheet->branch?->name ?? 'Tất cả'],
            ['Trạng thái', $paysheet->status],
            ['Số NV', $paysheet->employee_count],
            ['Tổng lương (DB)', number_format($paysheet->total_salary) . 'đ'],
            ['Đã trả', number_format($paysheet->total_paid) . 'đ'],
            ['Còn lại', number_format($paysheet->total_remaining) . 'đ'],
        ]);

        $periodStart = Carbon::parse($paysheet->period_start);
        $periodEnd = Carbon::parse($paysheet->period_end);

        // Tính ngày công chuẩn
        $service = new SalaryCalculationService();
        $standardUnits = $this->callPrivateMethod($service, 'getStandardWorkUnits', [
            $paysheet->branch_id, $periodStart, $periodEnd,
        ]);
        $this->info("Ngày công chuẩn (kỳ này): {$standardUnits} ngày");
        $this->newLine();

        // ===== KIỂM TRA PIPELINE CHẤM CÔNG =====
        $this->info("═══ PIPELINE CHẤM CÔNG ═══");

        // 1. attendance_logs (raw từ máy)
        $totalLogs = AttendanceLog::whereBetween('punched_at', [
            $periodStart->copy()->startOfDay(),
            $periodEnd->copy()->endOfDay(),
        ])->count();
        $unmappedLogs = AttendanceLog::whereBetween('punched_at', [
            $periodStart->copy()->startOfDay(),
            $periodEnd->copy()->endOfDay(),
        ])->whereNull('employee_id')->count();
        $mappedLogs = $totalLogs - $unmappedLogs;

        // 2. employee_work_schedules
        $totalSchedules = EmployeeWorkSchedule::whereBetween('work_date', [$periodStart, $periodEnd])->count();

        // 3. timekeeping_records
        $totalTimekeeping = TimekeepingRecord::whereBetween('work_date', [$periodStart, $periodEnd])->count();
        $withCheckIn = TimekeepingRecord::whereBetween('work_date', [$periodStart, $periodEnd])
            ->whereNotNull('check_in_at')->count();

        $this->table(['Bước', 'Số lượng', 'Trạng thái'], [
            ['1. attendance_logs (máy chấm công)', $totalLogs, $totalLogs > 0 ? '✓' : '❌ TRỐNG!'],
            ['   → Đã map employee_id', $mappedLogs, $unmappedLogs > 0 ? "⚠ {$unmappedLogs} chưa map" : '✓'],
            ['2. employee_work_schedules (lịch làm)', $totalSchedules, $totalSchedules > 0 ? '✓' : '❌ TRỐNG!'],
            ['3. timekeeping_records (kết quả)', $totalTimekeeping, $totalTimekeeping > 0 ? '✓' : '❌ TRỐNG!'],
            ['   → Có check_in', $withCheckIn, ''],
        ]);

        if ($totalLogs > 0 && $totalSchedules == 0) {
            $this->error("❌ CÓ DỮ LIỆU MÁY CHẤM CÔNG nhưng KHÔNG CÓ LỊCH LÀM VIỆC!");
            $this->error("   → Cần tạo employee_work_schedules (lịch làm việc) cho NV trong kỳ này.");
            $this->error("   → Không có lịch → TimekeepingService không thể tạo timekeeping_records → lương = 0.");
        }
        if ($totalLogs > 0 && $unmappedLogs > 0) {
            $this->warn("⚠ Có {$unmappedLogs} attendance_logs chưa map employee_id!");
            $this->warn("  → Kiểm tra employees.attendance_code có khớp với device_user_id không.");
            $this->warn("  → Gọi API: POST /api/attendance-logs/refresh-mapping");
        }
        if ($totalSchedules > 0 && $totalTimekeeping == 0) {
            $this->error("❌ CÓ LỊCH LÀM VIỆC nhưng KHÔNG CÓ TIMEKEEPING RECORDS!");
            $this->error("   → Cần chạy recalculate: POST /api/timekeeping-records/recalculate");
            $this->error("   → Hoặc: POST /api/attendance-agent/force-recalculate?from={$periodStart->toDateString()}&to={$periodEnd->toDateString()}");
        }
        $this->newLine();

        // Kiểm tra tổng NV active vs NV trong paysheet
        $activeCount = Employee::where('is_active', true)
            ->when($paysheet->branch_id, fn($q) => $q->where('branch_id', $paysheet->branch_id))
            ->count();
        $slipCount = $paysheet->payslips->count();

        if ($slipCount < $activeCount) {
            $this->warn("⚠ CHÚ Ý: Chỉ có {$slipCount} phiếu lương nhưng có {$activeCount} NV active" .
                ($paysheet->branch_id ? " (chi nhánh {$paysheet->branch_id})" : "") . "!");
        }

        // Phân tích từng phiếu lương
        $this->info("═══ CHI TIẾT TỪNG NHÂN VIÊN ═══");
        $this->newLine();

        $issues = [];
        $totalRecalc = 0;
        $employeeRows = [];

        foreach ($paysheet->payslips as $slip) {
            $emp = $slip->employee;
            if (!$emp) {
                $issues[] = "Phiếu {$slip->code}: NV ID {$slip->employee_id} không tồn tại!";
                continue;
            }

            $result = $this->analyzeEmployee($emp, $periodStart, $periodEnd, $slip);
            $totalRecalc += $result['recalc_total'];
            $employeeRows[] = $result['row'];
            $issues = array_merge($issues, $result['issues']);
        }

        // Bảng tổng hợp
        $this->table(
            ['NV', 'Tên', 'Loại lương', 'Base Salary', 'Công TT', 'Công chuẩn', 'Base Calc', 'P.Cấp', 'Thưởng', 'Khấu trừ', 'OT Pay', 'Tổng', 'Vấn đề'],
            $employeeRows
        );

        $this->newLine();
        $this->info("═══ TỔNG KẾT ═══");
        $this->table(['Metric', 'Giá trị'], [
            ['Tổng lương trong DB', number_format($paysheet->total_salary) . 'đ'],
            ['Tổng tính lại', number_format($totalRecalc) . 'đ'],
            ['Chênh lệch', number_format($totalRecalc - $paysheet->total_salary) . 'đ'],
        ]);

        // In vấn đề
        if (!empty($issues)) {
            $this->newLine();
            $this->error("═══ CÁC VẤN ĐỀ PHÁT HIỆN ═══");
            foreach ($issues as $i => $issue) {
                $this->warn(($i + 1) . ". " . $issue);
            }
        } else {
            $this->info("✓ Không phát hiện vấn đề rõ ràng trong công thức.");
        }

        return 0;
    }

    private function diagnoseEmployee(int $employeeId, Carbon $from, Carbon $to): int
    {
        $employee = Employee::with('salarySetting')->find($employeeId);
        if (!$employee) {
            $this->error("Không tìm thấy nhân viên ID: {$employeeId}");
            return 1;
        }

        $this->info("╔══════════════════════════════════════════════════════════╗");
        $this->info("║  CHẨN ĐOÁN LƯƠNG NV: {$employee->code} - {$employee->name}");
        $this->info("║  Kỳ: {$from->format('d/m/Y')} → {$to->format('d/m/Y')}");
        $this->info("╚══════════════════════════════════════════════════════════╝");
        $this->newLine();

        $this->printDetailedEmployeeAnalysis($employee, $from, $to);
        return 0;
    }

    private function diagnoseAllEmployees(Carbon $from, Carbon $to): int
    {
        $employees = Employee::where('is_active', true)->with('salarySetting')->get();

        $this->info("╔══════════════════════════════════════════════════════════╗");
        $this->info("║  CHẨN ĐOÁN LƯƠNG TẤT CẢ NV ACTIVE");
        $this->info("║  Kỳ: {$from->format('d/m/Y')} → {$to->format('d/m/Y')}");
        $this->info("╚══════════════════════════════════════════════════════════╝");

        $issues = [];
        $rows = [];
        $grandTotal = 0;

        foreach ($employees as $emp) {
            $result = $this->analyzeEmployee($emp, $from, $to, null);
            $grandTotal += $result['recalc_total'];
            $rows[] = $result['row'];
            $issues = array_merge($issues, $result['issues']);
        }

        $this->table(
            ['NV', 'Tên', 'Loại lương', 'Base Salary', 'Công TT', 'Công chuẩn', 'Base Calc', 'P.Cấp', 'Thưởng', 'Khấu trừ', 'OT Pay', 'Tổng', 'Vấn đề'],
            $rows
        );

        $this->newLine();
        $this->info("Tổng lương tất cả NV: " . number_format($grandTotal) . "đ");

        if (!empty($issues)) {
            $this->newLine();
            $this->error("═══ CÁC VẤN ĐỀ PHÁT HIỆN ({$this->countUniqueIssues($issues)}) ═══");
            foreach ($issues as $i => $issue) {
                $this->warn(($i + 1) . ". " . $issue);
            }
        }

        return 0;
    }

    private function analyzeEmployee(Employee $employee, Carbon $from, Carbon $to, ?Payslip $existingSlip): array
    {
        $issues = [];
        $setting = $employee->salarySetting;
        $empLabel = "{$employee->code} ({$employee->name})";

        // Check 1: Thiếu salary setting
        if (!$setting) {
            $issues[] = "[{$empLabel}] KHÔNG CÓ EmployeeSalarySetting → lương = 0!";
            return [
                'row' => [
                    $employee->code, $employee->name, '❌ N/A', '0', '0', '-', '0', '0', '0', '0', '0', '0', 'NO SETTING'
                ],
                'recalc_total' => 0,
                'issues' => $issues,
            ];
        }

        // Check 2: base_salary = 0
        if ($setting->base_salary <= 0) {
            $issues[] = "[{$empLabel}] base_salary = {$setting->base_salary} (bằng 0 hoặc âm)!";
        }

        // Check 3: Hourly rate sanity
        if ($setting->salary_type === 'hourly' && $setting->base_salary > 500000) {
            $issues[] = "[{$empLabel}] Lương giờ nhưng base_salary = " . number_format($setting->base_salary) .
                "đ (có thể nhập nhầm lương tháng?)";
        }

        // Tính lương
        $calc = $employee->calculateSalaryForRange($from, $to);

        // Check 4: Không có dữ liệu chấm công
        if ($calc['work_units'] == 0 && $calc['paid_leave_units'] == 0) {
            // Kiểm tra sâu hơn: xem pipeline bị đứt ở đâu
            $hasLogs = AttendanceLog::where('employee_id', $employee->id)
                ->whereBetween('punched_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->exists();
            $hasSchedules = EmployeeWorkSchedule::where('employee_id', $employee->id)
                ->whereBetween('work_date', [$from, $to])
                ->exists();

            if (!$hasSchedules && $hasLogs) {
                $issues[] = "[{$empLabel}] Có attendance_logs nhưng KHÔNG CÓ LỊCH LÀM VIỆC (employee_work_schedules) → timekeeping_records không tạo được!";
            } elseif (!$hasSchedules && !$hasLogs) {
                $issues[] = "[{$empLabel}] Không có attendance_logs VÀ không có lịch làm việc → lương = 0!";
            } elseif ($hasSchedules && !$hasLogs) {
                $issues[] = "[{$empLabel}] Có lịch nhưng không có attendance_logs (máy chấm công chưa map?) → work_units = 0!";
            } else {
                $issues[] = "[{$empLabel}] work_units = 0 dù có cả logs + lịch → Cần recalculate timekeeping!";
            }
        }

        // Check 5: OT có nhưng ot_pay = 0
        if ($calc['ot_minutes'] > 0) {
            $otPayExpected = $this->estimateOtPay($setting, $calc);
            if ($otPayExpected > 0) {
                $issues[] = "[{$empLabel}] Có {$calc['ot_minutes']} phút OT nhưng ot_pay = 0 (ước tính nên = " .
                    number_format($otPayExpected) . "đ) → BUG: OT Pay chưa được tính!";
            }
        }

        // Check 6: Has flags = false → check if template/custom data exists
        if (!($setting->has_allowance ?? false) && !empty($setting->custom_allowances)) {
            $issues[] = "[{$empLabel}] has_allowance = false nhưng có custom_allowances → phụ cấp KHÔNG được tính!";
        }
        if (!($setting->has_bonus ?? false) && !empty($setting->custom_bonuses)) {
            $issues[] = "[{$empLabel}] has_bonus = false nhưng có custom_bonuses → thưởng KHÔNG được tính!";
        }

        // Check 7: So sánh với phiếu lương existing
        $deltaNote = '';
        if ($existingSlip && abs($existingSlip->total_salary - $calc['total']) > 1) {
            $deltaNote = 'Δ=' . number_format($calc['total'] - $existingSlip->total_salary);
            $issues[] = "[{$empLabel}] Phiếu lương cũ = " . number_format($existingSlip->total_salary) .
                "đ, tính lại = " . number_format($calc['total']) . "đ (chênh: {$deltaNote}đ)";
        }

        $typeLabel = match ($setting->salary_type) {
            'hourly' => 'Giờ',
            'by_workday' => 'Ngày công',
            'fixed' => 'Cố định',
            default => $setting->salary_type ?? '?',
        };

        $issueCount = count($issues);
        $issueLabel = $issueCount > 0 ? "⚠ {$issueCount}" : '✓';
        if ($deltaNote) $issueLabel .= " {$deltaNote}";

        return [
            'row' => [
                $employee->code,
                mb_substr($employee->name, 0, 15),
                $typeLabel,
                number_format($setting->base_salary),
                $calc['work_units'],
                $calc['standard_work_units'],
                number_format($calc['base']),
                number_format($calc['allowances']),
                number_format($calc['bonus'] ?? 0),
                number_format($calc['deductions']),
                number_format($calc['ot_pay'] ?? 0),
                number_format($calc['total']),
                $issueLabel,
            ],
            'recalc_total' => $calc['total'],
            'issues' => $issues,
        ];
    }

    private function printDetailedEmployeeAnalysis(Employee $employee, Carbon $from, Carbon $to): void
    {
        $setting = $employee->salarySetting;

        // Salary Setting
        $this->info("── CẤU HÌNH LƯƠNG ──");
        if (!$setting) {
            $this->error("❌ KHÔNG CÓ EmployeeSalarySetting! Lương sẽ = 0.");
            return;
        }

        $this->table(['Field', 'Value'], [
            ['salary_type', $setting->salary_type ?? 'NULL'],
            ['base_salary', number_format($setting->base_salary ?? 0) . 'đ'],
            ['salary_template_id', $setting->salary_template_id ?? 'NULL'],
            ['has_overtime', $setting->has_overtime ? 'Yes' : 'No'],
            ['overtime_rate', ($setting->overtime_rate ?? 150) . '%'],
            ['holiday_rate', ($setting->holiday_rate ?? 200) . '%'],
            ['has_bonus', $setting->has_bonus ? 'Yes' : 'No'],
            ['has_commission', $setting->has_commission ? 'Yes' : 'No'],
            ['has_allowance', $setting->has_allowance ? 'Yes' : 'No'],
            ['has_deduction', $setting->has_deduction ? 'Yes' : 'No'],
        ]);

        // Timekeeping records
        $this->newLine();
        $this->info("── DỮ LIỆU CHẤM CÔNG ──");

        // Kiểm tra pipeline cho NV cụ thể
        $empLogs = AttendanceLog::where('employee_id', $employee->id)
            ->whereBetween('punched_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->count();
        $empSchedules = EmployeeWorkSchedule::where('employee_id', $employee->id)
            ->whereBetween('work_date', [$from, $to])
            ->count();

        $this->table(['Pipeline', 'Số lượng'], [
            ['attendance_logs (máy chấm công)', $empLogs],
            ['employee_work_schedules (lịch)', $empSchedules],
        ]);

        if ($empLogs > 0 && $empSchedules == 0) {
            $this->error("❌ CÓ {$empLogs} logs từ máy chấm công nhưng KHÔNG CÓ LỊCH LÀM VIỆC → Không thể tạo timekeeping_records!");
        }
        if ($empLogs == 0) {
            // Kiểm tra xem có log chưa map không
            $unmappedForEmployee = AttendanceLog::whereNull('employee_id')
                ->whereBetween('punched_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->count();
            if ($unmappedForEmployee > 0) {
                $this->warn("⚠ Có {$unmappedForEmployee} attendance_logs chưa map employee_id (có thể của NV này)");
                $this->warn("  attendance_code NV: " . ($employee->attendance_code ?? 'CHƯA CÀI'));
            } else {
                $this->warn("⚠ Không có attendance_logs nào cho NV này trong kỳ.");
            }
        }
        $this->newLine();

        $records = $employee->timekeepingRecords()
            ->whereBetween('work_date', [$from, $to])
            ->orderBy('work_date')
            ->get();

        $this->line("Tổng bản ghi: {$records->count()}");
        $this->line("work (đi làm): " . $records->where('attendance_type', 'work')->count() . " ngày, tổng work_units = " . $records->where('attendance_type', 'work')->sum('work_units'));
        $this->line("leave_paid: " . $records->where('attendance_type', 'leave_paid')->count() . " ngày, tổng work_units = " . $records->where('attendance_type', 'leave_paid')->sum('work_units'));
        $this->line("leave_unpaid: " . $records->where('attendance_type', 'leave_unpaid')->count() . " ngày");
        $this->line("ot_minutes tổng: " . $records->sum('ot_minutes'));
        $this->line("Đi trễ: " . $records->where('late_minutes', '>', 0)->count() . " lần, tổng " . $records->sum('late_minutes') . " phút");

        if ($records->count() <= 35) {
            $dayRows = $records->map(fn($r) => [
                $r->work_date,
                $r->attendance_type,
                $r->work_units,
                $r->check_in_at ? Carbon::parse($r->check_in_at)->format('H:i') : '-',
                $r->check_out_at ? Carbon::parse($r->check_out_at)->format('H:i') : '-',
                $r->worked_minutes ?? 0,
                $r->late_minutes ?? 0,
                $r->ot_minutes ?? 0,
                $r->is_holiday ? 'Lễ(' . ($r->holiday_multiplier ?? 1) . 'x)' : '',
            ])->toArray();

            $this->table(['Ngày', 'Loại', 'Công', 'Vào', 'Ra', 'Làm(ph)', 'Trễ(ph)', 'OT(ph)', 'Ghi chú'], $dayRows);
        }

        // Tính lương
        $this->newLine();
        $this->info("── KẾT QUẢ TÍNH LƯƠNG ──");
        $calc = $employee->calculateSalaryForRange($from, $to);

        $this->table(['Thành phần', 'Giá trị'], [
            ['Lương gốc (cài đặt)', number_format($calc['base_salary_full'] ?? $setting->base_salary) . 'đ'],
            ['Ngày công chuẩn', $calc['standard_work_units']],
            ['Ngày công thực tế', $calc['work_units']],
            ['Ngày nghỉ có lương', $calc['paid_leave_units']],
            ['Lương cơ bản (đã tính)', number_format($calc['base']) . 'đ'],
            ['Thưởng', number_format($calc['bonus'] ?? 0) . 'đ'],
            ['Hoa hồng', number_format($calc['commission'] ?? 0) . 'đ'],
            ['Phụ cấp', number_format($calc['allowances']) . 'đ'],
            ['Khấu trừ', number_format($calc['deductions']) . 'đ'],
            ['OT phút', $calc['ot_minutes']],
            ['OT Pay (hiện tại)', number_format($calc['ot_pay'] ?? 0) . 'đ'],
            ['Phạt đi trễ', number_format($calc['late_penalty'] ?? 0) . 'đ'],
            ['TỔNG LƯƠNG', number_format($calc['total']) . 'đ'],
        ]);

        // Giải thích công thức
        $this->newLine();
        $this->info("── GIẢI THÍCH CÔNG THỨC ──");
        $totalUnits = $calc['work_units'];
        $stdUnits = $calc['standard_work_units'];

        if ($setting->salary_type === 'hourly') {
            $this->line("Loại: LƯƠNG GIỜ");
            $this->line("base = totalUnits × 8h × hourly_rate");
            $this->line("     = {$totalUnits} × 8 × " . number_format($setting->base_salary));
            $this->line("     = " . number_format($totalUnits * 8 * $setting->base_salary) . "đ");
        } elseif ($setting->salary_type === 'by_workday') {
            $this->line("Loại: LƯƠNG NGÀY CÔNG CHUẨN");
            $this->line("base = base_salary × totalUnits / standardWorkUnits");
            $this->line("     = " . number_format($setting->base_salary) . " × {$totalUnits} / {$stdUnits}");
            if ($stdUnits > 0) {
                $this->line("     = " . number_format($setting->base_salary * $totalUnits / $stdUnits) . "đ");
            }
        } else {
            $this->line("Loại: LƯƠNG CỐ ĐỊNH");
            $this->line("base = " . number_format($setting->base_salary) . "đ (không đổi theo chấm công)");
        }

        $this->line("total = base + bonus + commission + allowances - deductions");
        $this->line("      = " . number_format($calc['base']) . " + " . number_format($calc['bonus'] ?? 0) .
            " + " . number_format($calc['commission'] ?? 0) . " + " . number_format($calc['allowances']) .
            " - " . number_format($calc['deductions']));
        $this->line("      = " . number_format($calc['total']) . "đ");

        // Ước tính OT pay (nếu có)
        if ($calc['ot_minutes'] > 0) {
            $otEst = $this->estimateOtPay($setting, $calc);
            $this->newLine();
            $this->warn("⚠ OT Pay ước tính (nếu được implement): " . number_format($otEst) . "đ");
            $this->warn("  Công thức: ot_minutes / 60 × base_hourly_rate × overtime_rate%");
            $this->warn("  = {$calc['ot_minutes']} / 60 × hourly_rate × " . ($setting->overtime_rate ?? 150) . "%");
        }

        // Chi tiết phụ cấp/thưởng/khấu trừ
        if (!empty($calc['details'])) {
            $details = $calc['details'];
            foreach (['allowances' => 'PHỤ CẤP', 'bonus' => 'THƯỞNG', 'commission' => 'HOA HỒNG', 'deductions' => 'KHẤU TRỪ', 'late_penalty' => 'PHẠT TRỄ'] as $key => $label) {
                if (!empty($details[$key])) {
                    $this->newLine();
                    $this->info("── CHI TIẾT {$label} ──");
                    foreach ($details[$key] as $item) {
                        $this->line("  • " . ($item['name'] ?? $item['category'] ?? $key) .
                            ": " . number_format($item['calculated'] ?? $item['penalty'] ?? 0) . "đ" .
                            (isset($item['type']) ? " ({$item['type']})" : '') .
                            (isset($item['occurrences']) && $item['occurrences'] > 0 ? " × {$item['occurrences']} lần" : ''));
                    }
                }
            }
        }
    }

    private function estimateOtPay(EmployeeSalarySetting $setting, array $calc): float
    {
        if (($calc['ot_minutes'] ?? 0) <= 0) return 0;

        $overtimeRate = ($setting->overtime_rate ?? 150) / 100;
        $stdHours = 8;

        if ($setting->salary_type === 'hourly') {
            $hourlyRate = $setting->base_salary;
        } else {
            $stdUnits = $calc['standard_work_units'] ?? 26;
            $hourlyRate = $stdUnits > 0 ? $setting->base_salary / $stdUnits / $stdHours : 0;
        }

        return ($calc['ot_minutes'] / 60) * $hourlyRate * $overtimeRate;
    }

    private function showRecentPaysheets(): void
    {
        $paysheets = Paysheet::orderByDesc('id')->limit(10)->get();

        if ($paysheets->isEmpty()) {
            $this->warn("Không có bảng lương nào. Sử dụng: php artisan salary:diagnose --from=2026-03-01 --to=2026-03-31");
            return;
        }

        $this->info("Bảng lương gần nhất (dùng --paysheet=ID để chẩn đoán):");
        $this->table(
            ['ID', 'Mã', 'Kỳ lương', 'Tổng lương', 'NV', 'Trạng thái'],
            $paysheets->map(fn($p) => [
                $p->id,
                $p->code,
                ($p->period_start ? Carbon::parse($p->period_start)->format('d/m/Y') : '?') . ' → ' .
                ($p->period_end ? Carbon::parse($p->period_end)->format('d/m/Y') : '?'),
                number_format($p->total_salary) . 'đ',
                $p->employee_count,
                $p->status,
            ])->toArray()
        );
    }

    private function countUniqueIssues(array $issues): int
    {
        return count($issues);
    }

    private function callPrivateMethod(object $obj, string $method, array $args)
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }
}
