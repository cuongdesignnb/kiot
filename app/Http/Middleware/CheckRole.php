<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = Auth::user();

        if (!$user) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Chưa đăng nhập.'], 401)
                : redirect()->guest(route('login'));
        }

        if (!$user->hasAnyRole($roles) && !$user->isAdmin()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Bạn không có quyền thực hiện thao tác này.'], 403)
                : redirect('/')->with('error', 'Bạn không có quyền truy cập.');
        }

        return $next($request);
    }
}
