<!DOCTYPE html>
<html lang="vi">
<head>
    <style>
        @page { margin: 24px; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #1f2937; }
        .sheet { page-break-before: always; }
        .sheet.first { page-break-before: avoid; }
        .sheet-title { font-size: 10px; color: #6b7280; margin: 0 0 6px; }
        table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.grid td {
            border: 1px dashed #9ca3af;
            text-align: center;
            vertical-align: middle;
            padding: 2px 0;
            height: 44px;
        }
        table.grid td img { width: 34px; height: 34px; }
        .sku { font-family: monospace; font-size: 5px; margin-top: 0; word-break: break-all; }
    </style>
</head>
<body>
    @php $cols = 14; @endphp
    @foreach ($items as $item)
        <div class="sheet {{ $loop->first ? 'first' : '' }}">
            <p class="sheet-title">{{ $item->name }} — {{ $copies }} tem</p>
            <table class="grid">
                @for ($i = 0; $i < $copies; $i += $cols)
                    <tr>
                        @for ($c = $i; $c < $i + $cols; $c++)
                            <td>
                                @if ($c < $copies)
                                    <img src="data:image/png;base64,{{ $qrImages[$item->id] }}" alt="QR {{ $item->sku }}">
                                    <div class="sku">{{ $item->sku }}</div>
                                @endif
                            </td>
                        @endfor
                    </tr>
                @endfor
            </table>
        </div>
    @endforeach
</body>
</html>
