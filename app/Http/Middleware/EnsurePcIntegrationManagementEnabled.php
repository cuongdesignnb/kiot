<?php

namespace App\Http\Middleware;

use App\Support\Integrations\PcWebsite\PcIntegrationResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePcIntegrationManagementEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('integrations.pc_website.management_ui_enabled', false)) {
            return PcIntegrationResponse::error(
                'INTEGRATION_MANAGEMENT_DISABLED',
                'Chức năng quản trị tích hợp đang tắt.',
                [],
                503,
            );
        }

        return $next($request);
    }
}
