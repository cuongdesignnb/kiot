<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Find NXT by code
$employees = \App\Models\Employee::select('id','name','code')->get();
$nxt = null;
foreach ($employees as $e) {
    if (str_contains($e->name, 'Th') && str_contains($e->code, 'NV000')) {
        echo "{$e->id} | {$e->code} | {$e->name}\n";
        // NXT's code is NV000026 from screenshot
    }
}

// Use NV000026 directly
$nxt = \App\Models\Employee::where('code', 'NV000026')->first();
if (!$nxt) {
    // Try all employees
    echo "\n--- All employees ---\n";
    foreach ($employees as $e) {
        echo "{$e->id} | {$e->code} | {$e->name}\n";
    }
    exit("NXT not found by code NV000026\n");
}

echo "\n=== NXT: {$nxt->name} (ID: {$nxt->id}, Code: {$nxt->code}) ===\n\n";

// Get ALL records for March 2026
$records = \App\Models\TimekeepingRecord::where('employee_id', $nxt->id)
    ->whereBetween('date', ['2026-03-01', '2026-03-31'])
    ->orderBy('date')
    ->orderBy('id')
    ->get();

echo "Total records in DB: {$records->count()}\n\n";

$totalWU = 0;
$dateCount = [];
foreach ($records as $r) {
    $dateStr = \Carbon\Carbon::parse($r->date)->format('Y-m-d');
    $dayName = \Carbon\Carbon::parse($r->date)->format('D');
    $dateCount[$dateStr] = ($dateCount[$dateStr] ?? 0) + 1;
    $totalWU += $r->work_units;
    $dupeTag = $dateCount[$dateStr] > 1 ? ' *** DUPLICATE ***' : '';
    echo sprintf(
        "ID=%d | %s (%s) | type=%-10s | status=%-10s | WU=%.1f | shift=%s | holiday=%s%s\n",
        $r->id,
        $dateStr,
        $dayName,
        $r->attendance_type ?? 'work',
        $r->status ?? '-',
        $r->work_units,
        $r->shift_id ?? 'null',
        $r->is_holiday ? 'YES' : 'no',
        $dupeTag
    );
}

echo "\nTotal work_units sum: {$totalWU}\n";

// Check duplicates
$dupes = [];
foreach ($dateCount as $date => $cnt) {
    if ($cnt > 1) $dupes[$date] = $cnt;
}
if (!empty($dupes)) {
    echo "\n!!! DUPLICATE DATES:\n";
    foreach ($dupes as $d => $c) echo "  {$d}: {$c} records\n";
} else {
    echo "\nNo duplicate dates.\n";
}

// Count by attendance_type
$workRecords = $records->where('attendance_type', 'work');
echo "\nWork records: {$workRecords->count()}, sum WU={$workRecords->sum('work_units')}\n";
echo "Nghỉ ngày: ";
$offDays = [];
for ($d = 1; $d <= 31; $d++) {
    $dateStr = sprintf('2026-03-%02d', $d);
    if (!isset($dateCount[$dateStr])) {
        $offDays[] = $d;
    }
}
echo implode(', ', $offDays) . " (" . count($offDays) . " ngày)\n";
echo "Worked days: " . count($dateCount) . "\n";
