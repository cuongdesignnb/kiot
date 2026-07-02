<?php

namespace App\Enums;

final class StockTakeStatus implements StatusEnum
{
    public const DRAFT = 'draft';
    public const BALANCED = 'balanced';
    public const CANCELLED = 'cancelled';

    public static function options(): array
    {
        return [
            ['value' => self::DRAFT,     'label' => 'Phiếu tạm',    'color' => 'gray'],
            ['value' => self::BALANCED,  'label' => 'Đã cân bằng',  'color' => 'green'],
            ['value' => self::CANCELLED, 'label' => 'Đã hủy',       'color' => 'red'],
        ];
    }
}
