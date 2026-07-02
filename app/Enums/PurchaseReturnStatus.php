<?php

namespace App\Enums;

final class PurchaseReturnStatus implements StatusEnum
{
    public const DRAFT = 'draft';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public static function options(): array
    {
        return [
            ['value' => self::DRAFT,     'label' => 'Phiếu tạm',    'color' => 'gray'],
            ['value' => self::COMPLETED, 'label' => 'Đã trả hàng',  'color' => 'green'],
            ['value' => self::CANCELLED, 'label' => 'Đã hủy',       'color' => 'red'],
        ];
    }
}
