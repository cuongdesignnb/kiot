<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\PartnerDebtOperation;
use App\Models\PartnerDebtOperationParticipant;
use App\Services\Debt\CanonicalPartnerDebtService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class ApplyDebtFixPlanCommand extends Command
{
    protected $signature = 'debt:apply-fix-plan
        {--plan-json= : Required guarded plan JSON}
        {--dry-run : Explicit compatibility alias for the default dry-run mode}
        {--apply : Apply projection repairs; omitted means dry-run}
        {--approval-hash= : Required with --apply}
        {--fix-run-id= : Legacy preview guard}
        {--confirm-code= : Legacy preview confirmation}
        {--group= : Legacy fix-group filter}
        {--partner-code=* : Optional allowlist from the approved plan}
        {--limit= : Optional positive row limit}
        {--backup-confirmed : Legacy backup acknowledgement}
        {--rollback-export= : Legacy rollback preview export}';

    protected $description = 'Dry-run by default; guarded apply updates only stored projections backed by canonical events';

    public function handle(CanonicalPartnerDebtService $canonical): int
    {
        $path = (string) ($this->option('plan-json') ?? '');
        if ($path === '') {
            $this->error('Missing --plan-json.');

            return self::FAILURE;
        }
        if (! is_file($path)) {
            $this->error('Plan file not found: '.$path);

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! isset($payload['plan_hash'], $payload['database_fingerprint'])) {
            return $this->handleLegacyPreview($payload);
        }
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('--dry-run and --apply cannot be used together.');

            return self::FAILURE;
        }
        $rows = array_values((array) ($payload['rows'] ?? $payload['plans'] ?? []));
        $expectedPlanHash = hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        if (! hash_equals((string) ($payload['plan_hash'] ?? ''), $expectedPlanHash)) {
            $this->error('Plan hash mismatch. Regenerate the plan from the immutable audit report.');

            return self::FAILURE;
        }
        if (! hash_equals((string) ($payload['database_fingerprint'] ?? ''), $this->databaseFingerprint())) {
            $this->error('Database fingerprint mismatch. This plan belongs to another database/engine.');

            return self::FAILURE;
        }

        $rows = $this->selectedRows($rows);
        $repairs = collect($rows)->where('proposed_action_type', 'UPDATE_STORED_PROJECTION')->values();
        $blocked = collect($rows)->filter(fn (array $row): bool => (array) ($row['blocking_flags'] ?? []) !== []);
        $preview = [
            'mode' => $this->option('apply') ? 'apply' : 'dry-run',
            'plan_hash' => $expectedPlanHash,
            'selected_count' => count($rows),
            'repair_count' => $repairs->count(),
            'manual_review_count' => $blocked->count(),
            'rows_changed' => 0,
        ];

        if (! $this->option('apply')) {
            $this->line(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if (! hash_equals((string) ($payload['approval_hash'] ?? ''), (string) ($this->option('approval-hash') ?? ''))) {
            $this->error('Invalid --approval-hash.');

            return self::FAILURE;
        }
        if ($blocked->isNotEmpty()) {
            $this->error('Selected plan still contains manual-review evidence; nothing was applied.');

            return self::FAILURE;
        }
        if ($repairs->isEmpty()) {
            $this->error('Selected plan has no safe stored-projection repair.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('partner_debt_operations') || ! Schema::hasTable('partner_debt_operation_participants')) {
            $this->error('Debt operation schema is not installed. Run approved migrations on the clone first.');

            return self::FAILURE;
        }

        $selectionHash = hash('sha256', json_encode($repairs->pluck('partner_id')->sort()->values(), JSON_THROW_ON_ERROR));
        $idempotencyKey = 'debt-repair:'.$expectedPlanHash.':'.substr($selectionHash, 0, 16);
        $existing = PartnerDebtOperation::query()
            ->where('operation_type', 'debt.repair_projection')
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing && $existing->status === 'committed') {
            $this->line(json_encode([
                'result' => 'REPLAY',
                'operation_uuid' => $existing->operation_uuid,
                'rows_changed' => 0,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $result = DB::transaction(function () use ($repairs, $canonical, $idempotencyKey, $expectedPlanHash, $payload): array {
            $locked = Customer::query()
                ->whereKey($repairs->pluck('partner_id')->map(fn ($id) => (int) $id)->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $changes = [];

            foreach ($repairs->sortBy('partner_id') as $row) {
                $partner = $locked->get((int) $row['partner_id']);
                if (! $partner || (string) $partner->code !== (string) $row['partner_code']) {
                    throw new RuntimeException('Partner identity changed for plan row '.($row['partner_id'] ?? '?'));
                }
                $before = (array) $row['before_snapshot'];
                if (abs((float) $partner->debt_amount - (float) $before['customer_receivable']) > 1.0
                    || abs((float) $partner->supplier_debt_amount - (float) $before['supplier_payable']) > 1.0) {
                    throw new RuntimeException('Stored projection changed after plan generation for '.$partner->code);
                }

                $currentCanonical = $canonical->calculate($partner);
                $target = (array) $row['canonical_target'];
                if (abs((float) $currentCanonical['customer_receivable'] - (float) $target['customer_receivable']) > 1.0
                    || abs((float) $currentCanonical['supplier_payable'] - (float) $target['supplier_payable']) > 1.0) {
                    throw new RuntimeException('Canonical evidence changed after plan generation for '.$partner->code);
                }

                $partner->forceFill([
                    'debt_amount' => (float) $target['customer_receivable'],
                    'supplier_debt_amount' => (float) $target['supplier_payable'],
                ])->save();
                $changes[] = [
                    'partner_id' => (int) $partner->id,
                    'partner_code' => (string) $partner->code,
                    'before' => $before,
                    'after' => $target,
                    'customer_delta' => (float) $target['customer_receivable'] - (float) $before['customer_receivable'],
                    'supplier_delta' => (float) $target['supplier_payable'] - (float) $before['supplier_payable'],
                ];
            }

            $operation = PartnerDebtOperation::query()->create([
                'operation_uuid' => (string) Str::uuid(),
                'partner_id' => null,
                'operation_type' => 'debt.repair_projection',
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $expectedPlanHash,
                'request_hash_version' => 1,
                'status' => 'committed',
                'result' => ['rows_changed' => count($changes), 'changes' => $changes],
                'attempt_count' => 1,
                'initiated_at' => now(),
                'committed_at' => now(),
                'metadata' => [
                    'plan_hash' => $expectedPlanHash,
                    'report_hash' => $payload['source_report_sha256'] ?? null,
                    'database_fingerprint' => $payload['database_fingerprint'] ?? null,
                ],
            ]);

            foreach ($changes as $change) {
                $effectRole = abs($change['customer_delta']) > 1.0 && abs($change['supplier_delta']) > 1.0
                    ? 'both'
                    : (abs($change['supplier_delta']) > 1.0 ? 'supplier' : 'customer');
                PartnerDebtOperationParticipant::query()->create([
                    'operation_id' => $operation->id,
                    'partner_id' => $change['partner_id'],
                    'participant_role' => 'projection_repair',
                    'effect_role' => $effectRole,
                    'customer_delta' => in_array($effectRole, ['customer', 'both'], true) ? $change['customer_delta'] : null,
                    'supplier_delta' => in_array($effectRole, ['supplier', 'both'], true) ? $change['supplier_delta'] : null,
                ]);
                if (Schema::hasTable('activity_logs')) {
                    ActivityLog::log(
                        'partner_debt_projection_repair',
                        'Applied approved canonical partner debt projection repair.',
                        Customer::find($change['partner_id']),
                        $change + ['operation_uuid' => $operation->operation_uuid],
                    );
                }
            }

            return ['operation_uuid' => $operation->operation_uuid, 'rows_changed' => count($changes)];
        }, 3);

        $this->line(json_encode($result + ['result' => 'APPLIED'], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /**
     * Preserve the previous proposal-only contract for old plan artifacts.
     * Legacy payloads can never enter the P0 projection-repair write path.
     */
    private function handleLegacyPreview(array $payload): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        if (! $dryRun && ! $apply) {
            $this->error('Pass exactly one mode: --dry-run or --apply.');

            return self::FAILURE;
        }
        if ($dryRun && $apply) {
            $this->error('Pass only one mode. --dry-run and --apply cannot be used together.');

            return self::FAILURE;
        }

        if ($apply) {
            $fixRunId = (string) ($this->option('fix-run-id') ?? '');
            $confirmCode = (string) ($this->option('confirm-code') ?? '');
            if ($fixRunId === '') {
                $this->error('--apply requires --fix-run-id.');

                return self::FAILURE;
            }
            if ($confirmCode === '') {
                $this->error('--apply requires --confirm-code.');

                return self::FAILURE;
            }
            if (! hash_equals('CONFIRM-DEBT-FIX-'.$fixRunId, $confirmCode)) {
                $this->error('--confirm-code must equal CONFIRM-DEBT-FIX-{fix_run_id}.');

                return self::FAILURE;
            }
            if (! $this->option('backup-confirmed')) {
                $this->error('--apply requires --backup-confirmed.');

                return self::FAILURE;
            }
            if ((string) ($this->option('rollback-export') ?? '') === '') {
                $this->error('--apply requires --rollback-export.');

                return self::FAILURE;
            }
            if ((array) $this->option('partner-code') === []) {
                $this->error('--apply requires at least one --partner-code allowlist entry.');

                return self::FAILURE;
            }
        }

        $group = (string) ($this->option('group') ?? '');
        $codes = array_map('strval', (array) $this->option('partner-code'));
        $limit = $this->option('limit') === null ? null : max(1, (int) $this->option('limit'));
        $plans = collect((array) ($payload['plans'] ?? []))
            ->when($group !== '', fn ($rows) => $rows->where('fix_group', $group))
            ->when($codes !== [], fn ($rows) => $rows->filter(
                fn (array $row): bool => in_array((string) ($row['code'] ?? ''), $codes, true),
            ))
            ->when($limit !== null, fn ($rows) => $rows->take($limit))
            ->values();
        if ($plans->isEmpty()) {
            $this->error('No plan rows selected.');

            return self::FAILURE;
        }

        $allowedGroups = ['A_OPENING_BALANCE_REVIEW', 'B_DOCUMENTS_NO_LEDGER'];
        $blockedGroups = [
            'C_LEDGER_DOCUMENT_MISMATCH',
            'D_CUSTOMER_ONLY_REVIEW',
            'E_DUAL_ROLE_ORIENTATION_REVIEW',
            'F_STORED_BALANCE_OPENING_CANDIDATE',
            'X_PLAN_INPUT_MISMATCH',
            'Z_NEEDS_MANUAL_REVIEW',
        ];
        foreach ($plans as $plan) {
            $fixGroup = (string) ($plan['fix_group'] ?? '');
            if (($plan['classification'] ?? '') === 'PLAN_INPUT_MISMATCH' || $fixGroup === 'X_PLAN_INPUT_MISMATCH') {
                $this->error('Plan has PLAN_INPUT_MISMATCH. Rerun audit/inspect/plan from the same snapshot.');

                return self::FAILURE;
            }
            if (in_array($fixGroup, $blockedGroups, true)) {
                $this->error('Blocked fix group cannot be applied: '.$fixGroup);

                return self::FAILURE;
            }
            if (! in_array($fixGroup, $allowedGroups, true)) {
                $this->error('Fix group is not in allowlist: '.$fixGroup);

                return self::FAILURE;
            }
        }

        $preview = [
            'dry_run' => $dryRun,
            'apply_requested' => $apply,
            'apply_enabled' => false,
            'selected_count' => $plans->count(),
            'selected_partner_codes' => $plans->pluck('code')->values()->all(),
            'rows_changed' => 0,
        ];
        if ($rollbackPath = (string) ($this->option('rollback-export') ?? '')) {
            $directory = dirname($rollbackPath);
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            file_put_contents($rollbackPath, json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        $this->line(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ($apply) {
            $this->error('Apply mode is fail-safe for legacy plans. No data was modified.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function selectedRows(array $rows): array
    {
        $codes = array_map('strval', (array) $this->option('partner-code'));
        $limit = $this->option('limit') === null ? null : max(1, (int) $this->option('limit'));
        $selected = collect($rows)
            ->when($codes !== [], fn ($items) => $items->whereIn('partner_code', $codes))
            ->sortBy('partner_id')
            ->values();

        return ($limit === null ? $selected : $selected->take($limit))->all();
    }

    private function databaseFingerprint(): string
    {
        $connection = DB::connection();

        return hash('sha256', implode('|', [
            $connection->getDriverName(),
            $connection->getDatabaseName(),
            (string) $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
        ]));
    }
}
