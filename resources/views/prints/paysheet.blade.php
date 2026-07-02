<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bảng lương - {{ $paysheet->code }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Arial', 'Helvetica', sans-serif; font-size: 13px; line-height: 1.5; color: #333; }
        .receipt { max-width: 320px; margin: 0 auto; padding: 12px; }
        .header { text-align: center; padding-bottom: 10px; border-bottom: 1px dashed #999; margin-bottom: 10px; }
        .company-name { font-size: 16px; font-weight: bold; text-transform: uppercase; }
        .doc-title { font-size: 15px; font-weight: bold; text-align: center; margin: 10px 0 8px; text-transform: uppercase; }
        .info { margin-bottom: 10px; }
        .info-row { display: flex; margin-bottom: 3px; }
        .info-label { min-width: 110px; color: #666; }
        .info-value { flex: 1; font-weight: 500; }
        .sep { border: none; border-top: 1px dashed #999; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 12px; }
        thead { background: #f3f4f6; }
        th, td { padding: 4px 3px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { font-weight: 600; color: #555; }
        .text-right { text-align: right; }
        .total-row { display: flex; justify-content: space-between; padding: 3px 0; }
        .total-row.bold { font-weight: bold; font-size: 14px; }
        .status { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .status-draft { background: #e5e7eb; color: #374151; }
        .status-confirmed { background: #dbeafe; color: #1e40af; }
        .status-paid { background: #dcfce7; color: #166534; }
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
            @if($paysheet->branch)
            <div>{{ $paysheet->branch->name }}</div>
            @endif
        </div>

        <div class="doc-title">Bảng lương</div>

        <div class="info">
            <div class="info-row"><span class="info-label">Mã bảng lương:</span><span class="info-value">{{ $paysheet->code }}</span></div>
            <div class="info-row"><span class="info-label">Tên:</span><span class="info-value">{{ $paysheet->name }}</span></div>
            <div class="info-row"><span class="info-label">Kỳ lương:</span><span class="info-value">{{ $paysheet->pay_period }}</span></div>
            <div class="info-row"><span class="info-label">Từ ngày:</span><span class="info-value">{{ $paysheet->period_start ? $paysheet->period_start->format('d/m/Y') : '' }}</span></div>
            <div class="info-row"><span class="info-label">Đến ngày:</span><span class="info-value">{{ $paysheet->period_end ? $paysheet->period_end->format('d/m/Y') : '' }}</span></div>
            <div class="info-row"><span class="info-label">Số nhân viên:</span><span class="info-value">{{ $paysheet->employee_count }}</span></div>
            <div class="info-row">
                <span class="info-label">Trạng thái:</span>
                <span class="info-value">
                    @php
                        $statusClass = match($paysheet->status) {
                            'paid' => 'status-paid',
                            'confirmed' => 'status-confirmed',
                            default => 'status-draft',
                        };
                        $statusLabel = match($paysheet->status) {
                            'paid' => 'Đã trả',
                            'confirmed' => 'Đã xác nhận',
                            default => 'Nháp',
                        };
                    @endphp
                    <span class="status {{ $statusClass }}">{{ $statusLabel }}</span>
                </span>
            </div>
        </div>

        <hr class="sep">

        @if($paysheet->payslips && $paysheet->payslips->count())
        <table>
            <thead>
                <tr>
                    <th>Nhân viên</th>
                    <th class="text-right">Lương</th>
                    <th class="text-right">Đã trả</th>
                    <th class="text-right">Còn lại</th>
                </tr>
            </thead>
            <tbody>
                @foreach($paysheet->payslips as $slip)
                <tr>
                    <td>{{ $slip->employee ? $slip->employee->name : $slip->code }}</td>
                    <td class="text-right">{{ format_vnd($slip->total_salary) }}</td>
                    <td class="text-right">{{ format_vnd($slip->paid_amount) }}</td>
                    <td class="text-right">{{ format_vnd($slip->remaining) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif

        <hr class="sep">

        <div class="total-row"><span>Tổng lương:</span><span>{{ format_vnd($paysheet->total_salary) }}</span></div>
        <div class="total-row"><span>Đã trả:</span><span>{{ format_vnd($paysheet->total_paid) }}</span></div>
        <div class="total-row bold"><span>Còn lại:</span><span>{{ format_vnd($paysheet->total_remaining) }}</span></div>

        @if($paysheet->notes)
        <hr class="sep">
        <div style="font-size:12px;"><strong>Ghi chú:</strong> {{ $paysheet->notes }}</div>
        @endif

        <div class="footer">
            <p>{{ now()->format('d/m/Y H:i') }}</p>
        </div>

        <button class="btn-print no-print" onclick="window.print()">🖨️ In phiếu</button>
    </div>
    <script>window.onload = function() { window.print(); };</script>
</body>
</html>
