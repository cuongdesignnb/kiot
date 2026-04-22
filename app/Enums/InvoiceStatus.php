<?php

namespace App\Enums;

/**
 * Invoice status is stored as Vietnamese label strings in the database
 * (see 2026_03_01_150000_modify_invoices_table).  The enum mirrors those
 * exact values so the filter can match them directly.
 */
final class InvoiceStatus implements StatusEnum
{
    public const PROCESSING = 'Đang xử lý';
    public const COMPLETED = 'Hoàn thành';
    public const UNDELIVERABLE = 'Không giao được';
    public const CANCELLED = 'Đã hủy';

    public static function options(): array
    {
        return [
            ['value' => self::PROCESSING,    'label' => 'Đang xử lý',      'color' => 'amber'],
            ['value' => self::COMPLETED,     'label' => 'Hoàn thành',      'color' => 'green'],
            ['value' => self::UNDELIVERABLE, 'label' => 'Không giao được', 'color' => 'red'],
            ['value' => self::CANCELLED,     'label' => 'Đã hủy',          'color' => 'gray'],
        ];
    }
}
