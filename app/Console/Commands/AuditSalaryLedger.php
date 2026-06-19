<?php

namespace App\Console\Commands;

use App\Services\PayrollReconciliationService;
use Illuminate\Console\Command;

class AuditSalaryLedger extends Command
{
    protected $signature = 'payroll:audit-salary-ledger
        {--section=all : all|cache|payments|advances|legacy}
        {--branch= : Branch ID}
        {--employee= : Employee ID or code}
        {--format=table : table|csv|json}
        {--output= : Output file for csv/json}';

    protected $description = 'Read-only payroll salary ledger reconciliation';

    public function handle(PayrollReconciliationService $service): int
    {
        $format = $this->option('format');
        if (! in_array($format, ['table', 'csv', 'json'], true)) {
            $this->error('Format must be table, csv, or json.');

            return self::INVALID;
        }

        $report = $service->audit([
            'section' => $this->option('section'),
            'branch' => $this->option('branch'),
            'employee' => $this->option('employee'),
        ]);

        if ($format === 'json') {
            return $this->writeOrPrint(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        if ($format === 'csv') {
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [
                'record_type', 'group', 'severity', 'employee_id', 'employee_code',
                'employee_name', 'branch', 'cache', 'ledger', 'difference',
                'issues', 'primary_status', 'suggested_action', 'document_code', 'document_id',
            ]);
            foreach ($report['data'] as $row) {
                fputcsv($stream, [
                    'employee', '', $row['severity'], $row['employee_id'],
                    $row['employee_code'], $row['employee_name'], $row['branch'],
                    $row['salary_balance_cache'], $row['ledger_balance'], $row['difference'],
                    implode('|', $row['issues']), $row['primary_status'], $row['suggested_action'],
                    '', '',
                ]);
            }
            foreach ($report['document_issues'] as $issue) {
                fputcsv($stream, [
                    'document', $issue['group'], $issue['severity'], $issue['employee_id'],
                    $issue['employee_code'], $issue['employee_name'], '',
                    '', '', '', $issue['issue'], $issue['issue'], '',
                    $issue['document_code'], $issue['document_id'],
                ]);
            }
            rewind($stream);
            $content = stream_get_contents($stream);
            fclose($stream);

            return $this->writeOrPrint($content);
        }

        $this->table(
            ['Code', 'Employee', 'Branch', 'Cache', 'Ledger', 'Difference', 'Status', 'Issues', 'Action'],
            collect($report['data'])->map(fn ($row) => [
                $row['employee_code'], $row['employee_name'], $row['branch'],
                $row['salary_balance_cache'], $row['ledger_balance'], $row['difference'],
                $row['primary_status'], implode(',', $row['issues']), $row['suggested_action'],
            ])->all()
        );
        $this->line(json_encode($report['summary'], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function writeOrPrint(string $content): int
    {
        $output = $this->option('output');
        if ($output) {
            $directory = dirname($output);
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            file_put_contents($output, $content);
            $this->info("Written to {$output}");
        } else {
            $this->line($content);
        }

        return self::SUCCESS;
    }
}
