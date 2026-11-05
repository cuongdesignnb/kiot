<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\InventoryApiAccessLog;
use App\Models\InventoryApiToken;
use App\Services\Integrations\InventoryApi\InventoryApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryApiTokenController extends Controller
{
    public function __construct(private readonly InventoryApiTokenService $tokens) {}

    public function index(): Response
    {
        return Inertia::render('Settings/Integrations/InventoryApi', [
            'tokens' => InventoryApiToken::query()
                ->with(['creator:id,name', 'revoker:id,name'])
                ->latest('id')
                ->get()
                ->map(fn (InventoryApiToken $token) => $this->serialize($token))
                ->values(),
            'access_logs' => InventoryApiAccessLog::query()
                ->with('token:id,name,token_prefix')
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (InventoryApiAccessLog $log) => [
                    'id' => $log->id,
                    'token' => $log->token ? ['id' => $log->token->id, 'name' => $log->token->name, 'token_prefix' => $log->token->token_prefix] : null,
                    'method' => $log->method,
                    'path' => $log->path,
                    'query' => $log->query ?? [],
                    'status_code' => $log->status_code,
                    'outcome' => $log->outcome,
                    'result_count' => $log->result_count,
                    'duration_ms' => $log->duration_ms,
                    'ip_address' => $log->ip_address,
                    'created_at' => $log->created_at?->toIso8601String(),
                ]),
            'api_enabled' => (bool) config('integrations.inventory_api.enabled', false),
            'base_url' => rtrim(url('/api/public/v1/inventory'), '/'),
            'rate_limit_per_minute' => (int) config('integrations.inventory_api.rate_limit_per_minute', 60),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'account_label' => ['nullable', 'string', 'max:120'],
        ]);

        [$token, $plain] = $this->tokens->create($data['name'], $data['account_label'] ?? null, $request->user()?->id);

        return response()->json([
            'token' => $this->serialize($token->load('creator:id,name')),
            // Shown once; only the SHA-256 hash is stored.
            'plain_text_token' => $plain,
        ], 201);
    }

    public function revoke(Request $request, InventoryApiToken $inventoryApiToken): JsonResponse
    {
        $token = $this->tokens->revoke($inventoryApiToken, $request->user()?->id);

        return response()->json(['token' => $this->serialize($token->load(['creator:id,name', 'revoker:id,name']))]);
    }

    private function serialize(InventoryApiToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'account_label' => $token->account_label,
            'token_prefix' => $token->token_prefix,
            'abilities' => $token->abilities ?? [],
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'last_used_ip' => $token->last_used_ip,
            'request_count' => (int) $token->request_count,
            'created_by' => $token->creator?->name,
            'created_at' => $token->created_at?->toIso8601String(),
            'revoked_at' => $token->revoked_at?->toIso8601String(),
            'revoked_by' => $token->revoker?->name,
        ];
    }
}
