<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Setting;

class CheckRepairEnabled
{
    public function handle(Request $request, Closure $next)
    {
        if (!Setting::get('repair_tracking_enabled', false)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Module sửa chữa chưa được bật.'], 404);
            }
            abort(404);
        }

        return $next($request);
    }
}
