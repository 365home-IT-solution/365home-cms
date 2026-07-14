{{--
    Desktop (≥lg): cột "Thời gian" (ngày) đứng cố định bên trái, KHÔNG nằm trong carousel — chỉ
    khối khung giờ của từng phòng mới trượt theo kiểu Center Mode/Peek Carousel (Swiper):
    phòng đang xem ở giữa, kích thước đầy đủ; 2 phòng liền kề hé lộ 1 phần 2 bên, thu nhỏ + mờ.
    Vuốt/bấm mũi tên hoặc bấm thẳng vào phòng đang peek để chuyển phòng (Swiper tự trượt vào
    giữa — slideToClickedSlide). Ô khung giờ chỉ chọn được ở phòng đang active (2 bên
    pointer-events:none, tránh bấm nhầm khi định bấm để chuyển phòng).
    Mobile (< lg) vẫn dùng book/_mobile.blade.php (carousel 1 phòng kiểu cũ, không đổi).
    Inherits: $dates, $styleOneRooms, $today, $category
--}}
<div class="hidden lg:block book-dt-wrap"
    x-data="{ activeRoomIdx: 0 }"
    x-init="$nextTick(() => window.mountBookDtSwiper($el))">

    <div class="book-dt-carousel-head">
        <button type="button" class="book-nav-btn book-dt-nav-prev book-nav-btn-labeled book-dt-nav-btn-lg" aria-label="Phòng trước">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            <span>Trước</span>
        </button>
        <div class="book-dt-pagination"></div>
        <button type="button" class="book-nav-btn book-dt-nav-next book-nav-btn-labeled book-dt-nav-btn-lg" aria-label="Phòng tiếp">
            <span>Sau</span>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </button>
    </div>

    <div class="book-dt-body">
        {{-- Cột "Thời gian" (ngày) — dùng chung cho mọi phòng, đứng yên, không trượt theo carousel.
             Cuộn dọc đồng bộ 2 chiều với khối khung giờ của phòng ĐANG ACTIVE (activeRoomIdx). --}}
        <div class="book-dt-dates-col">
            {{-- Spacer vô hình cùng class/thẻ với tên phòng (.book-dt-room-name) bên carousel —
                 mỗi slide phòng có 1 dòng tên phòng (h3) nằm TRÊN book-dt-slots-header-row, còn
                 cột Thời gian này thì không, nên nếu thiếu spacer thì card "Thời gian" bị đẩy lên
                 cao hơn card khung giờ đúng bằng chiều cao dòng tên phòng, làm lệch hàng ngày so
                 với hàng khung giờ trong toàn bộ danh sách bên dưới. Dùng cùng class (thay vì đoán
                 cứng 1 số px) để tự động khớp chiều cao dù font-size/margin của .book-dt-room-name
                 có đổi sau này. --}}
            <h3 class="book-room-name book-dt-room-name" style="visibility:hidden;" aria-hidden="true">&nbsp;</h3>
            <div class="book-dt-col-header">Thời gian</div>
            <div class="book-dt-dates-card">
                <div class="book-dt-dates-scroll" x-ref="bookDtDatesScroll"
                    @scroll="const t = $refs['bookDtSlotsScroll' + activeRoomIdx]; if (t) t.scrollTop = $event.target.scrollTop">
                    @foreach ($dates as $date)
                        @php $dateShort = \Carbon\Carbon::createFromFormat('d-m-Y', $date['date'])->format('d-m-Y'); @endphp
                        <div class="book-dt-date-row{{ $date['is_today'] ? ' is-today' : '' }}">
                            <div class="font-extrabold">{{ $date['day'] }}</div>
                            <div class="text-[10px] text-gray-500 font-normal">{{ $dateShort }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Carousel Center Mode: mỗi slide là tên phòng + header khung giờ + bảng khung giờ của
             riêng phòng đó (khung giờ có thể khác nhau giữa các phòng nên header phải trượt theo). --}}
        <div class="book-dt-carousel-col">
            <div class="swiper book-dt-swiper" x-ref="bookDtSwiperEl">
                <div class="swiper-wrapper">
                    @foreach ($styleOneRooms as $room)
                        @php
                            // Phòng đang có khuyến mãi giảm giá (percentage/fixed) hiệu lực ngay bây
                            // giờ ở bất kỳ khung giờ nào — chỉ để hiện icon flash-sale trang trí cạnh
                            // tên phòng, không cần khớp chính xác từng ngày/giờ như calculateSlotPrice()
                            // (dùng khi tính giá thật cho từng ô lịch).
                            $roomHasDiscount = $room->roomTimeSlots->contains(
                                fn ($rts) => $rts->promotions->contains(
                                    fn ($p) => in_array($p->type, ['percentage', 'fixed'])
                                        && $p->is_active
                                        && \Carbon\Carbon::parse($p->start_at)->lte(now())
                                        && \Carbon\Carbon::parse($p->end_at)->gte(now())
                                )
                            );
                        @endphp
                        <div class="swiper-slide">
                            <h3 class="book-room-name book-dt-room-name">
                                {{ $room->name }}
                                @if($roomHasDiscount)
                                    <img src="{{ asset('images/flash-sale.gif') }}" alt="" class="book-room-flash-icon">
                                @endif
                            </h3>

                            <div class="book-dt-slots-header-row" x-ref="bookDtHeaderRow{{ $loop->index }}"
                                @scroll="$refs['bookDtSlotsScroll' + {{ $loop->index }}].scrollLeft = $event.target.scrollLeft">
                                @foreach ($room->roomTimeSlots as $roomTimeSlot)
                                    @php
                                        $startTime   = \Carbon\Carbon::parse($roomTimeSlot->timeSlot->start_time);
                                        $endTime     = \Carbon\Carbon::parse($roomTimeSlot->timeSlot->end_time);
                                        $isOvernight = $endTime->isNextDay() || $endTime->lt($startTime);
                                    @endphp
                                    <div class="book-dt-slot-th">
                                        {{ $startTime->format('H:i') }} - {{ $endTime->format('H:i') }}
                                        <br>
                                        @if ($isOvernight)
                                            <svg class="w-4 h-4 inline" style="color:#1e3a8a" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                                <path fill-rule="evenodd" d="M9.528 1.718a.75.75 0 0 1 .162.819A8.97 8.97 0 0 0 9 6a9 9 0 0 0 9 9 8.97 8.97 0 0 0 3.463-.69.75.75 0 0 1 .981.98 10.503 10.503 0 0 1-9.694 6.46c-5.799 0-10.5-4.7-10.5-10.5 0-4.368 2.667-8.112 6.46-9.694a.75.75 0 0 1 .818.162Z" clip-rule="evenodd" />
                                            </svg>
                                            <span class="text-xs" style="color:#1e3a8a">(Qua đêm)</span>
                                        @else
                                            <svg class="w-4 h-4 inline" style="color:#eab308" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor">
                                                <path d="M8 1a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 8 1ZM10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0ZM12.95 4.11a.75.75 0 1 0-1.06-1.06l-1.062 1.06a.75.75 0 0 0 1.061 1.062l1.06-1.061ZM15 8a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 15 8ZM11.89 12.95a.75.75 0 0 0 1.06-1.06l-1.06-1.062a.75.75 0 0 0-1.062 1.061l1.061 1.06ZM8 12a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 8 12ZM5.172 11.89a.75.75 0 0 0-1.061-1.062L3.05 11.89a.75.75 0 1 0 1.06 1.06l1.06-1.06ZM4 8a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 4 8ZM4.11 5.172A.75.75 0 0 0 5.173 4.11L4.11 3.05a.75.75 0 1 0-1.06 1.06l1.06 1.06Z" />
                                            </svg>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            <div class="book-dt-slots-card">
                                <div class="book-dt-slots-scroll" x-ref="bookDtSlotsScroll{{ $loop->index }}"
                                    @scroll="
                                        if (activeRoomIdx === {{ $loop->index }}) $refs.bookDtDatesScroll.scrollTop = $event.target.scrollTop;
                                        $refs['bookDtHeaderRow' + {{ $loop->index }}].scrollLeft = $event.target.scrollLeft;
                                    ">
                                    @foreach ($dates as $date)
                                        <div class="book-dt-slots-row">
                                            @foreach ($room->roomTimeSlots as $roomTimeSlot)
                                                <div class="book-dt-slot-cell">
                                                    @include('bladethemev1::livewire.book._slot-cell', ['room' => $room, 'date' => $date, 'roomTimeSlot' => $roomTimeSlot])
                                                </div>
                                            @endforeach
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    @if ($this->visibleDaysCount < \Modules\BladeThemeV1\Livewire\Book::MAX_VISIBLE_DAYS)
        <div class="book-loadmore-row">
            <button type="button" class="book-loadmore-btn" wire:click="loadMoreDates" wire:loading.attr="disabled" wire:target="loadMoreDates">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
                <span>Xem thêm {{ min(\Modules\BladeThemeV1\Livewire\Book::LOAD_MORE_DAYS_STEP, \Modules\BladeThemeV1\Livewire\Book::MAX_VISIBLE_DAYS - $this->visibleDaysCount) }} ngày</span>
            </button>
        </div>
    @endif
</div>
{{-- window.mountBookDtSwiper() + Livewire morph.updated hook sống trong public/js/home-sections.js
     (đã load sẵn qua <script src> ở mọi trang dùng Book.php — flash-sale.blade.php,
     search.blade.php, booking-board.blade.php) — KHÔNG đặt <script> ở đây: nội dung file này chỉ
     xuất hiện trong DOM sau khi Livewire morph vào (activeCategoryData rỗng lúc mount đầu), và
     trình duyệt KHÔNG tự thực thi <script> được chèn qua DOM patching kiểu đó (chỉ có directive
     Alpine như x-init mới được Livewire+Alpine tự động re-init trên nội dung mới morph vào). --}}
