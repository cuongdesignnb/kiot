<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phiếu xuất hủy - {{ $damage->code }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Arial', 'Helvetica', sans-serif; font-size: 13px; line-height: 1.5; color: #333; }
        .receipt { max-width: 320px; margin: 0 auto; padding: 12px; }
        .header { text-align: center; padding-bottom: 10px; border-bottom: 1px dashed #999; margin-bottom: 10px; }
        .company-name { font-size: 16px; font-weight: bold; text-transform: uppercase; }
        .doc-title { font-size: 15px; font-weight: bold; text-align: center; margin: 10px 0 8px; text-transform: uppercase; }
        .info { margin-bottom: 10px; }
        .info-row { display: flex; margin-bottom: 2px; }
        .info-label { min-width: 110px; color: #666; }
        .info-value { flex: 1; font-weight: 500; }
        .status-badge { display: inline-block; padding: 1px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; }
        .status-draft { background: #fef3c7; color: #92400e; }
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
            'completed' => ['Hoàn thành', 'completed'],
            'cancelled' => ['Đã hủy', 'cancelled'],
        ];
        $st = $statusMap[$damage->status] ?? [$damage->status, 'draft'];
    @endphp
    <div class="receipt">
        <div class="header">
            <div class="company-name">{{ config('app.name', 'KiotViet') }}</div>
        </div>

        <div class="doc-title">Phiếu xuất hủy</div>

        <div class="info">
            <div class="info-row"><span class="info-label">Mã phiếu:</span><span class="info-value">{{ $damage->code }}</span></div>
            <div class="info-row"><span class="info-label">Ngày tạo:</span><span class="info-value">{{ $damage->created_at->format('d/m/Y H:i') }}</span></div>
            <div class="info-row"><span class="info-label">Người tạo:</span><span class="info-value">{{ $damage->created_by_name ?? 'Admin' }}</span></div>
            <div class="info-row"><span class="info-label">Trạng thái:</span><span class="info-value"><span class="status-badge status-{{ $st[1] }}">{{ $st[0] }}</span></span></div>
            @if($damage->destroyed_by_name)
            <div class="info-row"><span class="info-label">Người xuất hủy:</span><span class="info-value">{{ $damage->destroyed_by_name }}</span></div>
            @endif
            @if($damage->destroyed_date)
            <div class="info-row"><span class="info-label">Ngày xuất hủy:</span><span class="info-value">{{ $damage->destroyed_date->format('d/m/Y H:i') }}</span></div>
            @endif
            @if($damage->branch)
            <div class="info-row"><span class="info-label">Chi nhánh:</span><span class="info-value">{{ $damage->branch->name }}</span></div>
            @endif
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th style="width:40%">Hàng hóa</th>
                    <th class="r">SL</th>
                    <th class="r">Giá vốn</th>
                    <th class="r">T.Tiền</th>
                </tr>
            </thead>
            <tbody>
                @foreach($damage->items as $item)
                <tr>
                    <td>
                        <div class="product-name">{{ $item->product->name ?? 'Sản phẩm' }}</div>
                        @if($item->product && $item->product->sku)
                        <div class="product-sku">{{ $item->product->sku }}</div>
                        @endif
                    </td>
                    <td class="r">{{ $item->qty }}</td>
                    <td class="r">{{ format_vnd($item->cost_price) }}</td>
                    <td class="r">{{ format_vnd($item->total_value ?? $item->cost_price * $item->qty) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <hr class="sep">

        <div>
            <div class="sum-row">
                <span>Tổng số lượng:</span>
                <span>{{ $damage->total_qty }}</span>
            </div>
            <div class="sum-row sum-total">
                <span>Tổng giá trị:</span>
                <span>{{ format_vnd($damage->total_value) }}</span>
            </div>
        </div>

        @if($damage->note)
        <div style="margin-top:8px;padding:6px;background:#f9fafb;border-radius:4px;font-size:12px;">
            <strong style="color:#666;">Ghi chú:</strong> {{ $damage->note }}
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
