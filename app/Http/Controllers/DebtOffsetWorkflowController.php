<?php

namespace App\Http\Controllers;

use App\Http\Requests\DebtOffsets\ApplyDebtOffsetRequest;
use App\Http\Requests\DebtOffsets\ApproveDebtOffsetRequest;
use App\Http\Requests\DebtOffsets\RejectDebtOffsetRequest;
use App\Http\Requests\DebtOffsets\ReverseDebtOffsetRequest;
use App\Http\Requests\DebtOffsets\StoreDebtOffsetDraftRequest;
use App\Http\Requests\DebtOffsets\SubmitDebtOffsetRequest;
use App\Http\Requests\DebtOffsets\UpdateDebtOffsetDraftRequest;
use App\Http\Requests\DebtOffsets\VoidDebtOffsetRequest;
use App\Models\Customer;
use App\Models\DebtOffset;
use App\Services\Debt\DebtOffsetWorkflowService;
use App\Services\Debt\DebtOffsetWriteMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DebtOffsetWorkflowController extends Controller
{
    public function __construct(
        private readonly DebtOffsetWorkflowService $service,
        private readonly DebtOffsetWriteMode $writeMode,
    ) {}

    public function index(Request $request): JsonResponse|Response
    {
        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);
        $query = DebtOffset::query()
            ->with([
                'customer:id,code,name,debt_amount,supplier_debt_amount,branch_id',
                'requester:id,name',
                'approver:id,name',
                'rejecter:id,name',
            ])
            ->orderByDesc('id');

        $status = trim((string) $request->input('status', ''));
        if ($status === 'legacy') {
            $query->whereNull('workflow_status');
        } elseif ($status !== '') {
            $query->where('workflow_status', $status);
        }
        foreach (['partner_id' => 'customer_id', 'requester_id' => 'requested_by', 'approver_id' => 'approved_by'] as $input => $column) {
            if ($request->filled($input)) {
                $query->where($column, (int) $request->input($input));
            }
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', (string) $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', (string) $request->input('date_to'));
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($nested) use ($search): void {
                $nested->where('code', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search): void {
                        $customerQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    });
            });
        }

        $offsets = $query->paginate($perPage)->withQueryString();
        $offsets->through(fn (DebtOffset $offset): array => $this->resource($offset));
        $payload = [
            'offsets' => $offsets,
            'filters' => $request->only(['status', 'partner_id', 'requester_id', 'approver_id', 'date_from', 'date_to', 'search', 'per_page']),
            'write_mode' => $this->writeMode->current(),
        ];

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('DebtOffsets/Index', $payload);
    }

    public function show(Request $request, DebtOffset $debtOffset): JsonResponse
    {
        $debtOffset->load([
            'customer:id,code,name,debt_amount,supplier_debt_amount,branch_id',
            'requester:id,name',
            'approver:id,name',
            'rejecter:id,name',
            'originalOffset:id,code',
            'reversalVoucher:id,code',
        ]);

        return response()->json(['data' => $this->resource($debtOffset)]);
    }

    public function store(StoreDebtOffsetDraftRequest $request, Customer $customer): JsonResponse
    {
        $result = $this->service->createDraft(
            $customer,
            $request->user(),
            $request->validated('amount'),
            $request->validated('note'),
            $request->idempotencyKey(),
        );

        return response()->json(['data' => $result], $result['idempotent_replay'] ? 200 : 201);
    }

    public function update(UpdateDebtOffsetDraftRequest $request, DebtOffset $debtOffset): JsonResponse
    {
        return $this->commandResponse($this->service->updateDraft(
            $debtOffset,
            $request->user(),
            $request->validated('amount'),
            $request->validated('note'),
            $request->validated('version_token'),
            $request->idempotencyKey(),
        ));
    }

    public function submit(SubmitDebtOffsetRequest $request, DebtOffset $debtOffset): JsonResponse
    {
        return $this->commandResponse($this->service->submit(
            $debtOffset,
            $request->user(),
            $request->validated('version_token'),
            $request->idempotencyKey(),
        ));
    }

    public function approve(ApproveDebtOffsetRequest $request, DebtOffset $debtOffset): JsonResponse
    {
        return $this->commandResponse($this->service->approve(
            $debtOffset,
            $request->user(),
            $request->validated('version_token'),
            $request->idempotencyKey(),
        ));
    }

    public function reject(RejectDebtOffsetRequest $request, DebtOffset $debtOffset): JsonResponse
    {
        return $this->commandResponse($this->service->reject(
            $debtOffset,
            $request->user(),
            $request->validated('rejection_reason'),
            $request->validated('version_token'),
            $request->idempotencyKey(),
        ));
    }

    public function apply(ApplyDebtOffsetRequest $request, DebtOffset $debtOffset): JsonResponse
    {
        return $this->commandResponse($this->service->apply(
            $debtOffset,
            $request->user(),
            $request->validated('version_token'),
            $request->idempotencyKey(),
        ));
    }

    public function reverse(ReverseDebtOffsetRequest $request, DebtOffset $debtOffset): JsonResponse
    {
        return $this->commandResponse($this->service->reverse(
            $debtOffset,
            $request->user(),
            $request->validated('reason'),
            $request->validated('version_token'),
            $request->idempotencyKey(),
        ));
    }

    public function void(VoidDebtOffsetRequest $request, DebtOffset $debtOffset): JsonResponse
    {
        return $this->commandResponse($this->service->void(
            $debtOffset,
            $request->user(),
            $request->validated('reason'),
            $request->validated('version_token'),
            $request->idempotencyKey(),
        ));
    }

    private function commandResponse(array $result): JsonResponse
    {
        return response()->json(['data' => $result]);
    }

    private function resource(DebtOffset $offset): array
    {
        return [
            'id' => $offset->id,
            'code' => $offset->code,
            'partner' => $offset->customer ? [
                'id' => $offset->customer->id,
                'code' => $offset->customer->code,
                'name' => $offset->customer->name,
                'debt_amount' => (string) $offset->customer->debt_amount,
                'supplier_debt_amount' => (string) $offset->customer->supplier_debt_amount,
                'branch_id' => $offset->customer->branch_id,
            ] : null,
            'amount' => (string) $offset->amount,
            'receivable_before' => (string) $offset->receivable_before,
            'payable_before' => (string) $offset->payable_before,
            'receivable_after' => (string) $offset->receivable_after,
            'payable_after' => (string) $offset->payable_after,
            'workflow_status' => $offset->workflow_status,
            'status' => $offset->status,
            'note' => $offset->note,
            'requester' => $offset->requester ? $offset->requester->only(['id', 'name']) : null,
            'requested_at' => $offset->requested_at?->toISOString(),
            'approver' => $offset->approver ? $offset->approver->only(['id', 'name']) : null,
            'approved_at' => $offset->approved_at?->toISOString(),
            'rejecter' => $offset->rejecter ? $offset->rejecter->only(['id', 'name']) : null,
            'rejected_at' => $offset->rejected_at?->toISOString(),
            'rejection_reason' => $offset->rejection_reason,
            'applied_at' => $offset->applied_at?->toISOString(),
            'cancel_reason' => $offset->cancel_reason,
            'reverses_debt_offset_id' => $offset->reverses_debt_offset_id,
            'original_offset' => $offset->relationLoaded('originalOffset') ? $offset->originalOffset?->only(['id', 'code']) : null,
            'reversal_voucher' => $offset->relationLoaded('reversalVoucher') ? $offset->reversalVoucher?->only(['id', 'code']) : null,
            'is_legacy' => $offset->isLegacy(),
            'version_token' => $offset->versionToken(),
            'created_at' => $offset->created_at?->toISOString(),
        ];
    }
}
