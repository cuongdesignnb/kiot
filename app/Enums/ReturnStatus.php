<?php

namespace App\Enums;

/**
 * OrderReturn (returns table) status — Vietnamese strings.
 */
final class ReturnStatus implements StatusEnum
{
    public const RETURNED = 'Đã trả';
    public const CANCELLED = 'Đã hủy';

    public static function options(): array
    {
        return [
            ['value' => self::RETURNED,  'label' => 'Đã trả', 'color' => 'green'],
            ['value' => self::CANCELLED, 'label' => 'Đã hủy', 'color' => 'red'],
        ];
    }
}
