<?php

namespace App\Enums;

final class StockTransferStatus implements StatusEnum
{
    public const DRAFT = 'draft';
    public const TRANSFERRING = 'transferring';
    public const RECEIVED = 'received';
    public const CANCELLED = 'cancelled';

    public static function options(): array
    {
        return [
            ['value' => self::DRAFT,        'label' => 'Phiếu tạm',   'color' => 'gray'],
            ['value' => self::TRANSFERRING, 'label' => 'Đang chuyển', 'color' => 'amber'],
            ['value' => self::RECEIVED,     'label' => 'Đã nhận',     'color' => 'green'],
            ['value' => self::CANCELLED,    'label' => 'Đã hủy',      'color' => 'red'],
        ];
    }
}
