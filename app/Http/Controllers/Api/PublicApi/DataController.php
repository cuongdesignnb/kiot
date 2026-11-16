<?php

namespace App\Http\Controllers\Api\PublicApi;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateInventoryApiToken;
use App\Services\Integrations\InventoryApi\DataApiQueryService;
use App\Services\Integrations\InventoryApi\InventoryApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Read-only business data for the public Kiot API (ability `data:read`).
 */
class DataController extends Controller
{
    public function __construct(private readonly DataApiQueryService $data) {}

    public function invoices(Request $request): JsonResponse
    {
        return $this->respond($request, $this->data->invoices($this->validated($request, [
            'status' => ['nullable', 'string', 'max:50'],
            'customer_code' => ['nullable', 'string', 'max:100'],
        ])));
    }

    public function invoice(Request $request, string $code): JsonResponse
    {
        $invoice = $this->data->invoice($code);
        $request->attributes->set(AuthenticateInventoryApiToken::RESULT_COUNT_ATTRIBUTE, 1);

        return response()->json(['data' => $invoice]);
    }

    public function returns(Request $request): JsonResponse
    {
        return $this->respond($request, $this->data->returns($this->validated($request, [
            'status' => ['nullable', 'string', 'max:50'],
        ])));
    }

    public function purchases(Request $request): JsonResponse
    {
        return $this->respond($request, $this->data->purchases($this->validated($request, [
            'status' => ['nullable', 'string', 'max:50'],
        ])));
    }

    public function repairs(Request $request): JsonResponse
    {
        return $this->respond($request, $this->data->repairs($this->validated($request, [
            'status' => ['nullable', 'string', 'max:50'],
        ])));
    }

    public function tasks(Request $request): JsonResponse
    {
        return $this->respond($request, $this->data->tasks($this->validated($request, [
            'status' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', Rule::in(['general', 'repair'])],
        ])));
    }

    public function customerDebts(Request $request): JsonResponse
    {
        return $this->respond($request, $this->data->customerDebts($this->validated($request, [
            'customer_code' => ['nullable', 'string', 'max:100'],
        ])));
    }

    public function cashflows(Request $request): JsonResponse
    {
        return $this->respond($request, $this->data->cashflows($this->validated($request, [
            'type' => ['nullable', Rule::in(['receipt', 'payment'])],
            'status' => ['nullable', Rule::in(['active', 'cancelled'])],
        ])));
    }

    public function overview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if ($validator->fails()) {
            throw new InventoryApiException('VALIDATION_FAILED', 'Tham số không hợp lệ.', 422, $validator->errors()->toArray());
        }

        $request->attributes->set(AuthenticateInventoryApiToken::RESULT_COUNT_ATTRIBUTE, 1);

        return response()->json(['data' => $this->data->overview($validator->validated())]);
    }

    private function validated(Request $request, array $extraRules): array
    {
        $validator = Validator::make($request->query(), [
            'cursor' => ['nullable', 'string', 'max:2048'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.DataApiQueryService::MAX_LIMIT],
            'updated_since' => ['nullable', 'date'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
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
