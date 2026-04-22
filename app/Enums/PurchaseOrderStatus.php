<?php

namespace App\Enums;

final class PurchaseOrderStatus implements StatusEnum
{
    public const DRAFT = 'draft';
    public const CONFIRMED = 'confirmed';
    public const PARTIAL = 'partial';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public static function options(): array
    {
        return [
            ['value' => self::DRAFT,     'label' => 'Phiếu tạm',    'color' => 'gray'],
            ['value' => self::CONFIRMED, 'label' => 'Đã xác nhận',  'color' => 'blue'],
            ['value' => self::PARTIAL,   'label' => 'Nhập một phần','color' => 'amber'],
            ['value' => self::COMPLETED, 'label' => 'Hoàn thành',   'color' => 'green'],
            ['value' => self::CANCELLED, 'label' => 'Đã hủy',       'color' => 'red'],
        ];
    }
}
