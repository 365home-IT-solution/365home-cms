{{--
    Lưới NGÀY × KHUNG GIỜ dạng bảng — tham khảo đúng cách hiển thị/tính trạng thái ở trang đặt
    phòng phía client (Modules/BladeThemeV1/.../_desktop-table.blade.php), KHÔNG đụng vào file đó.
    Chọn ô bằng wire:click gọi thẳng method PHP trên Livewire component (CreateOrder/EditOrder) —
    không dùng Alpine/$wire.entangle tự chế như 2 lần thử trước (từng làm mất phản ứng tính giá),
    đây là cách Livewire chính thống nên chắc chắn không phá vỡ cơ chế tính tổng đang chạy đúng.
--}}
@php
    // Nhiều khung giờ có thể được chọn CÙNG LÚC trên đúng 1 bảng này — dùng key
    // "slot_id|date" để so khớp ô nào đang được chọn (hỗ trợ chọn nhiều ngày/nhiều khung).
    $selectedKeys = collect($selectedSlots ?? [])
        ->filter(fn ($s) => ! empty($s['slot_id']) && ! empty($s['date']))
        ->map(fn ($s) => $s['slot_id'] . '|' . \Carbon\Carbon::parse($s['date'])->format('Y-m-d'))
        ->all();
@endphp

<div style="overflow-x:auto;border:1px solid #e5e7eb;border-radius:10px;">
    @if (empty($dates) || empty($slots))
        <div style="padding:16px;text-align:center;color:#9ca3af;font-size:0.85rem;">
            Chọn phòng trước để xem lưới khung giờ.
        </div>
    @else
        <table style="border-collapse:collapse;width:100%;font-size:0.78rem;">
            <thead>
                <tr>
                    <th style="position:sticky;left:0;background:#f9fafb;padding:8px 10px;text-align:left;border-bottom:1px solid #e5e7eb;border-right:1px solid #e5e7eb;white-space:nowrap;">Ngày</th>
                    @foreach ($slots as $slot)
                        <th style="padding:8px 10px;text-align:center;border-bottom:1px solid #e5e7eb;white-space:nowrap;color:#374151;">
                            {{ $slot['label'] }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($dates as $date)
                    <tr wire:key="tsgrid-row-{{ $itemKey }}-{{ $date }}">
                        <td style="position:sticky;left:0;background:#fff;padding:6px 10px;border-right:1px solid #e5e7eb;border-bottom:1px solid #f1f5f9;white-space:nowrap;font-weight:600;color:#374151;">
                            {{ \Carbon\Carbon::parse($date)->translatedFormat('d/m') }}
                            <div style="font-size:0.68rem;color:#9ca3af;font-weight:400;">{{ \Carbon\Carbon::parse($date)->translatedFormat('D') }}</div>
                        </td>
                        @foreach ($slots as $slot)
                            @php
                                $cell = $cells[$date][$slot['id']] ?? null;
                            @endphp
                            <td style="padding:4px;border-bottom:1px solid #f1f5f9;text-align:center;">
                                @if ($cell)
                                    @php
                                        $cellKey = $slot['id'] . '|' . $date;
                                        $isSelected = in_array($cellKey, $selectedKeys, true);
                                        // Ô đang chọn luôn bấm được (để bỏ chọn/đổi sang ô khác), kể cả khi
                                        // trạng thái tính toán là "past" (giờ hiện tại đã trôi qua giờ KẾT
                                        // THÚC của khung — xem OrderForm::getTimeslotGridData() — vẫn là
                                        // lượt admin đã đặt cho đơn này, không phải lỗi).
                                        $isClickable = $cell['status'] === 'available' || $isSelected;

                                        // Khung giờ đang khuyến mãi (còn trống, chưa chọn) → nổi bật bằng
                                        // MÀU NỀN riêng, KHÔNG thêm dòng chữ nào — giữ chiều cao ô đồng đều
                                        // giữa các khung giờ (trước đây thêm dòng giá gạch ngang + nhãn
                                        // "KM" khiến ô có khuyến mãi cao/rộng hơn hẳn ô thường, nhìn lộn xộn).
                                        $hasPromo = !empty($cell['has_promo']) && $cell['status'] === 'available';

                                        $bg = match (true) {
                                            $isSelected => '#2B5257',
                                            $cell['status'] === 'booked' => '#fee2e2',
                                            $cell['status'] === 'held' => '#fef2f2',
                                            $cell['status'] === 'past' => '#f3f4f6',
                                            $hasPromo => '#fef3c7',
                                            default => '#ecfdf5',
                                        };
                                        $color = match (true) {
                                            $isSelected => '#fff',
                                            $cell['status'] === 'booked' => '#b91c1c',
                                            $cell['status'] === 'held' => '#c2410c',
                                            $cell['status'] === 'past' => '#9ca3af',
                                            $hasPromo => '#92400e',
                                            default => '#065f46',
                                        };
                                        $border = ($hasPromo && !$isSelected) ? '2px solid #f59e0b' : '2px solid transparent';
                                    @endphp
                                    <div
                                        wire:key="tsgrid-{{ $itemKey }}-{{ $slot['id'] }}-{{ $date }}"
                                        @if ($isClickable) wire:click="selectTimeslot('{{ $itemKey }}', {{ $slot['id'] }}, '{{ $date }}')" @endif
                                        @if ($cell['status'] === 'held') title="Đang được {{ $cell['held_by'] }} chọn cho 1 đơn khác" @endif
                                        style="
                                            position:relative;
                                            background:{{ $bg }};color:{{ $color }};border:{{ $border }};
                                            border-radius:6px;padding:5px 4px;min-width:64px;
                                            {{ $isClickable ? 'cursor:pointer;' : 'cursor:not-allowed;' }}
                                            font-weight:600;white-space:nowrap;
                                        "
                                    >
                                        {{ number_format($cell['price'], 0, ',', '.') }}đ
                                        @if ($isSelected)
                                            <div style="font-size:0.65rem;font-weight:400;">✓ Đã chọn</div>
                                        @elseif ($cell['status'] === 'booked')
                                            <div style="font-size:0.65rem;font-weight:400;">Đã đặt</div>
                                        @elseif ($cell['status'] === 'held')
                                            <div style="font-size:0.65rem;font-weight:400;">🔒 {{ $cell['held_by'] }}</div>
                                        @elseif ($cell['status'] === 'past')
                                            <div style="font-size:0.65rem;font-weight:400;">Qua giờ</div>
                                        @elseif ($cell['over_night'])
                                            <div style="font-size:0.65rem;font-weight:400;">Qua đêm</div>
                                        @endif
                                        @if ($hasPromo)
                                            <span style="position:absolute;top:-5px;right:-5px;width:14px;height:14px;border-radius:50%;background:#f59e0b;color:#fff;font-size:0.55rem;font-weight:800;line-height:14px;text-align:center;">%</span>
                                        @endif
                                    </div>
                                @else
                                    —
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
