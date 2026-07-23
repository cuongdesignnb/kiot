<?php

namespace App\Http\Controllers\Api\Integrations\PcWebsite;

use App\Http\Controllers\Api\Integrations\PcWebsite\Concerns\HandlesPcIntegrationErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\PcWebsite\CancelExternalOrderRequest;
use App\Http\Requests\Integrations\PcWebsite\ImportExternalOrderRequest;
use App\Services\Integrations\PcWebsite\PcOrderImportService;
use App\Support\Integrations\PcWebsite\PcIntegrationResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ExternalOrderController extends Controller
{
    use HandlesPcIntegrationErrors;

    public function store(ImportExternalOrderRequest $request, PcOrderImportService $service): JsonResponse
    {
        try {
            $result = $service->import(
                $request->validated(),
                (string) $request->header('Idempotency-Key'),
                (string) $request->getContent(),
            );

            return PcIntegrationResponse::success(
                $this->orderData($result['order']),
                [],
                $result['duplicate'] ? 200 : 201,
                ['duplicate' => (bool) $result['duplicate']],
            );
        } catch (Throwable $exception) {
            return $this->integrationError($exception);
        }
    }

    public function status(Request $request, string $externalOrderId, PcOrderImportService $service): JsonResponse
    {
        try {
            return PcIntegrationResponse::success($service->status($externalOrderId));
        } catch (Throwable $exception) {
            return $this->integrationError($exception);
        }
    }

    public function cancel(
        CancelExternalOrderRequest $request,
        string $externalOrderId,
        PcOrderImportService $service,
    ): JsonResponse {
        try {
            $result = $service->cancel(
                $externalOrderId,
                $request->validated(),
                (string) $request->header('Idempotency-Key'),
                (string) $request->getContent(),
            );

            return PcIntegrationResponse::success(
                $this->orderData($result['order']),
                [],
                200,
                ['duplicate' => (bool) $result['duplicate']],
            );
        } catch (Throwable $exception) {
            return $this->integrationError($exception);
        }
    }

    private function orderData($order): array
    {
        return [
            'kiot_order_id' => $order->id,
            'kiot_order_code' => $order->code,
            'external_order_id' => $order->external_order_id,
            'status' => $order->status,
        ];
    }
}
