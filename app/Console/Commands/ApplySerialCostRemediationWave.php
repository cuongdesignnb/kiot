<?php

namespace App\Console\Commands;

use App\Services\SerialCostRemediationWaveService;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

class ApplySerialCostRemediationWave extends Command
{
    protected $signature = 'costing:apply-serial-remediation-wave
        {--plan-json= : Required guarded remediation plan JSON}
        {--wave-json= : Required prepared wave JSON}
        {--apply : Apply each guarded batch; omitted means read-only preview}
        {--operator= : Required operator identity for apply}
        {--backup-confirmed : Required acknowledgement of a checked restorable backup}
        {--backup-reference= : Required backup identifier shared by this wave}
        {--confirm-wave-hash= : Required confirmation code printed by preview}';

    protected $description = 'Preview or apply a 50-line wave as independent stop-on-failure 25-line transactions.';

    public function handle(SerialCostRemediationWaveService $waves): int
    {
        try {
            $plan = $this->readJsonFile((string) ($this->option('plan-json') ?? ''), 'plan');
            $wave = $this->readJsonFile((string) ($this->option('wave-json') ?? ''), 'wave');

            if (! $this->option('apply')) {
                $this->line(json_encode(
                    $waves->preview($plan, $wave),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
                ));

                return self::SUCCESS;
            }

            $this->assertApplyGuard($waves, $wave);
            $result = $waves->applyWave(
                $plan,
                $wave,
                (string) ($this->option('operator') ?? ''),
                (string) ($this->option('backup-reference') ?? ''),
            );
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return ($result['result'] ?? null) === 'PARTIAL_FAILURE'
                ? self::FAILURE
                : self::SUCCESS;
        } catch (JsonException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @param array<string, mixed> $wave */
    private function assertApplyGuard(SerialCostRemediationWaveService $waves, array $wave): void
    {
        if (trim((string) ($this->option('operator') ?? '')) === '') {
            throw new RuntimeException('--apply requires --operator.');
        }
        if (! $this->option('backup-confirmed')
            || trim((string) ($this->option('backup-reference') ?? '')) === '') {
            throw new RuntimeException('--apply requires --backup-confirmed and --backup-reference.');
        }

        $expected = $waves->confirmationCode((string) ($wave['wave_hash'] ?? ''));
        if (! hash_equals($expected, (string) ($this->option('confirm-wave-hash') ?? ''))) {
            throw new RuntimeException('--confirm-wave-hash does not match the prepared wave artifact.');
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
