<?php

namespace App\Support\Audit;

use App\Models\CashFlow;
use App\Models\Invoice;
use App\Models\OrderReturn;
use App\Models\Purchase;
use App\Models\PurchaseReturn;

/**
 * Batch 10 đợt 4 — item 31. Small, stable before/after snapshots of the key
 * fields of a document for the edit/delete/cancel journal. Cost snapshots
 * are deliberately left out of sales documents.
 */
class DocumentSnapshot
{
    public static function invoice(?Invoice $invoice): ?array
    {
        if (! $invoice) {
            return null;
        }
        $invoice->loadMissing('items.product', 'customer');

        return [
            'code' => $invoice->code,
            'status' => $invoice->status,
            'customer' => $invoice->customer?->name,
            'seller_name' => $invoice->seller_name,
            'seller_employee_id' => $invoice->created_by,
            'transaction_date' => self::date($invoice->transaction_date ?? $invoice->created_at),
            'subtotal' => (float) $invoice->subtotal,
            'discount' => (float) $invoice->discount,
            'total' => (float) $invoice->total,
            'customer_paid' => (float) $invoice->customer_paid,
            'note' => $invoice->note,
            'items' => $invoice->items->map(fn ($item) => [
                'sku' => $item->product?->sku,
                'name' => $item->product?->name,
                'quantity' => (float) $item->quantity,
                'price' => (float) $item->price,
                'discount' => (float) ($item->discount ?? 0),
                'serial' => $item->serial,
            ])->values()->all(),
        ];
    }

    public static function orderReturn(?OrderReturn $return): ?array
    {
        if (! $return) {
            return null;
        }
        $return->loadMissing('items.product', 'invoice');

        return [
            'code' => $return->code,
            'status' => $return->status,
            'invoice_code' => $return->invoice?->code,
            'subtotal' => (float) $return->subtotal,
            'discount' => (float) $return->discount,
            'fee' => (float) ($return->fee ?? 0),
            'total' => (float) $return->total,
            'paid_to_customer' => (float) ($return->paid_to_customer ?? 0),
            'note' => $return->note,
            'items' => $return->items->map(fn ($item) => [
                'sku' => $item->product?->sku,
                'name' => $item->product?->name,
                'quantity' => (float) ($item->quantity ?? $item->qty ?? 0),
                'price' => (float) ($item->price ?? 0),
            ])->values()->all(),
        ];
    }

    public static function purchase(?Purchase $purchase): ?array
    {
        if (! $purchase) {
            return null;
        }
        $purchase->loadMissing('items.product', 'supplier');

        return [
            'code' => $purchase->code,
            'status' => $purchase->status,
            'supplier' => $purchase->supplier?->name,
            'purchase_date' => self::date($purchase->purchase_date ?? $purchase->created_at),
            'total_amount' => (float) $purchase->total_amount,
            'discount' => (float) $purchase->discount,
            'paid_amount' => (float) $purchase->paid_amount,
            'note' => $purchase->note,
            'items' => $purchase->items->map(fn ($item) => [
                'sku' => $item->product?->sku,
                'name' => $item->product?->name,
                'quantity' => (float) $item->quantity,
                'price' => (float) $item->price,
                'discount' => (float) ($item->discount ?? 0),
            ])->values()->all(),
        ];
    }

    public static function purchaseReturn(?PurchaseReturn $return): ?array
    {
        if (! $return) {
            return null;
        }
        $return->loadMissing('items', 'supplier');

        return [
            'code' => $return->code,
            'status' => $return->status,
            'supplier' => $return->supplier?->name,
            'total_amount' => (float) $return->total_amount,
            'refund_amount' => (float) $return->refund_amount,
            'note' => $return->note,
            'items' => $return->items->map(fn ($item) => [
                'sku' => $item->product_code,
                'name' => $item->product_name,
                'quantity' => (float) ($item->quantity ?? 0),
                'price' => (float) ($item->price ?? 0),
            ])->values()->all(),
        ];
    }

    public static function cashFlow(?CashFlow $flow): ?array
    {
        if (! $flow) {
            return null;
        }

        return [
            'code' => $flow->code,
            'type' => $flow->type,
            'status' => $flow->status ?? null,
            'amount' => (float) $flow->amount,
            'time' => self::date($flow->time ?? $flow->created_at),
            'category' => $flow->category ?? null,
            'target_name' => $flow->target_name ?? null,
            'payment_method' => $flow->payment_method ?? null,
            'description' => $flow->description,
        ];
    }

    private static function date(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d H:i:s')
            : (string) $value;
    }

    /**
     * Only the fields that differ, as ['field' => ['before' => x, 'after' => y]].
     */
    public static function diff(?array $before, ?array $after): array
    {
        $changes = [];
        foreach (array_unique(array_merge(array_keys($before ?? []), array_keys($after ?? []))) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($old != $new) {
                $changes[$key] = ['before' => $old, 'after' => $new];
            }
        }

        return $changes;
    }
}
