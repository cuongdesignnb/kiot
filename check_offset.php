<?php
// Rollback test data
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$partner = \App\Models\Customer::where('code', 'NCC177227141922')->first();
$partner->is_customer = false;
$partner->debt_amount = 0;
$partner->supplier_debt_amount = 108050000;
$partner->save();

// Remove test offset
\App\Models\DebtOffset::where('code', 'CB000001')->delete();
// Remove related cash_flows
\App\Models\CashFlow::where('reference_type', 'DebtOffset')->delete();
\App\Models\SupplierDebtTransaction::where('type', 'offset')->delete();

echo "Rolled back!\n";
$partner->refresh();
echo "  is_customer: " . ($partner->is_customer ? 'Y' : 'N') . "\n";
echo "  KH debt: " . number_format($partner->debt_amount) . "\n";
echo "  NCC debt: " . number_format($partner->supplier_debt_amount) . "\n";
