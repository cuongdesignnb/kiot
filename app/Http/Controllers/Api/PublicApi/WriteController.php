<?php

namespace App\Http\Controllers\Api\PublicApi;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateInventoryApiToken;
use App\Models\InventoryApiToken;
use App\Models\PublicApiWriteLog;
use App\Services\Integrations\InventoryApi\PublicApiWriteGateway;
use App\Services\Integrations\InventoryApi\PublicApiWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The two write functions of the public Kiot API. Each one needs its own
 * token ability, is idempotent and is recorded in public_api_write_logs.
 */
class WriteController extends Controller
{
    public function __construct(
        private readonly PublicApiWriteGateway $gateway,
        private readonly PublicApiWriteService $writes,
    ) {}

    public function technicianPrice(Request $request, string $sku): JsonResponse
    {
        $token = $this->token($request);

        return $this->gateway->run(
            $request,
            $token,
            PublicApiWriteLog::ACTION_TECHNICIAN_PRICE_UPDATE,
            false,
            fn (PublicApiWriteLog $log) => $this->writes->updateTechnicianPrice($sku, $request->json()->all(), $token, $log),
            $sku,
        );
    }

    public function createInvoice(Request $request): JsonResponse
    {
        $token = $this->token($request);

        return $this->gateway->run(
            $request,
            $token,
            PublicApiWriteLog::ACTION_INVOICE_CREATE,
            true,
            fn (PublicApiWriteLog $log) => $this->writes->createInvoice($request->json()->all(), $token, $log),
        );
    }

    private function token(Request $request): InventoryApiToken
    {
        return $request->attributes->get(AuthenticateInventoryApiToken::TOKEN_ATTRIBUTE);
    }
}
