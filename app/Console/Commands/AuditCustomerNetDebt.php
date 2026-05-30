<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use Illuminate\Support\Facades\DB;

class AuditCustomerNetDebt extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'customers:audit-net-debt 
                            {--customer-id= : Audit specific customer ID} 
                            {--all : Audit all customers}
                            {--export= : Export dry-run results to a CSV file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit and dry-run net debt calculations for dual role partners';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $customerId = $this->option('customer-id');
        $all = $this->option('all');
        $exportPath = $this->option('export');

        if (!$customerId && !$all) {
            $this->error('Please specify either --customer-id=ID or --all');
            return 1;
        }

        $query = Customer::query();
        if ($customerId) {
            $query->where('id', $customerId);
        }

        $customers = $query->get();
        $results = [];

        foreach ($customers as $customer) {
            // Expected customer debt from completed unpaid invoices
            $expectedCustomerDebt = (float) Invoice::where('customer_id', $customer->id)
                ->where('status', 'Hoàn thành')
                ->sum(DB::raw('total - customer_paid'));

            // Expected supplier debt from completed purchases and returns
            $totalPurchases = (float) Purchase::where('supplier_id', $customer->id)
                ->where('status', 'completed')
                ->sum(DB::raw('total_amount - discount - paid_amount'));

            $totalReturns = (float) PurchaseReturn::where('supplier_id', $customer->id)
                ->where('status', 'completed')
                ->sum(DB::raw('total_amount - refund_amount'));

            $expectedSupplierDebt = max(0.0, $totalPurchases - $totalReturns);

            $expectedNet = $expectedCustomerDebt - $expectedSupplierDebt;

            $currentDebt = (float) $customer->debt_amount;
            $hasSupplierDebtColumn = \Illuminate\Support\Facades\Schema::hasColumn('customers', 'supplier_debt_amount');
            $currentSupplierDebt = $hasSupplierDebtColumn ? (float) $customer->supplier_debt_amount : 0.0;
            $currentNet = $currentDebt - $currentSupplierDebt;

            $delta = $expectedNet - $currentNet;
            $needsUpdate = abs($delta) >= 0.01;

            $results[] = [
                'customer_id' => $customer->id,
                'customer_code' => $customer->code,
                'customer_name' => $customer->name,
                'is_customer' => $customer->is_customer,
                'is_supplier' => $customer->is_supplier,
                'current_debt_amount' => $currentDebt,
                'current_supplier_debt_amount' => $currentSupplierDebt,
                'current_net' => $currentNet,
                'expected_customer_debt' => $expectedCustomerDebt,
                'expected_supplier_debt' => $expectedSupplierDebt,
                'expected_net' => $expectedNet,
                'delta' => $delta,
                'needs_update' => $needsUpdate ? 'YES' : 'NO',
                'notes' => $customer->is_customer && $customer->is_supplier ? 'Dual role partner' : 'Single role partner',
            ];
        }

        $this->table(
            ['ID', 'Code', 'Name', 'Current Customer', 'Current Supplier', 'Current Net', 'Expected Net', 'Delta', 'Needs Update'],
            array_map(fn($r) => [
                $r['customer_id'],
                $r['customer_code'],
                $r['customer_name'],
                number_format($r['current_debt_amount']),
                number_format($r['current_supplier_debt_amount']),
                number_format($r['current_net']),
                number_format($r['expected_net']),
                number_format($r['delta']),
                $r['needs_update']
            ], array_slice($results, 0, 50))
        );

        if (count($results) > 50) {
            $this->info('... and ' . (count($results) - 50) . ' more rows.');
        }

        if ($exportPath) {
            $dir = dirname($exportPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $fp = fopen($exportPath, 'w');
            // Write UTF-8 BOM
            fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));
            
            fputcsv($fp, [
                'customer_id', 'customer_code', 'customer_name', 'is_customer', 'is_supplier',
                'current_debt_amount', 'current_supplier_debt_amount', 'current_net',
                'expected_customer_debt', 'expected_supplier_debt', 'expected_net',
                'delta', 'needs_update', 'notes'
            ]);

            foreach ($results as $row) {
                fputcsv($fp, $row);
            }
            fclose($fp);

            $this->info("Audit report successfully exported to {$exportPath}");
        }

        return 0;
    }
}
