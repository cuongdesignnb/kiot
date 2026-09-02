<?php

namespace App\Console\Commands;

use App\Services\SerialCostLifecycleRemediationApplyService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ApplySerialCostLifecycleRemediationPlan extends Command
{
    protected $signature = 'costing:apply-serial-lifecycle-remediation
        {--plan-json= : Required lifecycle remediation plan JSON}
        {--approval-json= : Required separate lifecycle approval JSON}
        {--apply : Apply the approved plan; omitted means dry-run}
        {--operator= : Required operator identity}
        {--backup-confirmed : Required acknowledgement of a restorable backup}
        {--backup-reference= : Required backup filename and checksum}
        {--confirm-approval-hash= : Required confirmation code printed by dry-run}';

    protected $description = 'Dry-run by default; atomically repair approved serial sale-return-resale COGS.';

    public function handle(SerialCostLifecycleRemediationApplyService $remediation): int
    {
        try {
            $plan = $this->readJson((string) ($this->option('plan-json') ?? ''), 'plan');
            $approval = $this->readJson((string) ($this->option('approval-json') ?? ''), 'approval');
            if (! $this->option('apply')) {
                $preview = $remediation->preview($plan, $approval);
                $preview['confirmation_code'] = $this->confirmationCode((string) $preview['approval_hash']);
                $this->line(json_encode($preview, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->assertApplyGuard($approval);
            $result = $remediation->apply(
                $plan,
                $approval,
                (string) ($this->option('operator') ?? ''),
                (string) ($this->option('backup-reference') ?? ''),
            );
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @param array<string, mixed> $approval */
    private function assertApplyGuard(array $approval): void
    {
        if (trim((string) ($this->option('operator') ?? '')) === '') {
            throw new RuntimeException('--apply requires --operator.');
        }
        if (! $this->option('backup-confirmed') || trim((string) ($this->option('backup-reference') ?? '')) === '') {
            throw new RuntimeException('--apply requires --backup-confirmed and --backup-reference.');
        }
        $expected = $this->confirmationCode((string) ($approval['approval_hash'] ?? ''));
        if (! hash_equals($expected, (string) ($this->option('confirm-approval-hash') ?? ''))) {
            throw new RuntimeException('--confirm-approval-hash does not match the approval artifact.');
        }
    }

    private function confirmationCode(string $approvalHash): string
    {
        return 'APPLY-SERIAL-LIFECYCLE-COGS-'.substr($approvalHash, 0, 16);
    }

    /** @return array<string, mixed> */
    private function readJson(string $path, string $label): array
    {
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException('Missing lifecycle '.$label.' JSON file.');
        }
        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new RuntimeException('Invalid lifecycle '.$label.' JSON payload.');
        }

        return $payload;
    }
}
