<?php

namespace App\Enums;

final class DamageStatus implements StatusEnum
{
    public const DRAFT = 'draft';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public static function options(): array
    {
        return [
            ['value' => self::DRAFT,     'label' => 'Phiếu tạm',  'color' => 'gray'],
            ['value' => self::COMPLETED, 'label' => 'Hoàn thành', 'color' => 'green'],
            ['value' => self::CANCELLED, 'label' => 'Đã hủy',     'color' => 'red'],
        ];
    }
}
