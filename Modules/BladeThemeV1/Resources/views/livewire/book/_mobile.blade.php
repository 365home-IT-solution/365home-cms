{{--
    Mobile/tablet two-panel booking grid (1 phòng/lần, carousel prev-next) — desktop (≥lg) dùng
    book/_desktop-grid.blade.php hiển thị tất cả phòng cùng lúc thay cho khối này.
    Included from book.blade.php — inherits:
      $dates, $styleOneRooms, $totalStyleOneRooms, $today, $category
--}}
<div class="lg:hidden" x-data="{
    activeRoomIdx: 0, totalRooms: {{ $totalStyleOneRooms }},
    slotPage: 0, slotsPerPage: 5,
    slotCounts: [{{ $styleOneRooms->map(fn($r) => $r->roomTimeSlots->count())->join(', ') }}],
    categorySlug: '{{ \Str::slug($category['name']) }}',
    touchStartX: 0,
    dateLimit: {{ \Modules\BladeThemeV1\Livewire\Book::INITIAL_VISIBLE_DAYS }}, totalDates: {{ count($dates) }},
    get remainingDates() { return Math.max(0, Math.min({{ \Modules\BladeThemeV1\Livewire\Book::LOAD_MORE_DAYS_STEP }}, this.totalDates - this.dateLimit)); },
    loadMoreDates() { this.dateLimit = Math.min(this.dateLimit + {{ \Modules\BladeThemeV1\Livewire\Book::LOAD_MORE_DAYS_STEP }}, this.totalDates); },
    get totalSlotPages() { return Math.ceil((this.slotCounts[this.activeRoomIdx] ?? 5) / this.slotsPerPage); },
    changeRoom(dir) {
        const newIdx = this.activeRoomIdx + dir;
        if (newIdx >= 0 && newIdx < this.totalRooms) {
            this.activeRoomIdx = newIdx;
            this.slotPage = 0;
            return;
        }
        // Cross-branch: navigate to neighbouring tab when at the boundary
        const tabs = [...document.querySelectorAll('#default-styled-tab button')];
        const currentTabIdx = tabs.findIndex(t => t.id === 'styled-' + this.categorySlug + '-tab');
        const nextTabIdx = currentTabIdx + (dir > 0 ? 1 : -1);
        if (nextTabIdx >= 0 && nextTabIdx < tabs.length) {
            tabs[nextTabIdx].click();
            window.dispatchEvent(new CustomEvent('book-activate-room', {
                detail: { tabId: tabs[nextTabIdx].id, fromEnd: dir < 0 }
            }));
        }
    },
    onTouchStart(e) { this.touchStartX = e.touches[0].clientX; },
    onTouchEnd(e) {
        const diff = this.touchStartX - e.changedTouches[0].clientX;
        if (Math.abs(diff) > 40) { this.changeRoom(diff > 0 ? 1 : -1); }
    }
}"
    @touchstart.passive="onTouchStart($event)"
    @touchend.passive="onTouchEnd($event)"
    @book-activate-room.window="
        if ($event.detail.tabId === 'styled-' + categorySlug + '-tab') {
            activeRoomIdx = $event.detail.fromEnd ? totalRooms - 1 : 0;
            slotPage = 0;
        }
    "
>

    <div class="book-card-outer">

    {{-- ── Room carousel header — trải rộng hết chiều ngang (w-full). Tên phòng bên trái, 2 nút
         trượt gộp gần nhau bên phải cùng hàng (thay vì mỗi nút 1 đầu, cách xa nhau như trước). ── --}}
    <div class="book-top-row">
        <div class="book-room-nav-wrap w-full">
            <div class="book-room-titles-wrap">
                @foreach ($styleOneRooms as $ri => $roomTitle)
                @php
                    // Phòng đang có khuyến mãi giảm giá (percentage/fixed) hiệu lực ngay bây giờ ở
                    // bất kỳ khung giờ nào — chỉ để hiện icon flash-sale trang trí cạnh tên phòng,
                    // không cần khớp chính xác từng ngày/giờ như calculateSlotPrice() (dùng khi tính
                    // giá thật cho từng ô lịch).
                    $roomHasDiscount = $roomTitle->roomTimeSlots->contains(
                        fn ($rts) => $rts->promotions->contains(
                            fn ($p) => in_array($p->type, ['percentage', 'fixed'])
                                && $p->is_active
                                && \Carbon\Carbon::parse($p->start_at)->lte(now())
                                && \Carbon\Carbon::parse($p->end_at)->gte(now())
                        )
                    );
                @endphp
                <div x-show="activeRoomIdx === {{ $ri }}"
                    class="book-room-title-block">
                    <h3 class="book-room-name">
                        {{ $roomTitle->name }}
                        @if($roomHasDiscount)
                            <img src="{{ asset('images/flash-sale.gif') }}" alt="" class="book-room-flash-icon">
                        @endif
                    </h3>
                </div>
                @endforeach
            </div>
            <div class="book-nav-btn-group">
                <button class="book-nav-btn book-nav-btn-labeled" type="button"
                    @click="changeRoom(-1)"
                    aria-label="Phòng trước">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                    </svg>
                    <span>Trước</span>
                </button>
                <button class="book-nav-btn book-nav-btn-labeled" type="button"
                    @click="changeRoom(1)"
                    aria-label="Phòng tiếp">
                    <span>Sau</span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    {{-- ── Slot page navigation strip (only when room has > 5 slots) ── --}}
    @foreach ($styleOneRooms as $ri => $room)
    @if($room->roomTimeSlots->count() > 5)
    <div x-show="activeRoomIdx === {{ $ri }}" x-cloak class="slot-page-strip">
        <button class="slot-pg-btn slot-pg-prev" @click="slotPage = Math.max(0, slotPage - 1)" :disabled="slotPage === 0">
            &#8249; <span>Quay lại</span>
        </button>
        <span class="slot-pg-info" x-text="'Khung giờ ' + (slotPage * slotsPerPage + 1) + '–' + Math.min((slotPage + 1) * slotsPerPage, slotCounts[activeRoomIdx])"></span>
        <button class="slot-pg-btn slot-pg-next" @click="slotPage = Math.min(totalSlotPages - 1, slotPage + 1)" :disabled="slotPage >= totalSlotPages - 1">
            <span>Xem thêm</span> &#8250;
        </button>
    </div>
    @endif
    @endforeach

    {{-- ── Fixed column headers (ngoài khung cuộn — không còn cần position:sticky vì cuộn
         giờ nằm bên trong từng card thân) ── --}}
    <div class="book-grid-header">
        <div class="book-col-header">Ngày</div>
        <div class="book-slots-headers-wrap">
            @foreach ($styleOneRooms as $ri => $room)
            <div x-show="activeRoomIdx === {{ $ri }}" x-cloak class="book-slots-header-row">
                @foreach ($room->roomTimeSlots as $roomTimeSlot)
                @php
                $startTime   = \Carbon\Carbon::parse($roomTimeSlot->timeSlot->start_time);
                $endTime     = \Carbon\Carbon::parse($roomTimeSlot->timeSlot->end_time);
                $isOvernight = $endTime->isNextDay() || $endTime->lt($startTime);
                @endphp
                <div class="book-slot-th" x-show="Math.floor({{ $loop->index }} / slotsPerPage) === slotPage">
                    <span class="book-slot-time-start">{{ $startTime->format('H:i') }}</span><span class="book-slot-time-sep">&nbsp;–&nbsp;</span><br class="book-slot-time-br"><span class="book-slot-time-end">{{ $endTime->format('H:i') }}</span>
                    @if($isOvernight)
                        <svg class="book-slot-icon" style="color:#1e3a8a" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                            <path fill-rule="evenodd" d="M9.528 1.718a.75.75 0 0 1 .162.819A8.97 8.97 0 0 0 9 6a9 9 0 0 0 9 9 8.97 8.97 0 0 0 3.463-.69.75.75 0 0 1 .981.98 10.503 10.503 0 0 1-9.694 6.46c-5.799 0-10.5-4.7-10.5-10.5 0-4.368 2.667-8.112 6.46-9.694a.75.75 0 0 1 .818.162Z" clip-rule="evenodd" />
                        </svg>
                    @else
                        <svg class="book-slot-icon" style="color:#eab308" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor">
                            <path d="M8 1a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 8 1ZM10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0ZM12.95 4.11a.75.75 0 1 0-1.06-1.06l-1.062 1.06a.75.75 0 0 0 1.061 1.062l1.06-1.061ZM15 8a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 15 8ZM11.89 12.95a.75.75 0 0 0 1.06-1.06l-1.06-1.062a.75.75 0 0 0-1.062 1.061l1.061 1.06ZM8 12a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 8 12ZM5.172 11.89a.75.75 0 0 0-1.061-1.062L3.05 11.89a.75.75 0 1 0 1.06 1.06l1.06-1.06ZM4 8a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 4 8ZM4.11 5.172A.75.75 0 0 0 5.173 4.11L4.11 3.05a.75.75 0 1 0-1.06 1.06l1.06 1.06Z" />
                        </svg>
                    @endif
                </div>
                @endforeach
            </div>
            @endforeach
        </div>
    </div>

    {{-- ── Thân: khung (viền/bo góc) của mỗi card đứng yên cố định, chỉ nội dung bên trong
         cuộn dọc, đồng bộ 2 chiều qua @scroll để cột Ngày và khung giờ luôn khớp hàng ── --}}
    <div class="book-grid-outer">

        {{-- Left: Dates card --}}
        <div class="book-dates-card">
            <div class="book-dates-scroll" x-ref="bookDatesScroll"
                @scroll="$refs['bookSlotsScroll' + activeRoomIdx].scrollTop = $event.target.scrollTop">
                @foreach ($dates as $date)
                @php $dateShort = \Carbon\Carbon::createFromFormat('d-m-Y', $date['date'])->format('d/m'); @endphp
                <div class="book-date-row{{ $date['is_today'] ? ' is-today' : '' }}" x-show="{{ $loop->index }} < dateLimit" x-cloak>
                    <span class="book-date-day">{{ $date['day'] }}</span>
                    <span class="book-date-num">{{ $dateShort }}</span>
                </div>
                @endforeach
            </div>
        </div>

        {{-- Right: Scrollable slots per room --}}
        <div class="book-slots-outer">
            @foreach ($styleOneRooms as $ri => $room)
            <div x-show="activeRoomIdx === {{ $ri }}" x-cloak class="book-slots-card">
                <div class="book-slots-scroll" x-ref="bookSlotsScroll{{ $ri }}"
                    @scroll="$refs.bookDatesScroll.scrollTop = $event.target.scrollTop">

                {{-- One row per date --}}
                @foreach ($dates as $date)
                <div class="book-slots-row" x-show="{{ $loop->index }} < dateLimit" x-cloak>
                    @foreach ($room->roomTimeSlots as $roomTimeSlot)
                    <div class="book-slot-cell" x-show="Math.floor({{ $loop->index }} / slotsPerPage) === slotPage">
                        @include('bladethemev1::livewire.book._slot-cell', ['room' => $room, 'date' => $date, 'roomTimeSlot' => $roomTimeSlot])
                    </div>
                    @endforeach
                </div>
                @endforeach

                </div>{{-- end .book-slots-scroll --}}
            </div>
            @endforeach
        </div>{{-- end .book-slots-outer --}}
    </div>{{-- end .book-grid-outer --}}

    {{-- ── Xem thêm ngày (mỗi lần bấm hiện thêm tối đa 10 ngày kế tiếp, tự ẩn khi đã hiện hết) ── --}}
    <div class="book-loadmore-row" x-show="dateLimit < totalDates" x-cloak>
        <button type="button" class="book-loadmore-btn" @click="loadMoreDates()">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
            <span x-text="'Xem thêm ' + remainingDates + ' ngày'"></span>
        </button>
    </div>

    </div>{{-- end .book-card-outer --}}
</div>{{-- end x-data room carousel --}}
