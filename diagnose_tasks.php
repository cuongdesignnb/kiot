<?php
/**
 * DIAGNOSTIC: Kiểm tra dữ liệu tasks trên production
 * Chạy: php diagnose_tasks.php
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "=== TASK DATA DIAGNOSTIC ===\n\n";

// 1. Kiểm tra tables tồn tại
echo "1. TABLES:\n";
$tables = ['tasks', 'task_assignments', 'task_parts', 'task_categories', 'task_comments',
           'device_repairs', 'device_repair_parts'];
foreach ($tables as $t) {
    $exists = Schema::hasTable($t);
    $count = $exists ? DB::table($t)->count() : 'N/A';
    $icon = $exists ? '✅' : '❌';
    echo "   {$icon} {$t}: " . ($exists ? "{$count} records" : "NOT EXISTS") . "\n";
}

// 2. Kiểm tra migration status
echo "\n2. MIGRATION STATUS:\n";
$migrations = DB::table('migrations')
    ->where('migration', 'like', '%task%')
    ->orWhere('migration', 'like', '%repair%')
    ->orderBy('id')
    ->get();
foreach ($migrations as $m) {
    echo "   [{$m->batch}] {$m->migration}\n";
}

// 3. Kiểm tra tasks data
echo "\n3. TASKS DATA:\n";
if (Schema::hasTable('tasks')) {
    $total = DB::table('tasks')->count();
    echo "   Total tasks: {$total}\n";
    
    if ($total > 0) {
        $byStatus = DB::table('tasks')
            ->select('status', DB::raw('count(*) as cnt'))
            ->groupBy('status')
            ->get();
        foreach ($byStatus as $s) {
            echo "   - {$s->status}: {$s->cnt}\n";
        }
        
        $byType = DB::table('tasks')
            ->select('type', DB::raw('count(*) as cnt'))
            ->groupBy('type')
            ->get();
        echo "   By type:\n";
        foreach ($byType as $t) {
            echo "   - {$t->type}: {$t->cnt}\n";
        }
        
        // Show latest 5
        echo "\n   Latest 5 tasks:\n";
        $latest = DB::table('tasks')->orderByDesc('id')->limit(5)->get();
        foreach ($latest as $t) {
            echo "   #{$t->id} {$t->code} | {$t->title} | status={$t->status} | type={$t->type}\n";
        }
    }
}

// 4. Kiểm tra task_assignments
echo "\n4. TASK ASSIGNMENTS:\n";
if (Schema::hasTable('task_assignments')) {
    $total = DB::table('task_assignments')->count();
    echo "   Total assignments: {$total}\n";
    
    if ($total > 0) {
        $byStatus = DB::table('task_assignments')
            ->select('status', DB::raw('count(*) as cnt'))
            ->groupBy('status')
            ->get();
        foreach ($byStatus as $s) {
            echo "   - {$s->status}: {$s->cnt}\n";
        }
    }
}

// 5. Kiểm tra employees liên kết user
echo "\n5. EMPLOYEES WITH USER:\n";
$employees = DB::table('employees')
    ->where('is_active', true)
    ->get(['id', 'name', 'user_id']);
foreach ($employees as $e) {
    $icon = $e->user_id ? '✅' : '❌';
    $email = 'N/A';
    if ($e->user_id) {
        $user = DB::table('users')->where('id', $e->user_id)->first();
        $email = $user ? $user->email : 'USER DELETED!';
    }
    echo "   {$icon} EMP#{$e->id} {$e->name} | user_id={$e->user_id} | email={$email}\n";
}

// 6. Kiểm tra bảng tasks structure
echo "\n6. TASKS TABLE COLUMNS:\n";
$columns = Schema::getColumnListing('tasks');
echo "   " . implode(', ', $columns) . "\n";

// 7. Kiểm tra device_repairs nếu tồn tại
if (Schema::hasTable('device_repairs')) {
    echo "\n7. ⚠️ OLD TABLE device_repairs STILL EXISTS!\n";
    $count = DB::table('device_repairs')->count();
    echo "   Records: {$count}\n";
    if ($count > 0) {
        echo "   ⚡ DATA IS IN OLD TABLE! Migration may not have run properly.\n";
        echo "   Latest 3:\n";
        $old = DB::table('device_repairs')->orderByDesc('id')->limit(3)->get();
        foreach ($old as $o) {
            echo "   #{$o->id} " . ($o->code ?? 'NO CODE') . " status=" . ($o->status ?? '?') . "\n";
        }
    }
}

echo "\n=== END DIAGNOSTIC ===\n";
