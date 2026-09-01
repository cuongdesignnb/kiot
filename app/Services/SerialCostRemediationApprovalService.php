<?php

namespace App\Services;

use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Keeps human approval separate from plan generation and application. The
 * resulting artifact binds the exact eligible lines, plan and database.
 */
final class SerialCostRemediationApprovalService
{
    public const CONTRACT_VERSION = 'serial-cost-remediation-approval-v1';

    public const MAX_BATCH_LINES = 25;

    public function __construct(private readonly SerialCostRemediationPlanService $plans) {}

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<int, string>  $invoiceCodes
     * @return array<string, mixed>
     */
    public function create(
        array $plan,
        array $invoiceCodes,
        ?int $limit,
        string $approvedBy,
        string $approvalReference,
    ): array {
        if ($invoiceCodes === [] && $limit === null) {
            throw new RuntimeException('Approval must select invoice codes or an explicit positive limit.');
        }

        $selected = $this->selectRepairLines($this->plans->validatedRepairLines($plan), $invoiceCodes, $limit);

        return $this->createFromSelection($plan, $selected, $approvedBy, $approvalReference);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<int, string>  $lineKeys
     * @return array<string, mixed>
     */
    public function createForLineKeys(
        array $plan,
        array $lineKeys,
        string $approvedBy,
        string $approvalReference,
    ): array {
        $lineKeys = array_values(array_unique(array_filter(array_map('strval', $lineKeys))));
        if ($lineKeys === []) {
            throw new RuntimeException('Approval must select at least one exact remediation line.');
        }

        $eligible = collect($this->plans->validatedRepairLines($plan))->keyBy('line_key');
        $selected = collect($lineKeys)
            ->map(function (string $lineKey) use ($eligible): array {
                $line = $eligible->get($lineKey);
                if (! is_array($line)) {
                    throw new RuntimeException('Approval selected a line outside the eligible remediation plan: '.$lineKey);
                }

                return $line;
            })
            ->sortBy('line_key')
            ->values();

        return $this->createFromSelection($plan, $selected, $approvedBy, $approvalReference);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  Collection<int, array<string, mixed>>  $selected
     * @return array<string, mixed>
     */
    private function createFromSelection(
        array $plan,
        Collection $selected,
        string $approvedBy,
        string $approvalReference,
    ): array {
        $approvedBy = trim($approvedBy);
        $approvalReference = trim($approvalReference);
        if ($approvedBy === '' || $approvalReference === '') {
            throw new RuntimeException('Approval requires both approved-by and approval-reference.');
        }
        if ($selected->isEmpty()) {
            throw new RuntimeException('No eligible repair lines matched the approval selection.');
        }
        if ($selected->count() > self::MAX_BATCH_LINES) {
            throw new RuntimeException('Approval batch exceeds '.self::MAX_BATCH_LINES.' invoice lines. Split it into smaller batches.');
        }

        $payload = [
            'contract_version' => self::CONTRACT_VERSION,
            'plan_hash' => (string) $plan['plan_hash'],
            'database_fingerprint' => (string) $plan['database_fingerprint'],
            'line_keys' => $selected->pluck('line_key')->values()->all(),
            'selection_hash' => $this->selectionHash($selected),
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
            throw new RuntimeException('Unsupported serial COGS approval contract.');
        }
        if (! hash_equals((string) ($approval['approval_hash'] ?? ''), $this->approvalHash($approval))) {
            throw new RuntimeException('Approval hash mismatch. Generate a new approval artifact.');
        }
        if (! hash_equals((string) ($approval['plan_hash'] ?? ''), (string) ($plan['plan_hash'] ?? ''))) {
            throw new RuntimeException('Approval does not belong to this remediation plan.');
        }
        if (! hash_equals((string) ($approval['database_fingerprint'] ?? ''), (string) ($plan['database_fingerprint'] ?? ''))) {
            throw new RuntimeException('Approval database fingerprint does not match the remediation plan.');
        }
        if (trim((string) ($approval['approved_by'] ?? '')) === ''
            || trim((string) ($approval['approval_reference'] ?? '')) === '') {
            throw new RuntimeException('Approval is missing approver identity or accounting reference.');
        }

        $eligible = collect($this->plans->validatedRepairLines($plan))
            ->keyBy('line_key');
        $lineKeys = array_values(array_unique(array_filter(array_map('strval', (array) ($approval['line_keys'] ?? [])))));
        if ($lineKeys === []) {
            throw new RuntimeException('Approval has no selected remediation lines.');
        }
        $selected = collect($lineKeys)
            ->map(function (string $lineKey) use ($eligible): array {
                $line = $eligible->get($lineKey);
                if (! is_array($line)) {
                    throw new RuntimeException('Approval selected a line outside the eligible remediation plan: '.$lineKey);
                }

                return $line;
            })
            ->sortBy('line_key')
            ->values();
        if ($selected->count() > self::MAX_BATCH_LINES) {
            throw new RuntimeException('Approval exceeds the maximum batch size.');
        }
        if (! hash_equals((string) ($approval['selection_hash'] ?? ''), $this->selectionHash($selected))) {
            throw new RuntimeException('Approval selection hash mismatch.');
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
        return $this->plans->canonicalHash($lines
            ->sortBy('line_key')
            ->map(fn (array $line): array => [
                'line_key' => $line['line_key'],
                'precondition_hash' => $line['precondition_hash'],
            ])
            ->values()
            ->all());
    }

    /** @param array<string, mixed> $approval */
    private function approvalHash(array $approval): string
    {
        unset($approval['approval_hash']);

        return $this->plans->canonicalHash($approval);
    }

    /**
     * @param  array<int, array<string, mixed>>  $repairLines
     * @param  array<int, string>  $invoiceCodes
     * @return Collection<int, array<string, mixed>>
     */
    private function selectRepairLines(array $repairLines, array $invoiceCodes, ?int $limit): Collection
    {
        $invoiceCodes = array_values(array_unique(array_filter(array_map('trim', $invoiceCodes))));
        if ($limit !== null && $limit < 1) {
            throw new RuntimeException('Approval limit must be a positive integer.');
        }

        $selected = collect($repairLines)
            ->when($invoiceCodes !== [], fn (Collection $lines): Collection => $lines->whereIn('invoice_code', $invoiceCodes))
            ->sortBy('line_key')
            ->values();

        return $limit === null ? $selected : $selected->take($limit)->values();
    }
}
