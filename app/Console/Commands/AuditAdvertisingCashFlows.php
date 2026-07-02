<?php

namespace App\Console\Commands;

use App\Models\CashFlow;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class AuditAdvertisingCashFlows extends Command
{
    protected $signature = 'cashflows:audit-ad-category
        {--dry-run : List likely advertising expenses with a non-advertising category}';

    protected $description = 'Dry-run audit for advertising-like payment cashflows not categorized as Quảng cáo.';

    private const SUGGESTED_CATEGORY = 'Quảng cáo';

    private const KEYWORDS = [
        'FB Ads',
        'Facebook',
        'Tiktok',
        'TikTok',
        'Chat GPT',
        'ChatGPT',
        'Google Ads',
        'Ads',
    ];

    public function handle(): int
    {
        if (!$this->option('dry-run')) {
            $this->warn('This hotfix command is read-only. Re-run with --dry-run to audit likely mismatches.');
            return self::FAILURE;
        }

        $rows = $this->candidateQuery()
            ->orderByDesc('time')
            ->get(['id', 'code', 'time', 'category', 'amount', 'description']);

        $this->info('Advertising cashflow category audit (dry-run, no data changes).');

        if ($rows->isEmpty()) {
            $this->info('No likely advertising cashflows with mismatched category were found.');
            return self::SUCCESS;
        }

        $this->table(
            ['id', 'code', 'time', 'category hiện tại', 'amount', 'description', 'suggested_category'],
            $rows->map(fn (CashFlow $flow) => [
                $flow->id,
                $flow->code,
                $flow->time ? \Carbon\Carbon::parse($flow->time)->format('Y-m-d H:i:s') : '',
                $flow->category ?: '(blank)',
                number_format((float) $flow->amount, 0, '.', ','),
                $flow->description,
                self::SUGGESTED_CATEGORY,
            ])->all()
        );

        $this->newLine();
        $this->line('Tổng số phiếu nghi sai: ' . $rows->count());
        $this->line('Tổng tiền: ' . number_format((float) $rows->sum('amount'), 0, '.', ','));

        $this->newLine();
        $this->line('Group theo category hiện tại:');
        $this->table(
            ['category hiện tại', 'total', 'amount'],
            $rows
                ->groupBy(fn (CashFlow $flow) => $flow->category ?: '(blank)')
                ->map(fn ($group, $category) => [
                    $category,
                    $group->count(),
                    number_format((float) $group->sum('amount'), 0, '.', ','),
                ])
                ->values()
                ->all()
        );

        return self::SUCCESS;
    }

    private function candidateQuery(): Builder
    {
        return CashFlow::query()
            ->where('type', 'payment')
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->where(function (Builder $query) {
                foreach (self::KEYWORDS as $keyword) {
                    $query->orWhere('description', 'like', '%' . $keyword . '%');
                }
            })
            ->where(function (Builder $query) {
                $query->whereNull('category')
                    ->orWhereRaw('LOWER(TRIM(category)) <> ?', [mb_strtolower(self::SUGGESTED_CATEGORY)]);
            });
    }
}
