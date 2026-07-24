<?php

use App\Http\Controllers\Settings\PcIntegrationManagementController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/settings/integrations/website-pc', [PcIntegrationManagementController::class, 'index'])
        ->middleware('permission:integrations.view')
        ->name('settings.integrations.website-pc');

    Route::middleware(['permission:integrations.manage', 'pc.integration.management'])
        ->prefix('/settings/integrations/website-pc')
        ->group(function () {
            Route::post('/clients', [PcIntegrationManagementController::class, 'store']);
            Route::patch('/clients/{integrationClient}', [PcIntegrationManagementController::class, 'update']);
            Route::post('/clients/{integrationClient}/enable', [PcIntegrationManagementController::class, 'enable']);
            Route::post('/clients/{integrationClient}/disable', [PcIntegrationManagementController::class, 'disable']);
            Route::post('/clients/{integrationClient}/revoke', [PcIntegrationManagementController::class, 'revoke']);
            Route::post('/clients/{integrationClient}/pairing-token', [PcIntegrationManagementController::class, 'pairingToken']);
            Route::post('/clients/{integrationClient}/test', [PcIntegrationManagementController::class, 'testConnection']);
            Route::post('/import-environment', [PcIntegrationManagementController::class, 'importEnvironment']);
        });

    Route::post('/settings/integrations/website-pc/clients/{integrationClient}/rotate-secret', [PcIntegrationManagementController::class, 'rotateSecret'])
        ->middleware(['permission:integrations.rotate-secret', 'pc.integration.management']);
});
