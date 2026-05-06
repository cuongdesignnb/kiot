<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phiếu nhập hàng - {{ $purchase->code }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Arial', 'Helvetica', sans-serif; font-size: 13px; line-height: 1.5; color: #333; }
        .receipt { max-width: 320px; margin: 0 auto; padding: 12px; }
        .header { text-align: center; padding-bottom: 10px; border-bottom: 1px dashed #999; margin-bottom: 10px; }
        .company-name { font-size: 16px; font-weight: bold; text-transform: uppercase; }
        .doc-title { font-size: 15px; font-weight: bold; text-align: center; margin: 10px 0 8px; text-transform: uppercase; }
        .info { margin-bottom: 10px; }
        .info-row { display: flex; margin-bottom: 2px; }
        .info-label { min-width: 100px; color: #666; }
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

        <div class="doc-title">Phiếu nhập hàng</div>

        <div class="info">
            <div class="info-row"><span class="info-label">Mã nhập hàng:</span><span class="info-value">{{ $purchase->code }}</span></div>
            <div class="info-row"><span class="info-label">Ngày tạo:</span><span class="info-value">{{ $purchase->created_at->format('d/m/Y H:i') }}</span></div>
            @if($purchase->supplier)
            <div class="info-row"><span class="info-label">Nhà cung cấp:</span><span class="info-value">{{ $purchase->supplier->name }}</span></div>
            @if($purchase->supplier->phone)
            <div class="info-row"><span class="info-label">SĐT:</span><span class="info-value">{{ $purchase->supplier->phone }}</span></div>
            @endif
            @endif
            <div class="info-row"><span class="info-label">Trạng thái:</span><span class="info-value">{{ $purchase->status === 'completed' ? 'Hoàn thành' : $purchase->status }}</span></div>
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
                @foreach($purchase->items as $item)
                <tr>
                    <td>
                        <div class="product-name">{{ $item->product->name ?? $item->product_name }}</div>
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
                <span>Tổng tiền hàng:</span>
                <span>{{ format_vnd($purchase->total_amount) }}</span>
            </div>
            @if(($purchase->discount ?? 0) > 0)
            <div class="sum-row">
                <span>Chiết khấu:</span>
                <span>-{{ format_vnd($purchase->discount) }}</span>
            </div>
            @endif
            <div class="sum-row sum-total">
                <span>Cần thanh toán:</span>
                <span>{{ format_vnd($purchase->total_amount - ($purchase->discount ?? 0)) }}</span>
            </div>
            @if(($purchase->paid_amount ?? 0) > 0)
            <div class="sum-row">
                <span>Đã thanh toán:</span>
                <span>{{ format_vnd($purchase->paid_amount) }}</span>
            </div>
            @endif
            @if(($purchase->debt_amount ?? 0) > 0)
            <div class="sum-row">
                <span>Công nợ:</span>
                <span style="color:#d00">{{ format_vnd($purchase->debt_amount) }}</span>
            </div>
            @endif
        </div>

        @if($purchase->note)
        <div style="margin-top:8px;padding:6px;background:#f9fafb;border-radius:4px;font-size:12px;">
            <strong style="color:#666;">Ghi chú:</strong> {{ $purchase->note }}
        </div>
        @endif

        <div class="footer">
            <p>{{ now()->format('d/m/Y H:i') }}</p>
        </div>

        <button class="btn-print no-print" onclick="window.print()">🖨️ In phiếu</button>
    </div>
    <script>window.onload = function() { window.print(); };</script>
</body>
</html>
