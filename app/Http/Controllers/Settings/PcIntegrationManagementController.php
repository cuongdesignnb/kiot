<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\PcIntegrationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\PcWebsite\StoreIntegrationClientRequest;
use App\Http\Requests\Integrations\PcWebsite\UpdateIntegrationClientRequest;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\IntegrationClient;
use App\Services\Integrations\PcWebsite\PcIntegrationCredentialResolver;
use App\Services\Integrations\PcWebsite\PcIntegrationCredentialService;
use App\Support\Integrations\PcWebsite\PcIntegrationResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PcIntegrationManagementController extends Controller
{
    public function __construct(
        private readonly PcIntegrationCredentialService $credentials,
        private readonly PcIntegrationCredentialResolver $resolver,
    ) {}

    public function index(): Response
    {
        $clients = IntegrationClient::query()
            ->where('provider', IntegrationClient::PROVIDER_PC_WEBSITE)
            ->with('branch:id,name')
            ->orderBy('id')
            ->get();
        $clientIds = $clients->pluck('id');
        $history = $clientIds->isEmpty()
            ? collect()
            : ActivityLog::query()
                ->where('subject_type', IntegrationClient::class)
                ->whereIn('subject_id', $clientIds)
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (ActivityLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'label' => $log->action_label,
                    'description' => $log->description,
                    'properties' => $log->properties ?? [],
                    'created_at' => $log->created_at?->toIso8601String(),
                ]);

        return Inertia::render('Settings/Integrations/PcWebsite', [
            'management_enabled' => (bool) config('integrations.pc_website.management_ui_enabled', false),
            'configuration_source' => $this->resolver->source(),
            'environment_import_available' => ! $this->resolver->hasDatabaseConfiguration()
                && trim((string) config('integrations.pc_website.client_id')) !== ''
                && (string) config('integrations.pc_website.secret') !== '',
            'clients' => $clients->map(fn (IntegrationClient $client) => $this->serializeClient($client))->values(),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'history' => $history,
            'defaults' => [
                'sales_channel' => 'Website PC',
                'timestamp_tolerance_seconds' => 300,
                'nonce_ttl_seconds' => 600,
                'rate_limit_per_minute' => 60,
                'reservation_ttl_minutes' => 1440,
            ],
        ]);
    }

    public function store(StoreIntegrationClientRequest $request): JsonResponse
    {
        try {
            $result = $this->credentials->create($request->validated(), $request->user());

            return response()->json([
                'client' => $this->serializeClient($result['client']->load('branch:id,name')),
                'secret' => $result['secret'],
            ], 201);
        } catch (PcIntegrationException $exception) {
            return $this->error($exception);
        }
    }

    public function update(UpdateIntegrationClientRequest $request, IntegrationClient $integrationClient): JsonResponse
    {
        try {
            $client = $this->credentials->update($integrationClient, $request->validated(), $request->user());

            return response()->json(['client' => $this->serializeClient($client->load('branch:id,name'))]);
        } catch (PcIntegrationException $exception) {
            return $this->error($exception);
        }
    }

    public function importEnvironment(Request $request): JsonResponse
    {
        try {
            $client = $this->credentials->importEnvironment($request->user());

            return response()->json([
                'client' => $this->serializeClient($client->load('branch:id,name')),
                'configuration_source' => 'database',
            ], 201);
        } catch (PcIntegrationException $exception) {
            return $this->error($exception);
        }
    }

    public function rotateSecret(Request $request, IntegrationClient $integrationClient): JsonResponse
    {
        try {
            $result = $this->credentials->rotate($integrationClient, $request->user());

            return response()->json([
                'client' => $this->serializeClient($result['client']->load('branch:id,name')),
                'secret' => $result['secret'],
            ]);
        } catch (PcIntegrationException $exception) {
            return $this->error($exception);
        }
    }

    public function enable(Request $request, IntegrationClient $integrationClient): JsonResponse
    {
        return $this->setEnabled($request, $integrationClient, true);
    }

    public function disable(Request $request, IntegrationClient $integrationClient): JsonResponse
    {
        return $this->setEnabled($request, $integrationClient, false);
    }

    public function revoke(Request $request, IntegrationClient $integrationClient): JsonResponse
    {
        try {
            $client = $this->credentials->revoke($integrationClient, $request->user());

            return response()->json(['client' => $this->serializeClient($client->load('branch:id,name'))]);
        } catch (PcIntegrationException $exception) {
            return $this->error($exception);
        }
    }

    public function pairingToken(Request $request, IntegrationClient $integrationClient): JsonResponse
    {
        try {
            $result = $this->credentials->issuePairingToken($integrationClient, $request->user());

            return response()->json([
                'reference' => $result['reference'],
                'pairing_code' => $result['pairing_code'],
                'expires_at' => $result['expires_at']->toIso8601String(),
            ], 201);
        } catch (PcIntegrationException $exception) {
            return $this->error($exception);
        }
    }

    public function testConnection(Request $request, IntegrationClient $integrationClient): JsonResponse
    {
        try {
            if (! $this->credentials->isComplete($integrationClient)) {
                throw new PcIntegrationException('INTEGRATION_NOT_CONFIGURED', 'Kết nối chưa đủ cấu hình.', 409);
            }
            ActivityLog::log('integration.connection_tested', 'Kiểm tra cấu hình kết nối Website PC', $integrationClient, [
                'status' => 'ready',
                'test_type' => 'configuration_readiness',
            ], $request->user()->id);

            return response()->json([
                'status' => 'ready',
                'message' => 'Cấu hình sẵn sàng. Hãy test handshake từ Website PC để xác nhận hai chiều.',
                'api_version' => $integrationClient->api_version,
            ]);
        } catch (PcIntegrationException $exception) {
            return $this->error($exception);
        }
    }

    private function setEnabled(Request $request, IntegrationClient $client, bool $enabled): JsonResponse
    {
        try {
            $client = $this->credentials->setEnabled($client, $enabled, $request->user());

            return response()->json(['client' => $this->serializeClient($client->load('branch:id,name'))]);
        } catch (PcIntegrationException $exception) {
            return $this->error($exception);
        }
    }

    private function serializeClient(IntegrationClient $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'provider' => $client->provider,
            'client_id' => $client->client_id,
            'secret_fingerprint' => $client->secret_fingerprint,
            'secret_status' => $client->revoked_at !== null
                ? 'revoked'
                : ($client->previous_secret_expires_at?->isFuture() ? 'rotation_grace' : 'active'),
            'website_url' => $client->website_url,
            'default_branch_id' => $client->default_branch_id,
            'branch' => $client->relationLoaded('branch') && $client->branch
                ? ['id' => $client->branch->id, 'name' => $client->branch->name]
                : null,
            'sales_channel' => $client->sales_channel,
            'is_enabled' => (bool) $client->is_enabled,
            'timestamp_tolerance_seconds' => (int) $client->timestamp_tolerance_seconds,
            'nonce_ttl_seconds' => (int) $client->nonce_ttl_seconds,
            'rate_limit_per_minute' => (int) $client->rate_limit_per_minute,
            'reservation_ttl_minutes' => (int) $client->reservation_ttl_minutes,
            'api_version' => $client->api_version,
            'last_connected_at' => $client->last_connected_at?->toIso8601String(),
            'last_request_at' => $client->last_request_at?->toIso8601String(),
            'last_request_ip' => $client->last_request_ip,
            'secret_created_at' => $client->secret_created_at?->toIso8601String(),
            'secret_rotated_at' => $client->secret_rotated_at?->toIso8601String(),
            'previous_secret_expires_at' => $client->previous_secret_expires_at?->toIso8601String(),
            'revoked_at' => $client->revoked_at?->toIso8601String(),
        ];
    }

    private function error(PcIntegrationException $exception): JsonResponse
    {
        return PcIntegrationResponse::error(
            $exception->errorCode,
            $exception->getMessage(),
            $exception->details,
            $exception->httpStatus,
        );
    }
}
