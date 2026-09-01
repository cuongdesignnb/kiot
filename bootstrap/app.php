<?php

use App\Console\Commands\AuditProductDeletions;
use App\Console\Commands\AuditSerialCostSnapshots;
use App\Console\Commands\MediaLibraryBackfillCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->group(base_path('routes/integration-management.php'));
            Route::middleware('web')
                ->group(base_path('routes/product-images.php'));
        },
    )
    ->withCommands([
        AuditProductDeletions::class,
        AuditSerialCostSnapshots::class,
        MediaLibraryBackfillCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Enable Sanctum cookie/session auth for API routes (required for /api/my-tasks, /api/notifications)
        $middleware->statefulApi();

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\AutoLockBranch::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'attendance.agent' => \App\Http\Middleware\VerifyAttendanceAgentSignature::class,
            'pc.integration' => \App\Http\Middleware\VerifyPcIntegrationSignature::class,
            'pc.integration.management' => \App\Http\Middleware\EnsurePcIntegrationManagementEnabled::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
