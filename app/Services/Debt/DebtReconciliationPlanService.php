<?php

namespace App\Services\Debt;

class DebtReconciliationPlanService
{
    public const CSV_COLUMNS = [
        'partner_id', 'partner_code', 'role', 'risk_level', 'primary_classification',
        'proposed_action_type', 'customer_delta', 'supplier_delta', 'proposed_voucher',
        'confidence', 'requires_backup', 'requires_manual_approval', 'rollback_strategy',
        'evidence_required', 'status',
    ];

    public function generate(array $auditRows): array
    {
        return array_map(fn (array $row): array => $this->planRow($row), $auditRows);
    }

    public function planRow(array $row): array
    {
        $classification = (string) ($row['primary_classification'] ?? 'AUDIT_ERROR');
        $flags = array_values(array_filter((array) ($row['classification_flags'] ?? [$classification])));
        $action = $this->action($classification, $flags);

        return [
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
        ];
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
