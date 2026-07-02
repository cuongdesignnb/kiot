<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $cashFlow->type === 'receipt' ? 'Phiếu thu' : 'Phiếu chi' }} - {{ $cashFlow->code }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Arial', 'Helvetica', sans-serif; font-size: 13px; line-height: 1.5; color: #333; }
        .receipt { max-width: 320px; margin: 0 auto; padding: 12px; }
        .header { text-align: center; padding-bottom: 10px; border-bottom: 1px dashed #999; margin-bottom: 10px; }
        .company-name { font-size: 16px; font-weight: bold; text-transform: uppercase; }
        .doc-title { font-size: 15px; font-weight: bold; text-align: center; margin: 10px 0 8px; text-transform: uppercase; }
        .doc-title.receipt-type { color: #16a34a; }
        .doc-title.payment-type { color: #dc2626; }
        .info { margin-bottom: 10px; }
        .info-row { display: flex; margin-bottom: 2px; }
        .info-label { min-width: 100px; color: #666; }
        .info-value { flex: 1; font-weight: 500; }
        .sep { border: none; border-top: 1px dashed #999; margin: 10px 0; }
        .amount-box { text-align: center; padding: 12px 0; }
        .amount-label { color: #666; font-size: 12px; margin-bottom: 4px; }
        .amount-value { font-size: 22px; font-weight: bold; }
        .amount-value.receipt-type { color: #16a34a; }
        .amount-value.payment-type { color: #dc2626; }
        .note-box { padding: 8px; background: #f9fafb; border-radius: 4px; font-size: 12px; margin-top: 8px; }
        .note-box .note-label { font-weight: bold; color: #666; margin-bottom: 2px; }
        .footer { text-align: center; margin-top: 12px; padding-top: 10px; border-top: 1px dashed #999; font-size: 11px; color: #666; }
        .sign-area { display: flex; justify-content: space-between; margin-top: 20px; padding-top: 10px; }
        .sign-col { text-align: center; width: 45%; }
        .sign-col .sign-title { font-weight: bold; font-size: 12px; margin-bottom: 40px; }
        .sign-col .sign-name { font-size: 11px; color: #999; }
        .btn-print { display: block; margin: 15px auto 0; padding: 8px 24px; background: #2563eb; color: #fff; border: none; border-radius: 4px; font-size: 13px; cursor: pointer; }
        .btn-print:hover { background: #1d4ed8; }
        @media print { body { margin: 0; } .receipt { max-width: none; } .no-print { display: none !important; } }
    </style>
</head>
<body>
    @php $isReceipt = $cashFlow->type === 'receipt'; @endphp
    <div class="receipt">
        <div class="header">
            <div class="company-name">{{ config('app.name', 'KiotViet') }}</div>
        </div>

        <div class="doc-title {{ $isReceipt ? 'receipt-type' : 'payment-type' }}">
            {{ $isReceipt ? 'Phiếu thu' : 'Phiếu chi' }}
        </div>

        <div class="info">
            <div class="info-row"><span class="info-label">Mã phiếu:</span><span class="info-value">{{ $cashFlow->code }}</span></div>
            <div class="info-row"><span class="info-label">Thời gian:</span><span class="info-value">{{ $cashFlow->time ? \Carbon\Carbon::parse($cashFlow->time)->format('d/m/Y H:i') : ($cashFlow->created_at ? $cashFlow->created_at->format('d/m/Y H:i') : '') }}</span></div>
            @if($cashFlow->category)
            <div class="info-row"><span class="info-label">Hạng mục:</span><span class="info-value">{{ $cashFlow->category }}</span></div>
            @endif
            @if($cashFlow->target_name)
            <div class="info-row"><span class="info-label">{{ $isReceipt ? 'Người nộp' : 'Người nhận' }}:</span><span class="info-value">{{ $cashFlow->target_name }}</span></div>
            @endif
            @if($cashFlow->reference_code)
            <div class="info-row"><span class="info-label">Mã tham chiếu:</span><span class="info-value">{{ $cashFlow->reference_code }}</span></div>
            @endif
            <div class="info-row">
                <span class="info-label">Phương thức:</span>
                <span class="info-value">
                    @if($cashFlow->payment_method === 'bank')
                        Chuyển khoản
                        @if($cashFlow->bankAccount)
                            ({{ $cashFlow->bankAccount->bank_name }} - {{ $cashFlow->bankAccount->account_number }})
                        @endif
                    @else
                        Tiền mặt
                    @endif
                </span>
            </div>
        </div>

        <hr class="sep">

        <div class="amount-box">
            <div class="amount-label">Số tiền {{ $isReceipt ? 'thu' : 'chi' }}</div>
            <div class="amount-value {{ $isReceipt ? 'receipt-type' : 'payment-type' }}">
                {{ format_vnd($cashFlow->amount) }} đ
            </div>
        </div>

        <hr class="sep">

        @if($cashFlow->description)
        <div class="note-box">
            <div class="note-label">Ghi chú:</div>
            <div>{{ $cashFlow->description }}</div>
        </div>
        @endif

        <div class="sign-area">
            <div class="sign-col">
                <div class="sign-title">{{ $isReceipt ? 'Người nộp tiền' : 'Người nhận tiền' }}</div>
                <div class="sign-name">(Ký, họ tên)</div>
            </div>
            <div class="sign-col">
                <div class="sign-title">{{ $isReceipt ? 'Người thu tiền' : 'Người chi tiền' }}</div>
                <div class="sign-name">(Ký, họ tên)</div>
            </div>
        </div>

        <div class="footer">
            <div style="margin-top: 8px;">{{ $cashFlow->created_at ? $cashFlow->created_at->format('d/m/Y H:i:s') : '' }}</div>
        </div>

        <button class="btn-print no-print" onclick="window.print()">🖨️ In phiếu</button>
    </div>
    <script>window.onload = function() { window.print(); };</script>
</body>
</html>
