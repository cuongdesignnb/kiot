<?php

namespace App\Http\Controllers\Api\Integrations\PcWebsite;

use App\Http\Controllers\Api\Integrations\PcWebsite\Concerns\HandlesPcIntegrationErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\PcWebsite\ProductSyncRequest;
use App\Services\Integrations\PcWebsite\PcProductSyncService;
use App\Support\Integrations\PcWebsite\PcIntegrationResponse;
use Illuminate\Http\JsonResponse;
use Throwable;

class ProductSyncController extends Controller
{
    use HandlesPcIntegrationErrors;

    public function index(ProductSyncRequest $request, PcProductSyncService $service): JsonResponse
    {
        try {
            $result = $service->paginate($request->validated());

            return PcIntegrationResponse::success($result['data'], $result['meta']);
        } catch (Throwable $exception) {
            return $this->integrationError($exception);
        }
    }

    public function show(ProductSyncRequest $request, string $sku, PcProductSyncService $service): JsonResponse
    {
        try {
            return PcIntegrationResponse::success($service->findBySku($sku));
        } catch (Throwable $exception) {
            return $this->integrationError($exception);
        }
    }
}
