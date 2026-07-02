<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phiếu kiểm kho - {{ $stockTake->code }}</title>
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
        .status-badge { display: inline-block; padding: 1px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; }
        .status-draft { background: #fef3c7; color: #92400e; }
        .status-balanced { background: #d1fae5; color: #065f46; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .items { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .items th { text-align: left; padding: 4px 2px; border-bottom: 1px solid #333; font-size: 11px; font-weight: bold; }
        .items td { padding: 4px 2px; border-bottom: 1px dashed #ddd; font-size: 11px; }
        .items .r { text-align: right; }
        .items .product-name { font-weight: 500; }
        .items .product-sku { font-size: 10px; color: #888; }
        .sep { border: none; border-top: 1px dashed #999; margin: 8px 0; }
        .sum-row { display: flex; justify-content: space-between; padding: 2px 0; font-size: 13px; }
        .sum-total { font-weight: bold; font-size: 14px; border-top: 1px solid #333; padding-top: 5px; margin-top: 4px; }
        .diff-plus { color: #16a34a; }
        .diff-minus { color: #dc2626; }
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
            'balanced' => ['Đã cân bằng kho', 'balanced'],
            'cancelled' => ['Đã hủy', 'cancelled'],
        ];
        $st = $statusMap[$stockTake->status] ?? [$stockTake->status, 'draft'];
    @endphp
    <div class="receipt">
        <div class="header">
            <div class="company-name">{{ config('app.name', 'KiotViet') }}</div>
        </div>

        <div class="doc-title">Phiếu kiểm kho</div>

        <div class="info">
            <div class="info-row"><span class="info-label">Mã phiếu:</span><span class="info-value">{{ $stockTake->code }}</span></div>
            <div class="info-row"><span class="info-label">Ngày tạo:</span><span class="info-value">{{ $stockTake->created_at->format('d/m/Y H:i') }}</span></div>
            <div class="info-row"><span class="info-label">Người tạo:</span><span class="info-value">{{ $stockTake->user_name ?? 'Admin' }}</span></div>
            <div class="info-row"><span class="info-label">Trạng thái:</span><span class="info-value"><span class="status-badge status-{{ $st[1] }}">{{ $st[0] }}</span></span></div>
            @if($stockTake->balancer_name)
            <div class="info-row"><span class="info-label">Người cân bằng:</span><span class="info-value">{{ $stockTake->balancer_name }}</span></div>
            @endif
            @if($stockTake->balanced_date)
            <div class="info-row"><span class="info-label">Ngày cân bằng:</span><span class="info-value">{{ $stockTake->balanced_date->format('d/m/Y H:i') }}</span></div>
            @endif
        </div>

        <table class="items">
            <thead>
                <tr>
                    <th style="width:35%">Hàng hóa</th>
                    <th class="r">Tồn</th>
                    <th class="r">T.Tế</th>
                    <th class="r">Lệch</th>
                    <th class="r">G.Trị</th>
                </tr>
            </thead>
            <tbody>
                @foreach($stockTake->items as $item)
                <tr>
                    <td>
                        <div class="product-name">{{ $item->product->name ?? 'Sản phẩm' }}</div>
                        @if($item->product && $item->product->sku)
                        <div class="product-sku">{{ $item->product->sku }}</div>
                        @endif
                    </td>
                    <td class="r">{{ $item->system_stock }}</td>
                    <td class="r">{{ $item->actual_stock }}</td>
                    <td class="r {{ $item->diff_qty > 0 ? 'diff-plus' : ($item->diff_qty < 0 ? 'diff-minus' : '') }}">{{ $item->diff_qty > 0 ? '+' : '' }}{{ $item->diff_qty }}</td>
                    <td class="r">{{ format_vnd(abs($item->diff_value ?? 0)) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <hr class="sep">

        <div>
            <div class="sum-row">
                <span>Tổng SL thực tế:</span>
                <span>{{ $stockTake->total_actual_qty }}</span>
            </div>
            <div class="sum-row">
                <span>Tổng SL lệch:</span>
                <span>{{ $stockTake->total_diff_qty > 0 ? '+' : '' }}{{ $stockTake->total_diff_qty }}</span>
            </div>
            @if(($stockTake->total_diff_increase ?? 0) > 0)
            <div class="sum-row">
                <span>Tổng tăng:</span>
                <span class="diff-plus">+{{ format_vnd($stockTake->total_diff_increase) }}</span>
            </div>
            @endif
            @if(($stockTake->total_diff_decrease ?? 0) > 0)
            <div class="sum-row">
                <span>Tổng giảm:</span>
                <span class="diff-minus">-{{ format_vnd($stockTake->total_diff_decrease) }}</span>
            </div>
            @endif
            <div class="sum-row sum-total">
                <span>Giá trị lệch:</span>
                <span>{{ format_vnd(abs($stockTake->total_diff_value ?? 0)) }}</span>
            </div>
        </div>

        @if($stockTake->note)
        <div style="margin-top:8px;padding:6px;background:#f9fafb;border-radius:4px;font-size:12px;">
            <strong style="color:#666;">Ghi chú:</strong> {{ $stockTake->note }}
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
