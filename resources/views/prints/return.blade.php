<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phiếu trả hàng - {{ $return->code }}</title>
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

        <div class="doc-title">Phiếu trả hàng</div>

        <div class="info">
            <div class="info-row"><span class="info-label">Mã phiếu trả:</span><span class="info-value">{{ $return->code }}</span></div>
            <div class="info-row"><span class="info-label">Ngày tạo:</span><span class="info-value">{{ $return->created_at->format('d/m/Y H:i') }}</span></div>
            <div class="info-row"><span class="info-label">Người tạo:</span><span class="info-value">{{ $return->created_by_name ?? 'Admin' }}</span></div>
            @if($return->invoice)
            <div class="info-row"><span class="info-label">Hóa đơn gốc:</span><span class="info-value">{{ $return->invoice->code }}</span></div>
            @endif
            @if($return->customer)
            <div class="info-row"><span class="info-label">Khách hàng:</span><span class="info-value">{{ $return->customer->name }}</span></div>
            @if($return->customer->phone)
            <div class="info-row"><span class="info-label">SĐT:</span><span class="info-value">{{ $return->customer->phone }}</span></div>
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
                @foreach($return->items as $item)
                <tr>
                    <td>
                        <div class="product-name">{{ $item->product->name ?? 'Sản phẩm' }}</div>
                        @if($item->product && $item->product->sku)
                        <div class="product-sku">{{ $item->product->sku }}</div>
                        @endif
                    </td>
                    <td class="r">{{ $item->qty }}</td>
                    <td class="r">{{ format_vnd($item->price) }}</td>
                    <td class="r">{{ format_vnd(($item->subtotal ?? $item->price * $item->qty)) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <hr class="sep">

        <div>
            <div class="sum-row">
                <span>Tổng tiền hàng trả:</span>
                <span>{{ format_vnd($return->subtotal ?? $return->total) }}</span>
            </div>
            @if(($return->discount ?? 0) > 0)
            <div class="sum-row">
                <span>Giảm trừ:</span>
                <span>-{{ format_vnd($return->discount) }}</span>
            </div>
            @endif
            <div class="sum-row sum-total">
                <span>Cần trả khách:</span>
                <span>{{ format_vnd($return->total) }}</span>
            </div>
            @if(($return->paid_to_customer ?? 0) > 0)
            <div class="sum-row">
                <span>Đã trả khách:</span>
                <span>{{ format_vnd($return->paid_to_customer) }}</span>
            </div>
            @endif
        </div>

        <div class="footer">
            <p>Cảm ơn quý khách!</p>
            <p>{{ now()->format('d/m/Y H:i') }}</p>
        </div>

        <button class="btn-print no-print" onclick="window.print()">🖨️ In phiếu</button>
    </div>
    <script>window.onload = function() { window.print(); };</script>
</body>
</html>
