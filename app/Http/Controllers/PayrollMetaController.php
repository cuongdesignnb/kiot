<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\LockPeriodService;

class PayrollMetaController extends Controller
{
    public function datePolicy(LockPeriodService $lockPeriod)
    {
        $user = auth()->user();

        return response()->json([
            'lock_date' => $lockPeriod->getLockDate()?->toDateString(),
            'backdate_limit_days' => (int) Setting::get('payroll_backdate_limit_days', 30),
            'can_override_locked_period' => (bool) $user?->hasPermission('payroll.override_locked_period'),
            'can_override_backdate_limit' => (bool) $user?->hasPermission('payroll.override_backdate_limit'),
            'server_date' => now()->toDateString(),
        ]);
    }
}
