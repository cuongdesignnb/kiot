<?php

namespace App\Enums;

final class OrderStatus implements StatusEnum
{
    public const DRAFT = 'draft';
    public const CONFIRMED = 'confirmed';
    public const DELIVERING = 'delivering';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';
    public const RETURNED = 'return';

    public static function options(): array
    {
        return [
            ['value' => self::DRAFT,      'label' => 'Phiếu tạm',   'color' => 'gray'],
            ['value' => self::CONFIRMED,  'label' => 'Đã xác nhận', 'color' => 'blue'],
            ['value' => self::DELIVERING, 'label' => 'Đang giao',   'color' => 'amber'],
            ['value' => self::COMPLETED,  'label' => 'Hoàn thành',  'color' => 'green'],
            ['value' => self::CANCELLED,  'label' => 'Đã hủy',      'color' => 'red'],
            ['value' => self::RETURNED,   'label' => 'Trả hàng',    'color' => 'purple'],
        ];
    }
}
