<?php

namespace App\Http\Controllers\Api\Integrations\PcWebsite;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\IntegrationClient;
use App\Services\Integrations\PcWebsite\PcIntegrationCredentialResolver;
use App\Services\Integrations\PcWebsite\RuntimePcIntegrationConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConnectionController extends Controller
{
    public function __invoke(Request $request, PcIntegrationCredentialResolver $resolver): JsonResponse
    {
        /** @var RuntimePcIntegrationConfig $runtime */
        $runtime = $request->attributes->get('pc_integration_runtime');
        $resolver->recordAuthenticatedRequest($runtime, $request, true);

        if ($runtime->integrationClientId !== null) {
            $client = IntegrationClient::query()->find($runtime->integrationClientId);
            if ($client) {
                ActivityLog::log('integration.connection_tested', 'Website PC kiểm tra kết nối thành công', $client, [
                    'status' => 'connected',
                    'api_version' => $runtime->apiVersion,
                ]);
            }
        }

        return response()->json([
            'status' => 'ok',
            'provider' => 'kiot',
            'api_version' => $runtime->apiVersion,
            'server_time' => now()->toIso8601String(),
            'client_id' => $runtime->clientId,
            'configuration_source' => $runtime->source,
            'capabilities' => [
                'products' => true,
                'orders' => true,
                'price_books' => false,
                'google_sheets' => false,
            ],
        ]);
    }
}
