<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $user = Auth::user();

        if (!$user) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Chưa đăng nhập.'], 401)
                : redirect()->guest(route('login'));
        }

        $permissions = preg_split('/[|,]/', $permission, -1, PREG_SPLIT_NO_EMPTY);
        $permissions = array_map('trim', $permissions ?: [$permission]);

        if (!$user->hasAnyPermission($permissions)) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Bạn không có quyền thực hiện thao tác này.'], 403)
                : abort(403, 'Bạn không có quyền truy cập.');
        }

        return $next($request);
    }
}
