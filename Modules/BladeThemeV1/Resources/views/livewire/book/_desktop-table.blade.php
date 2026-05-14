                {{-- ── DESKTOP: full table with all rooms (≥ md) ── --}}
                <div class="hidden md:block">
                <div class="overflow-x-auto max-h-[500px]"><table class="w-full text-[10px] text-center min-w-[700px] border-collapse">
                        <thead class="bg-gray-100 sticky top-0">
                            <tr>
                                <th colspan="2" class="py-1 px-2 min-w-[110px] border sticky-col-header">Chi
                                    nhánh</th>
                                <th colspan="24" class="py-1 px-2 min-w-[110px] border">
                                    Home - {{ $category['name'] }}, {{ $category['parent_name'] ?? '' }}
                                </th>
                            </tr>
                            <tr>
                                <th colspan="2" class="py-1 px-2 min-w-[110px] border sticky-col-header">Tên
                                    phòng</th>
                                @foreach ($category['products'] as $room)
                                @if(($room->styles ?? 1) == 1)
                                @php
                                $roomBgColor = $loop->index % 2 == 1 ? 'bg-yellow-100' : '';
                                $roomConfig = $productColors[$room->id] ?? null;
                                $bgColor = $roomConfig['color'] ?? '#f3f4f6';
                                $textColor = $roomConfig['color_text'] ?? '#333333';
                                @endphp
                                <th colspan="{{ $room->roomTimeSlots->count() }}" class="py-1 px-2 border"
                                    style="background-color: {{ $bgColor }}; color: {{ $textColor }}">
                                    {{ $room['name'] }}
                                </th>
                                @endif
                                @endforeach
                            </tr>

                            <tr>
                                <th class="py-1 px-2 border min-w-[45px] sticky-col-header sticky-col-thu">Thứ</th>
                                <th class="py-1 px-2 border min-w-[60px] sticky-col-header sticky-col-ngay">Ngày
                                </th>
                                @foreach ($category['products'] as $room)
                                @if(($room->styles ?? 1) == 1)
                                @php
                                $roomBgColor = $loop->index % 2 == 1 ? 'bg-yellow-100' : '';
                                $roomConfig = $productColors[$room->id] ?? null;
                                $bgColor = $roomConfig['color'] ?? '#f3f4f6';
                                $textColor = $roomConfig['color_text'] ?? '#333333';
                                @endphp
                                @foreach ($room->roomTimeSlots as $roomTimeSlot)
                                @php
                                $startTime = \Carbon\Carbon::parse($roomTimeSlot->timeSlot->start_time);
                                $endTime = \Carbon\Carbon::parse($roomTimeSlot->timeSlot->end_time);
                                $isOvernight = $endTime->isNextDay() || $endTime->lt($startTime);
                                @endphp
                                <th class="py-1 px-2 border min-w-[90px]"
                                    style="background-color: {{ $bgColor }}; color: {{ $textColor }};">
                                    {{ $startTime->format('H:i') }} - {{ $endTime->format('H:i') }}
                                    <br>
                                            @if ($isOvernight)
                                    <span class="text-xs" style="color: {{ $textColor }};">(Qua
                                                        đêm)</span>
                                    @else
                                    <svg class="w-4 h-4 inline" style="color: {{ $textColor }};"
                                        xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor"
                                        aria-hidden="true">
                                        <path
                                            d="M8 1a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 8 1ZM10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0ZM12.95 4.11a.75.75 0 1 0-1.06-1.06l-1.062 1.06a.75.75 0 0 0 1.061 1.062l1.06-1.061ZM15 8a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 15 8ZM11.89 12.95a.75.75 0 0 0 1.06-1.06l-1.06-1.062a.75.75 0 0 0-1.062 1.061l1.061 1.06ZM8 12a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 8 12ZM5.172 11.89a.75.75 0 0 0-1.061-1.062L3.05 11.89a.75.75 0 1 0 1.06 1.06l1.06-1.06ZM4 8a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 4 8ZM4.11 5.172A.75.75 0 0 0 5.173 4.11L4.11 3.05a.75.75 0 1 0-1.06 1.06l1.06 1.06Z">
                                        </path>
                                    </svg>
                                    @endif
                                </th>
                                @endforeach
                                @endif
                                @endforeach
                            </tr>
                        </thead>
                        @php
                        $current_time_str = now()->format('H:i:s');
                        $today = now()->startOfDay();
                        @endphp
                        <tbody>
                            @foreach ($this->getDatesForOneMonth() as $date)
                            <tr class="border-t">
                                <td
                                    class="py-1 border-2 sticky-col sticky-col-thu {{ $date['is_today'] ? 'text-primary' : '' }}">
                                    {{ $date['day'] }}
                                </td>
                                <td
                                    class="py-1 border-2 sticky-col sticky-col-ngay {{ $date['is_today'] ? 'text-primary' : '' }}">
                                    {{ $date['date'] }}
                                </td>
                                @foreach ($category['products'] as $room)
                                @if(($room->styles ?? 1) == 1)
                                @php
                                $roomBgColor = $loop->index % 2 == 1 ? 'bg-yellow-100' : '';
                                $roomConfig = $productColors[$room->id] ?? null;
                                $bgColor = $roomConfig['color'] ?? '#f3f4f6';
                                $textColor = $roomConfig['color_text'] ?? '#333333';
                                @endphp

                                @foreach ($room->roomTimeSlots as $roomTimeSlot)
                                @php
                                // --- 1. Khởi tạo biến ---
                                $price = $roomTimeSlot->price ?? 0;
                                $classes = '';
                                $isSelectable = true;
                                $finalPrice = $price;

                                $currentDateTime = \Carbon\Carbon::createFromFormat(
                                'd-m-Y H:i:s',
                                $date['date'] . ' ' . $roomTimeSlot->timeSlot->start_time,
                                );

                                // --- 2. Logic trạng thái ---
                                $status = 'available';
                                $matchedItem = null;

                                foreach ($room->orderItems as $orderItem) {
                                $checkin = \Carbon\Carbon::parse($orderItem->checkin_date);
                                $checkout = \Carbon\Carbon::parse($orderItem->checkout_date);

                                if ($currentDateTime->between($checkin, $checkout)) {
                                if ($orderItem->order) {
                                $status = $orderItem->order->status;
                                }
                                $matchedItem = $orderItem;
                                break;
                                }
                                }

                                if ($status === 'pending') {
                                $classes .= ' pending';
                                $isSelectable = false;
                                } elseif (in_array($status, ['paid', 'shipped', 'confirmed'])) {
                                $classes .= ' booked';
                                $isSelectable = false;
                                }

                                
                                $orderColor = null;
                                if ($matchedItem) {
                                    if (in_array($status, ['paid', 'shipped', 'confirmed'])) {
                                    $orderColor = '#4e6b4c';
                                    } elseif ($status === 'deposit') {
                                    $orderColor = '#3b82f6';
                                    } elseif ($status === 'pending') {
                                    $orderColor = '#f97316';
                                    } else {
                                    $orderColor = '#94a3b8';
                                    }
                                }

                                $slotDate = \Carbon\Carbon::createFromFormat('d-m-Y', $date['date'])->startOfDay();
                                $yesterday = now()->subDay()->startOfDay();
                                $cutoffTime = now()->startOfDay()->setTime(7, 30, 0);
                                
                                if ($slotDate->lt($yesterday)) {
                                $isSelectable = false;
                                $classes .= ' past-date';
                                } elseif ($slotDate->eq($yesterday)) {
                                if (now()->gte($cutoffTime)) {
                                $isSelectable = false;
                                $classes .= ' past-date';
                                }
                                } elseif ($slotDate->eq($today)) {
                                // Nếu là hôm nay, kiểm tra end_time của khung giờ đã qua chưa
                                $slotEndTimeParsed = \Carbon\Carbon::parse($roomTimeSlot->timeSlot->end_time);
                                $isOvernightSlot = $slotEndTimeParsed->lt(\Carbon\Carbon::parse($roomTimeSlot->timeSlot->start_time));
                                $slotEndDateTime = $slotDate->copy()->setTime(
                                $slotEndTimeParsed->hour,
                                $slotEndTimeParsed->minute,
                                $slotEndTimeParsed->second
                                );
                                if ($isOvernightSlot) {
                                $slotEndDateTime->addDay();
                                }
                                if (now()->gte($slotEndDateTime)) {
                                $isSelectable = false;
                                $classes .= ' past-date';
                                }
                                }


                                $rtsSettings = is_array($roomTimeSlot->settings)
                                ? $roomTimeSlot->settings
                                : (json_decode($roomTimeSlot->settings, true) ?? []);

                                $blockedDates = $rtsSettings['blocked_dates'] ?? [];
                                $slotDateYmd = \Carbon\Carbon::createFromFormat('d-m-Y', $date['date'])->toDateString();

                                if (in_array($slotDateYmd, $blockedDates)) {
                                $isSelectable = false;
                                $classes .= ' blocked';
                                }

                                // --- 3. Tính giá với promotion ---
                                $slotStartTime =
                                \Carbon\Carbon::parse($roomTimeSlot->timeSlot->start_time)->format('H:i:s');
                                $priceData = $this->calculateSlotPrice($roomTimeSlot, $date['date'], $slotStartTime);

                                $finalPrice = $priceData['final_price'];
                                $originalPrice = $priceData['original_price'];
                                $totalDiscount = $priceData['total_discount'];
                                $hasPromotion = $priceData['has_promotion'];
                                $isIncrease = $priceData['is_increase'];
                                $activePromotions = $priceData['promotions'] ?? [];

                                // Tách riêng promotion giảm và tăng
                                $hasDiscountPromotion = false;
                                $hasIncreasePromotion = false;
                                $discountPromotions = [];
                                $increasePromotions = [];

                                foreach ($activePromotions as $promo) {
                                if (in_array($promo->type, ['percentage', 'fixed'])) {
                                $hasDiscountPromotion = true;
                                $discountPromotions[] = $promo;
                                }
                                if (in_array($promo->type, ['increase_percentage', 'increase_fixed'])) {
                                $hasIncreasePromotion = true;
                                $increasePromotions[] = $promo;
                                }
                                }


                                $showPromotion = $hasDiscountPromotion;

                                if ($hasDiscountPromotion) {
                                $classes .= ' promo';
                                }
                                if ($hasIncreasePromotion && !$hasDiscountPromotion) {
                                $classes .= ' promo-increase';
                                }

                                // Chuẩn bị data promotions - tách riêng discount và increase
                                $discountPromotionsData = collect($discountPromotions)->map(function($p) use
                                ($originalPrice, $priceData) {
                                $amount = 0;
                                if ($p->type === 'percentage') {
                                $amount = ($originalPrice + $priceData['increase_amount']) * ($p->value / 100);
                                } elseif ($p->type === 'fixed') {
                                $amount = $p->value;
                                }
                                return [
                                'name' => $p->name,
                                'type' => $p->type,
                                'value' => $p->value,
                                'amount' => $amount,
                                'lable_client' => $p->lable_client ?? null,
                                'image' => $p->image ?? null
                                ];
                                })->toArray();

                                $increasePromotionsData = collect($increasePromotions)->map(function($p) use
                                ($originalPrice) {
                                $amount = 0;
                                if ($p->type === 'increase_percentage') {
                                $amount = $originalPrice * ($p->value / 100);
                                } elseif ($p->type === 'increase_fixed') {
                                $amount = $p->value;
                                }
                                return [
                                'name' => $p->name,
                                'type' => $p->type,
                                'value' => $p->value,
                                'amount' => $amount,
                                'lable_client' => $p->lable_client ?? null,
                                'image' => $p->image ?? null
                                ];
                                })->toArray();
                                $displayPromotion = $increasePromotions[0] ?? null;
                                $displayDiscountPromotion = $discountPromotionsData[0] ?? null;
                                @endphp

                                <td class="border-2 p-1.5 relative overflow-visible"
                                    style="background-color: {{ $bgColor }}; color: {{ $textColor }};">
                                    <div class="selectable {{ $classes }}"
                                        style="{{ !$isSelectable ? 'pointer-events:none;opacity:0.6;' : 'cursor:pointer;' }}{{ $orderColor ? '--order-color:' . $orderColor . ';' : '' }}"
                                        @click="toggleSlot($el, {
                                                        date: '{{ $date['date'] }}',
                                                        startTime: '{{ $roomTimeSlot->timeSlot->start_time }}',
                                                        endTime: '{{ $roomTimeSlot->timeSlot->end_time }}',
                                                        timeslotId: '{{ $roomTimeSlot->timeSlot->id }}',
                                                        roomId: '{{ $room->id }}',
                                                        price: {{ $finalPrice }},
                                                        originalPrice: {{ $priceData['price_after_increase'] }},
                                                        basePrice: {{ $originalPrice }},
                                                        increaseAmount: {{ $priceData['increase_amount'] ?? 0 }},
                                                        promoDiscount: {{ $totalDiscount }},
                                                        hasDiscount: {{ $hasDiscountPromotion ? 'true' : 'false' }},
                                                        hasIncrease: {{ $hasIncreasePromotion ? 'true' : 'false' }},
                                                        isIncrease: {{ $isIncrease ? 'true' : 'false' }},
                                                        is_activated: {{ $room->is_activated ? 'true' : 'false' }},
                                                        overNight: {{ $roomTimeSlot->over_night ?? 0 }},
                                                        totalSlotsInRoom: {{ $room->roomTimeSlots->count() }},
                                                        fullBookingDiscountValue: '{{ $room->full_booking_discount }}',
                                                        discountPromotions: {{ json_encode($discountPromotionsData) }},
                                                        increasePromotions: {{ json_encode($increasePromotionsData) }}
                                                    })">
                                        {{-- Hình ảnh promotion ở góc trên bên phải --}}
                                        @if ($hasIncreasePromotion && $displayPromotion && $displayPromotion->image)
                                        <div class="promotion-corner-image">
                                            <img src="{{ asset('storage/' . $displayPromotion->image) }}"
                                                                 alt="{{ $displayPromotion->name }}"
                                                                 class="corner-img">
                                        </div>
                                        @endif

                                        {{-- Label ở giữa ô (text thuần với emoji/ký tự đặc biệt) --}}
                                        @if ($hasIncreasePromotion && $displayPromotion &&
                                        $displayPromotion->lable_client)
                                        <div class="promotion-center-label">
                                            {!! $displayPromotion->lable_client !!}
                                        </div>
                                        @endif

                                        {{-- Hình ảnh discount promotion ở góc (chỉ hiện nếu không có increase promotion) --}}
                                        @if ($hasDiscountPromotion && !$hasIncreasePromotion && $displayDiscountPromotion && !empty($displayDiscountPromotion['image']))
                                        <div class="promotion-corner-image">
                                            <img src="{{ asset('storage/' . $displayDiscountPromotion['image']) }}"
                                                 alt="{{ $displayDiscountPromotion['name'] }}"
                                                 class="corner-img">
                                        </div>
                                        @endif

                                        {{-- Label discount promotion ở giữa ô (chỉ hiện nếu không có increase promotion) --}}
                                        @if ($hasDiscountPromotion && !$hasIncreasePromotion && $displayDiscountPromotion && !empty($displayDiscountPromotion['lable_client']))
                                        <div class="promotion-center-label">
                                            {!! $displayDiscountPromotion['lable_client'] !!}
                                        </div>
                                        @endif

                                        @if ($showPromotion)
                                        <span class="font-bold text-[11px] leading-tight relative z-20 text-red-600">
                                                            <!-- {{ number_format($finalPrice / 1000, 0, ',', '.') }}K -->
                                                        </span>
                                        @endif
                                    </div>
                                </td>
                                @endforeach
                                @endif
                                @endforeach
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>{{-- end overflow-x-auto --}}
                </div>{{-- end hidden md:block --}}
