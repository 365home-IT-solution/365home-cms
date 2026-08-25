<div>

{{-- ===== VIEW MỚI: Phòng theo loại roomtype (carousel) ===== --}}
@if (!empty($searchSections))
    @foreach ($searchSections as $sIndex => $section)
    @php $trackId = 'pt-' . $sIndex; @endphp
    <section class="w-full mx-auto">
        <div style="max-width:80rem; margin:0 auto; padding:0 1.25rem;">

            {{-- Header --}}
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <h2 style="font-size:1.15rem; font-weight:800; color:#111827; text-transform:uppercase; letter-spacing:.02em; margin:0;">
                        {{ $section['title'] }}
                    </h2>
                    {{-- <span style="font-size:12px; color:#9ca3af;">({{ $section['count'] }} phòng)</span> --}}
                </div>
                <a href="{{ route('product.search') }}?type={{ $section['slug'] ?? '' }}"
                    style="font-size:13px; font-weight:600; color:#0f766e; text-decoration:none; display:flex; align-items:center; gap:3px; white-space:nowrap; flex-shrink:0;">
                    Xem tất cả
                    <svg style="width:14px;height:14px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>

            {{-- Carousel wrapper --}}
            <div style="position:relative;">

                {{-- Nút Prev --}}
                <button onclick="document.getElementById('{{ $trackId }}').scrollBy({left:-880,behavior:'smooth'})"
                    style="position:absolute; left:-14px; top:50%; transform:translateY(-60%); z-index:10; width:34px; height:34px; border-radius:50%; background:#fff; border:1.5px solid #e5e7eb; box-shadow:0 2px 8px rgba(0,0,0,.12); display:flex; align-items:center; justify-content:center; cursor:pointer; color:#374151;">
                    <svg style="width:16px;height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>

                {{-- Nút Next --}}
                <button onclick="document.getElementById('{{ $trackId }}').scrollBy({left:880,behavior:'smooth'})"
                    style="position:absolute; right:-14px; top:50%; transform:translateY(-60%); z-index:10; width:34px; height:34px; border-radius:50%; background:#fff; border:1.5px solid #e5e7eb; box-shadow:0 2px 8px rgba(0,0,0,.12); display:flex; align-items:center; justify-content:center; cursor:pointer; color:#374151;">
                    <svg style="width:16px;height:16px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>

                {{-- Scroll track --}}
                <div id="{{ $trackId }}"
                    style="display:flex; gap:16px; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none; padding-bottom:6px;">
                    <style>#{{ $trackId }}::-webkit-scrollbar{display:none}</style>

                    @foreach ($section['products'] as $room)
                    @php
                        $grandChild = $room->display_grandchild ?? null;
                        $child      = $room->display_child ?? null;

                        $lowestPrice = null;
                        $lowestUnit  = '';
                        if (!empty($room->time_price_pairs)) {
                            foreach (array_map('trim', explode(',', $room->time_price_pairs)) as $pair) {
                                if (strpos($pair, ':') === false) continue;
                                [$tv, $pr] = explode(':', $pair, 2);
                                $tv = floatval($tv);
                                $pr = floatval(str_replace([',','.'], '', trim($pr)));
                                if ($tv == 999 || $tv < 0 || $tv == 0 || $pr <= 0) continue;
                                if ($lowestPrice === null || $pr < $lowestPrice) {
                                    $lowestPrice = $pr;
                                    $hours = floor($tv); $mins = round(($tv - $hours) * 100);
                                    $lowestUnit = $mins > 0 ? "{$hours}h{$mins}" : "{$hours}h";
                                }
                            }
                        }
                    @endphp

                    {{-- Card kiểu Airbnb --}}
                    <a href="{{ route('product.detail', ['slug' => $room->pslug]) }}"
                        style="flex:0 0 220px; scroll-snap-align:start; display:flex; flex-direction:column; gap:9px; text-decoration:none;">

                        {{-- Ảnh --}}
                        <div style="position:relative; padding-top:72%; overflow:hidden; background:#f3f4f6; border-radius:14px; flex-shrink:0;">
                            @if ($room->media_file)
                                <img src="{{ asset('storage/' . $room->media_file) }}"
                                    alt="{{ $room->pname }}"
                                    loading="lazy"
                                    style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover; transition:transform .35s;"
                                    onmouseover="this.style.transform='scale(1.04)'"
                                    onmouseout="this.style.transform='scale(1)'" />
                            @else
                                <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center;">
                                    <svg style="width:2rem;height:2rem;color:#d1d5db;" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                            d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                </div>
                            @endif
                        </div>

                        {{-- Text: 2 hàng --}}
                        <div style="padding:0 2px; display:flex; flex-direction:column; gap:3px;">
                            {{-- Hàng 1: Tên phòng --}}
                            <p style="font-size:13px; font-weight:600; color:#111827; margin:0; overflow:hidden; white-space:nowrap; text-overflow:ellipsis;">
                                {{ $room->pname }}
                            </p>
                            {{-- Hàng 2: Giá + số sao --}}
                            <div style="display:flex; align-items:center; justify-content:space-between; gap:6px;">
                                <p style="font-size:13px; color:#111827; margin:0; white-space:nowrap;">
                                    @if ($lowestPrice)
                                        <span style="font-weight:700;">{{ number_format($lowestPrice) }}đ</span><span style="color:#9ca3af; font-size:11px;"> /{{ $lowestUnit }}</span>
                                    @else
                                        <span style="font-weight:700;">Liên hệ</span>
                                    @endif
                                </p>
                                @if ($room->rating_score > 0)
                                <span style="font-size:12px; color:#374151; white-space:nowrap; display:flex; align-items:center; gap:2px; flex-shrink:0;">
                                    <svg style="width:12px;height:12px;color:#f59e0b;flex-shrink:0;" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                    </svg>
                                    {{ number_format($room->rating_score, 1) }}
                                    @if ($room->ratings_count > 0)
                                        <span style="color:#9ca3af;">({{ $room->ratings_count }})</span>
                                    @endif
                                </span>
                                @endif
                            </div>
                        </div>
                    </a>
                    @endforeach

                </div>{{-- end scroll track --}}
            </div>{{-- end carousel wrapper --}}
        </div>
    </section>
    @endforeach

    <div style="height:28px;"></div>

@else
    <div style="padding:4rem 1rem; text-align:center;">
        <p style="color:#6b7280; font-size:15px;">Chưa có phòng nào.</p>
    </div>
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
