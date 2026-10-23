@php
    use App\Models\ApprovalRequest;

    // Giá trị hiển thị: bật/tắt, danh sách, văn bản dài cắt bớt.
    $fmt = function ($v) {
        if ($v === null || $v === '') {
            return '—';
        }
        if (is_bool($v)) {
            return $v ? 'Bật / Có' : 'Tắt / Không';
        }
        if (is_array($v)) {
            return \Illuminate\Support\Str::limit(json_encode($v, JSON_UNESCAPED_UNICODE), 400);
        }

        return \Illuminate\Support\Str::limit((string) $v, 400);
    };

    $row = 'padding:6px 10px;border-bottom:1px solid rgba(128,128,128,.25);vertical-align:top;';
@endphp

<div style="display:flex;flex-direction:column;gap:16px;font-size:14px;">
    <table style="width:100%;border-collapse:collapse;">
        <tr><td style="{{ $row }}width:170px;opacity:.7;">Đối tác</td><td style="{{ $row }}">{{ $record->partner?->legal_name ?: $record->partner?->name ?: '—' }}</td></tr>
        <tr><td style="{{ $row }}opacity:.7;">Người gửi</td><td style="{{ $row }}">{{ $record->requester?->fullname ?: '—' }} · {{ $record->created_at?->format('d/m/Y H:i') }}</td></tr>
        <tr><td style="{{ $row }}opacity:.7;">Trạng thái</td><td style="{{ $row }}">{{ $record->statusLabel() }}@if($record->reviewed_at) · {{ $record->reviewer?->fullname }} · {{ $record->reviewed_at->format('d/m/Y H:i') }}@endif</td></tr>
        @if($record->review_note)
            <tr><td style="{{ $row }}opacity:.7;">Ghi chú / lý do</td><td style="{{ $row }}">{{ $record->review_note }}</td></tr>
        @endif
    </table>

    @if(count($subject))
        <table style="width:100%;border-collapse:collapse;">
            @foreach($subject as $label => $value)
                <tr><td style="{{ $row }}width:170px;opacity:.7;">{{ $label }}</td><td style="{{ $row }}">{{ $value }}</td></tr>
            @endforeach
        </table>
    @endif

    @if(count($changes))
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="text-align:left;">
                    <th style="{{ $row }}">Trường</th>
                    <th style="{{ $row }}">Đang chạy</th>
                    <th style="{{ $row }}">Đề xuất</th>
                </tr>
            </thead>
            <tbody>
                @foreach($changes as $c)
                    <tr>
                        <td style="{{ $row }}font-weight:600;">{{ $c['label'] }}</td>
                        <td style="{{ $row }}opacity:.75;">{{ $fmt($c['old']) }}</td>
                        <td style="{{ $row }}">{{ $fmt($c['new']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @elseif($record->kind === ApprovalRequest::KIND_ROOM_UPDATE || $record->kind === ApprovalRequest::KIND_PRICE_BOARD_UPDATE)
        <p style="opacity:.7;">Không còn thay đổi nào để đối chiếu.</p>
    @endif
</div>
