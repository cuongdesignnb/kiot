<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\CashFlow;
use App\Services\CustomerDebtDocumentTimelineService;

class DebugDocumentTimelineCommand extends Command
{
    protected $signature = 'debt:debug-document-timeline
        {--customer-code=NCC178090885683 : Customer code}
        {--document-code=HD178090993527 : Invoice/return/cashflow code}
        {--json : Output JSON}';

    protected $description = 'Debug document-first timeline metrics for customer and invoice';

    public function handle()
    {
        $customerCode = $this->option('customer-code');
        $documentCode = $this->option('document-code');

        $customer = Customer::where('code', $customerCode)->first();
        if (!$customer) {
            $this->error("Customer not found: $customerCode");
            return 1;
        }

        $service = app(CustomerDebtDocumentTimelineService::class);
        $timeline = $service->build($customer);
        $entries = collect($timeline['entries'] ?? []);

        // Retrieve specific entry
        $entry = $entries->first(fn($e) => $e['code'] === $documentCode);
        if (!$entry) {
            $entry = $entries->first(fn($e) => ($e['reference_code'] ?? null) === $documentCode);
        }

        // Retrieve invoice details
        $invoice = Invoice::where('code', $documentCode)->first();
        $invoiceTotal = $invoice ? (float) $invoice->total : 0.0;
        $invoicePaid = $invoice ? (float) $invoice->customer_paid : 0.0;

        // Retrieve cashflow details
        $cashflows = CashFlow::where('reference_code', $documentCode)
            ->orWhere('code', $documentCode)
            ->active()
            ->get();
        $cashflowTotal = (float) $cashflows->sum('amount');

        $expectedEffect = $invoiceTotal ?: ($entry ? (float) $entry['document_amount'] : 0.0);
        if ($entry && $entry['event_kind'] === 'sales_return') {
            $expectedEffect = -$expectedEffect;
        }
        $actualEffect = $entry ? (float) ($entry['customer_display_effect'] ?? $entry['display_effect'] ?? 0.0) : 0.0;
        $source = $entry ? $entry['source'] : 'none';
        $badgeLabel = $entry ? ($entry['badge_label'] ?? 'null') : 'none';

        $isPass = abs($actualEffect - $expectedEffect) < 0.01 && $source === 'document_first';
        $statusStr = $isPass ? 'PASS' : 'FAIL';

        $missingRunningBalanceCodes = [];
        $allEntriesHaveRunningBalance = true;
        foreach ($entries as $e) {
            if (!isset($e['customer_display_running_balance']) || $e['customer_display_running_balance'] === null) {
                $allEntriesHaveRunningBalance = false;
                $missingRunningBalanceCodes[] = $e['code'] ?: ('id:' . $e['id']);
            }
        }

        $output = [
            'customer_code' => $customerCode,
            'document_code' => $documentCode,
            'api_mode_document_effect' => $actualEffect,
            'api_default_effect' => $actualEffect,
            'source' => $source,
            'badge_label' => $badgeLabel,
            'invoice_total' => $invoiceTotal,
            'invoice_customer_paid' => $invoicePaid,
            'cashflow_total' => $cashflowTotal,
            'expected_invoice_effect' => $expectedEffect,
            'pass_fail' => $statusStr,
            'entry_count' => $entries->count(),
            'all_entries_have_running_balance' => $allEntriesHaveRunningBalance,
            'missing_running_balance_codes' => $missingRunningBalanceCodes,
            'document_final_balance' => (float) ($timeline['summary']['document_final_balance'] ?? 0.0),
            'stored_net' => (float) ($timeline['summary']['current_debt'] ?? 0.0),
            'reconcile_severity' => $timeline['reconcile']['severity'] ?? 'none',
        ];

        if ($this->option('json')) {
            $this->output->write(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return 0;
        }

        $this->line("customer_code: " . $output['customer_code']);
        $this->line("document_code: " . $output['document_code']);
        $this->line("api_mode_document_effect: " . $output['api_mode_document_effect']);
        $this->line("api_default_effect: " . $output['api_default_effect']);
        $this->line("source: " . $output['source']);
        $this->line("badge_label: " . $output['badge_label']);
        $this->line("invoice_total: " . $output['invoice_total']);
        $this->line("invoice_customer_paid: " . $output['invoice_customer_paid']);
        $this->line("cashflow_total: " . $output['cashflow_total']);
        $this->line("expected_invoice_effect: " . $output['expected_invoice_effect']);
        
        $this->line("entry_count: " . $output['entry_count']);
        $this->line("all_entries_have_running_balance: " . ($output['all_entries_have_running_balance'] ? 'true' : 'false'));
        $this->line("missing_running_balance_codes: " . implode(', ', $output['missing_running_balance_codes']));
        $this->line("document_final_balance: " . $output['document_final_balance']);
        $this->line("stored_net: " . $output['stored_net']);
        $this->line("reconcile_severity: " . $output['reconcile_severity']);

        $this->line("pass/fail: " . $output['pass_fail']);

        $this->line("{$documentCode} expected +{$expectedEffect} actual +{$actualEffect} {$statusStr}");

        return $isPass && $allEntriesHaveRunningBalance ? 0 : 1;
    }
}
