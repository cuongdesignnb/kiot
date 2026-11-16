<?php

namespace App\Http\Controllers\Api\Integrations\PcWebsite;

use App\Http\Controllers\Api\Integrations\PcWebsite\Concerns\HandlesPcIntegrationErrors;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\PcWebsite\CancelExternalOrderRequest;
use App\Http\Requests\Integrations\PcWebsite\DeliverExternalOrderRequest;
use App\Http\Requests\Integrations\PcWebsite\ExternalOrderPaymentRequest;
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

    /**
     * Website PC reports the order as delivered -> Kiot converts it to an invoice.
     */
    public function deliver(
        DeliverExternalOrderRequest $request,
        string $externalOrderId,
        PcOrderImportService $service,
    ): JsonResponse {
        try {
            $result = $service->deliver(
                $externalOrderId,
                $request->validated(),
                (string) $request->header('Idempotency-Key'),
                (string) $request->getContent(),
            );

            return PcIntegrationResponse::success(
                $this->orderData($result['order']) + ['kiot_invoice_code' => $result['invoice']?->code],
                [],
                200,
                ['duplicate' => (bool) $result['duplicate']],
            );
        } catch (Throwable $exception) {
            return $this->integrationError($exception);
        }
    }

    /**
     * Website PC reports a payment: order deposit before conversion, invoice
     * collection after conversion.
     */
    public function payments(
        ExternalOrderPaymentRequest $request,
        string $externalOrderId,
        PcOrderImportService $service,
    ): JsonResponse {
        try {
            $result = $service->payment(
                $externalOrderId,
                $request->validated(),
                (string) $request->header('Idempotency-Key'),
                (string) $request->getContent(),
            );

            return PcIntegrationResponse::success(
                [
                    'kiot_order_code' => $result['order']->code,
                    'external_order_id' => $result['order']->external_order_id,
                    'kiot_invoice_code' => $result['invoice']?->code,
                    'cash_flow_code' => $result['cash_flow']?->code,
                    'amount_paid_total' => (int) round((float) $result['amount_paid_total']),
                ],
                [],
                $result['duplicate'] ? 200 : 201,
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
