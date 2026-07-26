<?php

namespace App\Http\Controllers\Api\Integrations\PcWebsite;

use App\Http\Controllers\Api\Integrations\PcWebsite\Concerns\HandlesPcIntegrationErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\PcWebsite\CatalogSyncRequest;
use App\Services\Integrations\PcWebsite\PcPriceBookSyncService;
use App\Support\Integrations\PcWebsite\PcIntegrationResponse;
use Illuminate\Http\JsonResponse;
use Throwable;

class PriceBookSyncController extends Controller
{
    use HandlesPcIntegrationErrors;

    public function __invoke(CatalogSyncRequest $request, PcPriceBookSyncService $service): JsonResponse
    {
        try {
            $result = $service->paginate($request->validated());

            return PcIntegrationResponse::success($result['data'], $result['meta']);
        } catch (Throwable $exception) {
            return $this->integrationError($exception);
        }
    }
}
