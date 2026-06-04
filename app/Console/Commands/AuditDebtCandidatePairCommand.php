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

class AuditDebtCandidatePairCommand extends Command
{
    protected $signature = 'debt:audit-candidate-pair
        {--dry-run : Required. Read-only only, do not write DB}
        {--partner-code= : Partner code}
        {--invoice-code= : Invoice code}
        {--cashflow-code= : Cashflow code}
        {--export-json= : Export JSON evidence}
        {--export-md= : Export Markdown report}';

    protected $description = 'Read-only audit for one candidate invoice and cashflow debt pair';

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
        $relatedCustomerDebts = $this->relatedCustomerDebts($partner, $invoice, $cashflow, $invoiceCode, $cashflowCode);
        $relatedCashflows = $this->relatedCashflows($partner, $invoiceCode, $cashflowCode);
        $relatedInvoices = $this->relatedInvoices($partner, $cashflow);
        $timeline = $this->timelineEntries($ledgerService, $partner, $invoiceCode, $cashflowCode, $cashflow);
        $signals = $this->signals($partner, $invoice, $cashflow, $relatedCustomerDebts, $timeline);
        $candidateDecision = $this->candidateDecision($partner, $invoice, $cashflow, $signals);

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'dry_run' => true,
            'partner' => $this->partnerPayload($partner),
            'invoice' => $this->invoicePayload($invoice),
            'cashflow' => $this->cashflowPayload($cashflow),
            'related_customer_debts' => $relatedCustomerDebts,
            'related_cashflows' => $relatedCashflows,
            'related_invoices' => $relatedInvoices,
            'timeline_entries' => $timeline,
            'signals' => $signals,
            'business_questions' => $this->businessQuestions($signals, $candidateDecision),
            'candidate_decision' => $candidateDecision,
            'data_safety' => [
                'migration' => false,
                'backfill' => false,
                'update_old_data' => false,
                'delete' => false,
                'recalculate' => false,
                'write_db' => false,
                'requires_confirmation_before_fix' => true,
            ],
        ];

        if ($export = $this->option('export-json')) {
            $this->writeTextFile((string) $export, $this->json($payload));
            $this->info('Candidate pair JSON exported: ' . $export);
        }

        if ($export = $this->option('export-md')) {
            $this->writeTextFile((string) $export, $this->markdown($payload));
            $this->info('Candidate pair Markdown exported: ' . $export);
        }

        if (!$this->option('export-json') && !$this->option('export-md')) {
            $this->line($this->json($payload));
        }

        return self::SUCCESS;
    }

    private function partnerPayload(Customer $partner): array
    {
        return $this->onlyModelFields($partner, 'customers', [
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

        $payload = $this->onlyModelFields($invoice, 'invoices', [
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

        $payload = $this->onlyModelFields($cashflow, 'cash_flows', [
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
            'customer_id',
            'partner_id',
            'reference_type',
            'reference_id',
            'reference_code',
            'note',
            'description',
            'object_type',
            'object_id',
        ]);
        $payload['found'] = true;
        $payload['payer_receiver'] = $cashflow->target_name ?? null;
        $payload['debt_adjustment_signal'] = $this->debtAdjustmentSignal($cashflow);

        return $payload;
    }

    private function relatedCustomerDebts(Customer $partner, ?Invoice $invoice, ?CashFlow $cashflow, string $invoiceCode, string $cashflowCode): array
    {
        $rows = CustomerDebt::query()
            ->where('customer_id', $partner->id)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->map(fn (CustomerDebt $row) => $this->onlyModelFields($row, 'customer_debts', [
                'id',
                'customer_id',
                'order_id',
                'order_return_id',
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

        return [
            'for_invoice' => array_values(array_filter($rows, fn (array $row) => $this->debtMatches($row, $invoice, $invoiceCode, null))),
            'for_cashflow' => array_values(array_filter($rows, fn (array $row) => $this->debtMatches($row, null, $cashflowCode, $cashflow))),
            'all_for_partner' => $rows,
        ];
    }

    private function relatedCashflows(Customer $partner, string $invoiceCode, string $cashflowCode): array
    {
        $rows = CashFlow::query()
            ->where(function ($query) use ($partner, $invoiceCode, $cashflowCode) {
                $query->where('target_id', $partner->id)
                    ->orWhere('reference_code', $invoiceCode)
                    ->orWhere('code', $cashflowCode);
            })
            ->orderBy('created_at')
            ->get()
            ->map(fn (CashFlow $row) => $this->cashflowPayload($row))
            ->values()
            ->all();

        return [
            'invoice_reference' => array_values(array_filter($rows, fn (array $row) => (string) ($row['reference_code'] ?? '') === $invoiceCode || $this->textContains($row['description'] ?? $row['note'] ?? '', $invoiceCode))),
            'cashflow_record' => array_values(array_filter($rows, fn (array $row) => (string) ($row['code'] ?? '') === $cashflowCode)),
            'all_for_partner_or_pair' => $rows,
        ];
    }

    private function relatedInvoices(Customer $partner, ?CashFlow $cashflow): array
    {
        $referenceCode = (string) ($cashflow?->reference_code ?? '');
        if ($referenceCode === '') {
            return [];
        }

        return Invoice::query()
            ->where('customer_id', $partner->id)
            ->where('code', $referenceCode)
            ->get()
            ->map(fn (Invoice $row) => $this->invoicePayload($row))
            ->values()
            ->all();
    }

    private function timelineEntries(PartnerDebtLedgerService $ledgerService, Customer $partner, string $invoiceCode, string $cashflowCode, ?CashFlow $cashflow): array
    {
        $ledger = $ledgerService->buildCustomerNetLedger($partner);
        $entries = collect($ledger['entries'] ?? [])->map(fn ($entry) => (array) $entry)->values();
        $amount = abs((float) ($cashflow?->amount ?? 0));
        $cashflowTime = $this->timestamp($cashflow?->time ?? $cashflow?->created_at ?? null);

        $filtered = $entries
            ->filter(function (array $entry) use ($invoiceCode, $cashflowCode, $amount, $cashflowTime) {
                $code = (string) ($entry['code'] ?? '');
                $referenceCode = (string) ($entry['reference_code'] ?? '');
                $entryAmount = max(
                    abs((float) ($entry['display_effect'] ?? 0)),
                    abs((float) ($entry['balance_effect'] ?? 0)),
                    abs((float) ($entry['amount'] ?? 0))
                );
                $entryTime = $this->timestamp($entry['time'] ?? $entry['display_time'] ?? null);

                return $code === $invoiceCode
                    || $code === $cashflowCode
                    || $referenceCode === $invoiceCode
                    || $referenceCode === $cashflowCode
                    || ($amount >= 0.01 && abs($entryAmount - $amount) < 0.01)
                    || ($cashflowTime && $entryTime && abs($entryTime - $cashflowTime) <= 7 * 86400);
            })
            ->values()
            ->all();

        return [
            'summary' => $ledger['summary'] ?? [],
            'reconcile' => $ledger['reconcile'] ?? [],
            'entries' => $filtered,
            'cashflow_entry_found' => collect($filtered)->contains(fn (array $entry) => (string) ($entry['code'] ?? '') === $cashflowCode || (string) ($entry['reference_code'] ?? '') === $cashflowCode),
        ];
    }

    private function signals(Customer $partner, ?Invoice $invoice, ?CashFlow $cashflow, array $relatedCustomerDebts, array $timeline): array
    {
        $outstanding = $invoice ? $this->invoiceOutstanding($invoice) : 0.0;
        $cashflowAmount = abs((float) ($cashflow?->amount ?? 0));
        $debtAdjustmentSignal = $cashflow ? $this->debtAdjustmentSignal($cashflow) : false;
        $samePartner = $cashflow
            ? (int) ($cashflow->target_id ?? 0) === (int) $partner->id
            : false;
        $amountMatches = $outstanding >= 0.01 && abs(abs($outstanding) - $cashflowAmount) < 0.01;
        $cashflowAfterInvoice = $invoice && $cashflow
            ? ($this->timestamp($cashflow->time ?? $cashflow->created_at) ?? 0) >= ($this->timestamp($invoice->transaction_date ?? $invoice->created_at) ?? 0)
            : false;

        return [
            'invoice_creates_debt' => $invoice && $outstanding >= 0.01 && !$this->cancelledStatus((string) ($invoice->status ?? '')),
            'invoice_outstanding' => $outstanding,
            'invoice_has_customer_debt' => count($relatedCustomerDebts['for_invoice'] ?? []) > 0,
            'cashflow_belongs_to_partner' => $samePartner,
            'cashflow_amount' => $cashflowAmount,
            'cashflow_status_active' => $cashflow ? !$this->cancelledStatus((string) ($cashflow->status ?? '')) : false,
            'debt_adjustment_signal' => $debtAdjustmentSignal,
            'amount_matches_invoice_outstanding' => $amountMatches,
            'cashflow_after_invoice' => $cashflowAfterInvoice,
            'cashflow_can_explain_settlement' => $debtAdjustmentSignal && $samePartner && $amountMatches && $cashflowAfterInvoice,
            'stored_customer_debt' => (float) ($partner->debt_amount ?? 0),
            'timeline_cashflow_entry_found' => (bool) ($timeline['cashflow_entry_found'] ?? false),
            'timeline_reconcile' => $timeline['reconcile'] ?? [],
            'double_count_risk_if_invoice_ledger_only' => $debtAdjustmentSignal && $samePartner && $amountMatches && abs((float) ($partner->debt_amount ?? 0)) < 0.01,
        ];
    }

    private function businessQuestions(array $signals, array $decision): array
    {
        return [
            [
                'question' => 'Invoice HD177598589311 co tao cong no 15M khong?',
                'answer' => $signals['invoice_creates_debt'] ? 'Co, invoice dang co outstanding 15,000,000 va chua co customer_debt row.' : 'Chua du bang chung.',
                'evidence' => 'outstanding=' . $signals['invoice_outstanding'] . '; invoice_has_customer_debt=' . ($signals['invoice_has_customer_debt'] ? 'yes' : 'no'),
            ],
            [
                'question' => 'Cashflow PT26042215161822 co phai dieu chinh cong no cua Anh Bay khong?',
                'answer' => $signals['debt_adjustment_signal'] && $signals['cashflow_belongs_to_partner'] ? 'Co dau hieu ro.' : 'Chua du bang chung.',
                'evidence' => 'debt_adjustment_signal=' . ($signals['debt_adjustment_signal'] ? 'yes' : 'no') . '; same_partner=' . ($signals['cashflow_belongs_to_partner'] ? 'yes' : 'no'),
            ],
            [
                'question' => 'Cashflow co lam cong no 15M ve 0 khong?',
                'answer' => $signals['cashflow_can_explain_settlement'] ? 'Co kha nang giai thich settlement 15M ve 0, nhung timeline hien chua show cashflow entry.' : 'Chua du bang chung.',
                'evidence' => 'amount_matches=' . ($signals['amount_matches_invoice_outstanding'] ? 'yes' : 'no') . '; stored_customer_debt=' . $signals['stored_customer_debt'] . '; timeline_cashflow_entry_found=' . ($signals['timeline_cashflow_entry_found'] ? 'yes' : 'no'),
            ],
            [
                'question' => 'Insert ledger cho invoice co double-count khong?',
                'answer' => $signals['double_count_risk_if_invoice_ledger_only'] ? 'Co nguy co double-count.' : 'Chua thay nguy co double-count ro.',
                'evidence' => 'cashflow settlement signal + stored debt 0 + amount matches invoice outstanding.',
            ],
            [
                'question' => 'Fix dung la gi?',
                'answer' => $decision['recommended_fix_type'],
                'evidence' => $decision['reason'],
            ],
        ];
    }

    private function candidateDecision(Customer $partner, ?Invoice $invoice, ?CashFlow $cashflow, array $signals): array
    {
        if ($signals['cashflow_can_explain_settlement']) {
            return [
                'candidate_status' => 'blocked',
                'recommended_fix_type' => 'MANUAL_REVIEW_REQUIRED',
                'reason' => 'Cashflow already represents debt adjustment/payment. Inserting invoice debt ledger alone may double-count.',
                'write_operations_preview' => [],
                'double_count_risk' => true,
                'blocked_reason' => 'possible settlement cashflow exists; manual decision required',
                'required_confirmation' => true,
                'backup_required' => true,
                'rollback_required' => true,
                'can_apply_real_fix' => false,
            ];
        }

        if (($signals['invoice_creates_debt'] ?? false) && !$cashflow) {
            return [
                'candidate_status' => 'candidate_ready',
                'recommended_fix_type' => 'NEED_LEDGER_FOR_INVOICE',
                'reason' => 'Invoice has outstanding debt and no related settlement cashflow was found.',
                'write_operations_preview' => [[
                    'operation' => 'insert_customer_debt',
                    'target_table' => 'customer_debts',
                    'partner_code' => $partner->code,
                    'source_document_type' => 'invoice',
                    'source_code' => $invoice?->code,
                    'amount' => $this->invoiceOutstanding($invoice),
                    'direction' => 'increase_debt',
                    'recorded_at' => (string) ($invoice?->transaction_date ?? $invoice?->created_at ?? ''),
                    'fix_run_id' => 'PREVIEW_ONLY',
                ]],
                'double_count_risk' => false,
                'blocked_reason' => null,
                'required_confirmation' => true,
                'backup_required' => true,
                'rollback_required' => true,
                'can_apply_real_fix' => false,
            ];
        }

        return [
            'candidate_status' => 'blocked',
            'recommended_fix_type' => 'MANUAL_REVIEW_REQUIRED',
            'reason' => 'Evidence is not sufficient to choose invoice, cashflow, or ledger authority automatically.',
            'write_operations_preview' => [],
            'double_count_risk' => (bool) ($signals['double_count_risk_if_invoice_ledger_only'] ?? false),
            'blocked_reason' => 'manual authority decision required',
            'required_confirmation' => true,
            'backup_required' => true,
            'rollback_required' => true,
            'can_apply_real_fix' => false,
        ];
    }

    private function debtMatches(array $row, ?Invoice $invoice, string $code, ?CashFlow $cashflow): bool
    {
        foreach (['ref_code', 'reference_code', 'code'] as $field) {
            if ((string) ($row[$field] ?? '') === $code) {
                return true;
            }
        }
        if ($invoice && (string) ($row['invoice_id'] ?? '') === (string) $invoice->id) {
            return true;
        }
        if ($cashflow && (string) ($row['cash_flow_id'] ?? '') === (string) $cashflow->id) {
            return true;
        }

        return $this->textContains($row['note'] ?? '', $code);
    }

    private function debtAdjustmentSignal(CashFlow $cashflow): bool
    {
        $referenceType = strtoupper((string) ($cashflow->reference_type ?? ''));
        $text = $this->normalizeSignalText((string) (($cashflow->description ?? '') . ' ' . ($cashflow->note ?? '')));

        return $referenceType === 'DEBTADJUSTMENT'
            || str_contains($text, 'DIEUCHINHCONGNO')
            || str_contains($text, 'CONGNO')
            || (str_contains($text, '15000000') && str_contains($text, '0'));
    }

    private function invoiceOutstanding(?Invoice $invoice): float
    {
        if (!$invoice) {
            return 0.0;
        }
        if (Schema::hasColumn('invoices', 'outstanding') && $invoice->outstanding !== null) {
            return (float) $invoice->outstanding;
        }
        if (Schema::hasColumn('invoices', 'debt_amount') && $invoice->debt_amount !== null) {
            return (float) $invoice->debt_amount;
        }

        return max(0.0, (float) ($invoice->total ?? 0) - (float) ($invoice->customer_paid ?? 0));
    }

    private function onlyModelFields(Model $model, string $table, array $fields): array
    {
        $row = [];
        foreach ($fields as $field) {
            if (Schema::hasColumn($table, $field) || array_key_exists($field, $model->getAttributes())) {
                $row[$field] = $this->scalar($model->getAttribute($field));
            }
        }

        return $row;
    }

    private function cancelledStatus(string $status): bool
    {
        $normalized = strtoupper($this->normalizeSignalText($status));

        return str_contains($normalized, 'HUY') || str_contains($normalized, 'CANCEL');
    }

    private function textContains(mixed $haystack, string $needle): bool
    {
        return $needle !== '' && str_contains((string) $haystack, $needle);
    }

    private function normalizeSignalText(string $text): string
    {
        $text = strtoupper($text);
        $text = str_replace([',', '.', ' ', '|', '-', '_', '→', '->'], '', $text);

        return preg_replace('/[^A-Z0-9]/', '', $text) ?: '';
    }

    private function timestamp(mixed $value): ?int
    {
        if (!$value) {
            return null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : $timestamp;
    }

    private function scalar(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    private function json(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function markdown(array $payload): string
    {
        $decision = $payload['candidate_decision'];
        $questions = $payload['business_questions'];
        $lines = [
            '# Debt candidate pair audit - ' . ($payload['partner']['code'] ?? 'unknown'),
            '',
            '## Scope',
            '',
            '- Partner: `' . $this->md($payload['partner']['code'] ?? '') . ' - ' . $this->md($payload['partner']['name'] ?? '') . '`.',
            '- Invoice: `' . $this->md($payload['invoice']['code'] ?? 'not found') . '`.',
            '- Cashflow: `' . $this->md($payload['cashflow']['code'] ?? 'not found') . '`.',
            '- Dry-run: `true`.',
            '',
            '## Data safety',
            '',
            '- Migration: no.',
            '- Backfill: no.',
            '- Update old data: no.',
            '- Delete: no.',
            '- Recalculate: no.',
            '- Write DB: no.',
            '- Requires confirmation before fix: yes.',
            '',
            '## Evidence',
            '',
            '### Partner',
            '',
            '```json',
            $this->json($payload['partner']),
            '```',
            '',
            '### Invoice',
            '',
            '```json',
            $this->json($payload['invoice']),
            '```',
            '',
            '### Cashflow',
            '',
            '```json',
            $this->json($payload['cashflow']),
            '```',
            '',
            '### Related ledger',
            '',
            '- Invoice ledger rows: `' . count($payload['related_customer_debts']['for_invoice'] ?? []) . '`.',
            '- Cashflow ledger rows: `' . count($payload['related_customer_debts']['for_cashflow'] ?? []) . '`.',
            '- All partner customer_debts: `' . count($payload['related_customer_debts']['all_for_partner'] ?? []) . '`.',
            '',
            '### Timeline',
            '',
            '- Timeline entries matched: `' . count($payload['timeline_entries']['entries'] ?? []) . '`.',
            '- Cashflow entry found in timeline: `' . (($payload['timeline_entries']['cashflow_entry_found'] ?? false) ? 'yes' : 'no') . '`.',
            '- Reconcile: `' . $this->md(json_encode($payload['timeline_entries']['reconcile'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') . '`.',
            '',
            '## Business questions',
            '',
            '| Question | Answer | Evidence |',
            '|---|---|---|',
        ];

        foreach ($questions as $question) {
            $lines[] = '| ' . $this->md($question['question']) . ' | ' . $this->md($question['answer']) . ' | ' . $this->md($question['evidence']) . ' |';
        }

        $lines = array_merge($lines, [
            '',
            '## Candidate decision',
            '',
            '- Status: `' . $decision['candidate_status'] . '`.',
            '- Recommended fix type: `' . $decision['recommended_fix_type'] . '`.',
            '- Write operations preview: `' . count($decision['write_operations_preview']) . '`.',
            '- Double-count risk: `' . ($decision['double_count_risk'] ? 'yes' : 'no') . '`.',
            '- Blocked reason: `' . ($decision['blocked_reason'] ?? 'null') . '`.',
            '- Required confirmation: `yes`.',
            '- Backup required: `yes`.',
            '- Rollback required: `yes`.',
            '',
            '## Conclusion',
            '',
            '- Candidate insert ledger cho invoice chua duoc duyet.',
            '- Co nguy co double-count: `' . ($decision['double_count_risk'] ? 'yes' : 'no') . '`.',
            '- Co the chay fix that chua: Chua.',
            '- Can manual decision ve mapping/ledger strategy.',
            '',
        ]);

        return implode(PHP_EOL, $lines);
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
