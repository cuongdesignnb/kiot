<?php

namespace App\Enums;

final class UserStatus implements StatusEnum
{
    public const ACTIVE = 'active';
    public const INACTIVE = 'inactive';

    public static function options(): array
    {
        return [
            ['value' => self::ACTIVE,   'label' => 'Đang hoạt động', 'color' => 'green'],
            ['value' => self::INACTIVE, 'label' => 'Đã khóa',        'color' => 'red'],
        ];
    }
}
