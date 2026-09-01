<?php

namespace App\Console\Commands;

use App\Services\SerialCostRemediationWaveService;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

class PrepareSerialCostRemediationWave extends Command
{
    protected $signature = 'costing:prepare-serial-remediation-wave
        {--plan-json= : Required guarded remediation plan JSON}
        {--limit=50 : Maximum lines for the wave; hard-capped at 50}
        {--approved-by= : Required delegated accounting/QA approver identity}
        {--approval-reference= : Required wave approval reference}';

    protected $description = 'Read-only: prepare up to two independently approved 25-line serial COGS remediation batches.';

    public function handle(SerialCostRemediationWaveService $waves): int
    {
        try {
            $plan = $this->readJsonFile((string) ($this->option('plan-json') ?? ''), 'plan');
            $wave = $waves->prepare(
                $plan,
                (int) ($this->option('limit') ?? 50),
                (string) ($this->option('approved-by') ?? ''),
                (string) ($this->option('approval-reference') ?? ''),
            );
            $this->line(json_encode($wave, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (JsonException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
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
