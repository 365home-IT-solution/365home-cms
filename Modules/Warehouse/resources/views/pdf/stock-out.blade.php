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
        .signatures { width: 100%; margin-top: 40px; }
        .signatures td { text-align: center; width: 33.33%; }
        .signatures .sig-title { font-weight: bold; }
        .signatures .sig-space { height: 70px; }
        .footer-note { margin-top: 24px; color: #9ca3af; font-size: 10px; }
    </style>
</head>
<body>
    <h1>Phiếu xuất kho</h1>
    <p class="subtitle">{{ $stockOut->partner?->name ?? '—' }}</p>

    <table class="meta-table">
        <tr>
            <td class="meta-label">Số phiếu:</td>
            <td><strong>{{ $stockOut->code }}</strong></td>
            <td class="meta-label">Ngày xuất:</td>
            <td>{{ optional($stockOut->issued_at)->format('d/m/Y H:i') }}</td>
        </tr>
        <tr>
            <td class="meta-label">Xuất cho:</td>
            <td>{{ $stockOut->employee?->name ?? $stockOut->issued_to ?? '—' }}</td>
            <td class="meta-label">Người xuất:</td>
            <td>{{ $creatorName }}</td>
        </tr>
        @if ($stockOut->room)
            <tr>
                <td class="meta-label">Phòng:</td>
                <td colspan="3">{{ $stockOut->room->name }}</td>
            </tr>
        @endif
        @if ($stockOut->note)
            <tr>
                <td class="meta-label">Ghi chú:</td>
                <td colspan="3">{{ $stockOut->note }}</td>
            </tr>
        @endif
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 30px;">#</th>
                <th>Vật tư</th>
                <th style="width: 70px;">ĐVT</th>
                <th style="width: 70px;" class="num">SL</th>
                <th style="width: 130px;">Lý do</th>
                <th>Ghi chú</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($stockOut->items as $index => $line)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $line->item?->name }} @if($line->item?->sku)<br><small>{{ $line->item->sku }}</small>@endif</td>
                    <td>{{ $line->item?->unit?->name ?? '—' }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format($line->quantity, 2, ',', '.'), '0'), ',') }}</td>
                    <td>{{ \Modules\Warehouse\App\Models\WarehouseStockOut::REASONS[$line->reason] ?? $line->reason }}</td>
                    <td>{{ $line->note }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="signatures">
        <tr>
            <td class="sig-title">Người nhận</td>
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
