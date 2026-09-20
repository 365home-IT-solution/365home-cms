<!DOCTYPE html>
<html lang="vi">
<head>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; text-align: center; margin: 0 0 4px; text-transform: uppercase; }
        .subtitle { text-align: center; color: #6b7280; margin: 0 0 20px; }

        /* 1 hàng = 1 sản phẩm, cách nhau rộng để dễ cắt/dễ quét, không bị dính mã bên cạnh khi camera
           bắt hình cả tấm giấy (khác bản lưới 4 cột trước — quá sát, camera dễ đọc nhầm mã kế bên). */
        .card {
            border: 1px dashed #9ca3af;
            border-radius: 6px;
            padding: 16px 24px;
            margin-bottom: 24px;
            display: table;
            width: 100%;
        }
        .card .qr-cell {
            display: table-cell;
            width: 240px;
            vertical-align: middle;
            text-align: center;
        }
        .card .qr-cell img { width: 220px; height: 220px; }
        .card .info-cell {
            display: table-cell;
            vertical-align: middle;
            padding-left: 24px;
        }
        .item-name { font-weight: bold; font-size: 16px; margin: 0 0 6px; }
        .item-sku { color: #4b5563; font-size: 13px; }
        .item-sku span { font-family: monospace; font-size: 14px; }
        .footer-note { margin-top: 16px; color: #9ca3af; font-size: 10px; }
    </style>
</head>
<body>
    <h1>Thẻ mã QR vật tư</h1>
    <p class="subtitle">Tổng {{ count($items) }} vật tư — In lúc {{ now()->format('d/m/Y H:i') }}</p>

    @foreach ($items as $item)
        <div class="card">
            <div class="qr-cell">
                <img src="data:image/png;base64,{{ $qrImages[$item->id] }}" alt="QR {{ $item->sku }}">
            </div>
            <div class="info-cell">
                <div class="item-name">{{ $item->name }}</div>
                <div class="item-sku">Mã SKU: <span>{{ $item->sku }}</span></div>
            </div>
        </div>
    @endforeach

    <p class="footer-note">Cắt theo đường nét đứt để có từng thẻ riêng — quét bằng camera hoặc máy quét mã vạch ở ô "Quét / nhập mã vạch". 365home CMS</p>
</body>
</html>
