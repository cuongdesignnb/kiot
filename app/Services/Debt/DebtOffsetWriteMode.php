<?php

namespace App\Services\Debt;

use App\Exceptions\DebtOffsetWorkflowException;

final class DebtOffsetWriteMode
{
    public const LEGACY = 'legacy';

    public const WORKFLOW = 'workflow';

    public const DISABLED = 'disabled';

    public function current(): string
    {
        $mode = strtolower(trim((string) config('debt.offsets.write_mode', self::LEGACY)));
        if (! in_array($mode, [self::LEGACY, self::WORKFLOW, self::DISABLED], true)) {
            throw DebtOffsetWorkflowException::forbidden(
                'DEBT_OFFSET_WORKFLOW_DISABLED',
                'Chế độ ghi cấn trừ công nợ không được hỗ trợ.'
            );
        }

        return $mode;
    }

    public function assertWorkflowAllowed(): void
    {
        if ($this->current() !== self::WORKFLOW) {
            throw DebtOffsetWorkflowException::forbidden(
                'DEBT_OFFSET_WORKFLOW_DISABLED',
                'Quy trình duyệt cấn trừ công nợ chưa được bật.'
            );
        }
    }

    public function assertLegacyAllowed(): void
    {
        $mode = $this->current();
        if ($mode === self::WORKFLOW) {
            throw DebtOffsetWorkflowException::conflict(
                'LEGACY_DEBT_OFFSET_WRITE_DISABLED',
                'Ghi cấn trừ trực tiếp đã bị tắt. Vui lòng dùng quy trình phê duyệt.'
            );
        }

        if ($mode === self::DISABLED) {
            throw DebtOffsetWorkflowException::forbidden(
                'DEBT_OFFSET_WORKFLOW_DISABLED',
                'Chức năng ghi cấn trừ công nợ đang bị tắt.'
            );
        }
    }
}
