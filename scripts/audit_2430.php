<?php
// HOTFIX 24.30 — DB discovery script (READ ONLY)
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== USERS ===" . PHP_EOL;
$users = DB::table('users')->select('id','name','email','role_id','branch_id','status','deleted_at')->orderBy('id')->get();
foreach ($users as $u) { echo json_encode($u) . PHP_EOL; }

echo PHP_EOL . "=== EMPLOYEES ===" . PHP_EOL;
$emps = DB::table('employees')->select('id','user_id','code','name','is_active')->orderBy('id')->get();
foreach ($emps as $e) { echo json_encode($e) . PHP_EOL; }

echo PHP_EOL . "=== EMPLOYEE-USER LINKS ===" . PHP_EOL;
$links = DB::table('employees as e')
    ->leftJoin('users as u','u.id','=','e.user_id')
    ->select('e.id as emp_id','e.code','e.name as emp_name','e.user_id','u.name as user_name','u.email','e.is_active')
    ->orderBy('e.id')->get();
foreach ($links as $l) { echo json_encode($l) . PHP_EOL; }

echo PHP_EOL . "=== INVOICES WITH ADMIN (this month) ===" . PHP_EOL;
$invs = DB::table('invoices as i')
    ->leftJoin('employees as e','e.id','=','i.created_by')
    ->leftJoin('users as u','u.id','=','e.user_id')
    ->select('i.id','i.code','i.created_by','i.seller_name','i.created_by_name','i.total','i.status','i.created_at',
             'e.name as emp_name','e.code as emp_code','e.user_id as emp_user_id','u.name as linked_user_name')
    ->where('i.status','!=','Đã hủy')
    ->where('i.created_at','>=','2026-05-01')
    ->where(function($q) {
        $q->where('e.name','like','%Admin%')
          ->orWhere('i.seller_name','like','%Admin%')
          ->orWhere('i.created_by_name','like','%Admin%');
    })
    ->orderBy('i.created_at','desc')
    ->get();
foreach ($invs as $inv) { echo json_encode($inv) . PHP_EOL; }

echo PHP_EOL . "=== SELLER AGGREGATION (this month) ===" . PHP_EOL;
$agg = DB::table('invoices as i')
    ->leftJoin('employees as e','e.id','=','i.created_by')
    ->leftJoin('users as u','u.id','=','e.user_id')
    ->select('i.created_by','e.code as emp_code','e.name as emp_name','e.user_id as emp_user_id',
             'u.name as linked_user_name','i.seller_name',
             DB::raw('COUNT(*) as invoice_count'),
             DB::raw('SUM(i.total) as total_sum'))
    ->where('i.status','!=','Đã hủy')
    ->where('i.created_at','>=','2026-05-01')
    ->groupBy('i.created_by','e.code','e.name','e.user_id','u.name','i.seller_name')
    ->orderByDesc('total_sum')
    ->get();
foreach ($agg as $a) { echo json_encode($a) . PHP_EOL; }

echo PHP_EOL . "=== UNKNOWN SELLER INVOICES (this month) ===" . PHP_EOL;
$unknowns = DB::table('invoices')
    ->select('id','code','created_by','seller_name','created_by_name','total','status','created_at')
    ->where('status','!=','Đã hủy')
    ->where('created_at','>=','2026-05-01')
    ->whereNull('created_by')
    ->where(function($q) {
        $q->whereNull('seller_name')->orWhere('seller_name','');
    })
    ->orderByDesc('created_at')
    ->get();
foreach ($unknowns as $uk) { echo json_encode($uk) . PHP_EOL; }

echo PHP_EOL . "=== INVOICE 5,850,000 ===" . PHP_EOL;
$inv5 = DB::table('invoices as i')
    ->leftJoin('employees as e','e.id','=','i.created_by')
    ->leftJoin('users as u','u.id','=','e.user_id')
    ->select('i.id','i.code','i.created_by','i.seller_name','i.created_by_name','i.total','i.status','i.created_at',
             'e.name as emp_name','e.code as emp_code','u.name as linked_user_name')
    ->where('i.status','!=','Đã hủy')
    ->where('i.created_at','>=','2026-05-01')
    ->where('i.total',5850000)
    ->get();
foreach ($inv5 as $i5) { echo json_encode($i5) . PHP_EOL; }

echo PHP_EOL . "DONE" . PHP_EOL;
