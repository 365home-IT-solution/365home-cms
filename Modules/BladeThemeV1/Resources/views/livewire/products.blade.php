<div class="md:px-8 mx-auto">
    @if ($parentCategory)
    <h2 class="mt-4 mb-2 text-center text-4xl font-bold">Điểm đến</h2>
    <h5 class="mb-4 text-center text-2xl text-primary font-bold">
        {{ $parentCategory->name }}
    </h5>
    @endif

    @if (!empty($configuredCategories))

    <div class="flex items-center justify-center gap-3 mb-5">
        <div class="flex-1 h-px bg-gradient-to-r from-transparent to-primary/30 max-w-[120px]"></div>
        <div class="promo-badge-btn text-primary px-6 py-2 rounded-full text-md font-semibold">
            Chọn chi nhánh phù hợp với bạn
        </div>
        <div class="flex-1 h-px bg-gradient-to-l from-transparent to-primary/30 max-w-[120px]"></div>
    </div>

    <div class="overflow-x-auto mb-6 -mx-4 px-4 tab-scroll-container">
        <div class="inline-flex items-center bg-gray-800 rounded-full p-1.5 gap-1 min-w-max" id="sub-tab-all-{{ $uniqueId }}"
            data-tabs-toggle="#sub-tab-content-all-{{ $uniqueId }}"
            data-tabs-active-classes="bg-primary text-white shadow border-0"
            data-tabs-inactive-classes="text-gray-400 hover:text-white border-0" role="tablist">

            @foreach ($configuredCategories as $gIndex => $item)
            @php
            $grandChild = $item['category'];
            @endphp
            <button
                class="inline-flex items-center gap-1.5 px-3 py-2 md:px-5 md:py-2.5 rounded-full text-xs md:text-sm font-bold tracking-wide transition-all duration-200 uppercase whitespace-nowrap"
                id="sub-tab-btn-{{ $uniqueId }}-{{ $gIndex }}" data-tabs-target="#sub-styled-{{ $uniqueId }}-{{ $gIndex }}"
                type="button" role="tab" aria-controls="sub-tab-{{ $uniqueId }}-{{ $gIndex }}"
                aria-selected="{{ $gIndex === 0 ? 'true' : 'false' }}">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 md:w-4 md:h-4 shrink-0" fill="none"
                            viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        {{ $grandChild->name }}
                    </button>
            @endforeach
        </div>
    </div>

    <div id="sub-tab-content-all-{{ $uniqueId }}">
        @foreach ($configuredCategories as $gIndex => $item)
        @php
        $grandChild = $item['category'];
        $grandChildRooms = $item['products'];
        $child = $childCategories->get($grandChild->parent_id);
        $roomCount = $grandChildRooms->count();
        @endphp

        <div class="{{ $gIndex === 0 ? '' : 'hidden' }} rounded-lg" id="sub-styled-{{ $uniqueId }}-{{ $gIndex }}"
            role="tabpanel" aria-labelledby="sub-tab-{{ $uniqueId }}-{{ $gIndex }}">

            <div class="flex items-center justify-between mt-4 mb-3 gap-3">
                <h2 class="text-2xl md:text-3xl font-bold flex-1">
                    Home - {{ $grandChild->name }} {{ $child->name ?? '' }}
                </h2>
                <div class="flex items-center gap-1 shrink-0">
                    <button type="button" class="carousel-custom-prev-{{ $uniqueId }}-{{ $gIndex }} w-9 h-9 flex items-center justify-center rounded-full border-2 border-primary bg-white text-primary hover:bg-primary hover:text-white transition-all duration-200 shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                    </button>
                    <button type="button" class="carousel-custom-next-{{ $uniqueId }}-{{ $gIndex }} w-9 h-9 flex items-center justify-center rounded-full border-2 border-primary bg-white text-primary hover:bg-primary hover:text-white transition-all duration-200 shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                    </button>
                </div>
            </div>

            <div class="owl-carousel owl-carousel-{{ $uniqueId }}" data-room-count="{{ $roomCount }}" data-uid="{{ $uniqueId }}" data-gindex="{{ $gIndex }}">
                @if ($grandChildRooms->isNotEmpty())
                @foreach ($grandChildRooms as $room)
                @if (($room->styles ?? 1) == 2)
                {{-- styles=2: Full image card (Airbnb-style) --}}
                @php
                $pdPrice = (float)($room->price ?? 0);
                $pdDisc = (float)($room->discount ?? 0);
                $pdDisplayPrice = $pdDisc > 0 ? number_format(round($pdPrice * (1 - $pdDisc / 100))) : number_format((int)$pdPrice);
                $pdRating = $room->rating ?? null;
                $pdReviews = $room->reviews_count ?? null;
                @endphp
                <div class="flex flex-col">
                    <a class="relative block pt-[60%] overflow-hidden rounded-2xl"
                        href="{{ route('product.detail', ['slug' => $room->pslug]) }}">
                        <img src="{{ asset('storage/' . $room->media_file) }}"
                            alt="{{ $room->pname }}"
                            class="w-full h-full object-cover top-0 bottom-0 left-0 right-0 absolute" />
                        {{-- Gradient blur primary bottom --}}
                        <div class="absolute inset-x-0 bottom-0 h-1/3 pointer-events-none" style="background: linear-gradient(to top, rgba(var(--color-primary-rgb), 0.55) 0%, transparent 100%); backdrop-filter: blur(0px);"></div>
                        @if (!empty($room->badge))
                        <div class="absolute top-3 left-3 bg-white text-gray-900 text-xs font-semibold px-3 py-1.5 rounded-full shadow-md">
                            {{ $room->badge }}
                        </div>
                        @endif
                    </a>
                    <div class="pt-2 px-1">
                        <h3 class="text-base font-semibold text-gray-900 line-clamp-1 leading-snug">{{ $room->pname }}</h3>
                        <p class="text-xs text-gray-400 line-clamp-1 mt-0.5">{{ $grandChild->name }}{{ !empty($child->name) ? ', ' . $child->name : '' }}</p>
                        <div class="flex items-center justify-between mt-1">
                            <div class="leading-none">
                                <span class="text-base font-bold text-gray-900">{{ $pdDisplayPrice }}đ</span>
                                <span class="text-sm text-gray-500 font-normal">/ ngày</span>
                            </div>
                            @if ($pdRating)
                            <div class="flex items-center gap-1 text-xs">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" viewBox="0 0 24 24" fill="currentColor" style="color:#f59e0b"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                <span class="font-semibold text-gray-800">{{ $pdRating }}</span>
                                @if ($pdReviews)<span class="text-gray-400">({{ $pdReviews }})</span>@endif
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
                @else
                {{-- styles=1: default card --}}
                <div class="bg-white rounded-2xl border border-gray-200 flex flex-col">
                    <a class="relative block pt-[60%] overflow-hidden rounded-t-2xl"
                        href="{{ route('product.detail', ['slug' => $room->pslug]) }}">
                        <img src="{{ asset('storage/' . $room->media_file) }}"
                                            alt="{{ $room->pname }}"
                                            class="w-full h-full object-cover top-0 bottom-0 left-0 right-0 absolute" />
                        {{-- Gradient blur primary bottom --}}
                        <div class="absolute inset-x-0 bottom-0 h-1/3 pointer-events-none" style="background: linear-gradient(to top, rgba(var(--color-primary-rgb), 0.55) 0%, transparent 100%);"></div>
                        {{-- Badge "Được khách yêu thích" --}}
                        @if (!empty($room->badge))
                        <div class="absolute top-3 left-3 bg-white text-gray-900 text-xs font-semibold px-3 py-1.5 rounded-full shadow-md">
                            {{ $room->badge }}
                        </div>
                        @endif
                    </a>

                    <div class="p-5">
                        <h2 class="text-start text-2xl font-bold text-black mb-1 line-clamp-1">
                            {{ $room->pname }}</h2>
                        <div class="text-start text-sm font-medium text-gray-400 mb-4 line-clamp-1">
                            {{ $grandChild->name }}{{ !empty($child->name) ? ', ' . $child->name : '' }}</div>
                        <div class="grid grid-cols-7 gap-2 mb-4 border-b border-gray-200 pb-4">
                            @if (!empty($room->tag_image) && !empty($room->tag_name))
                            @php
                            $tagImages = array_values(
                            array_filter(
                            array_map('trim', explode(',', $room->tag_image)),
                            ),
                            );
                            $tagNames = array_values(
                            array_filter(
                            array_map('trim', explode(',', $room->tag_name)),
                            ),
                            );
                            $minLength = min(count($tagImages), count($tagNames));

                            $tags = [];
                            for ($i = 0; $i < $minLength; $i++) { $tags[]=[ 'image'=> $tagImages[$i],
                                'name' => $tagNames[$i],
                                ];
                                }

                            $maxVisible = 14;
                            $totalTags = count($tags);
                            $hasMore = $totalTags > $maxVisible;
                            $displayTags = $hasMore ? array_slice($tags, 0, $maxVisible - 1) : $tags;
                            $hiddenCount = $hasMore ? ($totalTags - ($maxVisible - 1)) : 0;
                                @endphp
                                @foreach ($displayTags as $tag)
                                <div class="flex items-center justify-center p-2 bg-gray-50 w-10 h-10 rounded-full cursor-default border border-gray-100">
                                    <img src="{{ asset('storage/' . $tag['image']) }}"
                                                            alt="{{ $tag['name'] }}"
                                                            class="w-6 h-6 object-contain filter">
                                </div>
                                @endforeach
                                @if ($hasMore)
                                <div class="flex items-center justify-center w-10 h-10 rounded-full bg-gray-100 border border-gray-300 text-gray-500 font-bold text-xs cursor-default select-none">
                                    ...
                                </div>
                                @endif
                                @endif
                        </div>

                        <div class="flex items-end justify-between">
                            <div class="text-start min-w-[60%]">
                                <p class="font-bold">GIÁ PHÒNG</p>
                                @if (!empty($room->time_price_pairs))
                                @php
                                $pairs = array_map(
                                'trim',
                                explode(',', $room->time_price_pairs),
                                );
                                $priceHour = [];

                                foreach ($pairs as $pair) {
                                if (strpos($pair, ':') === false) {
                                continue;
                                }
                                [$timeValue, $price] = explode(':', $pair, 2);

                                $timeValue = floatval($timeValue);
                                $price = trim($price);
                                $priceNumeric = floatval(
                                str_replace([',', '.'], '', $price),
                                );

                                $hours = floor($timeValue);
                                $minutes = round(($timeValue - $hours) * 100);

                                if ($timeValue == 999) {
                                // Đây là giá cả ngày
                                $display = 'Cả ngày';
                                $sortOrder = 999;
                                } elseif ($timeValue < 0) { $display='đêm' ; $sortOrder=998; } elseif ($timeValue==0) {
                                    $display='liên hệ' ; $sortOrder=-1; $priceNumeric=-1; } elseif ($timeValue> 10 &&
                                    $timeValue < 999) { $display='Qua đêm' ; $sortOrder=$timeValue; } elseif ($hours==0
                                        && $minutes> 0) {
                                        $display = $minutes . ' phút';
                                        $sortOrder = $timeValue;
                                        } elseif ($hours > 0 && $minutes > 0) {
                                        $display = $hours . 'h' . $minutes;
                                        $sortOrder = $timeValue;
                                        } else {
                                        $display = $hours . 'h';
                                        $sortOrder = $timeValue;
                                        }

                                        $priceHour[] = [
                                        'price' => $price,
                                        'price_numeric' => $priceNumeric,
                                        'sort_order' => $sortOrder,
                                        'display' => $display,
                                        'is_full_day' => $timeValue == 999,
                                        ];
                                        }

                                        usort($priceHour, function ($a, $b) {
                                        if ($a['price_numeric'] == -1) {
                                        return 1;
                                        }
                                        if ($b['price_numeric'] == -1) {
                                        return -1;
                                        }

                                        // Đưa "Cả ngày" xuống cuối (trước "liên hệ")
                                        if ($a['is_full_day']) {
                                        return 1;
                                        }
                                        if ($b['is_full_day']) {
                                        return -1;
                                        }

                                        return $a['price_numeric'] <=> $b['price_numeric'];
                                            });

                                        // Loại bỏ trùng lặp: giữ mỗi cặp (giá + thời gian) duy nhất
                                        $seen = [];
                                        $priceHour = array_values(array_filter($priceHour, function($item) use (&$seen) {
                                            $key = $item['price_numeric'] . '|' . $item['display'];
                                            if (isset($seen[$key])) return false;
                                            $seen[$key] = true;
                                            return true;
                                        }));
                                            @endphp
                                            <div class="space-y-1">
                                                @foreach ($priceHour as $item)
                                                <div
                                                    class="text-primary font-bold text-lg {{ $item['is_full_day'] ? 'border-t pt-2 mt-2' : '' }}">
                                                    {{ number_format($item['price']) }}
                                                    <span class="text-md font-medium text-black">
                                                                    đ/{{ $item['display'] }}
                                                                </span>
                                                </div>
                                                @endforeach
                                            </div>
                                            @else
                                            <p class="text-gray-400 text-sm">Liên hệ để biết giá</p>
                                            @endif
                            </div>
                            <a href="{{ route('product.detail', ['slug' => $room->pslug]) }}" class="block w-full">
                                <button
                                                    class="w-full text-white text-sm font-semibold py-3 border border-borderGray px-6 rounded-full outline-none focus:ring-4 focus:ring-primary bg-primary text-borderGray hover:bg-borderGray hover:text-primary hover:border-primary">
                                                    Đặt phòng
                                                </button>
                            </a>
                        </div>
                    </div>
                </div>
                @endif
                @endforeach
                @else
                <p class="text-center text-gray-500">Không có sản phẩm nào trong danh mục này.</p>
                @endif
            </div>
        </div>
        @endforeach
    </div>
    @else
    <p class="text-center text-gray-500">Không khu vực nào</p>
    @endif

    <script>
        if (typeof window.roomCalendar === 'undefined') {
            window.roomCalendar = function(bookedRanges, productUrl, basePrice, discount, dayPrices, datePrices) {
                return {
                    startDate: null, endDate: null, hoveredDate: null,
                    viewYear: new Date().getFullYear(), viewMonth: new Date().getMonth(),
                    bookedRanges: bookedRanges || [], productUrl: productUrl,
                    basePrice: basePrice || 0, discount: discount || 0,
                    dayPrices: dayPrices || {}, datePrices: datePrices || {},
                    prevMonth() { if (this.viewMonth === 0) { this.viewMonth = 11; this.viewYear--; } else this.viewMonth--; },
                    nextMonth() { if (this.viewMonth === 11) { this.viewMonth = 0; this.viewYear++; } else this.viewMonth++; },
                    isoDate(y, m, d) { return y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0'); },
                    monthName() { return new Date(this.viewYear, this.viewMonth, 1).toLocaleDateString('vi-VN', { month: 'long', year: 'numeric' }); },
                    daysInMonth() { return new Date(this.viewYear, this.viewMonth + 1, 0).getDate(); },
                    firstDay() { var d = new Date(this.viewYear, this.viewMonth, 1).getDay(); return d === 0 ? 6 : d - 1; },
                    isToday(d) { var t = new Date(); return d === t.getDate() && this.viewMonth === t.getMonth() && this.viewYear === t.getFullYear(); },
                    isPast(d) { var cur = new Date(this.viewYear, this.viewMonth, d); var t = new Date(); t.setHours(0,0,0,0); return cur < t; },
                    isBooked(d) {
                        var iso = this.isoDate(this.viewYear, this.viewMonth, d);
                        for (var i = 0; i < this.bookedRanges.length; i++) {
                            if (iso >= this.bookedRanges[i].start && iso <= this.bookedRanges[i].end) return true;
                        }
                        return false;
                    },
                    isStart(d) { return !!this.startDate && this.isoDate(this.viewYear, this.viewMonth, d) === this.startDate; },
                    isEnd(d) { return !!this.endDate && this.isoDate(this.viewYear, this.viewMonth, d) === this.endDate; },
                    inRange(d) {
                        if (!this.startDate) return false;
                        var cur = this.isoDate(this.viewYear, this.viewMonth, d), end = this.endDate || this.hoveredDate;
                        return !!end && cur > this.startDate && cur < end;
                    },
                    priceForDate(isoStr) {
                        if (this.datePrices[isoStr] !== undefined) return this.datePrices[isoStr];
                        var dow = new Date(isoStr + 'T00:00:00').getDay();
                        if (this.dayPrices[dow] !== undefined && this.dayPrices[dow] > 0) return this.dayPrices[dow];
                        return this.discount > 0 ? Math.round(this.basePrice * (1 - this.discount / 100)) : this.basePrice;
                    },
                    selectDay(d) {
                        if (this.isPast(d) || this.isBooked(d)) return;
                        var iso = this.isoDate(this.viewYear, this.viewMonth, d);
                        if (!this.startDate || (this.startDate && this.endDate)) {
                            this.startDate = iso; this.endDate = null;
                        } else {
                            if (iso <= this.startDate) { this.startDate = iso; this.endDate = null; return; }
                            var blocked = false;
                            for (var i = 0; i < this.bookedRanges.length; i++) {
                                var r = this.bookedRanges[i];
                                if (r.start < iso && r.end > this.startDate) { blocked = true; break; }
                            }
                            if (blocked) { this.startDate = iso; this.endDate = null; }
                            else { this.endDate = iso; }
                        }
                    },
                    get nightCount() {
                        if (!this.startDate || !this.endDate) return 0;
                        return Math.round((new Date(this.endDate) - new Date(this.startDate)) / 86400000);
                    },
                    get totalPrice() {
                        if (!this.startDate || !this.endDate) return 0;
                        var total = 0, d = new Date(this.startDate + 'T00:00:00'), end = new Date(this.endDate + 'T00:00:00');
                        while (d < end) { total += this.priceForDate(d.toISOString().slice(0, 10)); d.setDate(d.getDate() + 1); }
                        return total;
                    },
                    hasPromo(d) {
                        if (this.isPast(d) || this.isBooked(d)) return false;
                        return this.datePrices[this.isoDate(this.viewYear, this.viewMonth, d)] !== undefined;
                    },
                    dayStyle(d) {
                        if (this.isStart(d) || this.isEnd(d)) return 'background:#4e6b4c;border-radius:50%;color:#fff;font-weight:700;';
                        if (this.inRange(d)) return 'background:#d4ead4;border-radius:0;color:#1f2937;';
                        if (this.isToday(d)) return 'box-shadow:inset 0 0 0 2px #4e6b4c;border-radius:50%;color:#4e6b4c;font-weight:700;';
                        if (this.isBooked(d) || this.isPast(d)) return 'color:#d1d5db;';
                        if (this.hasPromo(d)) return 'color:#ea580c;font-weight:600;';
                        return 'color:#374151;';
                    }
                };
            };
        }
    </script>

    <style>
        .tab-active-border {
            border-bottom: 6px solid rgb(239 68 68);
            /* primary */
        }

        .promo-badge-btn {
            position: relative;
            z-index: 1;
            background: #fff;
        }

        .promo-badge-btn::before {
            content: "";
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            border-radius: 9999px;
            background: linear-gradient(270deg,
                    #ff0000,
                    #ff9900,
                    #33ff00,
                    #00ffff,
                    #3300ff,
                    #ff00cc,
                    #ff0000);
            background-size: 300% 300%;
            animation: borderFlow 10s linear infinite;
            z-index: -1;
            filter: blur(5px);
        }

        .promo-badge-btn::after {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border-radius: 9999px;
            background: #fff;
            z-index: -1;
        }

        @keyframes borderFlow {
            0% {
                background-position: 0% 50%;
            }

            100% {
                background-position: 300% 50%;
            }
        }

        #sub-tab-all button[aria-selected="true"] {
            border-bottom-width: 2px !important;
        }

        .owl-nav {
            display: none !important;
        }

        .owl-stage-outer {
            padding-top: 10px;
        }

        .owl-carousel- {
                {
                $uniqueId
            }
        }


        .owl-nav .owl-prev,
        .owl-nav .owl-next {
            align-items: center;
            background: #fff;
            border: 2px solid var(--color-primary);
            border-radius: 50%;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            color: var(--color-primary);
            display: flex;
            height: 48px;
            justify-content: center;
            margin: 0;
            position: absolute;
            text-align: center;
            top: 40%;
            width: 48px;
            transition: background 0.2s, color 0.2s;
        }

        .owl-nav .owl-prev:hover,
        .owl-nav .owl-next:hover {
            background: var(--color-primary);
            color: #fff;
        }

        .owl-nav .owl-prev:hover svg *,
        .owl-nav .owl-next:hover svg * {
            stroke: #fff !important;
        }

        .owl-nav .owl-prev svg,
        .owl-nav .owl-next svg {
            display: block;
            transition: stroke 0.2s;
        }

        .owl-nav .owl-prev svg *,
        .owl-nav .owl-next svg * {
            stroke: var(--color-primary) !important;
            transition: stroke 0.2s;
        }

        .owl-nav .owl-prev {
            left: -58px;
        }

        .owl-nav .owl-next {
            right: -58px;
        }

        @media screen and (max-width: 768px) {
            .owl-nav .owl-prev {
                left: 4px;
                z-index: 10;
            }

            .owl-nav .owl-next {
                right: 4px;
                z-index: 10;
            }

            .owl-item {
                margin-right: 10px;
            }
        }

        .price-gradient {
            background: linear-gradient(90deg, #f43f5e, #ec4899);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .tab-scroll-container {
            scrollbar-width: none;
        }
        .tab-scroll-container::-webkit-scrollbar {
            display: none;
        }
    </style>
</div>
<script>
    $(document).ready(function() {

        // Tính perPage dựa trên window width — không phụ thuộc owl internal state
        // để tránh sai khi gọi lúc initialized (owl.settings chưa ready)
        function getPerPage() {
            var w = $(window).width();
            if (w >= 1200) return 4;
            if (w >= 992)  return 3;
            if (w >= 640)  return 2;
            return 1;
        }

        $('.owl-carousel-{{ $uniqueId }}').each(function() {
            var $carousel = $(this);
            var roomCount = parseInt($carousel.data('room-count')) || 0;
            var uid = $carousel.data('uid');
            var gIdx = $carousel.data('gindex');

            function updateNavState(event) {
                var $prev = $('.carousel-custom-prev-' + uid + '-' + gIdx);
                var $next = $('.carousel-custom-next-' + uid + '-' + gIdx);
                var info = event.item || {};
                var current = info.index !== undefined ? info.index : 0;
                // Dùng roomCount (PHP) — tránh event.item.count sai khi tab ẩn
                var total = roomCount;
                // Dùng getPerPage() — tránh owl.settings.items chưa ready lúc initialized
                var perPage = getPerPage();

                // Ẩn hoàn toàn nếu tất cả sản phẩm vừa 1 trang
                if (total <= perPage) {
                    $prev.addClass('hidden');
                    $next.addClass('hidden');
                    return;
                }
                $prev.removeClass('hidden').prop('disabled', false).removeClass('opacity-40 cursor-not-allowed').addClass('hover:bg-primary hover:text-white');
                $next.removeClass('hidden').prop('disabled', false).removeClass('opacity-40 cursor-not-allowed').addClass('hover:bg-primary hover:text-white');
            }

            // initialized: gọi ngay khi mount (fix initial render)
            // changed: gọi khi slide thay đổi
            // resized: gọi khi breakpoint thay đổi
            $carousel.on('initialized.owl.carousel changed.owl.carousel resized.owl.carousel refreshed.owl.carousel', function(event) {
                updateNavState(event);

                // Autoplay chỉ bật khi màn hình nhỏ (< 768px)
                if (roomCount > 1) {
                    if ($(window).width() < 768) {
                        $carousel.trigger('play.owl.autoplay', [2000]);
                    } else {
                        $carousel.trigger('stop.owl.autoplay');
                    }
                }

                var $prevBtn = $('.carousel-custom-prev-' + uid + '-' + gIdx);
                var $nextBtn = $('.carousel-custom-next-' + uid + '-' + gIdx);
                if (!$prevBtn.data('bound')) {
                    $prevBtn.data('bound', true).on('click', function() {
                        if (!$(this).prop('disabled')) $carousel.trigger('prev.owl.carousel');
                    });
                    $nextBtn.data('bound', true).on('click', function() {
                        if (!$(this).prop('disabled')) $carousel.trigger('next.owl.carousel');
                    });
                }
            });

            $carousel.owlCarousel({
                loop: roomCount > 1,
                responsiveClass: true,
                margin: 30,
                autoplay: false,
                autoplayTimeout: 2000,
                autoplayHoverPause: true,
                autoplaySpeed: 600,
                nav: false,
                responsive: {
                    0: {
                        items: 1,
                        margin: 12,
                        nav: roomCount > 1,
                        dots: true
                    },
                    480: {
                        items: 1,
                        margin: 16,
                        nav: roomCount > 1,
                        dots: true
                    },
                    640: {
                        items: 2,
                        margin: 16,
                        nav: roomCount > 2,
                        dots: true
                    },
                    768: {
                        items: 2,
                        margin: 20,
                        nav: roomCount > 2,
                        dots: true
                    },
                    992: {
                        items: 3,
                        margin: 24,
                        nav: roomCount > 3,
                        dots: false
                    },
                    1200: {
                        items: 4,
                        margin: 28,
                        nav: roomCount > 4,
                        dots: false
                    },
                    1400: {
                        items: 4,
                        margin: 30,
                        nav: roomCount > 4,
                        dots: false
                    },
                    1600: {
                        items: 4,
                        margin: 30,
                        nav: roomCount > 4,
                        dots: false
                    }
                }
            });
        });

        // Refresh carousel khi chuyển tab để tránh tính sai items/page trong tab ẩn
        $('#sub-tab-all-{{ $uniqueId }} [role="tab"]').on('click', function() {
            var targetId = $(this).data('tabs-target');
            setTimeout(function() {
                $(targetId + ' .owl-carousel-{{ $uniqueId }}').trigger('refresh.owl.carousel');
            }, 150);
        });
    });
</script>