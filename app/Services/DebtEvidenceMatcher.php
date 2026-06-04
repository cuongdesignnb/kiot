<?php

namespace App\Services;

class DebtEvidenceMatcher
{
    private const BLOCKED_GROUPS = [
        'C_LEDGER_DOCUMENT_MISMATCH',
        'D_CUSTOMER_ONLY_REVIEW',
        'E_DUAL_ROLE_ORIENTATION_REVIEW',
        'F_STORED_BALANCE_OPENING_CANDIDATE',
        'X_PLAN_INPUT_MISMATCH',
        'Z_NEEDS_MANUAL_REVIEW',
    ];

    public function match(array $inspection, array $plan): array
    {
        $raw = $inspection['raw'] ?? $this->emptyRawSources();
        $documentRows = $this->documentRows($raw);
        $ledgerRows = $this->ledgerRows($raw);
        $cashflowRows = array_values($raw['cash_flows'] ?? []);

        $usedLedger = [];
        $usedCashflow = [];
        $possibleMatches = [];
        $matrix = [];

        foreach ($documentRows as $document) {
            $ledgerCandidates = $this->candidateRows($document, $ledgerRows, 'ledger');
            $cashflowCandidates = $this->candidateRows($document, $cashflowRows, 'cashflow');
            $bestLedger = $ledgerCandidates[0] ?? null;
            $bestCashflow = $cashflowCandidates[0] ?? null;

            foreach (array_slice(array_merge($ledgerCandidates, $cashflowCandidates), 0, 8) as $candidate) {
                $possibleMatches[] = $this->possibleMatchRow($document, $candidate);
            }

            if ($bestLedger && isset($bestLedger['row']['id'])) {
                $usedLedger[(string) $bestLedger['row']['id']] = true;
            }
            if ($bestCashflow && isset($bestCashflow['row']['id'])) {
                $usedCashflow[(string) $bestCashflow['row']['id']] = true;
            }

            $matrix[] = $this->documentMatrixRow(
                $document,
                $bestLedger,
                $bestCashflow,
                $ledgerCandidates,
                $cashflowCandidates,
                count($ledgerCandidates),
                count($cashflowCandidates)
            );
        }

        foreach ($ledgerRows as $ledger) {
            if (isset($ledger['id']) && isset($usedLedger[(string) $ledger['id']])) {
                continue;
            }

            $matrix[] = $this->unmatchedLedgerRow($ledger);
        }

        foreach ($cashflowRows as $cashflow) {
            if (isset($cashflow['id']) && isset($usedCashflow[(string) $cashflow['id']])) {
                continue;
            }

            $matrix[] = $this->unmatchedCashflowRow($cashflow);
        }

        $issues = $this->detectedIssues($matrix, $plan);
        $candidatePreview = $this->candidatePreview($inspection, $plan, $matrix, $issues);

        return [
            'document_rows' => $documentRows,
            'ledger_rows' => $ledgerRows,
            'cashflow_rows' => $cashflowRows,
            'matching_matrix' => $matrix,
            'possible_matches' => $possibleMatches,
            'detected_issues' => $issues,
            'candidate_preview' => $candidatePreview,
            'summary' => [
                'document_count' => count($documentRows),
                'ledger_count' => count($ledgerRows),
                'cashflow_count' => count($cashflowRows),
                'matrix_count' => count($matrix),
                'possible_match_count' => count($possibleMatches),
                'issue_count' => count($issues),
                'critical_issue_count' => count(array_filter($issues, fn (array $issue) => ($issue['severity'] ?? '') === 'critical')),
                'candidate_ready' => (bool) ($candidatePreview['candidate_ready'] ?? false),
                'write_operations_preview_count' => count($candidatePreview['write_operations_preview'] ?? []),
            ],
        ];
    }

    private function normalizeCode(?string $code): array
    {
        $original = $code;
        $code = strtoupper(trim((string) $code));
        $code = preg_replace('/\s+/', '', $code) ?: '';
        $withoutSeparators = str_replace(['_', '-'], '', $code);
        $base = explode(':', $withoutSeparators)[0] ?? $withoutSeparators;
        $kind = 'unknown';

        if ($base === '') {
            $kind = 'unknown';
        } elseif (preg_match('/^MERGECUSTOMER\d+$/', $base) === 1 || str_starts_with($base, 'MERGECUSTOMER')) {
            $kind = 'merge';
        } elseif (preg_match('/^PCPN(.+)$/', $base, $m) === 1) {
            $base = 'PN' . $m[1];
            $kind = 'purchase_payment';
        } elseif (str_starts_with($base, 'PN')) {
            $kind = 'purchase';
        } elseif (str_starts_with($base, 'HD')) {
            $kind = 'invoice';
        } elseif (str_starts_with($base, 'TH') || str_starts_with($base, 'PTN')) {
            $kind = 'return';
        } elseif (str_starts_with($base, 'CKTT') || str_starts_with($base, 'PT') || str_starts_with($base, 'PC')) {
            $kind = 'cashflow';
        } elseif (str_starts_with($base, 'DCNCC') || str_starts_with($base, 'DC') || str_starts_with($base, 'CK')) {
            $kind = 'manual';
        }

        return [
            'original' => $original,
            'normalized' => $withoutSeparators,
            'base_code' => $base,
            'prefix' => $this->prefix($base),
            'kind_hint' => $kind,
        ];
    }

    private function candidateRows(array $document, array $rows, string $targetType): array
    {
        $candidates = [];
        foreach ($rows as $row) {
            $candidate = $this->scoreCandidate($document, $row, $targetType);
            if ($candidate['score'] <= 0) {
                continue;
            }
            $candidates[] = $candidate;
        }

        usort($candidates, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return $candidates;
    }

    private function scoreCandidate(array $document, array $row, string $targetType): array
    {
        $docCode = $document['normalized_code'];
        $rowCodes = $this->rowCodes($row);
        $strategies = [];
        $score = 0;
        $reason = '';

        foreach ($rowCodes as $rowCode) {
            if ($docCode['original'] !== null && (string) $docCode['original'] !== '' && (string) $docCode['original'] === (string) $rowCode['original']) {
                $score = max($score, 100);
                $strategies[] = 'exact_reference';
                $reason = 'Exact code/reference match.';
            }
            if ($docCode['base_code'] !== '' && $docCode['base_code'] === $rowCode['base_code']) {
                $score = max($score, 90);
                $strategies[] = 'normalized_base_code';
                $reason = 'Normalized base code matches.';
            }
        }

        if ($this->fkMatches($document, $row, $targetType)) {
            $score = max($score, 100);
            $strategies[] = 'direct_fk';
            $reason = 'Direct FK/reference id matches.';
        }

        if ($this->amountDateNear($document, $row)) {
            $score = max($score, 70);
            $strategies[] = 'amount_date_near';
            $reason = $reason ?: 'Amount and date are near with compatible direction.';
        }

        if ($targetType === 'cashflow' && $this->paymentPair($document, $row)) {
            $score = max($score, abs((float) ($document['expected_effect'] ?? 0)) < 0.01 ? 85 : 75);
            $strategies[] = 'payment_cashflow_pair';
            $reason = 'Cashflow/payment pair explains document settlement.';
        }

        return [
            'target_type' => $targetType,
            'row' => $row,
            'score' => $score,
            'confidence' => $this->confidence($score),
            'strategy' => $strategies[0] ?? null,
            'strategies' => array_values(array_unique($strategies)),
            'reason' => $reason,
        ];
    }

    private function documentMatrixRow(
        array $document,
        ?array $ledger,
        ?array $cashflow,
        array $ledgerCandidates,
        array $cashflowCandidates,
        int $ledgerCount,
        int $cashflowCount
    ): array
    {
        $status = $this->documentStatus($document, $ledger, $cashflow, $ledgerCount, $cashflowCount);
        $ledgerRow = $ledger['row'] ?? null;
        $cashflowRow = $cashflow['row'] ?? null;
        $score = max((int) ($ledger['score'] ?? 0), (int) ($cashflow['score'] ?? 0));
        $strategy = $ledger['strategy'] ?? $cashflow['strategy'] ?? null;

        return [
            'source_type' => $document['source_type'],
            'source_id' => $document['source_id'] ?? null,
            'source_code' => $document['source_code'],
            'source_base_code' => $document['normalized_code']['base_code'],
            'source_date' => $document['source_date'],
            'source_status' => $document['source_status'],
            'source_amount' => $this->number($document['source_amount'] ?? 0),
            'expected_effect' => $this->number($document['expected_effect'] ?? 0),
            'candidate_ledger_codes' => $this->candidateCodes($ledgerCandidates),
            'candidate_cashflow_codes' => $this->candidateCodes($cashflowCandidates),
            'best_match_type' => $strategy,
            'best_match_score' => $score,
            'best_match_confidence' => $this->confidence($score),
            'matched_ledger_id' => $ledgerRow['id'] ?? null,
            'matched_ledger_code' => $ledgerRow['code'] ?? $ledgerRow['reference_code'] ?? null,
            'matched_ledger_amount' => $ledgerRow ? $this->number($ledgerRow['amount'] ?? 0) : null,
            'matched_cashflow_id' => $cashflowRow['id'] ?? null,
            'matched_cashflow_code' => $cashflowRow['code'] ?? $cashflowRow['reference_code'] ?? null,
            'matched_cashflow_amount' => $cashflowRow ? $this->number($cashflowRow['amount'] ?? 0) : null,
            'match_status' => $status['status'],
            'issue' => $status['issue'],
            'severity' => $status['severity'],
            'can_auto_candidate' => (bool) $status['can_auto_candidate'],
        ];
    }

    private function documentStatus(array $document, ?array $ledger, ?array $cashflow, int $ledgerCount, int $cashflowCount): array
    {
        if (abs((float) ($document['expected_effect'] ?? 0)) < 0.01) {
            return $this->status('ZERO_EFFECT_DOCUMENT', 'Zero-effect document should not create a high missing-ledger issue.', 'info', false);
        }
        if ($ledgerCount > 1) {
            return $this->status('DUPLICATE_LEDGER', 'Multiple ledger candidates remain ambiguous.', 'critical', false);
        }
        if ($cashflowCount > 1) {
            return $this->status('DUPLICATE_CASHFLOW', 'Multiple cashflow candidates remain ambiguous.', 'critical', false);
        }
        if (!$ledger) {
            return $this->status('MISSING_LEDGER', 'No ledger candidate found after exact/FK/normalized/amount-date strategies.', 'high', true);
        }

        $ledgerRow = $ledger['row'];
        if ($this->signMismatch((float) ($document['expected_effect'] ?? 0), (float) ($ledgerRow['amount'] ?? 0))) {
            return $this->status('SIGN_MISMATCH', 'Document effect and ledger amount signs differ.', 'critical', false);
        }
        if ($this->amountMismatch((float) ($document['expected_effect'] ?? 0), (float) ($ledgerRow['amount'] ?? 0))) {
            return $this->status('AMOUNT_MISMATCH', 'Document expected effect and ledger amount differ.', 'high', false);
        }

        return match ($ledger['strategy']) {
            'exact_reference' => $this->status('MATCHED_EXACT', '', 'info', false),
            'direct_fk' => $this->status('MATCHED_FK', '', 'info', false),
            'normalized_base_code' => $this->status('MATCHED_NORMALIZED_CODE', '', 'info', false),
            'amount_date_near' => $this->status('MATCHED_AMOUNT_DATE', '', 'low', false),
            default => $this->status('POSSIBLE_MATCH', 'Possible match requires manual confirmation.', 'medium', false),
        };
    }

    private function unmatchedLedgerRow(array $ledger): array
    {
        $code = $this->normalizeCode($ledger['code'] ?? $ledger['reference_code'] ?? null);
        $isManual = in_array($code['kind_hint'], ['merge', 'manual'], true);
        $amount = $this->number($ledger['amount'] ?? 0);

        return [
            'source_type' => 'ledger',
            'source_code' => $ledger['code'] ?? $ledger['reference_code'] ?? null,
            'source_base_code' => $code['base_code'],
            'source_date' => $ledger['recorded_at'] ?? $ledger['created_at'] ?? null,
            'source_status' => $ledger['status'] ?? null,
            'source_amount' => $amount,
            'expected_effect' => $amount,
            'candidate_ledger_codes' => [$ledger['code'] ?? $ledger['reference_code'] ?? null],
            'candidate_cashflow_codes' => [],
            'best_match_type' => $isManual ? 'manual_merge_ledger' : null,
            'best_match_score' => 0,
            'best_match_confidence' => 'none',
            'matched_ledger_id' => $ledger['id'] ?? null,
            'matched_ledger_code' => $ledger['code'] ?? $ledger['reference_code'] ?? null,
            'matched_ledger_amount' => $amount,
            'matched_cashflow_id' => null,
            'matched_cashflow_code' => null,
            'matched_cashflow_amount' => null,
            'match_status' => $isManual ? 'MANUAL_LEDGER_REQUIRES_AUTHORITY' : 'MISSING_DOCUMENT',
            'issue' => $isManual ? 'Manual/merge ledger requires source authority before any fix.' : 'Ledger row has no matching document.',
            'severity' => $isManual && abs($amount) >= 1000000 ? 'critical' : 'high',
            'can_auto_candidate' => false,
        ];
    }

    private function unmatchedCashflowRow(array $cashflow): array
    {
        $code = $this->normalizeCode($cashflow['reference_code'] ?? $cashflow['code'] ?? null);
        $amount = $this->number($cashflow['amount'] ?? 0);

        return [
            'source_type' => 'cashflow',
            'source_code' => $cashflow['reference_code'] ?? $cashflow['code'] ?? null,
            'source_base_code' => $code['base_code'],
            'source_date' => $cashflow['time'] ?? $cashflow['created_at'] ?? null,
            'source_status' => $cashflow['status'] ?? null,
            'source_amount' => $amount,
            'expected_effect' => $amount,
            'candidate_ledger_codes' => [],
            'candidate_cashflow_codes' => [$cashflow['code'] ?? $cashflow['reference_code'] ?? null],
            'best_match_type' => null,
            'best_match_score' => 0,
            'best_match_confidence' => 'none',
            'matched_ledger_id' => null,
            'matched_ledger_code' => null,
            'matched_ledger_amount' => null,
            'matched_cashflow_id' => $cashflow['id'] ?? null,
            'matched_cashflow_code' => $cashflow['code'] ?? $cashflow['reference_code'] ?? null,
            'matched_cashflow_amount' => $amount,
            'match_status' => 'UNMATCHED',
            'issue' => 'Cashflow row has no matching document after evidence matching.',
            'severity' => 'medium',
            'can_auto_candidate' => false,
        ];
    }

    private function detectedIssues(array $matrix, array $plan): array
    {
        $issues = [];
        foreach ($matrix as $row) {
            if (in_array($row['match_status'], ['MATCHED_EXACT', 'MATCHED_NORMALIZED_CODE', 'MATCHED_FK', 'MATCHED_AMOUNT_DATE'], true)) {
                continue;
            }
            if ($row['match_status'] === 'ZERO_EFFECT_DOCUMENT' && ($row['severity'] ?? '') === 'info') {
                continue;
            }

            $issues[] = [
                'severity' => $row['severity'],
                'type' => $row['match_status'],
                'evidence' => [
                    'source_type' => $row['source_type'],
                    'source_code' => $row['source_code'],
                    'source_base_code' => $row['source_base_code'],
                    'issue' => $row['issue'],
                    'fix_group' => $plan['fix_group'] ?? null,
                    'best_match_score' => $row['best_match_score'],
                ],
                'suggested_action' => $this->suggestedAction($row),
                'can_auto_fix' => false,
            ];
        }

        return $issues;
    }

    private function candidatePreview(array $inspection, array $plan, array $matrix, array $issues): array
    {
        $group = (string) ($plan['fix_group'] ?? 'Z_NEEDS_MANUAL_REVIEW');
        $critical = count(array_filter($issues, fn (array $issue) => ($issue['severity'] ?? '') === 'critical')) > 0;
        $ambiguous = count(array_filter($matrix, fn (array $row) => in_array($row['match_status'], ['DUPLICATE_LEDGER', 'DUPLICATE_CASHFLOW', 'SIGN_MISMATCH', 'STATUS_MISMATCH'], true))) > 0;

        if (in_array($group, self::BLOCKED_GROUPS, true)) {
            return $this->blockedPreview('group blocked by default', [
                'Senior Auditor must choose authority for ' . $group,
                'Partner must be allowlisted before any write step',
            ]);
        }

        if ($group === 'B_DOCUMENTS_NO_LEDGER') {
            if ($critical || $ambiguous) {
                return $this->blockedPreview('critical or duplicate/sign/status ambiguity remains', ['Resolve ambiguity before candidate preview.']);
            }

            $operations = [];
            foreach ($matrix as $row) {
                if ($row['match_status'] !== 'MISSING_LEDGER' || !$row['can_auto_candidate']) {
                    continue;
                }
                if (abs((float) ($row['expected_effect'] ?? 0)) < 0.01 || empty($row['source_date'])) {
                    continue;
                }

                $operations[] = $this->ledgerPreviewOperation($inspection, $row);
            }

            return $operations
                ? $this->readyPreview($group, $operations)
                : $this->blockedPreview('no high-confidence write operation preview could be built', ['Confirm source status, sign, amount, and date.']);
        }

        if ($group === 'A_OPENING_BALANCE_REVIEW') {
            $operation = $this->openingBalancePreview($inspection);

            return $operation
                ? $this->readyPreview($group, [$operation])
                : $this->blockedPreview('opening balance evidence is not sufficient', ['Confirm virtual opening is only source and target side/date are approved.']);
        }

        return $this->blockedPreview('group is not allowlisted for candidate preview', ['Only A/B can produce preview operations in this step.']);
    }

    private function ledgerPreviewOperation(array $inspection, array $row): array
    {
        $partner = $inspection['partner'] ?? [];
        $isSupplier = (bool) ($partner['is_supplier'] ?? false) && !((bool) ($partner['is_customer'] ?? false) && (string) ($row['source_type'] ?? '') === 'invoice');
        $amount = $this->number($row['expected_effect'] ?? 0);

        return [
            'operation' => $isSupplier ? 'insert_supplier_debt_transaction' : 'insert_customer_debt',
            'target_table' => $isSupplier ? 'supplier_debt_transactions' : 'customer_debts',
            'partner_id' => $partner['id'] ?? null,
            'partner_code' => $partner['code'] ?? null,
            'source_document_type' => $row['source_type'],
            'source_document_id' => $row['source_id'] ?? null,
            'source_code' => $row['source_code'],
            'amount' => $amount,
            'direction' => $amount >= 0 ? 'increase_debt' : 'decrease_debt',
            'recorded_at' => $row['source_date'],
            'reference_type' => $row['source_type'],
            'reference_id' => $row['source_id'] ?? null,
            'reference_code' => $row['source_code'],
            'note' => 'PREVIEW ONLY - generated from debt evidence matcher',
            'fix_run_id' => 'PREVIEW_ONLY',
            'confidence' => 'high',
        ];
    }

    private function openingBalancePreview(array $inspection): ?array
    {
        $partner = $inspection['partner'] ?? [];
        $stored = $inspection['stored_balances'] ?? [];
        $isSupplier = (bool) ($partner['is_supplier'] ?? false) && abs((float) ($stored['supplier_payable'] ?? 0)) >= 0.01;
        $amount = $isSupplier ? (float) ($stored['supplier_payable'] ?? 0) : (float) ($stored['customer_receivable'] ?? 0);

        if (abs($amount) < 0.01) {
            return null;
        }

        return [
            'operation' => 'insert_opening_balance',
            'target_table' => $isSupplier ? 'supplier_debt_transactions' : 'customer_debts',
            'partner_id' => $partner['id'] ?? null,
            'partner_code' => $partner['code'] ?? null,
            'amount' => $this->number($amount),
            'direction' => $amount >= 0 ? 'increase_debt' : 'decrease_debt',
            'recorded_at' => 'configured_opening_date',
            'reference_code' => 'OPENING-DEBT-FIX-PREVIEW',
            'note' => 'PREVIEW ONLY - opening balance candidate',
            'fix_run_id' => 'PREVIEW_ONLY',
            'confidence' => 'medium',
        ];
    }

    private function documentRows(array $raw): array
    {
        $rows = [];
        foreach ($raw['invoices'] ?? [] as $row) {
            $rows[] = $this->documentRow('invoice', $row, $row['outstanding'] ?? $row['debt_amount'] ?? 0, $row['transaction_date'] ?? $row['created_at'] ?? null);
        }
        foreach ($raw['order_returns'] ?? [] as $row) {
            $rows[] = $this->documentRow('order_return', $row, -abs((float) ($row['amount'] ?? $row['total'] ?? 0)), $row['business_date'] ?? $row['created_at'] ?? null);
        }
        foreach ($raw['purchases'] ?? [] as $row) {
            $rows[] = $this->documentRow('purchase', $row, $row['outstanding'] ?? $row['total_amount'] ?? 0, $row['purchase_date'] ?? $row['created_at'] ?? null);
        }
        foreach ($raw['purchase_returns'] ?? [] as $row) {
            $rows[] = $this->documentRow('purchase_return', $row, -abs((float) ($row['amount'] ?? $row['total'] ?? 0)), $row['business_date'] ?? $row['created_at'] ?? null);
        }
        foreach ($raw['debt_offsets'] ?? [] as $row) {
            $rows[] = $this->documentRow('debt_offset', $row, $row['amount'] ?? 0, $row['business_date'] ?? $row['created_at'] ?? null);
        }

        return $rows;
    }

    private function documentRow(string $type, array $row, mixed $effect, mixed $date): array
    {
        $code = $row['code'] ?? $row['reference_code'] ?? null;

        return [
            'source_type' => $type,
            'source_id' => $row['id'] ?? null,
            'source_code' => $code,
            'normalized_code' => $this->normalizeCode($code),
            'source_date' => $date,
            'source_status' => $row['status'] ?? $row['payment_status'] ?? null,
            'source_amount' => $this->number($row['total'] ?? $row['total_amount'] ?? $row['amount'] ?? $row['outstanding'] ?? 0),
            'expected_effect' => $this->number($effect),
            'raw' => $row,
        ];
    }

    private function ledgerRows(array $raw): array
    {
        $rows = array_values(array_merge($raw['customer_debts'] ?? [], $raw['supplier_debt_transactions'] ?? []));

        return array_map(function (array $row) {
            $row['normalized_codes'] = $this->rowCodes($row);
            return $row;
        }, $rows);
    }

    private function rowCodes(array $row): array
    {
        $codes = [];
        foreach (['code', 'reference_code', 'ref_code'] as $key) {
            if (!empty($row[$key])) {
                $codes[] = $this->normalizeCode((string) $row[$key]);
            }
        }

        return $codes ?: [$this->normalizeCode(null)];
    }

    private function fkMatches(array $document, array $row, string $targetType): bool
    {
        $docId = (string) ($document['source_id'] ?? '');
        $docType = (string) ($document['source_type'] ?? '');
        if ($docId === '') {
            return false;
        }

        if ($docType === 'invoice' && (string) ($row['invoice_id'] ?? '') === $docId) {
            return true;
        }
        if ($docType === 'purchase' && (string) ($row['purchase_id'] ?? '') === $docId) {
            return true;
        }
        if ($targetType === 'cashflow' && (string) ($row['reference_id'] ?? '') === $docId) {
            return true;
        }

        return false;
    }

    private function amountDateNear(array $document, array $row): bool
    {
        $docAmount = abs((float) ($document['expected_effect'] ?? 0));
        $rowAmount = abs((float) ($row['amount'] ?? 0));
        if ($docAmount < 0.01 || abs($docAmount - $rowAmount) >= 0.01) {
            return false;
        }

        $docDate = $this->timestamp($document['source_date'] ?? null);
        $rowDate = $this->timestamp($row['recorded_at'] ?? $row['created_at'] ?? $row['time'] ?? null);
        if (!$docDate || !$rowDate) {
            return false;
        }

        return abs($docDate - $rowDate) <= 3 * 86400
            && !$this->signMismatch((float) ($document['expected_effect'] ?? 0), (float) ($row['amount'] ?? 0));
    }

    private function paymentPair(array $document, array $row): bool
    {
        $docCode = $document['normalized_code']['base_code'] ?? '';
        foreach ($this->rowCodes($row) as $rowCode) {
            if ($docCode !== '' && $docCode === $rowCode['base_code']) {
                return true;
            }
        }

        return false;
    }

    private function possibleMatchRow(array $document, array $candidate): array
    {
        $row = $candidate['row'];

        return [
            'source' => $document['source_code'],
            'source_type' => $document['source_type'],
            'candidate' => $row['code'] ?? $row['reference_code'] ?? null,
            'candidate_type' => $candidate['target_type'],
            'strategy' => $candidate['strategy'],
            'strategies' => $candidate['strategies'],
            'score' => $candidate['score'],
            'confidence' => $candidate['confidence'],
            'reason' => $candidate['reason'],
        ];
    }

    private function candidateCodes(array $candidates): array
    {
        return array_values(array_filter(array_map(
            fn (array $candidate) => $candidate['row']['code'] ?? $candidate['row']['reference_code'] ?? null,
            $candidates
        )));
    }

    private function status(string $status, string $issue, string $severity, bool $canAutoCandidate): array
    {
        return [
            'status' => $status,
            'issue' => $issue,
            'severity' => $severity,
            'can_auto_candidate' => $canAutoCandidate,
        ];
    }

    private function readyPreview(string $group, array $operations): array
    {
        return [
            'candidate_ready' => true,
            'confidence' => 'high',
            'allowed_group' => $group,
            'write_operations_preview' => $operations,
            'blocked_reason' => null,
            'required_manual_decision' => [
                'Senior Auditor written approval is still required before real apply.',
            ],
            'requires_confirmation_before_fix' => true,
            'requires_backup' => true,
            'rollback_required' => true,
        ];
    }

    private function blockedPreview(string $reason, array $manualDecisions): array
    {
        return [
            'candidate_ready' => false,
            'confidence' => 'none',
            'allowed_group' => null,
            'write_operations_preview' => [],
            'blocked_reason' => $reason,
            'required_manual_decision' => $manualDecisions,
            'requires_confirmation_before_fix' => true,
            'requires_backup' => true,
            'rollback_required' => true,
        ];
    }

    private function suggestedAction(array $row): string
    {
        return match ($row['match_status']) {
            'MANUAL_LEDGER_REQUIRES_AUTHORITY' => 'Manual/merge ledger must be reviewed by authority before any write.',
            'MISSING_LEDGER' => 'Review candidate preview only if group is B and no ambiguity remains.',
            'MISSING_DOCUMENT' => 'Find source document or keep as manual review; do not delete automatically.',
            'UNMATCHED' => 'Review cashflow reference and payment pair manually.',
            'AMOUNT_MISMATCH' => 'Compare totals, paid/refund, and ledger amount.',
            default => 'Manual review required before any real fix.',
        };
    }

    private function confidence(int $score): string
    {
        if ($score >= 90) {
            return 'high';
        }
        if ($score >= 70) {
            return 'medium';
        }
        if ($score > 0) {
            return 'low';
        }

        return 'none';
    }

    private function prefix(string $code): ?string
    {
        return preg_match('/^[A-Z]+/', $code, $m) === 1 ? $m[0] : null;
    }

    private function amountMismatch(float $documentEffect, float $ledgerAmount): bool
    {
        return abs(abs($documentEffect) - abs($ledgerAmount)) >= 0.01;
    }

    private function signMismatch(float $documentEffect, float $ledgerAmount): bool
    {
        return $documentEffect !== 0.0 && $ledgerAmount !== 0.0
            && ($documentEffect <=> 0.0) !== ($ledgerAmount <=> 0.0);
    }

    private function timestamp(mixed $value): ?int
    {
        if (!$value) {
            return null;
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? null : $timestamp;
    }

    private function number(mixed $value): float
    {
        return (float) ($value ?? 0);
    }

    private function emptyRawSources(): array
    {
        return [
            'customer_debts' => [],
            'supplier_debt_transactions' => [],
            'invoices' => [],
            'order_returns' => [],
            'purchases' => [],
            'purchase_returns' => [],
            'cash_flows' => [],
            'debt_offsets' => [],
        ];
    }
}
