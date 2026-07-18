<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Services\Debt\PartnerDebtRoleResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class ApplyPartnerRoleRepairPlanCommand extends Command
{
    private const ALLOWED_DATABASES = [
        'kiot_partner_timeline_audit',
        'kiot_partner_timeline_validation',
    ];

    protected $signature = 'debt:apply-role-repair-plan
        {--plan= : role-repair-plan.json under storage/app/audits}
        {--apply : Apply owner-confirmed role flags; otherwise read-only dry-run}
        {--approval-hash= : Must exactly match plan_hash when applying}';

    protected $description = 'Dry-run or apply owner-confirmed dual-role flags on disposable validation clones only';

    public function handle(): int
    {
        try {
            $payload = $this->readPlan((string) ($this->option('plan') ?? ''));
            $actions = (array) ($payload['actions'] ?? []);
            $planHash = hash('sha256', json_encode($actions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (! hash_equals((string) ($payload['plan_hash'] ?? ''), $planHash)) {
                throw new RuntimeException('ROLE_REPAIR_PLAN_HASH_MISMATCH');
            }
            $this->validateActions($actions);

            if (! $this->option('apply')) {
                $this->line('DRY_RUN=yes');
                $this->line('ROLE_REPAIR_ACTIONS='.count($actions));
                $this->line('ROLE_REPAIR_ROWS_CHANGED=0');
                $this->line('FINANCIAL_FIELDS_CHANGED=0');
                $this->line('PLAN_HASH='.$planHash);

                return self::SUCCESS;
            }

            $database = (string) DB::connection()->getDatabaseName();
            if (! in_array($database, self::ALLOWED_DATABASES, true)) {
                throw new RuntimeException('ROLE_REPAIR_CLONE_DATABASE_REQUIRED');
            }
            if (! hash_equals($planHash, (string) ($this->option('approval-hash') ?? ''))) {
                throw new RuntimeException('ROLE_REPAIR_APPROVAL_HASH_MISMATCH');
            }

            $changed = DB::transaction(function () use ($actions, $planHash): int {
                $changed = 0;
                $sorted = collect($actions)->sortBy('partner_id')->values();
                foreach ($sorted as $action) {
                    $partner = Customer::query()->lockForUpdate()->findOrFail((int) $action['partner_id']);
                    if ((string) $partner->code !== (string) $action['partner_code']) {
                        throw new RuntimeException('ROLE_REPAIR_PARTNER_IDENTITY_CHANGED');
                    }
                    $beforeCustomerDebt = (float) $partner->debt_amount;
                    $beforeSupplierDebt = (float) $partner->supplier_debt_amount;
                    if ((bool) $partner->is_customer && (bool) $partner->is_supplier) {
                        continue;
                    }
                    $expected = (array) ($action['before'] ?? []);
                    if ((bool) $partner->is_customer !== (bool) ($expected['is_customer'] ?? false)
                        || (bool) $partner->is_supplier !== (bool) ($expected['is_supplier'] ?? false)) {
                        throw new RuntimeException('ROLE_REPAIR_BEFORE_STATE_CHANGED');
                    }
                    if (abs($beforeCustomerDebt - (float) ($expected['debt_amount'] ?? 0)) > 0.01
                        || abs($beforeSupplierDebt - (float) ($expected['supplier_debt_amount'] ?? 0)) > 0.01) {
                        throw new RuntimeException('ROLE_REPAIR_FINANCIAL_SNAPSHOT_CHANGED');
                    }

                    $partner->forceFill(['is_customer' => true, 'is_supplier' => true])->save();
                    $partner->refresh();
                    if ((float) $partner->debt_amount !== $beforeCustomerDebt
                        || (float) $partner->supplier_debt_amount !== $beforeSupplierDebt) {
                        throw new RuntimeException('ROLE_REPAIR_FINANCIAL_FIELD_MUTATION');
                    }
                    ActivityLog::log(
                        'partner_role_repair',
                        'Clone-only owner-confirmed partner role correction',
                        $partner,
                        [
                            'idempotency_key' => $action['idempotency_key'],
                            'plan_hash' => $planHash,
                            'before' => [
                                'is_customer' => (bool) ($expected['is_customer'] ?? false),
                                'is_supplier' => (bool) ($expected['is_supplier'] ?? false),
                            ],
                            'after' => ['is_customer' => true, 'is_supplier' => true],
                            'financial_fields_changed' => 0,
                        ],
                    );
                    $changed++;
                }

                return $changed;
            }, 3);

            $this->line('DRY_RUN=no');
            $this->line('DATABASE='.$database);
            $this->line('ROLE_REPAIR_ROWS_CHANGED='.$changed);
            $this->line('FINANCIAL_FIELDS_CHANGED=0');
            $this->line('PLAN_HASH='.$planHash);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function readPlan(string $path): array
    {
        if ($path === '') {
            throw new RuntimeException('--plan is required.');
        }
        $normalized = str_replace('\\', '/', $path);
        if (str_contains($normalized, '/../') || str_ends_with($normalized, '/..')) {
            throw new RuntimeException('ROLE_REPAIR_PLAN_PATH_TRAVERSAL');
        }
        $absolute = preg_match('/^[A-Za-z]:\//', $normalized) === 1 || str_starts_with($normalized, '/')
            ? $normalized
            : str_replace('\\', '/', base_path($normalized));
        $root = rtrim(str_replace('\\', '/', storage_path('app/audits')), '/');
        if (! str_starts_with($absolute, $root.'/') || ! File::isFile($absolute)) {
            throw new RuntimeException('ROLE_REPAIR_PLAN_MUST_BE_UNDER_AUDITS');
        }

        return json_decode(File::get($absolute), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<int, array<string, mixed>> $actions */
    private function validateActions(array $actions): void
    {
        foreach ($actions as $action) {
            if (($action['action'] ?? null) !== 'set_persisted_role_dual'
                || ! in_array((string) ($action['partner_code'] ?? ''), PartnerDebtRoleResolver::OWNER_CONFIRMED_DUAL_ROLE_CODES, true)
                || (bool) ($action['after']['is_customer'] ?? false) !== true
                || (bool) ($action['after']['is_supplier'] ?? false) !== true
                || empty($action['idempotency_key'])) {
                throw new RuntimeException('ROLE_REPAIR_ACTION_NOT_OWNER_APPROVED');
            }
        }
    }
}
