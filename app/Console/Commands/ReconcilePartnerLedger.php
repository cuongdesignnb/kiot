<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Models\DebtOffset;
use App\Services\PartnerDebtLedgerService;
use Illuminate\Support\Facades\Schema;

class ReconcilePartnerLedger extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'customers:reconcile-partner-ledger
        {--customer-id= : Customer ID}
        {--code= : Customer/Supplier code}
        {--name= : Partner name keyword}
        {--phone= : Partner phone}
        {--json : Output JSON}';

    /**
     * The console command description.
     */
    protected $description = 'Detailed reconciliation of customer receivable, supplier payable, and net debt timelines';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $customerId = $this->option('customer-id');
        $code = $this->option('code');
        $name = $this->option('name');
        $phone = $this->option('phone');
        $json = $this->option('json');

        // Build query
        $query = Customer::query();

        if ($customerId) {
            $query->where('id', $customerId);
        }
        if ($code) {
            $query->where('code', $code);
        }
        if ($name) {
            $query->where('name', 'like', '%' . $name . '%');
        }
        if ($phone) {
            $query->where('phone', 'like', '%' . $phone . '%');
        }

        // If no filter is provided, demand at least one
        if (!$customerId && !$code && !$name && !$phone) {
            if ($json) {
                $this->line(json_encode(['error' => 'Please provide at least one filter option: --customer-id, --code, --name, or --phone.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('Please provide at least one filter option: --customer-id, --code, --name, or --phone.');
            }
            return 1;
        }

        $partners = $query->get(['id', 'code', 'name', 'phone', 'debt_amount', 'supplier_debt_amount', 'is_customer', 'is_supplier']);

        if ($partners->isEmpty()) {
            if ($json) {
                $this->line(json_encode(['error' => 'No partner found matching the criteria.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('No partner found matching the criteria.');
            }
            return 1;
        }

        if ($partners->count() > 1) {
            if ($json) {
                $this->line(json_encode([
                    'error' => 'Multiple partners found. Please refine your search.',
                    'matches' => $partners->map(fn($p) => [
                        'id' => $p->id,
                        'code' => $p->code,
                        'name' => $p->name,
                        'phone' => $p->phone ?? '',
                    ])->toArray()
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('Multiple partners found. Please refine your search:');
                $this->table(
                    ['ID', 'Code', 'Name', 'Phone'],
                    $partners->map(fn($p) => [
                        $p->id,
                        $p->code,
                        $p->name,
                        $p->phone ?? '—',
                    ])->toArray()
                );
            }
            return 1;
        }

        $customer = $partners->first();

        $hasSupplierColumn = Schema::hasColumn('customers', 'supplier_debt_amount');

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

        // Chronological net ledger list
        $entries = collect($netLedger['entries'])->reverse()->values();

        $running_customer_receivable = 0.0;
        $running_supplier_payable = 0.0;
        $running_net = 0.0;

        $detailEntries = [];
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

            $detailEntries[] = [
                'code' => $entry['code'] ?? null,
                'source' => $entry['source'] ?? null,
                'type' => $entry['display_type'] ?? $entry['type'] ?? null,
                'customer_effect' => $c_effect,
                'supplier_effect' => $s_effect,
                'running_customer_receivable' => $running_customer_receivable,
                'running_supplier_payable' => $running_supplier_payable,
                'running_net' => $running_net,
                'affects_debt_balance' => $affects,
            ];

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

        $hasDebtOffsetVoucher = DebtOffset::query()
            ->where('customer_id', $customer->id)
            ->where('status', '!=', 'cancelled')
            ->exists();

        if ($json) {
            $this->line(json_encode([
                'partner' => [
                    'id' => $customer->id,
                    'code' => $customer->code,
                    'name' => $customer->name,
                    'phone' => $customer->phone ?? '',
                ],
                'reconciliation' => [
                    // Canonical keys (HOTFIX FOLLOW-UP)
                    'customer_receivable_cached'   => $customer_receivable_cached,
                    'customer_receivable_computed' => $customer_ledger_computed,
                    'receivable_mismatch'          => $receivable_mismatch,
                    'supplier_payable_cached'      => $supplier_payable_cached,
                    'supplier_payable_computed'    => $supplier_ledger_computed,
                    'payable_mismatch'             => $payable_mismatch,
                    'partner_net_position_cached'  => $net_cached,
                    'partner_net_position_computed'=> $net_ledger_computed,
                    'partner_net_position_mismatch'=> $net_mismatch,
                    'is_actual_offset'             => false,
                    'has_debt_offset_voucher'      => $hasDebtOffsetVoucher,

                    // Backward-compatible keys
                    'customer_ledger_computed' => $customer_ledger_computed,
                    'supplier_ledger_computed' => $supplier_ledger_computed,
                    'net_cached' => $net_cached,
                    'net_ledger_computed' => $net_ledger_computed,
                    'net_mismatch' => $net_mismatch,
                ],
                'entries' => $detailEntries,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return 0;
        }

        // Print pretty terminal table
        $this->info("=== RECONCILIATION REPORT FOR PARTNER: {$customer->name} (Code: {$customer->code}, ID: {$customer->id}) ===");
        
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
                    'Partner Net Position (Vị thế ròng)',
                    number_format($net_cached, 2) . 'đ',
                    number_format($net_ledger_computed, 2) . 'đ',
                    $net_mismatch ? '⚠️ MISMATCH' : '✅ OK',
                ],
            ]
        );

        $this->line('');
        $this->line('Has actual debt-offset voucher (CB/HCB): ' . ($hasDebtOffsetVoucher ? 'YES' : 'NO'));
        $this->line('Note: partner_net_position is a display delta of receivable - payable.');
        $this->line('      It is NOT a debt-offset voucher. Only a CB/HCB row indicates an actual offset.');

        $this->info("\n=== DETAILED LEDGER ENTRIES CHRONOLOGICAL ===");
        $this->table(
            ['Code', 'Source', 'Type', 'Cust Effect', 'Sup Effect', 'Run Cust Rec', 'Run Sup Pay', 'Run Net', 'Affects?'],
            $tableRows
        );

        return 0;
    }
}
