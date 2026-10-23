{{-- Phòng THEO NGÀY (styles=2) của chi nhánh đang chọn — carousel (Swiper, cùng kiểu nút Trước/Sau với bảng
     khung giờ _desktop-grid.blade.php), mỗi slide là lịch đặt phòng gọn của 1 phòng: chọn ngày nhận/trả ngay tại
     đây, "Đặt phòng" mở trang chi tiết phòng với đúng khoảng ngày đó. Dữ liệu: Book::buildDailyCards()
     ($room['calendar'] = DailyRoomCalendar::data()); logic lịch: window.dailyRoomCalendar(), gắn Swiper:
     window.mountDailySwiper() — cả 2 trong public/js/home-sections.js. --}}
@php $dailyRooms = $category['daily'] ?? []; @endphp
@if (! empty($dailyRooms))
<section class="daily-wrap" aria-label="Phòng theo ngày"
         x-data="{}" x-init="$nextTick(() => window.mountDailySwiper($el))">
    {{-- Chú thích trạng thái — cùng vị trí/kiểu với bảng khung giờ (book/_legend.blade.php), màu ô ngày
         bên dưới khớp đúng các mục này. --}}
    <div class="flex flex-wrap justify-start items-center gap-x-4 lg:gap-x-8 gap-y-3 mb-4 px-2.5 lg:px-0 text-sm font-medium">
        <div class="flex items-center gap-1">
            <span class="w-4 h-4 bg-primary rounded"></span> Đã Đặt
        </div>
        <div class="flex items-center gap-1">
            <span class="w-4 h-4 border border-[#DDDDDD] rounded bg-white"></span> Còn Trống
        </div>
        <div class="flex items-center gap-1">
            <span class="w-4 h-4 rounded {{ ($generalSettings ?? null)?->holiday_theme_active ? 'bg-red-600' : 'bg-gray-900' }}"></span> Đang chọn
        </div>
        <div class="flex items-center gap-1">
            <span class="selectable-mini promo-mini w-4 h-4 rounded"></span> Khuyến mãi
        </div>
    </div>

    <div class="book-dt-carousel-head daily-wrap__head">
        <button type="button" class="book-nav-btn book-nav-btn-labeled daily-nav-prev" aria-label="Phòng trước">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            <span>Trước</span>
        </button>
        <div class="book-dt-pagination daily-pagination"></div>
        <button type="button" class="book-nav-btn book-nav-btn-labeled daily-nav-next" aria-label="Phòng tiếp">
            <span>Sau</span>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </button>
    </div>

    <div class="swiper daily-swiper">
        <div class="swiper-wrapper">
            @foreach ($dailyRooms as $room)
            <div class="swiper-slide" wire:key="daily-{{ $room['id'] }}">
                <div class="daily-cal" x-data="dailyRoomCalendar(@js($room['calendar'] ?? []), @js($room['url']))">
                    <a href="{{ $room['url'] }}" class="daily-cal__title" title="Xem chi tiết phòng">
                        <span>{{ $room['name'] }}</span>
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M9 7h8v8"/></svg>
                    </a>

                    <div class="daily-cal__nav">
                        <button type="button" class="daily-cal__arrow" @click="prevMonth()" :disabled="!canPrev" aria-label="Tháng trước">
                            <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <span class="daily-cal__month" x-text="monthName()"></span>
                        <button type="button" class="daily-cal__arrow" @click="nextMonth()" aria-label="Tháng sau">
                            <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>

                    <div class="daily-cal__grid">
                        @foreach (['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'] as $dow)
                        <span class="daily-cal__dow">{{ $dow }}</span>
                        @endforeach
                        <template x-for="n in firstDay()" :key="'pad-' + n"><span></span></template>
                        <template x-for="d in daysInMonth()" :key="viewYear + '-' + viewMonth + '-' + d">
                            <button type="button" class="daily-cal__day"
                                    :disabled="isDisabled(d)"
                                    :class="dayClass(d)"
                                    :title="promoLabels[iso(d)] || ''"
                                    @click="selectDay(d)"
                                    @mouseenter="!isDisabled(d) && (hoveredDate = iso(d))"
                                    @mouseleave="hoveredDate = null"><span x-text="d"></span></button>
                        </template>
                    </div>

                    <div class="daily-cal__foot">
                        <p class="daily-cal__hint" x-show="!startDate">Chọn ngày nhận phòng</p>
                        <p class="daily-cal__hint" x-show="startDate && !endDate" x-cloak>
                            Nhận <b x-text="fmt(startDate)"></b> · chọn ngày trả
                            <button type="button" class="daily-cal__clear" @click="clear()">Xóa</button>
                        </p>
                        <div class="daily-cal__picked" x-show="startDate && endDate" x-cloak>
                            <div class="daily-cal__sum">
                                <span x-text="fmt(startDate) + ' → ' + fmt(endDate) + ' · ' + nightCount + ' đêm'"></span>
                                <p><b x-text="vnd(total)"></b><s x-show="savings > 0" x-text="vnd(originalTotal)"></s></p>
                            </div>
                            <button type="button" class="daily-cal__clear" @click="clear()" aria-label="Chọn lại">✕</button>
                            <button type="button" class="daily-cal__book" @click="book()">Đặt phòng</button>
                        </div>
                    </div>

                    {{-- Mobile/tablet (< lg): chọn xong khoảng ngày thì hiện bottom sheet giá — cùng khung/kiểu với sheet
                         của phòng khung giờ (book.blade.php .book-bottom-sheet + book/_pricing.blade.php; CSS tự ẩn ở ≥ lg).
                         x-teleport ra body: slide Swiper có transform nên position:fixed bên trong bị kẹt trong slide. --}}
                    <template x-teleport="body">
                        <div class="book-bottom-sheet daily-sheet"
                             x-show="startDate && endDate"
                             x-transition:enter="sheet-enter"
                             x-transition:enter-start="sheet-enter-from"
                             x-transition:enter-end="sheet-enter-to"
                             x-transition:leave="sheet-leave"
                             x-transition:leave-start="sheet-leave-from"
                             x-transition:leave-end="sheet-leave-to"
                             style="display:none">
                            <div class="book-sheet-handle-row">
                                <span class="book-sheet-handle"></span>
                                <button type="button" class="book-sheet-close" @click="clear()" aria-label="Đóng">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>
                            </div>
                            <div class="book-sheet-scroll">
                                <div class="book-pricing-card book-pricing-summary flex flex-col items-stretch mt-3 gap-4">
                                    <div class="w-full text-left font-semibold px-2">
                                        <p class="text-base font-bold text-gray-900">{{ $room['name'] }}</p>
                                        <p class="text-sm text-gray-500 font-medium mb-3"
                                           x-text="'Nhận ' + fmt(startDate) + ' → Trả ' + fmt(endDate) + ' · ' + nightCount + ' đêm'"></p>

                                        <p class="text-base text-primary mb-2 flex items-center gap-1">
                                            Giá cơ bản:
                                            <span class="font-bold" x-text="originalTotal.toLocaleString() + ' đ'"></span>
                                        </p>

                                        <template x-if="savings > 0">
                                            <div class="ml-4 mb-2">
                                                <p class="text-sm text-primary font-semibold">Khuyến mãi:</p>
                                                <template x-for="promo in rangePromos" :key="promo.label">
                                                    <p class="text-xs text-primary ml-4">
                                                        • <span x-text="promo.label"></span>
                                                        <span class="text-gray-500" x-show="!String(promo.label).includes('%')" x-text="'(-' + promo.discount + '%)'"></span>
                                                    </p>
                                                </template>
                                                <p class="text-sm text-primary font-bold ml-4 mt-1 border-t border-primary/30 pt-1">
                                                    Tổng khuyến mãi:
                                                    <span x-text="'-' + savings.toLocaleString() + ' đ'"></span>
                                                </p>
                                            </div>
                                        </template>

                                        <div class="mt-3 pt-3 border-t-2 border-primary">
                                            <p class="text-xl text-primary font-bold flex items-center gap-2">
                                                Tổng tiền tạm tính:
                                                <span class="text-2xl" x-text="total.toLocaleString() + ' đ'"></span>
                                            </p>
                                        </div>
                                        <p class="mt-2 text-xs text-gray-600 italic">Chưa gồm phụ thu khách &amp; dịch vụ thêm — xác nhận ở bước đặt phòng.</p>
                                    </div>

                                    <div class="px-2 pb-2 w-full text-right">
                                        <button type="button" class="book-cta-btn" @click="book()">Đặt phòng ngay</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>
@endif
