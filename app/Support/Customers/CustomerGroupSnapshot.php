<?php

namespace App\Support\Customers;

use App\Models\Customer;
use Illuminate\Support\Facades\Schema;

class CustomerGroupSnapshot
{
    public const UNGROUPED = 'Chưa phân nhóm';

    public static function normalize(?string $groupName): string
    {
        $groupName = trim((string) $groupName);

        return $groupName !== '' ? $groupName : self::UNGROUPED;
    }

    public static function forCustomerId(?int $customerId): array
    {
        if (!$customerId) {
            return [
                'customer_group_id' => null,
                'customer_group_name' => self::UNGROUPED,
            ];
        }

        $customer = Customer::find($customerId);

        return [
            'customer_group_id' => null,
            'customer_group_name' => self::normalize($customer?->customer_group),
        ];
    }

    public static function applyToAttributes(array $attributes, ?int $customerId, string $table): array
    {
        $snapshot = self::forCustomerId($customerId);

        if (Schema::hasColumn($table, 'customer_group_id')) {
            $attributes['customer_group_id'] = $snapshot['customer_group_id'];
        }

        if (Schema::hasColumn($table, 'customer_group_name')) {
            $attributes['customer_group_name'] = $snapshot['customer_group_name'];
        }

        return $attributes;
    }

    public static function invoiceGroupExpression(): string
    {
        if (Schema::hasColumn('invoices', 'customer_group_name')) {
            return "COALESCE(NULLIF(invoices.customer_group_name, ''), NULLIF(customers.customer_group, ''), '" . self::UNGROUPED . "')";
        }

        return "COALESCE(NULLIF(customers.customer_group, ''), '" . self::UNGROUPED . "')";
    }
}
