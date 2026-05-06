<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hóa đơn - {{ $invoice->code }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Arial', 'Helvetica', sans-serif; font-size: 13px; line-height: 1.5; color: #333; }
        .receipt { max-width: 320px; margin: 0 auto; padding: 12px; }
        .header { text-align: center; padding-bottom: 10px; border-bottom: 1px dashed #999; margin-bottom: 10px; }
        .company-name { font-size: 16px; font-weight: bold; text-transform: uppercase; }
        .doc-title { font-size: 15px; font-weight: bold; text-align: center; margin: 10px 0 8px; text-transform: uppercase; }
        .info { margin-bottom: 10px; }
        .info-row { display: flex; margin-bottom: 2px; }
        .info-label { min-width: 90px; color: #666; }
        .info-value { flex: 1; font-weight: 500; }
        .items { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .items th { text-align: left; padding: 4px 2px; border-bottom: 1px solid #333; font-size: 12px; font-weight: bold; }
        .items td { padding: 4px 2px; border-bottom: 1px dashed #ddd; font-size: 12px; }
        .items .r { text-align: right; }
        .items .product-name { font-weight: 500; }
        .items .product-sku { font-size: 10px; color: #888; }
        .sep { border: none; border-top: 1px dashed #999; margin: 8px 0; }
        .sum-row { display: flex; justify-content: space-between; padding: 2px 0; font-size: 13px; }
        .sum-total { font-weight: bold; font-size: 15px; border-top: 1px solid #333; padding-top: 5px; margin-top: 4px; }
        .sum-highlight { color: #d00; }
        .footer { text-align: center; margin-top: 12px; padding-top: 10px; border-top: 1px dashed #999; font-size: 11px; color: #666; }
        .btn-print { display: block; margin: 15px auto 0; padding: 8px 24px; background: #2563eb; color: #fff; border: none; border-radius: 4px; font-size: 13px; cursor: pointer; }
        .btn-print:hover { background: #1d4ed8; }
        @media print { body { margin: 0; } .receipt { max-width: none; } .no-print { display: none !important; } }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="header">
            <div class="company-name">{{ config('app.name', 'KiotViet') }}</div>
        </div>

        <div class="doc-title">Hóa đơn bán hàng</div>

        <div class="info">
            <div class="info-row"><span class="info-label">Mã hóa đơn:</span><span class="info-value">{{ $invoice->code }}</span></div>
            <div class="info-row"><span class="info-label">Ngày tạo:</span><span class="info-value">{{ $invoice->created_at->format('d/m/Y H:i') }}</span></div>
            <div class="info-row"><span class="info-label">Thu ngân:</span><span class="info-value">{{ $invoice->created_by_name ?? 'Admin' }}</span></div>
            @if($invoice->customer)
            <div class="info-row"><span class="info-label">Khách hàng:</span><span class="info-value">{{ $invoice->customer->name }}</span></div>
            @if($invoice->customer->phone)
            <div class="info-row"><span class="info-label">SĐT:</span><span class="info-value">{{ $invoice->customer->phone }}</span></div>
            @endif
            @if($invoice->customer->address)
            <div class="info-row"><span class="info-label">Địa chỉ:</span><span class="info-value">{{ $invoice->customer->address }}</span></div>
            @endif
            @endif
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th style="width:40%">Hàng hóa</th>
                    <th class="r">SL</th>
                    <th class="r">Đơn giá</th>
                    <th class="r">T.Tiền</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->items as $i => $item)
                <tr>
                    <td>
                        <div class="product-name">{{ $item->product->name ?? 'Sản phẩm' }}</div>
                        @if($item->product && $item->product->sku)
                        <div class="product-sku">{{ $item->product->sku }}</div>
                        @endif
                    </td>
                    <td class="r">{{ $item->quantity }}</td>
                    <td class="r">{{ format_vnd($item->price) }}</td>
                    <td class="r">{{ format_vnd($item->subtotal ?? $item->price * $item->quantity) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <hr class="sep">

        <div>
            <div class="sum-row">
                <span>Tổng tiền hàng ({{ $invoice->items->sum('quantity') }} sản phẩm):</span>
                <span>{{ format_vnd($invoice->subtotal ?? $invoice->items->sum(fn($i) => $i->subtotal ?? $i->price * $i->quantity)) }}</span>
            </div>
            <div class="sum-row">
                <span>Chiết khấu hóa đơn:</span>
                <span>{{ format_vnd($invoice->discount ?? 0) }}</span>
            </div>
            @if($invoice->other_fees > 0)
            <div class="sum-row">
                <span>Thu khác:</span>
                <span>{{ format_vnd($invoice->other_fees) }}</span>
            </div>
            @endif
            @if($invoice->is_delivery && $invoice->delivery_fee > 0)
            <div class="sum-row">
                <span>Phí giao hàng:</span>
                <span>{{ format_vnd($invoice->delivery_fee) }}</span>
            </div>
            @endif
            <div class="sum-row">
                <span>Công nợ cũ:</span>
                <span>{{ format_vnd($previousDebt ?? 0) }}</span>
            </div>
            <div class="sum-row sum-total">
                <span>Tổng cộng:</span>
                <span>{{ format_vnd($invoice->total) }}</span>
            </div>
            <div class="sum-row">
                <span>Khách hàng thanh toán:</span>
                <span>{{ format_vnd($invoice->customer_paid ?? 0) }}</span>
            </div>
            @php
                $change = ($invoice->customer_paid ?? 0) - $invoice->total;
                $newDebt = ($previousDebt ?? 0) + ($change < 0 ? abs($change) : 0);
            @endphp
            <div class="sum-row">
                <span>Công nợ còn lại:</span>
                <span>{{ format_vnd($newDebt) }}</span>
            </div>
            @if($change > 0)
            <div class="sum-row">
                <span>Tiền thừa trả khách:</span>
                <span>{{ format_vnd($change) }}</span>
            </div>
            @endif
            @if($invoice->payment_method)
            <div class="sum-row" style="font-size:12px; color:#666;">
                <span>Phương thức:</span>
                <span>{{ $invoice->payment_method === 'cash' ? 'Tiền mặt' : ($invoice->payment_method === 'bank' ? 'Chuyển khoản' : $invoice->payment_method) }}</span>
            </div>
            @endif
        </div>

        <div class="footer">
            @if($invoice->note)
            <div style="margin-bottom: 8px; font-style: italic;">{{ $invoice->note }}</div>
            @endif
            <div>Cảm ơn quý khách và hẹn gặp lại!</div>
            <div style="margin-top: 4px;">{{ $invoice->created_at->format('d/m/Y H:i:s') }}</div>
        </div>

        <button class="btn-print no-print" onclick="window.print()">🖨️ In hóa đơn</button>
    </div>
    <script>window.onload = function() { window.print(); };</script>
</body>
</html>
