<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Paysheet;
use App\Models\Employee;
use App\Models\TimekeepingRecord;
use App\Services\TimekeepingService;
use Carbon\Carbon;

class RecalculatePaysheet extends Command
{
    protected $signature = 'salary:recalculate
        {--paysheet= : ID bảng lương cần tính lại}
        {--all : Tính lại TẤT CẢ bảng lương chưa chốt}';

    protected $description = 'Recalculate timekeeping + salary cho bảng lương (cập nhật thật vào DB)';

    public function handle()
    {
        $paysheetId = $this->option('paysheet');
        $all = $this->option('all');

        if (!$paysheetId && !$all) {
            // Hiện danh sách paysheets
            $paysheets = Paysheet::orderByDesc('id')->limit(10)->get();
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
            $this->info("Dùng: php artisan salary:recalculate --paysheet=ID");
            $this->info("Hoặc: php artisan salary:recalculate --all (tất cả chưa chốt)");
            return 0;
        }

        if ($all) {
            $paysheets = Paysheet::where('status', '!=', 'locked')->get();
            if ($paysheets->isEmpty()) {
                $this->warn("Không có bảng lương nào chưa chốt.");
                return 0;
            }
            foreach ($paysheets as $ps) {
                $this->recalculateOne($ps);
            }
            return 0;
        }

        $paysheet = Paysheet::find((int) $paysheetId);
        if (!$paysheet) {
            $this->error("Không tìm thấy bảng lương ID: {$paysheetId}");
            return 1;
        }
        return $this->recalculateOne($paysheet);
    }

    private function recalculateOne(Paysheet $paysheet): int
    {
        if ($paysheet->status === 'locked') {
            $this->warn("⏭ Bảng lương {$paysheet->code} đã chốt, bỏ qua.");
            return 0;
        }

        $this->info("╔══════════════════════════════════════════════════════════╗");
        $this->info("║  RECALCULATE: {$paysheet->code}");
        $this->info("║  Kỳ: " . Carbon::parse($paysheet->period_start)->format('d/m/Y') . ' → ' . Carbon::parse($paysheet->period_end)->format('d/m/Y'));
        $this->info("╚══════════════════════════════════════════════════════════╝");

        $periodStart = Carbon::parse($paysheet->period_start);
        $periodEnd = Carbon::parse($paysheet->period_end);

        $paysheet->load('payslips');
        $employeeIds = $paysheet->payslips->pluck('employee_id')->unique()->toArray();

        // Bước 1: Recalculate timekeeping
        $this->info("🔄 Bước 1: Recalculate timekeeping cho {$paysheet->payslips->count()} NV...");
        $tkService = new TimekeepingService();
        $totalCreated = 0;
        $totalUpdated = 0;
        foreach ($employeeIds as $empId) {
            $r = $tkService->recalculateForRange($periodStart, $periodEnd, $empId);
            $totalCreated += $r['created'];
            $totalUpdated += $r['updated'];
        }
        $this->info("   → Timekeeping: created={$totalCreated}, updated={$totalUpdated}");

        // Bước 2: Recalculate salary cho từng phiếu lương
        $this->info("🔄 Bước 2: Tính lại lương cho từng NV...");
        $oldTotal = $paysheet->total_salary;
        $newTotal = 0;

        $rows = [];
        foreach ($paysheet->payslips as $slip) {
            $employee = Employee::with(['salarySetting'])->find($slip->employee_id);
            if (!$employee) {
                $this->warn("  ⚠ NV ID {$slip->employee_id} không tồn tại, bỏ qua.");
                continue;
            }

            $oldSalary = $slip->total_salary;
            $calc = $employee->calculateSalaryForRange($periodStart, $periodEnd);

            $slip->update([
                'base_salary' => $calc['base'],
                'bonus' => $calc['bonus'] ?? 0,
                'commission' => $calc['commission'] ?? 0,
                'allowances' => $calc['allowances'],
                'deductions' => $calc['deductions'],
                'ot_pay' => ($calc['ot_pay'] ?? 0) + ($calc['holiday_pay'] ?? 0),
                'total_salary' => $calc['total'],
                'remaining' => max(0, $calc['total'] - $slip->paid_amount),
                'work_units' => $calc['work_units'],
                'paid_leave_units' => $calc['paid_leave_units'] ?? 0,
                'ot_minutes' => $calc['ot_minutes'] ?? 0,
                'details' => $calc,
            ]);

            $newTotal += $calc['total'];
            $delta = $calc['total'] - $oldSalary;
            $rows[] = [
                $employee->code,
                mb_substr($employee->name, 0, 18),
                number_format($oldSalary),
                number_format($calc['total']),
                ($delta >= 0 ? '+' : '') . number_format($delta),
            ];
        }

        $this->table(['Mã NV', 'Tên', 'Lương cũ', 'Lương mới', 'Chênh lệch'], $rows);

        // Bước 3: Cập nhật tổng paysheet
        $paysheet->status = 'calculated';
        $paysheet->save();
        $paysheet->recalculateTotals();
        $paysheet->refresh();

        $this->newLine();
        $this->info("═══ KẾT QUẢ ═══");
        $this->table(['', 'Giá trị'], [
            ['Tổng lương CŨ', number_format($oldTotal) . 'đ'],
            ['Tổng lương MỚI', number_format($paysheet->total_salary) . 'đ'],
            ['Chênh lệch', number_format($paysheet->total_salary - $oldTotal) . 'đ'],
        ]);

        $this->info("✅ Đã cập nhật bảng lương {$paysheet->code} thành công!");
        $this->newLine();
        return 0;
    }
}
