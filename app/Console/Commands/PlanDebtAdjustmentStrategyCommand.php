<?php

namespace App\Console\Commands;

use App\Models\CashFlow;
use App\Models\Customer;
use App\Models\CustomerDebt;
use App\Models\Invoice;
use App\Services\PartnerDebtLedgerService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class PlanDebtAdjustmentStrategyCommand extends Command
{
    protected $signature = 'debt:strategy-debt-adjustment
        {--dry-run : Required. Strategy only, do not write DB}
        {--partner-code= : Partner code}
        {--invoice-code= : Invoice code}
        {--cashflow-code= : Cashflow code}
        {--export-json= : Export JSON strategy report}
        {--export-md= : Export Markdown strategy report}';

    protected $description = 'Read-only strategy planning for DebtAdjustment cashflow mapping and ledger decisions';

    public function handle(PartnerDebtLedgerService $ledgerService): int
    {
        if (!$this->option('dry-run')) {
            $this->error('This command is read-only. Please pass --dry-run. No data was modified.');
            return self::FAILURE;
        }

        $partnerCode = (string) $this->option('partner-code');
        $invoiceCode = (string) $this->option('invoice-code');
        $cashflowCode = (string) $this->option('cashflow-code');

        $partner = Customer::query()->where('code', $partnerCode)->first();
        if (!$partner) {
            $this->error('Partner not found: ' . $partnerCode);
            return self::FAILURE;
        }

        $invoice = Invoice::query()->where('code', $invoiceCode)->first();
        $cashflow = CashFlow::query()->where('code', $cashflowCode)->first();
        $currentState = $this->currentState($ledgerService, $partner, $invoice, $cashflow, $invoiceCode, $cashflowCode);
        $strategies = $this->strategies($partner, $invoice, $cashflow, $currentState);
        $recommended = $this->recommendedStrategy($strategies);

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'dry_run' => true,
            'partner' => $this->partnerPayload($partner),
            'invoice' => $this->invoicePayload($invoice),
            'cashflow' => $this->cashflowPayload($cashflow),
            'current_state' => $currentState,
            'strategies' => $strategies,
            'recommended_strategy' => $recommended,
            'code_only_proposal' => $this->codeOnlyProposal(),
            'data_safety' => [
                'migration' => false,
                'backfill' => false,
                'update_old_data' => false,
                'delete' => false,
                'recalculate' => false,
                'write_db' => false,
                'code_only_change' => true,
                'requires_confirmation_before_fix' => true,
            ],
        ];

        if ($export = $this->option('export-json')) {
            $this->writeTextFile((string) $export, $this->json($payload));
            $this->info('DebtAdjustment strategy JSON exported: ' . $export);
        }

        if ($export = $this->option('export-md')) {
            $this->writeTextFile((string) $export, $this->markdown($payload));
            $this->info('DebtAdjustment strategy Markdown exported: ' . $export);
        }

        if (!$this->option('export-json') && !$this->option('export-md')) {
            $this->line($this->json($payload));
        }

        return self::SUCCESS;
    }

    private function currentState(
        PartnerDebtLedgerService $ledgerService,
        Customer $partner,
        ?Invoice $invoice,
        ?CashFlow $cashflow,
        string $invoiceCode,
        string $cashflowCode
    ): array {
        $ledger = $ledgerService->buildCustomerNetLedger($partner);
        $entries = collect($ledger['entries'] ?? [])->map(fn ($entry) => (array) $entry)->values();
        $invoiceEntry = $entries->first(fn (array $entry) => $this->entryMatches($entry, $invoiceCode));
        $cashflowEntry = $entries->first(fn (array $entry) => $this->entryMatches($entry, $cashflowCode));
        $invoiceDebts = $this->customerDebtRows($partner, $invoice, $invoiceCode, null);
        $cashflowDebts = $this->customerDebtRows($partner, null, $cashflowCode, $cashflow);
        $invoiceOutstanding = $this->invoiceOutstanding($invoice);
        $cashflowAmount = abs((float) ($cashflow?->amount ?? 0));
        $timelineFinal = (float) ($ledger['summary']['display_balance_final'] ?? $ledger['summary']['current_debt'] ?? 0);
        $storedBalance = (float) ($partner->debt_amount ?? 0);

        return [
            'stored_customer_debt' => $storedBalance,
            'invoice_outstanding' => $invoiceOutstanding,
            'cashflow_amount' => $cashflowAmount,
            'invoice_customer_debt_rows' => count($invoiceDebts),
            'cashflow_customer_debt_rows' => count($cashflowDebts),
            'timeline_invoice_entry_found' => $invoiceEntry !== null,
            'timeline_cashflow_entry_found' => $cashflowEntry !== null,
            'timeline_invoice_effect' => $invoiceEntry ? (float) ($invoiceEntry['display_effect'] ?? $invoiceEntry['balance_effect'] ?? 0) : null,
            'timeline_cashflow_effect' => $cashflowEntry ? (float) ($cashflowEntry['display_effect'] ?? $cashflowEntry['balance_effect'] ?? 0) : null,
            'timeline_final_balance' => $timelineFinal,
            'stored_balance' => $storedBalance,
            'reconcile_mismatch' => (bool) ($ledger['reconcile']['has_mismatch'] ?? $ledger['reconcile']['ledger_mismatch'] ?? (abs($timelineFinal - $storedBalance) >= 0.01)),
            'cashflow_is_debt_adjustment' => $cashflow ? $this->isDebtAdjustment($cashflow) : false,
            'cashflow_same_partner' => $cashflow ? (int) ($cashflow->target_id ?? 0) === (int) $partner->id : false,
            'amount_matches_outstanding' => $invoiceOutstanding >= 0.01 && abs($invoiceOutstanding - $cashflowAmount) < 0.01,
            'customer_debt_rows' => [
                'invoice' => $invoiceDebts,
                'cashflow' => $cashflowDebts,
            ],
        ];
    }

    private function strategies(Customer $partner, ?Invoice $invoice, ?CashFlow $cashflow, array $state): array
    {
        $displayRecommended = $this->displayOnlyRuleMatches($state);
        $invoiceOutstanding = (float) ($state['invoice_outstanding'] ?? 0);
        $cashflowAmount = (float) ($state['cashflow_amount'] ?? 0);
        $cashflowCode = (string) ($cashflow?->code ?? '');
        $invoiceCode = (string) ($invoice?->code ?? '');

        return [
            [
                'strategy' => 'DISPLAY_ONLY_TIMELINE_FIX',
                'write_db' => false,
                'tables_affected_if_applied' => [],
                'code_only_change' => true,
                'operations_preview' => [],
                'expected_timeline_effect' => -$cashflowAmount,
                'expected_final_balance_after_display' => (float) ($state['timeline_final_balance'] ?? 0) - $cashflowAmount,
                'pros' => [
                    'Khong sua du lieu cu',
                    'Tranh double-count',
                    'Phu hop vi stored debt hien tai da la 0',
                ],
                'cons' => [
                    'Khong tao ledger that',
                    'Chi giai thich hien thi/timeline',
                    'Can test de khong anh huong phieu thu thuong',
                ],
                'risk' => 'LOW-MEDIUM',
                'recommended' => $displayRecommended,
                'reason' => $displayRecommended
                    ? 'Stored debt is 0, invoice +15M is shown, matching DebtAdjustment cashflow is missing from timeline.'
                    : 'Current evidence does not satisfy all display-only recommendation guards.',
            ],
            [
                'strategy' => 'LEDGER_PAIR_PREVIEW',
                'write_db' => 'true_if_approved_later',
                'tables_affected_if_applied' => ['customer_debts'],
                'code_only_change' => false,
                'operations_preview' => [
                    [
                        'operation' => 'insert_customer_debt',
                        'source' => 'invoice',
                        'source_code' => $invoiceCode,
                        'amount' => $invoiceOutstanding,
                        'direction' => 'increase_debt',
                        'partner_id' => $partner->id,
                        'partner_code' => $partner->code,
                    ],
                    [
                        'operation' => 'insert_customer_debt',
                        'source' => 'cashflow',
                        'source_code' => $cashflowCode,
                        'amount' => -$cashflowAmount,
                        'direction' => 'decrease_debt',
                        'partner_id' => $partner->id,
                        'partner_code' => $partner->code,
                    ],
                ],
                'net_effect' => $invoiceOutstanding - $cashflowAmount,
                'pros' => [
                    'Co ledger that',
                    'Giai thich du phat sinh tang va giam',
                ],
                'cons' => [
                    'Ghi du lieu cu',
                    'Can fix_run_id/rollback',
                    'Can xac nhan nghiep vu',
                ],
                'risk' => 'HIGH',
                'recommended' => false,
                'reason' => 'Only preview. Real ledger pair requires backup, rollback export, allowlist, and written confirmation.',
            ],
            [
                'strategy' => 'LINKAGE_ONLY_PREVIEW',
                'write_db' => 'true_if_approved_later',
                'tables_affected_if_applied' => ['cash_flows'],
                'code_only_change' => false,
                'operations_preview' => [
                    [
                        'operation' => 'update_cashflow_reference',
                        'cashflow_code' => $cashflowCode,
                        'reference_type' => 'Invoice',
                        'reference_id' => $invoice?->id,
                        'reference_code' => $invoiceCode,
                    ],
                ],
                'pros' => [
                    'Giu nguyen so tien',
                    'Khong tao ledger moi',
                ],
                'cons' => [
                    'Co the lam mat y nghia DebtAdjustment goc',
                    'Sua du lieu cu',
                    'Co the anh huong so quy/bao cao',
                ],
                'risk' => 'HIGH',
                'recommended' => false,
                'reason' => 'DebtAdjustment appears to be the original business meaning, so changing cashflow linkage is risky.',
            ],
        ];
    }

    private function recommendedStrategy(array $strategies): array
    {
        foreach ($strategies as $strategy) {
            if ((bool) ($strategy['recommended'] ?? false)) {
                return [
                    'strategy' => $strategy['strategy'],
                    'risk' => $strategy['risk'],
                    'write_db' => $strategy['write_db'],
                    'code_only_change' => $strategy['code_only_change'],
                    'reason' => $strategy['reason'],
                    'requires_confirmation_before_production' => true,
                    'can_write_db_now' => false,
                ];
            }
        }

        return [
            'strategy' => 'MANUAL_REVIEW_REQUIRED',
            'risk' => 'HIGH',
            'write_db' => false,
            'code_only_change' => false,
            'reason' => 'Strategy guards were not satisfied.',
            'requires_confirmation_before_production' => true,
            'can_write_db_now' => false,
        ];
    }

    private function displayOnlyRuleMatches(array $state): bool
    {
        return abs((float) ($state['stored_customer_debt'] ?? 0)) < 0.01
            && abs((float) ($state['stored_balance'] ?? 0)) < 0.01
            && abs((float) ($state['invoice_outstanding'] ?? 0) - 15000000.0) < 0.01
            && abs((float) ($state['cashflow_amount'] ?? 0) - 15000000.0) < 0.01
            && (bool) ($state['cashflow_is_debt_adjustment'] ?? false)
            && (bool) ($state['cashflow_same_partner'] ?? false)
            && (bool) ($state['timeline_invoice_entry_found'] ?? false)
            && !(bool) ($state['timeline_cashflow_entry_found'] ?? false)
            && (int) ($state['invoice_customer_debt_rows'] ?? 0) === 0
            && (int) ($state['cashflow_customer_debt_rows'] ?? 0) === 0;
    }

    private function codeOnlyProposal(): array
    {
        return [
            'files' => ['app/Services/PartnerDebtLedgerService.php'],
            'function' => 'buildStandaloneCustomerCashFlowEntries / buildCustomerNetLedger',
            'guard' => 'DebtAdjustment cashflow, same customer target_id, active/not cancelled, amount > 0, no customer_debts cash_flow_id/ref_code already representing it.',
            'expected_display_effect' => 'negative cashflow amount',
            'double_count_prevention' => 'Skip virtual/display DebtAdjustment when a real customer_debts row already represents the cashflow.',
            'tests' => ['tests/Feature/Customers/DebtAdjustmentTimelineDisplayTest.php'],
        ];
    }

    private function customerDebtRows(Customer $partner, ?Invoice $invoice, string $code, ?CashFlow $cashflow): array
    {
        return CustomerDebt::query()
            ->where('customer_id', $partner->id)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (CustomerDebt $debt) => $this->debtMatches($debt, $invoice, $code, $cashflow))
            ->map(fn (CustomerDebt $debt) => $this->modelPayload($debt, 'customer_debts', [
                'id',
                'customer_id',
                'invoice_id',
                'cash_flow_id',
                'ref_code',
                'reference_code',
                'amount',
                'debt_total',
                'type',
                'note',
                'recorded_at',
                'created_at',
            ]))
            ->values()
            ->all();
    }

    private function debtMatches(CustomerDebt $debt, ?Invoice $invoice, string $code, ?CashFlow $cashflow): bool
    {
        foreach (['ref_code', 'reference_code', 'code'] as $field) {
            if ((string) ($debt->{$field} ?? '') === $code) {
                return true;
            }
        }
        if ($invoice && $this->hasColumn('customer_debts', 'invoice_id') && (string) ($debt->invoice_id ?? '') === (string) $invoice->id) {
            return true;
        }
        if ($cashflow && $this->hasColumn('customer_debts', 'cash_flow_id') && (string) ($debt->cash_flow_id ?? '') === (string) $cashflow->id) {
            return true;
        }

        return str_contains((string) ($debt->note ?? ''), $code);
    }

    private function entryMatches(array $entry, string $code): bool
    {
        return $code !== ''
            && ((string) ($entry['code'] ?? '') === $code
                || (string) ($entry['reference_code'] ?? '') === $code);
    }

    private function partnerPayload(Customer $partner): array
    {
        return $this->modelPayload($partner, 'customers', [
            'id',
            'code',
            'name',
            'phone',
            'is_customer',
            'is_supplier',
            'debt_amount',
            'supplier_debt_amount',
            'created_at',
            'updated_at',
        ]);
    }

    private function invoicePayload(?Invoice $invoice): array
    {
        if (!$invoice) {
            return ['found' => false];
        }

        $payload = $this->modelPayload($invoice, 'invoices', [
            'id',
            'code',
            'customer_id',
            'status',
            'transaction_date',
            'created_at',
            'total',
            'customer_paid',
            'debt_amount',
            'outstanding',
            'payment_status',
            'note',
        ]);
        $payload['found'] = true;
        $payload['computed_outstanding'] = $this->invoiceOutstanding($invoice);

        return $payload;
    }

    private function cashflowPayload(?CashFlow $cashflow): array
    {
        if (!$cashflow) {
            return ['found' => false];
        }

        $payload = $this->modelPayload($cashflow, 'cash_flows', [
            'id',
            'code',
            'type',
            'amount',
            'status',
            'time',
            'created_at',
            'payment_method',
            'target_name',
            'target_id',
            'target_type',
            'reference_type',
            'reference_id',
            'reference_code',
            'description',
        ]);
        $payload['found'] = true;
        $payload['debt_adjustment_signal'] = $this->isDebtAdjustment($cashflow);

        return $payload;
    }

    private function modelPayload(Model $model, string $table, array $fields): array
    {
        $payload = [];
        foreach ($fields as $field) {
            if ($this->hasColumn($table, $field) || array_key_exists($field, $model->getAttributes())) {
                $payload[$field] = $this->scalar($model->getAttribute($field));
            }
        }

        return $payload;
    }

    private function invoiceOutstanding(?Invoice $invoice): float
    {
        if (!$invoice) {
            return 0.0;
        }
        if ($this->hasColumn('invoices', 'outstanding') && $invoice->outstanding !== null) {
            return (float) $invoice->outstanding;
        }
        if ($this->hasColumn('invoices', 'debt_amount') && $invoice->debt_amount !== null) {
            return (float) $invoice->debt_amount;
        }

        return max(0.0, (float) ($invoice->total ?? 0) - (float) ($invoice->customer_paid ?? 0));
    }

    private function isDebtAdjustment(CashFlow $cashflow): bool
    {
        $text = $this->normalizeSignalText((string) (($cashflow->description ?? '') . ' ' . ($cashflow->note ?? '')));

        return strtoupper((string) ($cashflow->reference_type ?? '')) === 'DEBTADJUSTMENT'
            || str_contains($text, 'DIEUCHINHCONGNO')
            || str_contains($text, 'CONGNO')
            || (str_contains($text, '15000000') && str_contains($text, '0'));
    }

    private function normalizeSignalText(string $text): string
    {
        $text = strtoupper($text);
        $text = str_replace([',', '.', ' ', '|', '-', '_', '→', '->'], '', $text);

        return preg_replace('/[^A-Z0-9]/', '', $text) ?: '';
    }

    private function hasColumn(string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = Schema::hasColumn($table, $column);
        }

        return $cache[$key];
    }

    private function scalar(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    private function markdown(array $payload): string
    {
        $state = $payload['current_state'];
        $recommended = $payload['recommended_strategy'];
        $lines = [
            '# DebtAdjustment mapping/ledger strategy - ' . ($payload['partner']['code'] ?? 'unknown'),
            '',
            '## Scope',
            '',
            '- Partner: `' . $this->md($payload['partner']['code'] ?? '') . ' - ' . $this->md($payload['partner']['name'] ?? '') . '`.',
            '- Invoice: `' . $this->md($payload['invoice']['code'] ?? '') . '`.',
            '- Cashflow: `' . $this->md($payload['cashflow']['code'] ?? '') . '`.',
            '- Dry-run: `true`.',
            '',
            '## Current evidence',
            '',
            '- Stored debt: `' . $state['stored_customer_debt'] . '`.',
            '- Invoice outstanding: `' . $state['invoice_outstanding'] . '`.',
            '- Cashflow amount: `' . $state['cashflow_amount'] . '`.',
            '- Cashflow DebtAdjustment: `' . ($state['cashflow_is_debt_adjustment'] ? 'yes' : 'no') . '`.',
            '- Timeline invoice entry: `' . ($state['timeline_invoice_entry_found'] ? 'yes' : 'no') . '`.',
            '- Timeline cashflow entry: `' . ($state['timeline_cashflow_entry_found'] ? 'yes' : 'no') . '`.',
            '- Timeline final balance: `' . $state['timeline_final_balance'] . '`.',
            '- CustomerDebt rows: invoice `' . $state['invoice_customer_debt_rows'] . '`, cashflow `' . $state['cashflow_customer_debt_rows'] . '`.',
            '- Reconcile mismatch: `' . ($state['reconcile_mismatch'] ? 'yes' : 'no') . '`.',
            '',
            '## Strategy comparison',
            '',
            '| Strategy | Write DB | Tables affected | Risk | Pros | Cons | Recommended |',
            '|---|---|---|---|---|---|---|',
        ];

        foreach ($payload['strategies'] as $strategy) {
            $lines[] = '| ' . $strategy['strategy']
                . ' | ' . $this->md(is_bool($strategy['write_db']) ? ($strategy['write_db'] ? 'true' : 'false') : $strategy['write_db'])
                . ' | ' . $this->md(implode(', ', $strategy['tables_affected_if_applied']))
                . ' | ' . $strategy['risk']
                . ' | ' . $this->md(implode('; ', $strategy['pros']))
                . ' | ' . $this->md(implode('; ', $strategy['cons']))
                . ' | ' . ((bool) $strategy['recommended'] ? 'yes' : 'no') . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Recommended strategy',
            '',
            '- Strategy: `' . $recommended['strategy'] . '`.',
            '- Reason: ' . $this->md($recommended['reason']) . '.',
            '- Why not insert invoice ledger only: It can double-count because DebtAdjustment cashflow already explains a 15M settlement to stored debt 0.',
            '- Why not update cashflow linkage now: It changes old cashflow meaning from DebtAdjustment to Invoice linkage and can affect cashbook/report semantics.',
            '- Why not create ledger pair now: It writes old data and requires backup, rollback, allowlist, and written approval.',
            '',
            '## Code-only proposal',
            '',
            '- Files: `app/Services/PartnerDebtLedgerService.php`.',
            '- Function: `buildStandaloneCustomerCashFlowEntries` / `buildCustomerNetLedger`.',
            '- Guard: ' . $this->md($payload['code_only_proposal']['guard']) . '.',
            '- Expected display effect: `-' . $state['cashflow_amount'] . '`.',
            '- Double-count prevention: ' . $this->md($payload['code_only_proposal']['double_count_prevention']) . '.',
            '- Tests: `tests/Feature/Customers/DebtAdjustmentTimelineDisplayTest.php` if code-only display fix is approved.',
            '',
            '## Data safety',
            '',
            '- Migration: no.',
            '- Backfill: no.',
            '- Update old data: no.',
            '- Delete: no.',
            '- Recalculate: no.',
            '- Write DB: no.',
            '- Code-only change: proposal only.',
            '- Requires confirmation before production: yes.',
            '',
        ]);

        return implode(PHP_EOL, $lines);
    }

    private function json(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function writeTextFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir)) {
            throw new \RuntimeException("Cannot prepare export directory: {$dir}");
        }

        file_put_contents($path, $content . PHP_EOL);
    }

    private function md(mixed $value): string
    {
        return str_replace('|', '\\|', (string) $value);
    }
}
