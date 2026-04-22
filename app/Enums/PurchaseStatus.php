<?php

namespace App\Enums;

final class PurchaseStatus implements StatusEnum
{
    public const DRAFT = 'draft';
    public const COMPLETED = 'completed';
    public const RETURNED = 'returned';
    public const CANCELLED = 'cancelled';

    public static function options(): array
    {
        return [
            ['value' => self::DRAFT,     'label' => 'Phiếu tạm',  'color' => 'gray'],
            ['value' => self::COMPLETED, 'label' => 'Hoàn thành', 'color' => 'green'],
            ['value' => self::RETURNED,  'label' => 'Đã trả',     'color' => 'amber'],
            ['value' => self::CANCELLED, 'label' => 'Đã hủy',     'color' => 'red'],
        ];
    }
}
