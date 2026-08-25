{{--
    Lưới NGÀY × KHUNG GIỜ dạng 2 khối (Ngày bên trái / Khung giờ bên phải) — CÙNG NGÔN NGỮ THỊ
    GIÁC với lịch đặt phòng phía khách hàng (Modules/BladeThemeV1/.../book/_mobile.blade.php +
    book/_styles.blade.php: class book-grid-header/book-dates-card/book-slots-card, icon mặt
    trời/mặt trăng, viền phát sáng nhiều màu cho ô đang khuyến mãi...) để admin nhìn thấy lịch
    giống hệt những gì khách nhìn thấy. Class ở đây đặt tiền tố "tsgrid-" RIÊNG (không dùng lại
    "book-*"/".selectable" của theme khách) vì 2 nơi build CSS khác nhau (Filament admin panel
    và theme khách), style ở đây được nhúng thẳng bằng <style> trong chính view này (không qua
    resources/css/filament/admin/*.css) nên áp dụng ngay không cần build lại asset.

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

    // Thứ viết tắt tiếng Việt (CN/T2..T7) theo dayOfWeek — giống cách làm sẵn có ở
    // Modules/BladeThemeV1/Livewire/Book.php và các nơi khác trong dự án, KHÔNG suy ra từ
    // translatedFormat('D') (phụ thuộc locale server có cài 'vi' hay không).
    $weekdayShortVi = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];

    // Khung giờ nào "qua đêm" — KHÔNG dùng thẳng cờ 'over_night' (đọc từ cột over_night khai
    // báo tay trên RoomTimeSlot, admin có thể quên tick dù giờ kết thúc thực sự nhỏ hơn/bằng
    // giờ bắt đầu, khiến icon hiện sai mặt trời cho khung giờ rõ ràng qua đêm — vd 23:50-09:30).
    // Suy ra TRỰC TIẾP từ start/end đã tính đúng ở OrderForm::computeSlotDatetimes() (hàm đó tự
    // cộng thêm 1 ngày cho $end nếu qua đêm, kể cả khi cờ trên bị sai) — lấy từ ô dữ liệu đầu
    // tiên tìm được của mỗi slot (start/end lệch ngày giống nhau cho mọi ngày của cùng 1 slot).
    $slotOvernight = [];
    foreach ($slots ?? [] as $slot) {
        foreach ($cells ?? [] as $dateCells) {
            if (isset($dateCells[$slot['id']])) {
                $slotOvernight[$slot['id']] = ! $dateCells[$slot['id']]['start']->isSameDay($dateCells[$slot['id']]['end']);
                break;
            }
        }
    }
@endphp

<style>
    .tsgrid-wrap { display: flex; flex-direction: column; }
    .tsgrid-header { display: flex; gap: 6px; padding: 0 0 0; }
    .tsgrid-corner {
        width: 60px; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
        height: 34px; background: #f9fafb; border: 1px solid #f0f0f0; border-bottom: none;
        border-radius: 10px 10px 0 0; font-size: .6rem; font-weight: 700; letter-spacing: .03em;
        text-transform: uppercase; color: #374151; text-align: center; padding: 0 2px;
    }
    .tsgrid-slots-header {
        flex: 1; min-width: 0; display: flex; gap: 4px; background: #fff;
        border: 1px solid #f0f0f0; border-bottom: none; border-radius: 10px 10px 0 0;
        padding: 4px 4px 3px; overflow-x: auto;
    }
    .tsgrid-slot-th { flex: 1; min-width: 52px; text-align: center; padding: 1px 1px 0; }
    .tsgrid-slot-th .tsgrid-time { font-size: .62rem; font-weight: 700; color: #111827; white-space: nowrap; }
    .tsgrid-icon { width: 11px; height: 11px; margin: 2px auto 0; display: block; }
    .tsgrid-overnight-wrap { display: flex; align-items: center; justify-content: center; margin-top: 2px; }
    .tsgrid-icon-inline { width: 12px; height: 12px; flex-shrink: 0; }

    .tsgrid-body { display: flex; gap: 6px; }
    .tsgrid-dates {
        width: 60px; flex-shrink: 0; background: #f9fafb; border: 1px solid #f0f0f0; border-top: none;
        border-radius: 0 0 10px 10px; box-shadow: inset 0 2px 4px 0 rgb(0 0 0 / .05); overflow: hidden;
    }
    .tsgrid-date-row {
        height: 44px; display: flex; flex-direction: column; align-items: center; justify-content: center;
        border-bottom: 1px solid #f1f5f9; gap: 1px; padding: 0 2px; text-align: center;
    }
    .tsgrid-date-row:last-child { border-bottom: none; }
    .tsgrid-date-day { font-size: .64rem; font-weight: 700; color: #111827; line-height: 1; }
    .tsgrid-date-num { font-size: .54rem; color: #9ca3af; line-height: 1; }
    .tsgrid-date-row.is-today .tsgrid-date-day,
    .tsgrid-date-row.is-today .tsgrid-date-num { color: var(--color-primary, #0f766e); }

    .tsgrid-slots {
        flex: 1; min-width: 0; background: #fff; border: 1px solid #f0f0f0; border-top: none;
        border-radius: 0 0 12px 12px; overflow: hidden;
    }
    .tsgrid-slots-row {
        display: flex; gap: 4px; padding: 4px; height: 44px; align-items: center;
        border-bottom: 1px solid #f1f5f9;
    }
    .tsgrid-slots-row:last-child { border-bottom: none; }
    .tsgrid-cell-wrap { flex: 1; min-width: 52px; display: flex; align-items: center; justify-content: center; }

    .tsgrid-cell {
        position: relative; z-index: 1; width: 100%; height: 34px; border-radius: 9px;
        border: 1px solid #e5e7eb; display: flex; flex-direction: column; align-items: center;
        justify-content: center; font-weight: 700; font-size: .6rem; overflow: visible;
        background: #fff; color: #374151;
    }
    .tsgrid-cell.is-clickable { cursor: pointer; }
    .tsgrid-cell.is-disabled { cursor: not-allowed; }
    .tsgrid-cell .tsgrid-sub { font-size: .5rem; font-weight: 400; margin-top: 1px; }
    .tsgrid-cell .tsgrid-lock { width: 13px; height: 13px; }

    .tsgrid-cell.available { background: none; border: none; box-shadow: inset 0 1px 3px 0 rgb(0 0 0 / .1); color: #065f46; }
    .tsgrid-cell.selected { background: #2B5257; border-color: #2B5257; color: #fff; }
    .tsgrid-cell.booked { background: #fee2e2; border-color: #fecaca; color: #b91c1c; }
    .tsgrid-cell.held { background: #fef3c7; border-color: #fde68a; color: #c2410c; }
    .tsgrid-cell.past { background: #9297a1; border-color: #9297a1; color: #fff; }

    /* Nội dung hiện trong ô (giá/nhãn phụ/icon khoá) phải nổi lên TRÊN lớp phủ trắng ::after
       của .has-promo bên dưới (::after không đặt z-index âm nên mặc định nằm ở stack level 0,
       CAO HƠN ::before z-index:-10 nhưng cũng cao hơn luôn nội dung thường (text/div không
       position) nếu không tự nâng stack level — thiếu bước này thì giá sẽ bị lớp phủ trắng che
       mất khi khung giờ có khuyến mãi). */
    .tsgrid-cell > * { position: relative; z-index: 1; }

    /* Khung giờ đang có khuyến mãi (còn trống, chưa chọn) — LẤY NGUYÊN kỹ thuật
       ".selectable.promo::before/::after" ở book/_styles.blade.php (theme khách, đúng file lịch
       khung giờ phía trang đặt phòng): ::before phủ full ô + filter:blur() để dải màu tràn RA
       NGOÀI viền thành quầng sáng mờ, ::after phủ TRẮNG ĐÈ LẠI đúng khung ô (không blur, không
       z-index âm) để che phần màu KHÔNG bị blur ở giữa ô — thiếu ::after này thì cả ô sẽ bị tô
       kín màu gradient thay vì chỉ thấy quầng sáng ở viền (đúng lỗi đã gặp: ô hiện đặc màu cam-
       hồng, không còn nền trắng). */
    .tsgrid-cell.has-promo {
        background: #fff;
        border-color: #fff;
        box-shadow: inset 0 1px 3px 0 rgb(0 0 0 / .12);
    }
    .tsgrid-cell.has-promo::before {
        content: "";
        position: absolute;
        top: 0; left: 0; right: 0; bottom: 0;
        border-radius: inherit;
        background: linear-gradient(270deg, #ff0000, #ff9900, #33ff00, #00ffff, #3300ff, #ff00cc, #ff0000);
        background-size: 300% 300%;
        animation: tsgrid-borderflow 10s linear infinite;
        z-index: -10;
        transform: scale(1, 1);
        filter: blur(1px);
        opacity: 1;
    }
    .tsgrid-cell.has-promo::after {
        content: "";
        position: absolute;
        top: 0; left: 0; right: 0; bottom: 0;
        border-radius: inherit;
        background: #fff;
    }
    @keyframes tsgrid-borderflow {
        0% { background-position: 0% 50%; }
        100% { background-position: 300% 50%; }
    }
</style>

<div class="tsgrid-wrap">
    @if (empty($dates) || empty($slots))
        <div style="padding:16px;text-align:center;color:#9ca3af;font-size:0.85rem;border:1px solid #e5e7eb;border-radius:12px;">
            Chọn phòng trước để xem lưới khung giờ.
        </div>
    @else
        <div class="tsgrid-header">
            <div class="tsgrid-corner">Thời gian</div>
            <div class="tsgrid-slots-header">
                @foreach ($slots as $slot)
                    @php $isOvernight = $slotOvernight[$slot['id']] ?? false; @endphp
                    <div class="tsgrid-slot-th">
                        <div class="tsgrid-time">{{ $slot['label'] }}</div>
                        @if ($isOvernight)
                            <div class="tsgrid-overnight-wrap">
                                <svg class="tsgrid-icon-inline" style="color:#1e3a8a" viewBox="0 0 24 24" fill="currentColor">
                                    <path fill-rule="evenodd" d="M9.528 1.718a.75.75 0 0 1 .162.819A8.97 8.97 0 0 0 9 6a9 9 0 0 0 9 9 8.97 8.97 0 0 0 3.463-.69.75.75 0 0 1 .981.98 10.503 10.503 0 0 1-9.694 6.46c-5.799 0-10.5-4.7-10.5-10.5 0-4.368 2.667-8.112 6.46-9.694a.75.75 0 0 1 .818.162Z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        @else
                            <svg class="tsgrid-icon" style="color:#eab308" viewBox="0 0 16 16" fill="currentColor">
                                <path d="M8 1a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 8 1ZM10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0ZM12.95 4.11a.75.75 0 1 0-1.06-1.06l-1.062 1.06a.75.75 0 0 0 1.061 1.062l1.06-1.061ZM15 8a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 15 8ZM11.89 12.95a.75.75 0 0 0 1.06-1.06l-1.06-1.062a.75.75 0 0 0-1.062 1.061l1.061 1.06ZM8 12a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 8 12ZM5.172 11.89a.75.75 0 0 0-1.061-1.062L3.05 11.89a.75.75 0 1 0 1.06 1.06l1.06-1.06ZM4 8a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 4 8ZM4.11 5.172A.75.75 0 0 0 5.173 4.11L4.11 3.05a.75.75 0 1 0-1.06 1.06l1.06 1.06Z" />
                            </svg>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <div class="tsgrid-body">
            <div class="tsgrid-dates">
                @foreach ($dates as $date)
                    @php $carbonDate = \Carbon\Carbon::parse($date); @endphp
                    <div class="tsgrid-date-row {{ $carbonDate->isToday() ? 'is-today' : '' }}">
                        <div class="tsgrid-date-day">{{ $weekdayShortVi[$carbonDate->dayOfWeek] }}</div>
                        <div class="tsgrid-date-num">{{ $carbonDate->format('d-m-Y') }}</div>
                    </div>
                @endforeach
            </div>

            <div class="tsgrid-slots">
                @foreach ($dates as $date)
                    <div class="tsgrid-slots-row" wire:key="tsgrid-row-{{ $itemKey }}-{{ $date }}">
                        @foreach ($slots as $slot)
                            @php
                                $cell = $cells[$date][$slot['id']] ?? null;
                            @endphp
                            <div class="tsgrid-cell-wrap">
                                @if ($cell)
                                    @php
                                        $cellKey = $slot['id'] . '|' . $date;
                                        $isSelected = in_array($cellKey, $selectedKeys, true);
                                        // Ô đang chọn luôn bấm được (để bỏ chọn/đổi sang ô khác), kể cả khi
                                        // trạng thái tính toán là "past" (giờ hiện tại đã trôi qua giờ KẾT
                                        // THÚC của khung — xem OrderForm::getTimeslotGridData() — vẫn là
                                        // lượt admin đã đặt cho đơn này, không phải lỗi).
                                        $isClickable = $cell['status'] === 'available' || $isSelected;

                                        $hasPromo = !empty($cell['has_promo']) && $cell['status'] === 'available';

                                        $stateClass = match (true) {
                                            $isSelected => 'selected',
                                            $cell['status'] === 'booked' => 'booked',
                                            $cell['status'] === 'held' => 'held',
                                            $cell['status'] === 'past' => 'past',
                                            default => 'available',
                                        };

                                        // "past"/"held" chỉ hiện icon khoá (giống ô bị khoá ở lịch phía
                                        // khách hàng), KHÔNG hiện giá — ô đã chọn/còn trống/đã đặt vẫn cần
                                        // thấy giá để admin biết đang chọn/đã thu bao nhiêu.
                                        $showLock = ! $isSelected && in_array($cell['status'], ['past', 'held'], true);
                                    @endphp
                                    <div
                                        wire:key="tsgrid-{{ $itemKey }}-{{ $slot['id'] }}-{{ $date }}"
                                        class="tsgrid-cell {{ $stateClass }} {{ $isClickable ? 'is-clickable' : 'is-disabled' }} {{ $hasPromo && !$isSelected ? 'has-promo' : '' }}"
                                        @if ($isClickable) wire:click="selectTimeslot('{{ $itemKey }}', {{ $slot['id'] }}, '{{ $date }}')" @endif
                                        @if ($cell['status'] === 'held') title="Đang được {{ $cell['held_by'] }} chọn cho 1 đơn khác" @endif
                                    >
                                        @if ($showLock)
                                            <svg class="tsgrid-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round">
                                                <rect x="4" y="11" width="16" height="9" rx="2" />
                                                <path d="M8 11V7a4 4 0 0 1 8 0v4" />
                                            </svg>
                                        @else
                                            <span>{{ number_format($cell['price'], 0, ',', '.') }}đ</span>
                                            @if ($isSelected)
                                                <div class="tsgrid-sub">✓ Đã chọn</div>
                                            @elseif ($cell['status'] === 'booked')
                                                <div class="tsgrid-sub">Đã đặt</div>
                                            @endif
                                        @endif
                                    </div>
                                @else
                                    <span style="color:#d1d5db;">—</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
