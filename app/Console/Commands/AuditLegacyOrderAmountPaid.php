<?php

namespace App\Console\Commands;

use App\Services\LegacyOrderAmountPaidAuditService;
use Illuminate\Console\Command;

class AuditLegacyOrderAmountPaid extends Command
{
    protected $signature = 'orders:audit-legacy-amount-paid
        {--json : Output machine-readable JSON}
        {--limit=100 : Maximum suspected orders included in the sample}';

    protected $description = 'Read-only audit for legacy orders.amount_paid cumulative-payment risk';

    public function handle(LegacyOrderAmountPaidAuditService $auditService): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($limit === false) {
            $this->error('--limit must be a positive integer.');

            return self::FAILURE;
        }

        $report = $auditService->audit($limit);
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $summary = $report['summary'];
        $this->info('Legacy order amount_paid audit');
        $this->line('Mode: read-only; no data was modified.');
        $this->line("Total orders checked: {$summary['orders_checked']}");
        $this->line("Orders amount_paid > 0: {$summary['orders_amount_paid_positive']}");
        $this->line("Orders with invoices: {$summary['orders_with_invoices']}");
        $this->line("Orders with paid invoices: {$summary['orders_with_paid_invoices']}");
        $this->line("Deposit only or no invoice: {$summary['deposit_only_or_no_invoice']}");
        $this->line(
            'Suspected legacy cumulative amount_paid: '
            .$summary['suspected_legacy_cumulative_amount_paid']
        );

        if ($report['items'] !== []) {
            $this->newLine();
            $this->table(
                [
                    'Order',
                    'Customer',
                    'Total',
                    'amount_paid',
                    'Invoices',
                    'Invoice paid',
                    'Deposit applied',
                    'Computed paid',
                    'Remaining',
                    'Reason',
                ],
                array_map(fn (array $item) => [
                    "{$item['order_code']} (#{$item['order_id']})",
                    $item['customer_name'] ?: ($item['customer_id'] ?: '-'),
                    $item['order_total'],
                    $item['order_amount_paid'],
                    $item['invoice_count'],
                    $item['sum_invoice_customer_paid'],
                    $item['sum_order_deposit_applied_amount'],
                    $item['computed_order_paid_total'],
                    $item['computed_remaining_debt'],
                    $item['reason'],
                ], $report['items'])
            );
        }

        $this->newLine();
        $this->warn('Suggested action: '.$report['suggested_action']);

        return self::SUCCESS;
    }
}
