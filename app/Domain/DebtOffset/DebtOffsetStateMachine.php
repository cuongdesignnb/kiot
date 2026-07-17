<?php

namespace App\Domain\DebtOffset;

use App\Exceptions\DebtOffsetWorkflowException;

final class DebtOffsetStateMachine
{
    public const DRAFT = 'draft';

    public const PENDING_APPROVAL = 'pending_approval';

    public const APPROVED = 'approved';

    public const APPLIED = 'applied';

    public const REJECTED = 'rejected';

    public const VOID = 'void';

    public const REVERSED = 'reversed';

    private const TRANSITIONS = [
        self::DRAFT => [self::PENDING_APPROVAL, self::VOID],
        self::PENDING_APPROVAL => [self::APPROVED, self::REJECTED],
        self::APPROVED => [self::APPLIED],
        self::APPLIED => [self::REVERSED],
        self::REJECTED => [],
        self::VOID => [],
        self::REVERSED => [],
    ];

    public function can(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function assertCan(string $from, string $to): void
    {
        if (! $this->can($from, $to)) {
            throw DebtOffsetWorkflowException::conflict(
                'INVALID_DEBT_OFFSET_TRANSITION',
                'Chuyển trạng thái phiếu cấn trừ không hợp lệ.'
            );
        }
    }

    /** @return array<string, list<string>> */
    public function transitions(): array
    {
        return self::TRANSITIONS;
    }
}
