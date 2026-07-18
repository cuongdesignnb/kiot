<?php

namespace App\Services\Debt;

class DebtReconciliationPlanService
{
    public const CSV_COLUMNS = [
        'partner_id', 'partner_code', 'role', 'risk_level', 'primary_classification',
        'proposed_action_type', 'customer_delta', 'supplier_delta', 'proposed_voucher',
        'confidence', 'requires_backup', 'requires_manual_approval', 'rollback_strategy',
        'evidence_required', 'status', 'source_report_sha256', 'database_fingerprint', 'approval_hash',
    ];

    public function generate(array $auditRows, string $sourceReportSha256 = '', string $databaseFingerprint = ''): array
    {
        return array_map(
            fn (array $row): array => $this->planRow($row, $sourceReportSha256, $databaseFingerprint),
            $auditRows,
        );
    }

    public function planRow(array $row, string $sourceReportSha256 = '', string $databaseFingerprint = ''): array
    {
        $classification = (string) ($row['primary_classification'] ?? 'AUDIT_ERROR');
        $flags = array_values(array_filter((array) ($row['classification_flags'] ?? [$classification])));
        $action = $this->action($classification, $flags);

        $beforeCustomer = (float) ($row['raw_customer_debt'] ?? 0);
        $beforeSupplier = (float) ($row['raw_supplier_debt'] ?? 0);
        $targetCustomer = (float) ($row['customer_document_raw_final'] ?? $beforeCustomer);
        $targetSupplier = (float) ($row['supplier_document_raw_final'] ?? $beforeSupplier);
        $blockingFlags = array_values(array_intersect($flags, [
            'AUDIT_ERROR', 'DUPLICATE_REAL_AND_FALLBACK', 'DUPLICATE_CUSTOMER_RECEIPT',
            'DUPLICATE_SUPPLIER_PAYMENT', 'RETURN_REFUND_DUPLICATE', 'CANCEL_REVERSAL_MISSING',
            'INVOICE_RECEIPT_ALLOCATION_MISMATCH', 'PURCHASE_PAYMENT_ALLOCATION_MISMATCH',
            'INVOICE_RECEIPT_ALLOCATION_EVIDENCE_MISSING', 'PURCHASE_PAYMENT_ALLOCATION_EVIDENCE_MISSING',
        ]));
        $hasProjectionDifference = abs($targetCustomer - $beforeCustomer) > PartnerDebtParityAuditService::TOLERANCE
            || abs($targetSupplier - $beforeSupplier) > PartnerDebtParityAuditService::TOLERANCE;
        if ($hasProjectionDifference && $blockingFlags === []) {
            $action = [
                'type' => 'UPDATE_STORED_PROJECTION',
                'customer_delta' => $targetCustomer - $beforeCustomer,
                'supplier_delta' => $targetSupplier - $beforeSupplier,
                'voucher' => null,
                'confidence' => 'high',
                'requires_backup' => true,
                'requires_manual_approval' => true,
                'rollback_strategy' => 'Restore the exact before projection in the same guarded transaction.',
                'evidence_required' => ['Canonical document event stream.', 'Source report SHA-256.', 'Approval hash.'],
            ];
        }

        $plan = [
            'partner_id' => (int) ($row['partner_id'] ?? 0),
            'partner_code' => (string) ($row['partner_code'] ?? ''),
            'role' => (string) ($row['role'] ?? ''),
            'risk_level' => (string) ($row['risk_level'] ?? 'CRITICAL'),
            'primary_classification' => $classification,
            'classification_flags' => $flags,
            'proposed_action_type' => $action['type'],
            'customer_delta' => $action['customer_delta'],
            'supplier_delta' => $action['supplier_delta'],
            'proposed_voucher' => $action['voucher'],
            'confidence' => $action['confidence'],
            'requires_backup' => $action['requires_backup'],
            'requires_manual_approval' => $action['requires_manual_approval'],
            'rollback_strategy' => $action['rollback_strategy'],
            'evidence_required' => $action['evidence_required'],
            'status' => 'PROPOSED',
            'before_snapshot' => [
                'customer_receivable' => $beforeCustomer,
                'supplier_payable' => $beforeSupplier,
                'partner_code' => (string) ($row['partner_code'] ?? ''),
                'role' => (string) ($row['role'] ?? ''),
            ],
            'canonical_target' => [
                'customer_receivable' => $targetCustomer,
                'supplier_payable' => $targetSupplier,
            ],
            'event_evidence' => [
                'customer_codes' => (array) ($row['suspect_invoice_codes'] ?? []),
                'supplier_codes' => (array) ($row['suspect_purchase_codes'] ?? []),
                'adjustment_codes' => (array) ($row['suspect_adjustment_codes'] ?? []),
            ],
            'blocking_flags' => $blockingFlags,
            'source_report_sha256' => $sourceReportSha256,
            'database_fingerprint' => $databaseFingerprint,
        ];

        $plan['approval_hash'] = hash('sha256', json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        return $plan;
    }

    private function action(string $classification, array $flags): array
    {
        if ($classification === 'OK') {
            return $this->payload(
                'NO_ACTION', 0.0, 0.0, null, 'high', false, false,
                'Không có thay đổi để rollback.',
                ['Stored, document và ledger khớp trong tolerance.'],
            );
        }

        if (in_array($classification, [
            'TARGET_TYPE_ALIAS_SUSPECT',
            'TECHNICAL_LEDGER_EXCLUDED',
            'VIRTUAL_DISPLAY_ALIGNMENT_ONLY',
            'DUPLICATE_REAL_AND_FALLBACK',
            'DUPLICATE_CUSTOMER_RECEIPT',
            'DUPLICATE_SUPPLIER_PAYMENT',
            'RETURN_REFUND_DUPLICATE',
            'PURCHASE_RETURN_REFUND_MISMATCH',
        ], true)) {
            return $this->payload(
                'CODE_REVIEW_REQUIRED', 0.0, 0.0, null, 'medium', false, true,
                'Revert code-only patch; không có database rollback.',
                ['Diff source classification/dedup.', 'Regression fixture theo mã chứng từ nghi vấn.'],
            );
        }

        if (in_array('VIRTUAL_OPENING_REQUIRED', $flags, true)
            || $classification === 'STORED_BALANCE_NO_HISTORY') {
            return $this->payload(
                'OPENING_BALANCE_REVIEW_ONLY', 0.0, 0.0, null, 'low', true, true,
                'Chưa apply. Nếu sau này được duyệt phải reversal theo batch, không xóa chứng từ.',
                ['Chứng từ trước kỳ hệ thống.', 'Biên bản xác nhận số dư đầu kỳ.', 'Approved plan hash.'],
            );
        }

        return $this->payload(
            'BLOCKED_UNCERTAIN_SOURCE_OF_TRUTH', 0.0, 0.0, null, 'low', true, true,
            'Không có thay đổi để rollback vì plan bị chặn.',
            [
                'Drilldown chứng từ thật và fallback.',
                'Đối chiếu stored, document raw và ledger raw.',
                'Xác nhận thủ công source of truth trước mọi delta.',
            ],
        );
    }

    private function payload(
        string $type,
        float $customerDelta,
        float $supplierDelta,
        ?string $voucher,
        string $confidence,
        bool $requiresBackup,
        bool $requiresManualApproval,
        string $rollbackStrategy,
        array $evidenceRequired,
    ): array {
        return [
            'type' => $type,
            'customer_delta' => $customerDelta,
            'supplier_delta' => $supplierDelta,
            'voucher' => $voucher,
            'confidence' => $confidence,
            'requires_backup' => $requiresBackup,
            'requires_manual_approval' => $requiresManualApproval,
            'rollback_strategy' => $rollbackStrategy,
            'evidence_required' => $evidenceRequired,
        ];
    }
}
