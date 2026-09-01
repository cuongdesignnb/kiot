<?php

namespace App\Console\Commands;

use App\Services\SerialCostRemediationApplyService;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

class ApplySerialCostRemediationPlan extends Command
{
    protected $signature = 'costing:apply-serial-remediation
        {--plan-json= : Required guarded plan JSON}
        {--approval-json= : Required separate approval JSON}
        {--apply : Actually apply the approved batch; omitted means dry-run}
        {--operator= : Required person executing the approved batch}
        {--backup-confirmed : Required acknowledgement that a restorable backup was checked}
        {--backup-reference= : Required backup identifier or storage reference}
        {--confirm-approval-hash= : Required confirmation code printed by the dry-run}';

    protected $description = 'Dry-run by default; apply only a small separately approved serial COGS remediation batch.';

    public function handle(SerialCostRemediationApplyService $remediation): int
    {
        try {
            $plan = $this->readJsonFile((string) ($this->option('plan-json') ?? ''), 'plan');
            $approval = $this->readJsonFile((string) ($this->option('approval-json') ?? ''), 'approval');
            if (! $this->option('apply')) {
                $preview = $remediation->preview($plan, $approval);
                $preview['confirmation_code'] = $this->confirmationCode((string) $preview['approval_hash']);
                $this->line(json_encode($preview, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->assertApplyGuard($approval);
            $result = $remediation->apply($plan, $approval, (string) ($this->option('operator') ?? ''));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (JsonException|RuntimeException $exception) {
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
        return 'APPLY-SERIAL-COGS-'.substr($approvalHash, 0, 16);
    }

    /** @return array<string, mixed> */
    private function readJsonFile(string $path, string $label): array
    {
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException('Missing '.$label.' JSON file.');
        }

        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new RuntimeException('Invalid '.$label.' JSON payload.');
        }

        return $payload;
    }
}
