<?php

namespace App\Http\Controllers\Api\PublicApi;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateInventoryApiToken;
use App\Services\Integrations\InventoryApi\InventoryApiException;
use App\Services\Integrations\InventoryApi\InventoryApiQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Read-only partner inventory API (Bearer token, see AuthenticateInventoryApiToken).
 */
class InventoryController extends Controller
{
    public function __construct(private readonly InventoryApiQueryService $inventory) {}

    public function products(Request $request): JsonResponse
    {
        $filters = $this->validated($request, [
            'category_id' => ['nullable', 'integer', 'min:1'],
            'in_stock' => ['nullable', 'boolean'],
        ]);

        return $this->respond($request, $this->inventory->products($filters));
    }

    public function serials(Request $request): JsonResponse
    {
        $filters = $this->validated($request, [
            'status' => ['nullable', Rule::in([...InventoryApiQueryService::SERIAL_STATUSES, 'all'])],
            'readiness' => ['nullable', Rule::in(['ready', 'repairing'])],
        ]);

        return $this->respond($request, $this->inventory->serials($filters));
    }

    private function validated(Request $request, array $extraRules): array
    {
        $validator = Validator::make($request->query(), [
            'cursor' => ['nullable', 'string', 'max:2048'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'updated_since' => ['nullable', 'date'],
            'sku' => ['nullable', 'string', 'max:255'],
            ...$extraRules,
        ]);

        if ($validator->fails()) {
            throw new InventoryApiException('VALIDATION_FAILED', 'Tham số không hợp lệ.', 422, $validator->errors()->toArray());
        }

        return $validator->validated();
    }

    private function respond(Request $request, array $payload): JsonResponse
    {
        $request->attributes->set(AuthenticateInventoryApiToken::RESULT_COUNT_ATTRIBUTE, count($payload['data']));

        return response()->json($payload);
    }
}
