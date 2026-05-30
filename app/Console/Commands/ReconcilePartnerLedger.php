<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Services\PartnerDebtLedgerService;

class ReconcilePartnerLedger extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customers:reconcile-partner-ledger {customer_id : The ID of the customer/supplier}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detailed reconciliation of customer receivable, supplier payable, and net debt timelines';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $customerId = $this->argument('customer_id');
        $customer = Customer::find($customerId);

        if (!$customer) {
            $this->error("Customer with ID {$customerId} not found.");
            return 1;
        }

        $this->info("=== RECONCILIATION REPORT FOR PARTNER: {$customer->name} (Code: {$customer->code}, ID: {$customer->id}) ===");
        
        $hasSupplierColumn = \Illuminate\Support\Facades\Schema::hasColumn('customers', 'supplier_debt_amount');

        $customer_receivable_cached = (float) $customer->debt_amount;
        $supplier_payable_cached = $hasSupplierColumn ? (float) $customer->supplier_debt_amount : 0.0;
        $net_cached = $customer_receivable_cached - $supplier_payable_cached;

        $ledgerService = app(PartnerDebtLedgerService::class);

        // Compute ledgers
        $supplierLedger = $ledgerService->buildSupplierPayableLedger($customer);
        $customerLedger = $ledgerService->buildCustomerReceivableLedger($customer);
        $netLedger = $ledgerService->buildCustomerNetLedger($customer);

        $customer_ledger_computed = 0.0;
        foreach ($customerLedger['entries'] as $entry) {
            if ($entry['affects_debt_balance'] ?? true) {
                $customer_ledger_computed += (float) $entry['customer_effect'];
            }
        }

        $supplier_ledger_computed = (float) $supplierLedger['closing_balance'];
        $net_ledger_computed = (float) $netLedger['reconcile']['computed_balance'];

        $receivable_mismatch = abs($customer_receivable_cached - $customer_ledger_computed) >= 0.01;
        $payable_mismatch = abs($supplier_payable_cached - $supplier_ledger_computed) >= 0.01;
        $net_mismatch = abs($net_cached - $net_ledger_computed) >= 0.01;

        $this->table(
            ['Metric', 'Cached Value (DB)', 'Computed Value (Ledger)', 'Mismatch?'],
            [
                [
                    'Customer Receivable (Phải thu)',
                    number_format($customer_receivable_cached, 2) . 'đ',
                    number_format($customer_ledger_computed, 2) . 'đ',
                    $receivable_mismatch ? '⚠️ MISMATCH' : '✅ OK',
                ],
                [
                    'Supplier Payable (Phải trả)',
                    number_format($supplier_payable_cached, 2) . 'đ',
                    number_format($supplier_ledger_computed, 2) . 'đ',
                    $payable_mismatch ? '⚠️ MISMATCH' : '✅ OK',
                ],
                [
                    'Net Debt (Nợ ròng)',
                    number_format($net_cached, 2) . 'đ',
                    number_format($net_ledger_computed, 2) . 'đ',
                    $net_mismatch ? '⚠️ MISMATCH' : '✅ OK',
                ],
            ]
        );

        $this->info("\n=== DETAILED LEDGER ENTRIES CHRONOLOGICAL ===");

        // We want chronological order (oldest first) to show the running balance progression
        $entries = collect($netLedger['entries'])->reverse()->values();

        $running_customer_receivable = 0.0;
        $running_supplier_payable = 0.0;
        $running_net = 0.0;

        $tableRows = [];
        foreach ($entries as $entry) {
            $affects = $entry['affects_debt_balance'] ?? true;
            $domain = $entry['domain'] ?? 'customer';

            $c_effect = 0.0;
            $s_effect = 0.0;

            if ($affects) {
                if ($domain === 'customer') {
                    $c_effect = (float) $entry['customer_effect'];
                    $running_customer_receivable += $c_effect;
                } else {
                    $s_effect = (float) ($entry['supplier_effect'] ?? 0.0);
                    $c_effect = (float) $entry['customer_effect']; // customer_effect = -1 * supplier_effect
                    $running_supplier_payable += $s_effect;
                }
                $running_net = $running_customer_receivable - $running_supplier_payable;
            }

            $tableRows[] = [
                $entry['code'] ?? '—',
                $entry['source'] ?? '—',
                $entry['display_type'] ?? $entry['type'] ?? '—',
                $affects ? number_format($c_effect, 2) . 'đ' : '0.00đ',
                $affects ? number_format($s_effect, 2) . 'đ' : '0.00đ',
                number_format($running_customer_receivable, 2) . 'đ',
                number_format($running_supplier_payable, 2) . 'đ',
                number_format($running_net, 2) . 'đ',
                $affects ? 'YES' : 'NO',
            ];
        }

        $this->table(
            ['Code', 'Source', 'Type', 'Cust Effect', 'Sup Effect', 'Run Cust Rec', 'Run Sup Pay', 'Run Net', 'Affects?'],
            $tableRows
        );

        return 0;
    }
}
