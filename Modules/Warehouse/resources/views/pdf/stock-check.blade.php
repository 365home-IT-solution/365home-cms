<!DOCTYPE html>
<html lang="vi">
<head>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; text-align: center; margin: 0 0 4px; text-transform: uppercase; }
        .subtitle { text-align: center; color: #6b7280; margin: 0 0 20px; }
        .meta-table { width: 100%; margin-bottom: 16px; }
        .meta-table td { padding: 2px 0; vertical-align: top; }
        .meta-label { color: #6b7280; width: 110px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.items th, table.items td { border: 1px solid #d1d5db; padding: 6px 8px; font-size: 11px; }
        table.items th { background: #f3f4f6; text-align: left; }
        table.items td.num { text-align: right; }
        .diff-pos { color: #059669; font-weight: bold; }
        .diff-neg { color: #dc2626; font-weight: bold; }
        .signatures { width: 100%; margin-top: 40px; }
        .signatures td { text-align: center; width: 33.33%; }
        .signatures .sig-title { font-weight: bold; }
        .signatures .sig-space { height: 70px; }
        .footer-note { margin-top: 24px; color: #9ca3af; font-size: 10px; }
    </style>
</head>
<body>
    <h1>Phiếu kiểm kê kho</h1>
    <p class="subtitle">{{ $stockCheck->partner?->name ?? '—' }}</p>

    <table class="meta-table">
        <tr>
            <td class="meta-label">Số phiếu:</td>
            <td><strong>{{ $stockCheck->code }}</strong></td>
            <td class="meta-label">Ngày kiểm kê:</td>
            <td>{{ optional($stockCheck->checked_at)->format('d/m/Y H:i') }}</td>
        </tr>
        <tr>
            <td class="meta-label">Người kiểm kê:</td>
            <td colspan="3">{{ $creatorName }}</td>
        </tr>
        @if ($stockCheck->note)
            <tr>
                <td class="meta-label">Ghi chú:</td>
                <td colspan="3">{{ $stockCheck->note }}</td>
            </tr>
        @endif
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 30px;">#</th>
                <th>Vật tư</th>
                <th style="width: 70px;">ĐVT</th>
                <th style="width: 80px;" class="num">Tồn hệ thống</th>
                <th style="width: 80px;" class="num">Tồn thực tế</th>
                <th style="width: 80px;" class="num">Chênh lệch</th>
                <th>Ghi chú</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($stockCheck->items as $index => $line)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $line->item?->name }} @if($line->item?->sku)<br><small>{{ $line->item->sku }}</small>@endif</td>
                    <td>{{ $line->item?->unit?->name ?? '—' }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format($line->system_quantity, 2, ',', '.'), '0'), ',') }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format($line->actual_quantity, 2, ',', '.'), '0'), ',') }}</td>
                    <td class="num {{ $line->difference > 0 ? 'diff-pos' : ($line->difference < 0 ? 'diff-neg' : '') }}">
                        {{ $line->difference > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($line->difference, 2, ',', '.'), '0'), ',') }}
                    </td>
                    <td>{{ $line->note }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="signatures">
        <tr>
            <td class="sig-title">Người kiểm kê</td>
            <td class="sig-title">Thủ kho</td>
            <td class="sig-title">Người phê duyệt</td>
        </tr>
        <tr>
            <td class="sig-space"></td>
            <td class="sig-space"></td>
            <td class="sig-space"></td>
        </tr>
    </table>

    <p class="footer-note">In lúc {{ now()->format('d/m/Y H:i') }} — 365home CMS</p>
</body>
</html>
