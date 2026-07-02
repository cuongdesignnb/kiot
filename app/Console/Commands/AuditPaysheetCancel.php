<?php

namespace App\Console\Commands;

use App\Models\Paysheet;
use App\Services\PayrollPostingService;
use Illuminate\Console\Command;

class AuditPaysheetCancel extends Command
{
    protected $signature = 'payroll:audit-paysheet-cancel
        {paysheet_code : Paysheet code, e.g. BL000009}
        {--format=table : table|json}';

    protected $description = 'Read-only audit to check whether a locked paysheet can be safely cancelled';

    public function handle(PayrollPostingService $service): int
    {
        $sheet = Paysheet::where('code', $this->argument('paysheet_code'))->first();
        if (! $sheet) {
            $this->error('Paysheet not found.');

            return self::FAILURE;
        }

        $row = $service->canCancel($sheet);

        if ($this->option('format') === 'json') {
            $this->line(json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table(
            [
                'paysheet_code',
                'paysheet_status',
                'total_salary',
                'paid_amount',
                'remaining_amount',
                'payslip_count',
                'active_payment_count',
                'payroll_accrual_count',
                'salary_payment_count',
                'cancel_reverse_count',
                'employee_count',
                'can_cancel',
                'mode',
                'reason',
                'requires_legacy_zero_net_reversal',
            ],
            [[
                $row['paysheet_code'],
                $row['paysheet_status'],
                $row['total_salary'],
                $row['paid_amount'],
                $row['remaining_amount'],
                $row['payslip_count'],
                $row['active_payment_count'],
                $row['payroll_accrual_count'],
                $row['salary_payment_count'],
                $row['cancel_reverse_count'],
                $row['employee_count'],
                $row['can_cancel'],
                $row['mode'] ?? null,
                $row['reason'],
                $row['requires_legacy_zero_net_reversal'] ?? false,
            ]]
        );

        return self::SUCCESS;
    }
}
