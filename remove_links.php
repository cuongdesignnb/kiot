<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Customer;

$affected = Customer::where('is_customer', true)->where('is_supplier', true)->update(['is_supplier' => false]);
echo "Removed automatic linkages for {$affected} records.\n";
