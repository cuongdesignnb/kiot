<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$employee = \App\Models\Employee::find(1);
$user = \App\Models\User::find(2);
echo "Employee: {$employee->name} (#{$employee->id})\n";
echo "User: {$user->email} (#{$user->id})\n";

$product = \App\Models\Product::first();
if (!$product) {
    echo "No products! Creating one...\n";
    $productId = DB::table('products')->insertGetId([
        'name' => 'SP Test', 'sku' => 'TEST001', 'stock_quantity' => 10,
        'cost_price' => 100000, 'selling_price' => 200000,
        'created_at' => now(), 'updated_at' => now(),
    ]);
} else {
    $productId = $product->id;
}

$serial = \App\Models\SerialImei::first();
if (!$serial) {
    $serialId = DB::table('serial_imeis')->insertGetId([
        'product_id' => $productId, 'serial_number' => 'IMEI-TEST-001',
        'status' => 'in_stock', 'created_at' => now(), 'updated_at' => now(),
    ]);
} else {
    $serialId = $serial->id;
}

// Check if CV-TEST-001 already exists
$existing = DB::table('tasks')->where('code', 'CV-TEST-001')->first();
if ($existing) {
    echo "Task CV-TEST-001 already exists (#{$existing->id}), skipping...\n";
    $taskId = $existing->id;
} else {
    $taskId = DB::table('tasks')->insertGetId([
        'code' => 'CV-TEST-001',
        'type' => 'general',
        'title' => 'Test giao viec - ' . now()->format('H:i d/m'),
        'priority' => 'normal',
        'status' => 'pending',
        'created_by' => 1,
        'product_id' => $productId,
        'serial_imei_id' => $serialId,
        'original_cost' => 0,
        'parts_cost' => 0,
        'total_cost' => 0,
        'progress' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    echo "Created TASK #{$taskId} (CV-TEST-001)\n";
}

// Assign
$existAssign = DB::table('task_assignments')
    ->where('task_id', $taskId)->where('employee_id', $employee->id)->first();
if (!$existAssign) {
    DB::table('task_assignments')->insert([
        'task_id' => $taskId,
        'employee_id' => $employee->id,
        'assigned_by' => 1,
        'status' => 'pending',
        'assigned_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    echo "Assigned to {$employee->name}\n";
} else {
    echo "Already assigned.\n";
}

echo "\n✅ Login: nhanvien@test.com / 123456\n";
echo "Go to: /my-tasks\n";
