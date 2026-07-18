<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Phase 5 — đồng bộ giá vốn serial từ task sửa chữa và recompute product cost mỗi đêm
Schedule::command('serial:sync-cost-from-tasks --recompute-products')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/serial-sync-cost.log'));

// Read-only drift detector. It never invokes reconcile/apply and a mismatch
// is surfaced as a non-zero scheduler result for operations alerting.
Schedule::command('debt:audit-parity --dry-run --all-partners --include-special-status --fail-on-mismatch --output=storage/app/audits/scheduled-debt-parity/latest')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/debt-parity-audit.log'));
