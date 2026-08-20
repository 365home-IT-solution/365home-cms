<!DOCTYPE html>
<html lang="vi">
<head>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; text-align: center; margin: 0 0 4px; text-transform: uppercase; }
        .subtitle { text-align: center; color: #6b7280; margin: 0 0 20px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.items th, table.items td { border: 1px solid #d1d5db; padding: 5px 8px; font-size: 11px; }
        table.items th { background: #f3f4f6; text-align: left; }
        table.items td.num { text-align: right; }
        .low-stock { color: #dc2626; font-weight: bold; }
        .inactive { color: #9ca3af; }
        .footer-note { margin-top: 24px; color: #9ca3af; font-size: 10px; }
    </style>
</head>
<body>
    <h1>Danh sách tồn kho</h1>
    <p class="subtitle">
        @if ($partnerName)
            {{ $partnerName }} —
        @endif
        Tổng {{ count($items) }} vật tư — In lúc {{ now()->format('d/m/Y H:i') }}
    </p>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 30px;">#</th>
                @if ($showPartnerColumn)
                    <th>Đối tác</th>
                @endif
                <th style="width: 90px;">SKU</th>
                <th>Tên vật tư</th>
                <th>Nhóm</th>
                <th style="width: 60px;">ĐVT</th>
                <th style="width: 80px;" class="num">Tồn kho</th>
                <th style="width: 80px;" class="num">Ngưỡng tối thiểu</th>
                <th style="width: 90px;" class="num">Đơn giá</th>
                <th style="width: 60px;">Trạng thái</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $index => $item)
                <tr @class(['inactive' => ! $item->status])>
                    <td>{{ $index + 1 }}</td>
                    @if ($showPartnerColumn)
                        <td>{{ $item->partner?->name ?? '—' }}</td>
                    @endif
                    <td>{{ $item->sku ?: '—' }}</td>
                    <td>{{ $item->name }}</td>
                    <td>{{ $item->category?->name ?? '—' }}</td>
                    <td>{{ $item->unit?->name ?? '—' }}</td>
                    <td class="num {{ $item->isLowStock() ? 'low-stock' : '' }}">
                        {{ rtrim(rtrim(number_format($item->quantity, 2, ',', '.'), '0'), ',') }}
                    </td>
                    <td class="num">{{ rtrim(rtrim(number_format($item->min_quantity, 2, ',', '.'), '0'), ',') }}</td>
                    <td class="num">{{ $item->unit_price !== null ? number_format($item->unit_price, 0, ',', '.') : '—' }}</td>
                    <td>{{ $item->status ? 'Đang dùng' : 'Ngừng' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="footer-note">Dòng in đỏ: tồn kho đã chạm hoặc dưới ngưỡng tối thiểu — 365home CMS</p>
</body>
</html>
