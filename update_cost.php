<?php
        $items = \App\Models\InvoiceItem::with('product')->get();
        foreach ($items as $item) {
            if ((float)$item->cost_price === 0.0 && $item->product) {
                $item->update(['cost_price' => (float)($item->product->cost_price ?? 0)]);
            }
        }
        echo "Cost prices retroactively updated for old invoice items.\n";
