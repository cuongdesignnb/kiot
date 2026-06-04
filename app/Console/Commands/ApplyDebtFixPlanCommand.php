<?php

namespace App\Console\Commands;

use App\Models\CashFlow;
use App\Models\CustomerDebt;
use App\Models\DebtOffset;
use App\Models\SupplierDebtTransaction;
use Illuminate\Console\Command;

class ApplyDebtFixPlanCommand extends Command
{
    protected $signature = 'debt:apply-fix-plan
        {--plan-json= : Required plan JSON}
        {--dry-run : Preview write operations only}
        {--apply : Actually write DB}
        {--fix-run-id= : Required unique run id}
        {--confirm-code= : Required confirmation code}
        {--group= : Only apply one fix group}
        {--partner-code=* : Only apply allowlisted partner codes}
        {--limit= : Limit rows}
        {--backup-confirmed : Confirm DB backup exists}
        {--rollback-export= : Export rollback JSON}';

    protected $description = 'Guarded debt fix plan apply preview. Real DB writes are disabled in this step';

    private const ALLOWED_GROUPS = [
        'A_OPENING_BALANCE_REVIEW',
        'B_DOCUMENTS_NO_LEDGER',
    ];

    private const BLOCKED_GROUPS = [
        'C_LEDGER_DOCUMENT_MISMATCH',
        'D_CUSTOMER_ONLY_REVIEW',
        'E_DUAL_ROLE_ORIENTATION_REVIEW',
        'F_STORED_BALANCE_OPENING_CANDIDATE',
        'X_PLAN_INPUT_MISMATCH',
        'Z_NEEDS_MANUAL_REVIEW',
    ];

    public function handle(): int
    {
        $guard = $this->validateGuards();
        if (!$guard['ok']) {
            $this->error($guard['message']);
            return self::FAILURE;
        }

        $payload = $this->readPlan((string) $this->option('plan-json'));
        $plans = $this->selectedPlans($payload['plans'] ?? []);
        $guard = $this->validatePlan($plans);
        if (!$guard['ok']) {
            $this->error($guard['message']);
            return self::FAILURE;
        }

        $preview = $this->previewPayload($payload, $plans);

        if ($export = $this->option('rollback-export')) {
            $this->writeJsonFile((string) $export, $preview);
            $this->info('Rollback preview exported: ' . $export);
        }

        $this->line(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        if ($this->option('apply')) {
            $this->error('Apply mode is fail-safe in this step. No data was modified.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function validateGuards(): array
    {
        $planJson = (string) ($this->option('plan-json') ?? '');
        if ($planJson === '') {
            return $this->failed('Missing --plan-json.');
        }
        if (!file_exists($planJson)) {
            return $this->failed('Plan file not found: ' . $planJson);
        }

        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');
        if (!$dryRun && !$apply) {
            return $this->failed('Pass exactly one mode: --dry-run or --apply.');
        }
        if ($dryRun && $apply) {
            return $this->failed('Pass only one mode. --dry-run and --apply cannot be used together.');
        }

        if ($apply) {
            $fixRunId = (string) ($this->option('fix-run-id') ?? '');
            $confirmCode = (string) ($this->option('confirm-code') ?? '');
            if ($fixRunId === '') {
                return $this->failed('--apply requires --fix-run-id.');
            }
            if ($confirmCode === '') {
                return $this->failed('--apply requires --confirm-code.');
            }
            if ($confirmCode !== 'CONFIRM-DEBT-FIX-' . $fixRunId) {
                return $this->failed('--confirm-code must equal CONFIRM-DEBT-FIX-{fix_run_id}.');
            }
            if (!$this->option('backup-confirmed')) {
                return $this->failed('--apply requires --backup-confirmed.');
            }
            if ((string) ($this->option('rollback-export') ?? '') === '') {
                return $this->failed('--apply requires --rollback-export.');
            }
        }

        return ['ok' => true, 'message' => null];
    }

    private function validatePlan(array $plans): array
    {
        if (!$plans) {
            return $this->failed('No plan rows selected.');
        }

        $partnerAllowlist = array_map('strval', (array) $this->option('partner-code'));
        $requestedGroup = (string) ($this->option('group') ?? '');

        if ($this->option('apply') && !$partnerAllowlist) {
            return $this->failed('--apply requires at least one --partner-code allowlist entry.');
        }

        foreach ($plans as $plan) {
            $group = (string) ($plan['fix_group'] ?? '');
            $code = (string) ($plan['code'] ?? '');

            if (($plan['classification'] ?? '') === 'PLAN_INPUT_MISMATCH' || $group === 'X_PLAN_INPUT_MISMATCH') {
                return $this->failed('Plan has PLAN_INPUT_MISMATCH. Rerun audit/inspect/plan from the same snapshot.');
            }
            if (in_array($group, self::BLOCKED_GROUPS, true)) {
                return $this->failed('Blocked fix group cannot be applied: ' . $group);
            }
            if (!in_array($group, self::ALLOWED_GROUPS, true)) {
                return $this->failed('Fix group is not in allowlist: ' . $group);
            }
            if ($requestedGroup !== '' && $group !== $requestedGroup) {
                return $this->failed('Selected plan contains group outside --group: ' . $group);
            }
            if ($this->option('apply') && !in_array($code, $partnerAllowlist, true)) {
                return $this->failed('Partner is not in --partner-code allowlist: ' . $code);
            }
            if ($this->option('apply') && empty($plan['proposed_write_operations'])) {
                return $this->failed('Plan has empty proposed_write_operations for partner: ' . $code);
            }
        }

        return ['ok' => true, 'message' => null];
    }

    private function readPlan(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Plan JSON is invalid: ' . $path);
        }

        return $payload;
    }

    private function selectedPlans(array $plans): array
    {
        $group = (string) ($this->option('group') ?? '');
        $codes = array_map('strval', (array) $this->option('partner-code'));
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $selected = [];

        foreach ($plans as $plan) {
            if ($group !== '' && (string) ($plan['fix_group'] ?? '') !== $group) {
                continue;
            }
            if ($codes && !in_array((string) ($plan['code'] ?? ''), $codes, true)) {
                continue;
            }

            $selected[] = $plan;
            if ($limit !== null && count($selected) >= $limit) {
                break;
            }
        }

        return $selected;
    }

    private function previewPayload(array $payload, array $plans): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'dry_run' => (bool) $this->option('dry-run'),
            'apply_requested' => (bool) $this->option('apply'),
            'apply_enabled' => false,
            'fail_safe' => true,
            'message' => 'Preview only. Real DB writes are disabled in this step.',
            'fix_run_id' => $this->option('fix-run-id') ?: 'PREVIEW_ONLY',
            'plan_snapshot_id' => $payload['input_snapshot_id'] ?? null,
            'selected_count' => count($plans),
            'selected_groups' => array_values(array_unique(array_map(fn (array $plan) => (string) ($plan['fix_group'] ?? ''), $plans))),
            'selected_partner_codes' => array_values(array_map(fn (array $plan) => (string) ($plan['code'] ?? ''), $plans)),
            'write_operations_preview' => array_values(array_merge(...array_map(
                fn (array $plan) => array_map(
                    fn (array $operation) => $operation + [
                        'partner_code' => $plan['code'] ?? null,
                        'fix_group' => $plan['fix_group'] ?? null,
                    ],
                    $plan['proposed_write_operations'] ?? []
                ),
                $plans
            ))),
            'rollback_preview' => [
                'required' => true,
                'old_values_exported' => false,
                'reason' => 'No real DB write is performed in this step.',
            ],
            'data_safety' => [
                'migration' => false,
                'backfill' => false,
                'update_old_data' => false,
                'delete' => false,
                'recalculate' => false,
                'write_db' => false,
                'customer_debts_count' => CustomerDebt::count(),
                'supplier_debt_transactions_count' => SupplierDebtTransaction::count(),
                'cash_flows_count' => CashFlow::count(),
                'debt_offsets_count' => DebtOffset::count(),
            ],
        ];
    }

    private function writeJsonFile(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir)) {
            throw new \RuntimeException("Cannot prepare rollback export directory: {$dir}");
        }

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    private function failed(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
