<?php

namespace App\Console\Commands;

use App\Services\PayrollReviewedRecalculation;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class RecalculateReviewedPayroll extends Command
{
    protected $signature = 'payroll:recalculate-reviewed
        {--report= : Private audit JSON path} {--report-sha256= : Reviewed file hash}
        {--paysheet= : Exact sheet ID} {--payslip=* : Exact reviewed payslip IDs}
        {--expected-delta= : Reviewed total salary change}
        {--operator= : Delegated operator identity} {--backup-reference= : Backup confirmation/reference}
        {--apply : Commit changes; default is dry-run} {--confirm= : Confirmation code returned by dry-run}';

    protected $description = 'Recalculate only reviewed unpaid payslips; preserve other rows and atomically audit changes.';

    public function handle(PayrollReviewedRecalculation $service): int
    {
        try {
            $path = (string) $this->option('report');
            $sha = (string) $this->option('report-sha256');
            $sheet = (string) $this->option('paysheet');
            $rawIds = $this->option('payslip');
            $rawDelta = (string) $this->option('expected-delta');
            if (! preg_match('/^[a-f0-9]{64}$/', $sha) || ! is_file($path) || hash_file('sha256', $path) !== $sha) {
                throw new RuntimeException('Audit file/hash mismatch');
            }
            if (! ctype_digit($sheet) || (int) $sheet <= 0 || ! $rawIds || collect($rawIds)->contains(fn ($id) => ! ctype_digit((string) $id) || (int) $id <= 0)
                || filter_var($rawDelta, FILTER_VALIDATE_INT) === false) {
                throw new RuntimeException('Explicit sheet, payslip IDs and integer expected delta required');
            }
            $ids = array_map('intval', $rawIds);
            $apply = (bool) $this->option('apply');
            $code = 'APPLY-PAYROLL-'.substr(PayrollReviewedRecalculation::operation($sha, (int) $sheet, $ids, (int) $rawDelta), 0, 16);
            if ($apply && ($this->option('confirm') !== $code || ! app()->isDownForMaintenance())) {
                throw new RuntimeException('Apply requires exact confirmation code and maintenance mode');
            }
            $report = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $result = $service->execute($report, $sha, (int) $sheet, $ids, (int) $rawDelta, (string) $this->option('operator'), (string) $this->option('backup-reference'), $apply);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return 0;
        } catch (Throwable $e) {
            $this->line(json_encode(['result' => 'STOP', 'error' => $e->getMessage(), 'production_business_data_mutation' => 'NO'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return 1;
        }
    }
}
