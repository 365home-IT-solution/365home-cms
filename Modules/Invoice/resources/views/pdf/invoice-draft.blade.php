<!DOCTYPE html>
<html lang="vi">
<head>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #1f2937; position: relative; }
        .watermark {
            position: fixed; top: 260px; left: 40px; width: 100%;
            font-size: 46px; color: #ef4444; opacity: 0.28; transform: rotate(-22deg);
            text-transform: uppercase; font-weight: bold; text-align: center; z-index: -1;
        }
        .warn-box {
            border: 2px solid #ef4444; background: #fef2f2; color: #991b1b;
            padding: 8px 12px; margin-bottom: 14px; font-size: 11px; font-weight: bold; text-align: center;
        }
        h1 { font-size: 18px; text-align: center; margin: 0 0 4px; text-transform: uppercase; }
        .subtitle { text-align: center; color: #6b7280; margin: 0 0 16px; }
        .meta-table { width: 100%; margin-bottom: 14px; }
        .meta-table td { padding: 2px 0; vertical-align: top; }
        .meta-label { color: #6b7280; width: 120px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.items th, table.items td { border: 1px solid #d1d5db; padding: 6px 8px; font-size: 11px; }
        table.items th { background: #f3f4f6; text-align: left; }
        table.items td.num, table.items th.num { text-align: right; }
        .totals { width: 100%; margin-top: 10px; }
        .totals td { padding: 3px 0; }
        .totals .label { text-align: right; color: #6b7280; padding-right: 12px; }
        .totals .value { text-align: right; width: 140px; font-weight: bold; }
        .footer-note { margin-top: 24px; color: #9ca3af; font-size: 10px; }
    </style>
</head>
<body>
    <div class="watermark">BẢN NHÁP<br>CHƯA PHÁT HÀNH</div>

    <div class="warn-box">
        ĐÂY LÀ BẢN NHÁP NỘI BỘ — CHƯA ĐƯỢC KÝ SỐ VÀ PHÁT HÀNH QUA MISA MEINVOICE.<br>
        KHÔNG CÓ GIÁ TRỊ PHÁP LÝ, KHÔNG DÙNG ĐỂ KÊ KHAI THUẾ HAY GIAO CHO KHÁCH THAY HOÁ ĐƠN THẬT.
    </div>

    <h1>Hoá đơn giá trị gia tăng (bản nháp)</h1>
    <p class="subtitle">{{ $invoice->order?->category?->name ?? $invoice->partner?->name ?? '—' }}</p>

    <table class="meta-table">
        <tr>
            <td class="meta-label">Mã đơn hàng:</td>
            <td><strong>{{ $invoice->order?->order_code ?? '—' }}</strong></td>
            <td class="meta-label">Ngày tạo bản nháp:</td>
            <td>{{ $invoice->created_at?->format('d/m/Y H:i') }}</td>
        </tr>
        <tr>
            <td class="meta-label">Mẫu số / Ký hiệu:</td>
            <td>{{ $invoice->invoice_template_code ?: '—' }} / {{ $invoice->invoice_series ?: '—' }}</td>
            <td class="meta-label">Trạng thái:</td>
            <td>{{ $invoice->statusLabel() }}</td>
        </tr>
        <tr>
            <td class="meta-label">Người mua:</td>
            <td>{{ $invoice->buyer_name ?: '—' }}</td>
            <td class="meta-label">Mã số thuế:</td>
            <td>{{ $invoice->buyer_tax_code ?: '—' }}</td>
        </tr>
        <tr>
            <td class="meta-label">Địa chỉ:</td>
            <td colspan="3">{{ $invoice->buyer_address ?: '—' }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>STT</th>
                <th>Tên hàng hoá, dịch vụ</th>
                <th>ĐVT</th>
                <th class="num">SL</th>
                <th class="num">Đơn giá</th>
                <th class="num">Thành tiền</th>
                <th class="num">Thuế suất</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $index => $line)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $line->description }}</td>
                    <td>{{ $line->unit ?: '—' }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 2, '.', ''), '0'), '.') }}</td>
                    <td class="num">{{ number_format((int) $line->unit_price, 0, ',', '.') }}</td>
                    <td class="num">{{ number_format((int) $line->amount, 0, ',', '.') }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->vat_rate, 2, '.', ''), '0'), '.') }}%</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="label">Cộng tiền hàng (chưa thuế):</td>
            <td class="value">{{ number_format((int) $invoice->subtotal_amount, 0, ',', '.') }} đ</td>
        </tr>
        <tr>
            <td class="label">Tiền thuế GTGT:</td>
            <td class="value">{{ number_format((int) $invoice->vat_amount, 0, ',', '.') }} đ</td>
        </tr>
        <tr>
            <td class="label">Tổng cộng tiền thanh toán:</td>
            <td class="value">{{ number_format((int) $invoice->total_amount, 0, ',', '.') }} đ</td>
        </tr>
    </table>

    <p class="footer-note">
        Bản nháp được tạo tự động từ đơn hàng {{ $invoice->order?->order_code }} trong hệ thống 365 Home.
        Hoá đơn điện tử hợp lệ chỉ tồn tại sau khi được ký số và phát hành qua MISA meInvoice.
    </p>
</body>
</html>
