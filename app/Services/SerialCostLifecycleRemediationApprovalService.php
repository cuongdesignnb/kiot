<?php

namespace App\Services;

use Illuminate\Support\Collection;
use RuntimeException;

/** Binds an explicit owner-delegated approval to the complete lifecycle plan. */
final class SerialCostLifecycleRemediationApprovalService
{
    public const CONTRACT_VERSION = 'serial-cost-lifecycle-remediation-approval-v1';

    public const MAX_LINES = 100;

    public function __construct(private readonly SerialCostLifecycleRemediationPlanService $plans) {}

    /** @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public function create(array $plan, string $approvedBy, string $approvalReference): array
    {
        $lines = collect($this->plans->validatedRepairLines($plan));
        $approvedBy = trim($approvedBy);
        $approvalReference = trim($approvalReference);
        if ($approvedBy === '' || $approvalReference === '') {
            throw new RuntimeException('Lifecycle approval requires approved-by and approval-reference.');
        }
        if ($lines->isEmpty()) {
            throw new RuntimeException('Lifecycle plan has no eligible repair lines.');
        }
        if ($lines->count() > self::MAX_LINES) {
            throw new RuntimeException('Lifecycle plan exceeds '.self::MAX_LINES.' lines; split it by product scope.');
        }
        if ((int) data_get($plan, 'summary.blocked_lines', 0) > 0) {
            throw new RuntimeException('Lifecycle plan still contains blocked dependencies. Resolve them before approval.');
        }

        $payload = [
            'contract_version' => self::CONTRACT_VERSION,
            'plan_hash' => (string) $plan['plan_hash'],
            'database_fingerprint' => (string) $plan['database_fingerprint'],
            'line_keys' => $lines->pluck('line_key')->sort()->values()->all(),
            'selection_hash' => $this->selectionHash($lines),
            'approved_by' => $approvedBy,
            'approval_reference' => $approvalReference,
            'approved_at' => now()->utc()->format('Y-m-d\\TH:i:s.u\\Z'),
        ];
        $payload['approval_hash'] = $this->approvalHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $approval
     * @return array{lines:Collection<int, array<string, mixed>>,plan_hash:string,approval_hash:string}
     */
    public function validatedSelection(array $plan, array $approval): array
    {
        if (($approval['contract_version'] ?? null) !== self::CONTRACT_VERSION) {
            throw new RuntimeException('Unsupported serial lifecycle approval contract.');
        }
        if (! hash_equals((string) ($approval['approval_hash'] ?? ''), $this->approvalHash($approval))) {
            throw new RuntimeException('Lifecycle approval hash mismatch.');
        }
        if (! hash_equals((string) ($approval['plan_hash'] ?? ''), (string) ($plan['plan_hash'] ?? ''))) {
            throw new RuntimeException('Lifecycle approval does not belong to this plan.');
        }
        if (! hash_equals(
            (string) ($approval['database_fingerprint'] ?? ''),
            (string) ($plan['database_fingerprint'] ?? ''),
        )) {
            throw new RuntimeException('Lifecycle approval database fingerprint mismatch.');
        }
        if (trim((string) ($approval['approved_by'] ?? '')) === ''
            || trim((string) ($approval['approval_reference'] ?? '')) === '') {
            throw new RuntimeException('Lifecycle approval is missing approver identity or reference.');
        }

        $eligible = collect($this->plans->validatedRepairLines($plan))->keyBy('line_key');
        $lineKeys = array_values(array_unique(array_filter(array_map('strval', (array) ($approval['line_keys'] ?? [])))));
        if ($lineKeys === [] || count($lineKeys) !== $eligible->count()) {
            throw new RuntimeException('Lifecycle approval must bind every eligible line in the plan.');
        }
        $selected = collect($lineKeys)->map(function (string $lineKey) use ($eligible): array {
            $line = $eligible->get($lineKey);
            if (! is_array($line)) {
                throw new RuntimeException('Lifecycle approval selected an unknown line: '.$lineKey);
            }

            return $line;
        })->sortBy('line_key')->values();
        if ($selected->count() > self::MAX_LINES) {
            throw new RuntimeException('Lifecycle approval exceeds the maximum line count.');
        }
        if (! hash_equals((string) ($approval['selection_hash'] ?? ''), $this->selectionHash($selected))) {
            throw new RuntimeException('Lifecycle approval selection hash mismatch.');
        }

        return [
            'lines' => $selected,
            'plan_hash' => (string) $plan['plan_hash'],
            'approval_hash' => (string) $approval['approval_hash'],
        ];
    }

    /** @param Collection<int, array<string, mixed>> $lines */
    private function selectionHash(Collection $lines): string
    {
        return $this->plans->canonicalHash($lines->sortBy('line_key')->map(fn (array $line): array => [
            'line_key' => $line['line_key'],
            'identity_hash' => $line['identity_hash'],
            'precondition_hash' => $line['precondition_hash'],
            'expected_state_hash' => $line['expected_state_hash'],
        ])->values()->all());
    }

    /** @param array<string, mixed> $approval */
    private function approvalHash(array $approval): string
    {
        unset($approval['approval_hash']);

        return $this->plans->canonicalHash($approval);
    }
}
