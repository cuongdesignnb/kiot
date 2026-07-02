<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phiếu bảo hành - {{ $warranty->invoice_code }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Arial', 'Helvetica', sans-serif; font-size: 13px; line-height: 1.5; color: #333; }
        .receipt { max-width: 320px; margin: 0 auto; padding: 12px; }
        .header { text-align: center; padding-bottom: 10px; border-bottom: 1px dashed #999; margin-bottom: 10px; }
        .company-name { font-size: 16px; font-weight: bold; text-transform: uppercase; }
        .doc-title { font-size: 15px; font-weight: bold; text-align: center; margin: 10px 0 8px; text-transform: uppercase; color: #2563eb; }
        .info { margin-bottom: 10px; }
        .info-row { display: flex; margin-bottom: 3px; }
        .info-label { min-width: 110px; color: #666; }
        .info-value { flex: 1; font-weight: 500; }
        .sep { border: none; border-top: 1px dashed #999; margin: 10px 0; }
        .warranty-box { border: 1px solid #2563eb; border-radius: 6px; padding: 10px; margin: 10px 0; }
        .warranty-box .title { font-weight: bold; color: #2563eb; font-size: 13px; margin-bottom: 8px; text-align: center; }
        .status-valid { color: #16a34a; font-weight: bold; }
        .status-expired { color: #dc2626; font-weight: bold; }
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

        <div class="doc-title">Phiếu bảo hành</div>

        <div class="info">
            <div class="info-row"><span class="info-label">Mã hóa đơn:</span><span class="info-value">{{ $warranty->invoice_code }}</span></div>
            <div class="info-row"><span class="info-label">Khách hàng:</span><span class="info-value">{{ $warranty->customer_name }}</span></div>
            @if($warranty->product)
            <div class="info-row"><span class="info-label">Sản phẩm:</span><span class="info-value">{{ $warranty->product->name }}</span></div>
            @if($warranty->product->sku)
            <div class="info-row"><span class="info-label">Mã hàng:</span><span class="info-value">{{ $warranty->product->sku }}</span></div>
            @endif
            @endif
            @if($warranty->serial_imei)
            <div class="info-row"><span class="info-label">Serial/IMEI:</span><span class="info-value">{{ $warranty->serial_imei }}</span></div>
            @endif
        </div>

        <div class="warranty-box">
            <div class="title">Thông tin bảo hành</div>
            <div class="info-row"><span class="info-label">Ngày mua:</span><span class="info-value">{{ $warranty->purchase_date ? $warranty->purchase_date->format('d/m/Y') : '' }}</span></div>
            <div class="info-row"><span class="info-label">Thời hạn BH:</span><span class="info-value">{{ $warranty->warranty_period }}</span></div>
            <div class="info-row"><span class="info-label">Hết hạn BH:</span><span class="info-value">{{ $warranty->warranty_end_date ? $warranty->warranty_end_date->format('d/m/Y') : '' }}</span></div>
            <div class="info-row">
                <span class="info-label">Tình trạng:</span>
                <span class="info-value {{ $warranty->warranty_end_date && $warranty->warranty_end_date->isFuture() ? 'status-valid' : 'status-expired' }}">
                    {{ $warranty->warranty_end_date && $warranty->warranty_end_date->isFuture() ? 'Còn hạn' : 'Hết hạn' }}
                </span>
            </div>
        </div>

        @if($warranty->maintenance_note)
        <div style="padding:6px;background:#f9fafb;border-radius:4px;font-size:12px;">
            <strong style="color:#666;">Ghi chú bảo trì:</strong> {{ $warranty->maintenance_note }}
        </div>
        @endif

        <div class="footer">
            <p>Vui lòng giữ phiếu này để được hỗ trợ bảo hành</p>
            <p>{{ now()->format('d/m/Y H:i') }}</p>
        </div>

        <button class="btn-print no-print" onclick="window.print()">🖨️ In phiếu</button>
    </div>
    <script>window.onload = function() { window.print(); };</script>
</body>
</html>
