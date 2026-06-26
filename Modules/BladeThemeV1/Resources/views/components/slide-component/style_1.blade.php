<div class="max-w-screen-xl md:px-8 px-4 mx-auto">
    @if ($parentCategory)
    <h2 class="mt-4 mb-2 text-center text-4xl font-bold">Điểm đến</h2>
    <h5 class="mb-4 text-center text-2xl text-primary font-bold">
        {{ $parentCategory->name }}
    </h5>
    @endif

    @if ($grandChildCategories->flatten()->isNotEmpty())
    <div class="mb-6 border-b border-primary">
        <!-- Tabs cho danh mục cấp 3 -->
        <ul class="flex flex-wrap items-center justify-center -mb-px text-lg font-bold text-center"
            id="sub-tab-{{ $uniqueId }}" data-tabs-toggle="#sub-tab-content-{{ $uniqueId }}"
            data-tabs-active-classes="text-primary hover:text-primary border border-gray-300 border-b-white"
            data-tabs-inactive-classes="text-gray-600 hover:text-gray-800 border-transparent" role="tablist">
            @php
            $flattenedGrandChildren = $grandChildCategories->flatten();
            @endphp
            @foreach ($flattenedGrandChildren as $gIndex => $grandChild)
            <li role="presentation">
                <button
                                class="inline-block p-3 rounded-t-lg {{ $gIndex === 0 ? 'text-primary border-gray-300 border-b-white' : 'text-gray-600 border-transparent' }}"
                                data-tabs-target="#sub-styled-{{ $grandChild->id }}" type="button" role="tab"
                                aria-controls="sub-tab-{{ $grandChild->id }}"
                                aria-selected="{{ $gIndex === 0 ? 'true' : 'false' }}">
                            {{ $grandChild->name }}
                        </button>
            </li>
            @endforeach
        </ul>
    </div>

    <!-- Nội dung các tab cấp 3 -->
    <div id="sub-tab-content-{{ $uniqueId }}">
        @foreach ($flattenedGrandChildren as $gIndex => $grandChild)
        <div class="{{ $gIndex === 0 ? '' : 'hidden' }} rounded-lg" id="sub-styled-{{ $grandChild->id }}"
            role="tabpanel" aria-labelledby="sub-tab-{{ $grandChild->id }}">

            @php
            $child = $childCategories->firstWhere('id', $grandChild->parent_id);
            $grandChildRooms = $rooms->filter(function ($room) use ($grandChild) {
            return in_array($grandChild->id, array_unique(explode(',', $room->cate_id ?? '')));
            });
            $roomCount = $grandChildRooms->count(); // Lấy số lượng sản phẩm
            @endphp

            <h2 class="mt-4 mb-3 text-center text-3xl font-bold">
                Home - {{ $grandChild->name }}, {{ $child->name ?? '' }}
            </h2>

            <div class="owl-carousel owl-carousel-{{ $uniqueId }} {{ $this->style_1 ? 'style-lv1' : '' }}"
                data-room-count="{{ $roomCount }}">
                @if ($grandChildRooms->isNotEmpty())
                @foreach ($grandChildRooms as $room)
                <div class="bg-white rounded-2xl overflow-hidden border border-gray-200 flex flex-col">
                    <!-- Hình ảnh -->
                    <a class="relative block pt-[60%] overflow-hidden"
                        href="{{ route('product.detail', ['slug' => $room->pslug]) }}">
                        <img src="{{ asset('storage/' . $room->media_file) }}"
                                                 alt="{{ $room->pname }}"
                                                 class="w-full h-full object-cover top-0 bottom-0 left-0 right-0 absolute transition hover:opacity-80" />
                        <div
                            class="absolute bottom-0 left-0 right-0 h-20 bg-gradient-to-t from-primary to-transparent flex items-end justify-center text-white text-md font-medium pb-2">
                            Home - {{ $grandChild->name }},
                            {{ $child->name ?? '' }}
                        </div>
                    </a>

                    <!-- Nội dung -->
                    <div class="p-5">
                        @if (($room->styles ?? 1) == 2)
                        {{-- styles=2: Compact booking calendar --}}
                        @php
                        $s1Price = (float)($room->price ?? 0);
                        $s1Disc = (float)($room->discount ?? 0);
                        $s1DisplayPrice = $s1Disc > 0 ? number_format(round($s1Price * (1 - $s1Disc / 100))) :
                        number_format((int)$s1Price);
                        $s1Url = route('product.detail', ['slug' => $room->pslug]);
                        $s1Booked = collect($room->orderItems ?? [])->map(fn($it) => [
                        'start' => \Carbon\Carbon::parse($it->checkin_date)->format('Y-m-d'),
                        'end' => \Carbon\Carbon::parse($it->checkout_date)->format('Y-m-d'),
                        ])->values()->toArray();
                        $s1DayMap = ['mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6,'sun'=>0];
                        $s1DayPrices = [];
                        foreach ($room->day_prices ?? [] as $k => $v) {
                        if (isset($s1DayMap[$k]) && (int)$v > 0) $s1DayPrices[$s1DayMap[$k]] = (int)$v;
                        }
                        $s1DatePrices = [];
                        foreach ($room->roomTimeSlots ?? [] as $rts) {
                        if ($rts->timeSlot && ($rts->timeSlot->type ?? '') === 'date' && !empty($rts->timeSlot->label))
                        {
                        $s1DatePrices[$rts->timeSlot->label] = (int)$rts->price;
                        }
                        }
                        @endphp
                        {{-- Name + Price row --}}
                        <div class="flex items-start justify-between gap-1 mb-1">
                            <h2 class="text-lg font-bold text-black line-clamp-1 leading-tight">{{ $room->pname }}</h2>
                            <div class="text-right shrink-0 leading-none">
                                <span class="text-base font-bold" style="color:#4e6b4c">{{ $s1DisplayPrice }}đ</span><br>
                                <span class="text-[10px] text-gray-400">/đêm</span>
                            </div>
                        </div>
                        <div class="text-[11px] text-gray-400 mb-2 line-clamp-1">{!! $room->psd !!}</div>
                        {{-- Always-visible calendar --}}
                        <div
                            x-data="roomCalendar({{ Js::from($s1Booked) }}, '{{ $s1Url }}', {{ (int)$s1Price }}, {{ (int)$s1Disc }}, {{ Js::from($s1DayPrices) }}, {{ Js::from($s1DatePrices) }})">
                            <div class="flex items-center justify-between mb-2">
                                <button type="button" @click="prevMonth()" class="w-7 h-7 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-400">
                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                                                        </button>
                                <span class="text-xs font-semibold text-gray-700" x-text="monthName()"></span>
                                <button type="button" @click="nextMonth()" class="w-7 h-7 flex items-center justify-center rounded-full hover:bg-gray-100 text-gray-400">
                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                                        </button>
                            </div>
                            <div class="grid grid-cols-7 mb-1">
                                <template x-for="lbl in ['T2','T3','T4','T5','T6','T7','CN']">
                                    <div class="text-center text-[10px] font-semibold text-gray-400" x-text="lbl"></div>
                                </template>
                            </div>
                            <div class="grid grid-cols-7 gap-y-0.5">
                                <template x-for="n in firstDay()">
                                    <div></div>
                                </template>
                                <template x-for="d in daysInMonth()" :key="'sd1-'+d">
                                    <button type="button"
                                                                :disabled="isPast(d) || isBooked(d)"
                                                                @click="selectDay(d)"
                                                                @mouseenter="!isPast(d) && !isBooked(d) && (hoveredDate = isoDate(viewYear, viewMonth, d))"
                                                                @mouseleave="hoveredDate = null"
                                                                class="w-full aspect-square flex items-center justify-center text-[11px] font-semibold select-none transition-all duration-100"
                                                                :class="{'opacity-30 cursor-not-allowed': isPast(d) || isBooked(d)}"
                                                                :style="dayStyle(d)">
                                                                <span class="flex flex-col items-center leading-none gap-[1px]">
                                                                    <span x-text="d"></span>
                                                                    <span x-show="hasPromo(d)" class="w-1 h-1 rounded-full" style="background:#f97316;"></span>
                                                                </span>
                                                            </button>
                                </template>
                            </div>
                            <div class="flex items-center justify-between border-t border-gray-100 pt-1.5 mt-1.5 gap-2">
                                <div class="text-[11px] text-gray-400 italic flex-1 min-w-0 truncate">
                                    <span x-show="!startDate">Nhấn chọn ngày check-in</span>
                                    <span x-show="startDate && !endDate" x-cloak>Chọn ngày kết thúc...</span>
                                    <span x-show="startDate && endDate" x-cloak x-text="nightCount + ' đêm · ' + totalPrice.toLocaleString('vi-VN') + 'đ'"></span>
                                </div>
                                <button type="button"
                                                            @click="if (startDate && endDate) { try { sessionStorage.setItem('booking_preselect', JSON.stringify({checkin: startDate, checkout: endDate})); } catch(e){} window.location.href = productUrl; } else { window.location.href = productUrl; }"
                                                            class="shrink-0 text-white text-xs font-bold px-4 py-1.5 rounded-full"
                                                            style="background:#4e6b4c">
                                                            Đặt ngay
                                                        </button>
                            </div>
                        </div>
                        @else
                        {{-- styles=1: Tiện ích + Giá + Button --}}
                        <h2 class="text-start text-2xl font-bold text-black mb-1 line-clamp-1">{{ $room->pname }}</h2>
                        <div class="text-start text-sm font-medium text-gray-400 mb-4 line-clamp-1">{!! $room->psd !!}
                        </div>
                        <div class="grid grid-cols-7 gap-2 mb-4 border-b border-gray-200 pb-4">
                            @if (!empty($room->tag_image) && !empty($room->tag_name))
                            @php
                            $tagImages = array_filter(array_map('trim', explode(',', $room->tag_image)));
                            $tagNames = array_filter(array_map('trim', explode(',', $room->tag_name)));
                            $minLength = min(count($tagImages), count($tagNames));
                            $tags = [];
                            for ($i = 0; $i < $minLength; $i++) { $tags[]=['image'=> $tagImages[$i], 'name' =>
                                $tagNames[$i]];
                                }
                                @endphp
                                @foreach ($tags as $tag)
                                <div class="flex items-center justify-center p-2 bg-gray-50 w-10 h-10 rounded-full cursor-default border border-gray-100">
                                    <img src="{{ asset('storage/' . $tag['image']) }}" alt="{{ $tag['name'] }}" class="w-6 h-6 object-contain filter">
                                </div>
                                @endforeach
                                @endif
                        </div>
                        <div class="flex items-end justify-between">
                            <div class="text-start min-w-[60%]">
                                <p class="font-bold">GIÁ PHÒNG</p>
                                @if (!empty($room->time_price_pairs))
                                @php
                                $pairs = array_map('trim', explode(',', $room->time_price_pairs));
                                $priceHour = [];
                                foreach ($pairs as $pair) {
                                [$hour, $price] = explode(':', $pair);
                                if ($hour == 3 || $hour < 0) { $priceHour[]=['hour'=> $hour, 'price' => $price];
                                    }
                                    }
                                    usort($priceHour, fn($a, $b) => ($a['hour'] == 3 && $b['hour'] < 0) ? -1 :
                                        (($a['hour'] < 0 && $b['hour']==3) ? 1 : 0)); @endphp @foreach ($priceHour as $item) <div class="text-primary font-bold text-lg">{{
                                        number_format($item['price'])
                                        }}<span class="text-md font-medium text-black">đ/{{ $item['hour'] < 0 ? 'đêm' : $item['hour'] . 'h' }}</span>
                            </div>
                            @endforeach
                            @endif
                        </div>
                        <a href="{{ route('product.detail', ['slug' => $room->pslug]) }}" class="block w-full">
                            <button class="w-full text-white text-sm font-semibold py-3 border border-borderGray px-6 rounded-full outline-none focus:ring-4 focus:ring-primary bg-primary text-borderGray hover:bg-borderGray hover:text-primary hover:border-primary">Đặt phòng</button>
                        </a>
                    </div>
                    @endif
                </div>
            </div>
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
</div>
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

    $(document).ready(function() {
        $('.owl-carousel-{{ $uniqueId }}.style-lv1').each(function() {
            var $carousel = $(this);
            var roomCount = parseInt($carousel.data('room-count')) || 0;

            $carousel.owlCarousel({
                loop: false,
                responsiveClass: true,
                margin: 30,
                autoplay: false,
                autoplayTimeout: 3000,
                nav: roomCount > 3,
                responsive: {
                    0: {
                        items: 1,
                        nav: roomCount > 1
                    },
                    768: {
                        items: 2,
                        nav: roomCount >
                            2
                    },
                    1000: {
                        items: 3,
                        nav: roomCount > 3
                    }
                }
            });
        });
    });
</script>