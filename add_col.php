<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

Schema::table('products', function (Blueprint $table) {
    if (!Schema::hasColumn('products', 'technician_price')) {
        $table->decimal('technician_price', 15, 2)->default(0)->after('retail_price');
    }
});
echo "Added technician_price successfully.\n";
