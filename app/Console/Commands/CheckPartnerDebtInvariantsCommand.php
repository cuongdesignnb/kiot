<?php

namespace App\Console\Commands;

use App\Services\Debt\PartnerDebtInvariantChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckPartnerDebtInvariantsCommand extends Command
{
    protected $signature = 'debt:check-invariants
        {--dry-run : Required read-only gate}
        {--partner-id=* : Limit the scan to one or more partner IDs}
        {--limit= : Limit the number of checked partners}';

    protected $description = 'Read-only debt invariant scan; never repairs or mutates debt data';

    public function handle(PartnerDebtInvariantChecker $checker): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Please pass --dry-run. This command never applies debt changes.');

            return self::FAILURE;
        }

        $partnerIds = array_values(array_unique(array_map('intval', (array) $this->option('partner-id'))));
        if (in_array(0, $partnerIds, true) || count(array_filter($partnerIds, fn (int $id): bool => $id < 1)) > 0) {
            $this->error('Invalid --partner-id. Use positive integer IDs.');

            return self::FAILURE;
        }

        $limitOption = $this->option('limit');
        if ($limitOption !== null && preg_match('/^[1-9][0-9]*$/', (string) $limitOption) !== 1) {
            $this->error('Invalid --limit. Use a positive integer.');

            return self::FAILURE;
        }

        $result = $checker->scan($partnerIds, $limitOption === null ? null : (int) $limitOption);
        $driftRows = collect($result['rows'])->where('drift_detected', true)->values();

        $this->info('Read-only: yes');
        $this->line('Total checked: '.$result['total_checked']);
        $this->line('Matched: '.$result['matched']);
        $this->line('Drift detected: '.$result['drift_detected']);
        $this->line('Audit errors: '.$result['audit_errors']);

        if ($driftRows->isNotEmpty()) {
            $this->table(
                ['Partner', 'Code', 'Role', 'Difference', 'Root cause', 'Risk'],
                $driftRows->map(fn (array $row): array => [
                    $row['partner_id'],
                    $row['partner_code'],
                    $row['role'],
                    $row['difference'],
                    $row['root_cause'],
                    $row['risk_level'],
                ])->all(),
            );

            Log::warning('Debt integrity drift detected', [
                'checked_at' => $result['checked_at'],
                'total_checked' => $result['total_checked'],
                'drift_detected' => $result['drift_detected'],
                'audit_errors' => $result['audit_errors'],
                'partner_ids' => $driftRows->pluck('partner_id')->all(),
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
