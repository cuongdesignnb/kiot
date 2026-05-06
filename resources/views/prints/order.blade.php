<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đơn hàng - {{ $order->code }}</title>
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
        .status-badge { display: inline-block; padding: 1px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; }
        .status-draft { background: #fef3c7; color: #92400e; }
        .status-confirmed { background: #dbeafe; color: #1e40af; }
        .status-processing { background: #e0e7ff; color: #3730a3; }
        .status-completed { background: #d1fae5; color: #065f46; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .items { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .items th { text-align: left; padding: 4px 2px; border-bottom: 1px solid #333; font-size: 12px; font-weight: bold; }
        .items td { padding: 4px 2px; border-bottom: 1px dashed #ddd; font-size: 12px; }
        .items .r { text-align: right; }
        .items .product-name { font-weight: 500; }
        .items .product-sku { font-size: 10px; color: #888; }
        .sep { border: none; border-top: 1px dashed #999; margin: 8px 0; }
        .sum-row { display: flex; justify-content: space-between; padding: 2px 0; font-size: 13px; }
        .sum-total { font-weight: bold; font-size: 15px; border-top: 1px solid #333; padding-top: 5px; margin-top: 4px; }
        .delivery-box { margin-top: 10px; padding: 8px; border: 1px dashed #999; border-radius: 4px; }
        .delivery-box .title { font-weight: bold; margin-bottom: 4px; font-size: 12px; }
        .delivery-box .line { font-size: 12px; margin-bottom: 2px; }
        .footer { text-align: center; margin-top: 12px; padding-top: 10px; border-top: 1px dashed #999; font-size: 11px; color: #666; }
        .btn-print { display: block; margin: 15px auto 0; padding: 8px 24px; background: #2563eb; color: #fff; border: none; border-radius: 4px; font-size: 13px; cursor: pointer; }
        .btn-print:hover { background: #1d4ed8; }
        @media print { body { margin: 0; } .receipt { max-width: none; } .no-print { display: none !important; } }
    </style>
</head>
<body>
    @php
        $statusMap = [
            'draft' => ['Phiếu tạm', 'draft'],
            'confirmed' => ['Đã xác nhận', 'confirmed'],
            'processing' => ['Đang xử lý', 'processing'],
            'completed' => ['Hoàn thành', 'completed'],
            'cancelled' => ['Đã hủy', 'cancelled'],
        ];
        $st = $statusMap[$order->status] ?? [$order->status, 'draft'];
    @endphp
    <div class="receipt">
        <div class="header">
            <div class="company-name">{{ config('app.name', 'KiotViet') }}</div>
        </div>

        <div class="doc-title">Đơn đặt hàng</div>

        <div class="info">
            <div class="info-row"><span class="info-label">Mã đơn hàng:</span><span class="info-value">{{ $order->code }}</span></div>
            <div class="info-row"><span class="info-label">Ngày tạo:</span><span class="info-value">{{ $order->created_at->format('d/m/Y H:i') }}</span></div>
            <div class="info-row"><span class="info-label">Người tạo:</span><span class="info-value">{{ $order->created_by_name ?? 'Admin' }}</span></div>
            @if($order->assigned_to_name)
            <div class="info-row"><span class="info-label">Người xử lý:</span><span class="info-value">{{ $order->assigned_to_name }}</span></div>
            @endif
            <div class="info-row"><span class="info-label">Trạng thái:</span><span class="info-value"><span class="status-badge status-{{ $st[1] }}">{{ $st[0] }}</span></span></div>
            @if($order->customer)
            <div class="info-row"><span class="info-label">Khách hàng:</span><span class="info-value">{{ $order->customer->name }}</span></div>
            @if($order->customer->phone)
            <div class="info-row"><span class="info-label">SĐT:</span><span class="info-value">{{ $order->customer->phone }}</span></div>
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
                @foreach($order->items as $item)
                <tr>
                    <td>
                        <div class="product-name">{{ $item->product->name ?? 'Sản phẩm' }}</div>
                        @if($item->product && $item->product->sku)
                        <div class="product-sku">{{ $item->product->sku }}</div>
                        @endif
                    </td>
                    <td class="r">{{ $item->qty }}</td>
                    <td class="r">{{ format_vnd($item->price) }}</td>
                    <td class="r">{{ format_vnd($item->subtotal ?? $item->price * $item->qty) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <hr class="sep">

        <div>
            <div class="sum-row">
                <span>Tổng tiền hàng ({{ $order->items->sum('qty') }} sản phẩm):</span>
                <span>{{ format_vnd($order->total_price) }}</span>
            </div>
            @if($order->discount > 0)
            <div class="sum-row">
                <span>Giảm giá:</span>
                <span>-{{ format_vnd($order->discount) }}</span>
            </div>
            @endif
            @if($order->other_fees > 0)
            <div class="sum-row">
                <span>Thu khác:</span>
                <span>{{ format_vnd($order->other_fees) }}</span>
            </div>
            @endif
            @if($order->delivery_fee > 0)
            <div class="sum-row">
                <span>Phí giao hàng:</span>
                <span>{{ format_vnd($order->delivery_fee) }}</span>
            </div>
            @endif
            <div class="sum-row sum-total">
                <span>Tổng cộng:</span>
                <span>{{ format_vnd($order->total_payment) }}</span>
            </div>
            <div class="sum-row">
                <span>Đã thanh toán:</span>
                <span>{{ format_vnd($order->amount_paid ?? 0) }}</span>
            </div>
            @php $debt = $order->total_payment - ($order->amount_paid ?? 0); @endphp
            @if($debt > 0)
            <div class="sum-row" style="color: #d00; font-weight: 500;">
                <span>Còn cần thu:</span>
                <span>{{ format_vnd($debt) }}</span>
            </div>
            @endif
        </div>

        @if($order->is_delivery && $order->receiver_name)
        <div class="delivery-box">
            <div class="title">📦 Thông tin giao hàng</div>
            <div class="line"><strong>Người nhận:</strong> {{ $order->receiver_name }}</div>
            @if($order->receiver_phone)
            <div class="line"><strong>SĐT:</strong> {{ $order->receiver_phone }}</div>
            @endif
            @php
                $addr = array_filter([$order->receiver_address, $order->receiver_ward, $order->receiver_district, $order->receiver_city]);
            @endphp
            @if(count($addr) > 0)
            <div class="line"><strong>Địa chỉ:</strong> {{ implode(', ', $addr) }}</div>
            @endif
            @if($order->delivery_partner)
            <div class="line"><strong>ĐVVC:</strong> {{ $order->delivery_partner }}</div>
            @endif
            @if($order->cod_amount > 0)
            <div class="line"><strong>Thu hộ (COD):</strong> {{ format_vnd($order->cod_amount) }}</div>
            @endif
        </div>
        @endif

        <div class="footer">
            @if($order->note)
            <div style="margin-bottom: 8px; font-style: italic;">{{ $order->note }}</div>
            @endif
            <div>Cảm ơn quý khách và hẹn gặp lại!</div>
            <div style="margin-top: 4px;">{{ $order->created_at->format('d/m/Y H:i:s') }}</div>
        </div>

        <button class="btn-print no-print" onclick="window.print()">🖨️ In đơn hàng</button>
    </div>
    <script>window.onload = function() { window.print(); };</script>
</body>
</html>
