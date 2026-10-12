<!DOCTYPE html>
<html lang="vi">
<head>
    <style>
        @page { margin: 24px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 16px; text-align: center; margin: 0 0 2px; text-transform: uppercase; }
        .subtitle { text-align: center; color: #6b7280; margin: 0 0 12px; font-size: 11px; }

        /* Lưới 3 thẻ/hàng (đổi $cols = 2 để còn 2 thẻ/hàng): mỗi thẻ có khung nét đứt để cắt theo đường kẻ, QR ở trên, tên vật tư và SKU ở dưới.
           Các thẻ cách nhau (padding + khung riêng) để camera quét cả tờ không đọc nhầm mã kế bên. */
        table.grid { width: 100%; border-collapse: separate; border-spacing: 8px; table-layout: fixed; }
        table.grid tr { page-break-inside: avoid; }
        table.grid td {
            border: 1px dashed #9ca3af;
            text-align: center;
            vertical-align: top;
            padding: 12px 8px 10px;
            height: 250px;
        }
        table.grid td.empty { border: 0; }
        .qr { margin: 0 auto 8px; }
        .item-name { font-weight: bold; font-size: 12px; line-height: 1.3; margin: 0 0 4px; word-wrap: break-word; }
        .item-sku { color: #4b5563; font-size: 10px; }
        .item-sku span { font-family: monospace; font-size: 11px; }
        .footer-note { margin-top: 10px; color: #9ca3af; font-size: 9px; text-align: center; }
    </style>
</head>
<body>
    @php
        $cols = 3;
        $qrSize = $cols >= 3 ? 160 : 220;
        $rows = $items->chunk($cols);
    @endphp

    <h1>Thẻ mã QR vật tư</h1>
    <p class="subtitle">Tổng {{ count($items) }} vật tư — In lúc {{ now()->format('d/m/Y H:i') }}</p>

    <table class="grid">
        @foreach ($rows as $row)
            <tr>
                @foreach ($row as $item)
                    <td>
                        <img class="qr" src="data:image/png;base64,{{ $qrImages[$item->id] }}" alt="QR {{ $item->sku }}" style="width: {{ $qrSize }}px; height: {{ $qrSize }}px;">
                        <div class="item-name">{{ $item->name }}</div>
                        <div class="item-sku">SKU: <span>{{ $item->sku }}</span></div>
                    </td>
                @endforeach
                @for ($pad = $row->count(); $pad < $cols; $pad++)
                    <td class="empty"></td>
                @endfor
            </tr>
        @endforeach
    </table>

    <p class="footer-note">Cắt theo đường nét đứt để có từng thẻ riêng — quét bằng camera hoặc máy quét mã vạch ở ô "Quét / nhập mã vạch". 365home CMS</p>
</body>
</html>
