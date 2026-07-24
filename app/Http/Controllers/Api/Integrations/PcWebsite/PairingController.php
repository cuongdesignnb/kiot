<?php

namespace App\Http\Controllers\Api\Integrations\PcWebsite;

use App\Exceptions\PcIntegrationException;
use App\Http\Controllers\Controller;
use App\Services\Integrations\PcWebsite\PcIntegrationPairingService;
use App\Support\Integrations\PcWebsite\PcIntegrationResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PairingController extends Controller
{
    public function __invoke(Request $request, PcIntegrationPairingService $pairing): JsonResponse
    {
        $payload = $request->validate([
            'reference' => ['required', 'string', 'max:64'],
            'pairing_code' => ['required', 'string', 'max:255'],
            'website_url' => ['required', 'string', 'max:2048'],
        ]);

        try {
            return response()->json($pairing->pair($payload, $request));
        } catch (PcIntegrationException $exception) {
            return PcIntegrationResponse::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->details,
                $exception->httpStatus,
            );
        }
    }
}
