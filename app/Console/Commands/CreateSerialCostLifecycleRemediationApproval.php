<?php

namespace App\Console\Commands;

use App\Services\SerialCostLifecycleRemediationApprovalService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class CreateSerialCostLifecycleRemediationApproval extends Command
{
    protected $signature = 'costing:approve-serial-lifecycle-remediation
        {--plan-json= : Required lifecycle remediation plan JSON}
        {--approved-by= : Required delegated QA/accounting approver identity}
        {--approval-reference= : Required approval batch reference}';

    protected $description = 'Create an immutable approval for every eligible line in one lifecycle plan.';

    public function handle(SerialCostLifecycleRemediationApprovalService $approvals): int
    {
        try {
            $plan = $this->readJson((string) ($this->option('plan-json') ?? ''));
            $approval = $approvals->create(
                $plan,
                (string) ($this->option('approved-by') ?? ''),
                (string) ($this->option('approval-reference') ?? ''),
            );
            $this->line(json_encode($approval, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException('Missing lifecycle plan JSON file.');
        }
        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new RuntimeException('Invalid lifecycle plan JSON payload.');
        }

        return $payload;
    }
}
