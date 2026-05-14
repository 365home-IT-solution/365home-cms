<div wire:ignore class="w-full mx-auto px-8">
    <h2 class="mt-4 mb-2 text-center text-4xl font-bold">Điểm đến</h2>
    @if($parentCategory)
        <h5 class="mb-4 text-center text-rose-500 text-2xl font-bold">{{ $parentCategory->name }}</h5>
    @endif

    @if(!empty($categoryData))
        <div class="mb-4 border-b border-primary">
            <ul class="flex flex-wrap items-center justify-center -mb-px text-lg font-bold text-center"
                id="default-styled-tab" data-tabs-toggle="#default-styled-tab-content"
                data-tabs-active-classes="text-primary hover:text-primary border border-gray-300 border-b-white"
                data-tabs-inactive-classes="" role="tablist">
                @foreach($categoryData as $index => $category)
                    <li role="presentation">
                        <button class="inline-block p-4 rounded-t-lg"
                                data-tabs-target="#styled-{{ $category['id'] }}"
                                type="button"
                                role="tab"
                                aria-controls="styled-{{ $category['id'] }}"
                                aria-selected="{{ $index === 0 ? 'true' : 'false' }}">
                            {{ $category['name'] }}
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>

        <div id="default-styled-tab-content">
            @php
                $branchIdArray = array_column($categoryData, 'id');
                $roomTimeSlots = $this->getRoomTimeSlotsByMultipleBranches($branchIdArray);
                // Group room time slots by category (c3)
                $groupedByCategory = [];
                foreach ($roomTimeSlots as $room) {
                    $categoryName = $room['c3'];
                    if (!isset($groupedByCategory[$categoryName])) {
                        $groupedByCategory[$categoryName] = [];
                    }
                    $groupedByCategory[$categoryName][] = $room;
                }
            @endphp

            @foreach($categoryData as $index => $category)
                <div class="hidden px-4 pb-10 relative" id="styled-{{ $category['id'] }}" role="tabpanel" aria-labelledby="styled-{{ $category['id'] }}-tab">
                    @php
                        $categoryRoomTimeSlots = $groupedByCategory[$category['name']] ?? [];
                    @endphp
                    <div x-data="{
                            selectedSlots: @entangle('selectedSlots'),
                            toggleSlot(date, timeslotId, price, promoPrice, promoType, startTime, endTime, status, roomId, roomName, timeslotLabel) {
                                // Nếu slot đã đặt thì không cho chọn
                                if (status === 'booked') return;

                                const key = `${date}-${roomId}-${timeslotId}`;
                                const index = this.selectedSlots.findIndex(slot => slot.key === key);

                                if (index > -1) {
                                    this.selectedSlots.splice(index, 1);
                                } else {
                                    // Nếu đã chọn slot phòng khác thì không cho chọn thêm
                                    const hasDifferentRoomSelected = this.selectedSlots.length > 0 &&
                                        this.selectedSlots.some(slot => slot.roomId !== roomId);
                                    if (hasDifferentRoomSelected) return;

                                    // Chặn chọn khung giờ không liền kề trong 1 ngày
                                    const slotsInSameDay = this.selectedSlots
                                        .filter(slot => slot.date === date && slot.roomId === roomId)
                                        .sort((a, b) => parseInt(a.timeslotId) - parseInt(b.timeslotId));

                                    if (slotsInSameDay.length > 0) {
                                        const ids = slotsInSameDay.map(slot => parseInt(slot.timeslotId)).sort((a, b) => a - b);
                                        const newId = parseInt(timeslotId);

                                        const min = ids[0];
                                        const max = ids[ids.length - 1];

                                        const validNext = newId === min - 1 || newId === max + 1;
                                        if (!validNext) return;
                                    }

                                    this.selectedSlots.push({
                                        key,
                                        date,
                                        startTime,
                                        endTime,
                                        price: parseFloat(price),
                                        promoPrice: parseFloat(promoPrice) || 0,
                                        promoType: promoType || '',
                                        timeslotId,
                                        roomId,
                                        roomName,
                                        timeslotLabel
                                    });
                                }

                                @this.set('selectedSlots', this.selectedSlots);
                                this.calculateTotal();
                            },
                            calculateTotal() {
                                if (this.selectedSlots.length === 0) {
                                    this.totalAmount = 0;
                                    this.discountAmount = 0;
                                    this.startTime = '';
                                    this.endTime = '';
                                    return;
                                }

                                const sortedSlots = this.selectedSlots.sort((a, b) =>
                                    new Date(`${a.date} ${a.startTime}`) - new Date(`${b.date} ${b.startTime}`)
                                );

                                this.startTime = new Date(`${sortedSlots[0].date} ${sortedSlots[0].startTime}`).toISOString().slice(0, 16);
                                this.endTime = new Date(`${sortedSlots[sortedSlots.length - 1].date} ${sortedSlots[sortedSlots.length - 1].endTime}`).toISOString().slice(0, 16);

                                let originalTotal = 0;
                                let finalTotal = 0;

                                this.selectedSlots.forEach(slot => {
                                    const price = slot.price;
                                    const promoPrice = slot.promoPrice;
                                    const promoType = slot.promoType;

                                    let finalPrice = price;
                                    if (promoType === 'fixed' && promoPrice > 0) {
                                        finalPrice = price - promoPrice;
                                    } else if (promoType === 'percentage' && promoPrice > 0) {
                                        finalPrice = price * (1 - promoPrice / 100);
                                    }

                                    originalTotal += price;
                                    finalTotal += finalPrice;
                                });

                                const slotCount = this.selectedSlots.length;
                                if (slotCount === 2) {
                                    finalTotal *= 0.95;
                                } else if (slotCount >= 3) {
                                    finalTotal *= 0.90;
                                }

                                this.discountAmount = originalTotal - finalTotal;
                                this.totalAmount = finalTotal;
                            },
                            submitBooking() {
                                @this.call('saveAndRedirect', this.selectedSlots);
                            },
                            totalAmount: 0,
                            discountAmount: 0,
                            startTime: '',
                            endTime: ''
                        }">
                        <!-- Legend -->
                        <div class="grid grid-cols-2 lg:flex justify-center items-center gap-8 text-sm font-medium mb-4">
                            <div class="flex items-center gap-1">
                                <span class="w-4 h-4 bg-[#ff566b] rounded"></span> Đã Đặt
                            </div>
                            <div class="flex items-center gap-1">
                                <span class="w-4 h-4 border border-[#ff566b] rounded"></span> Còn Trống
                            </div>
                            <div class="flex items-center gap-1">
                                <span class="w-4 h-4 bg-[#ffc8ce] rounded"></span> Đang chọn
                            </div>
                            <div class="flex items-center gap-1">
                                <span class="selectable-mini promo-mini w-4 h-4 rounded"></span> Khuyến mãi
                            </div>
                            <div class="relative group flex items-center gap-1">
                                <img src="{{ asset('storage/tuimu.png') }}" class="w-6 animated-img openModal" />
                                <div class="absolute top-6 left-0 bg-black text-white text-xs px-2 py-1 rounded hidden group-hover:block z-10">
                                    Click xem thể lệ!
                                </div>
                                <span>Túi mù</span>
                            </div>
                        </div>

                        <!-- Table -->
                        <div class="overflow-auto border w-full max-h-[350px] shadow bg-white">
                            <table class="w-full text-[10px] text-center min-w-[900px] overflow-y-auto">
                                <thead class="bg-gray-100">
                                <tr>
                                    <th colspan="2" class="py-2 px-3 min-w-[150px] border">Chi nhánh</th>
                                    <th colspan="24" class="py-2 px-3 min-w-[150px] border">
                                        Home - {{ $category['name'] }}, {{ $categoryRoomTimeSlots[0]['c2'] ?? '' }}
                                    </th>
                                </tr>
                                <tr>
                                    <th colspan="2" class="py-2 px-3 min-w-[150px] border">Tên phòng</th>
                                    @foreach($categoryRoomTimeSlots as $room)
                                        @php
                                            $roomBgColor = $loop->index % 2 == 1 ? 'bg-yellow-100' : '';
                                        @endphp
                                        <th colspan="{{ count($room['time_slots']) }}" class="py-2 px-3 border {{ $roomBgColor }}">
                                            {{ $room['pname'] }}
                                        </th>
                                    @endforeach
                                </tr>
                                <tr>
                                    <th class="py-2 px-3 border min-w-[60px]">Thứ</th>
                                    <th class="py-2 px-3 border min-w-[80px]">Ngày</th>
                                    @foreach($categoryRoomTimeSlots as $room)
                                        @php
                                            $roomBgColor = $loop->index % 2 == 1 ? 'bg-yellow-100' : '';
                                        @endphp
                                        @foreach($room['time_slots'] as $timeSlot)
                                            <th class="py-2 px-3 border min-w-[90px] {{ $roomBgColor }}">
                                                {{ \Carbon\Carbon::parse($timeSlot['start_time'])->format('H:i') }} - {{ \Carbon\Carbon::parse($timeSlot['end_time'])->format('H:i') }}
                                                <br>
                                                @php
                                                    $startHour = \Carbon\Carbon::parse($timeSlot['start_time'])->hour;
                                                    $isOvernight = $timeSlot['end_time'] == '09:50:00' || $startHour >= 18;
                                                @endphp
                                                @if($isOvernight)
                                                    <span class="text-xs">(Qua đêm)</span>
                                                @else
                                                    <svg class="w-4 h-4 inline text-black" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon">
                                                        <path d="M8 1a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 8 1ZM10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0ZM12.95 4.11a.75.75 0 1 0-1.06-1.06l-1.062 1.06a.75.75 0 0 0 1.061 1.062l1.06-1.061ZM15 8a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 15 8ZM11.89 12.95a.75.75 0 0 0 1.06-1.06l-1.06-1.062a.75.75 0 0 0-1.062 1.061l1.061 1.06ZM8 12a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 8 12ZM5.172 11.89a.75.75 0 0 0-1.061-1.062L3.05 11.89a.75.75 0 1 0 1.06 1.06l1.06-1.06ZM4 8a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 4 8ZM4.11 5.172A.75.75 0 0 0 5.173 4.11L4.11 3.05a.75.75 0 1 0-1.06 1.06l1.06 1.06Z"></path>
                                                    </svg>
                                                @endif
                                            </th>
                                        @endforeach
                                    @endforeach
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($dates as $date)
                                    <tr class="border-t">
                                        <td class="py-2 border-2 {{ $date['day'] === 'Hôm nay' ? 'text-primary' : '' }}">{{ $date['day'] }}</td>
                                        <td class="py-2 border-2 {{ $date['day'] === 'Hôm nay' ? 'text-primary' : '' }}">{{ $date['date'] }}</td>
                                        @foreach($categoryRoomTimeSlots as $room)
                                            @php
                                                $roomBgColor = $loop->index % 2 == 1 ? 'bg-yellow-100' : '';
                                            @endphp
                                            @foreach($room['time_slots'] as $timeSlot)
                                                @php
                                                    $slotStartAt = \Carbon\Carbon::parse($timeSlot['start_at']);
                                                    $slotEndAt = \Carbon\Carbon::parse($timeSlot['end_at']);
                                                    $isValidDate = $date['carbon_date']->startOfDay()->between($slotStartAt->startOfDay(), $slotEndAt->startOfDay(), true);
                                                    $isBooked = false;

                                                    foreach ($bookings ?? [] as $booking) {
                                                        if ($booking['timeslot_id'] == $timeSlot['timeslot_id'] && $booking['booking_date'] == $date['carbon_date']->format('Y-m-d')) {
                                                            $isBooked = true;
                                                            break;
                                                        }
                                                    }

                                                    if (!$isBooked && isset($bookedDates)) {
                                                        foreach ($bookedDates as $booking) {
                                                            $checkin = \Carbon\Carbon::parse($booking['checkin_date']);
                                                            $checkout = \Carbon\Carbon::parse($booking['checkout_date']);
                                                            if ($date['carbon_date']->format('Y-m-d') == $checkin->format('Y-m-d') &&
                                                                $timeSlot['start_time'] == $checkin->format('H:i') &&
                                                                $timeSlot['end_time'] == $checkout->format('H:i')) {
                                                                $isBooked = true;
                                                                break;
                                                            }
                                                        }
                                                    }

                                                    $timeslotStatus = $isBooked ? 'booked' : $timeSlot['timeslot_status'];
                                                @endphp
                                                <td class="border-2 p-2 relative overflow-visible {{ $roomBgColor }}">
                                                    <div class="w-full selectable {{ !is_null($timeSlot['promotionId']) && in_array($timeSlot['promotion_type'], ['fixed', 'percentage']) && $isValidDate ? 'promo' : '' }}"
                                                         :class="selectedSlots.some(slot => slot.key === '{{ $date['carbon_date']->format('Y-m-d') }}-{{ $room['pid'] }}-{{ $timeSlot['timeslot_id'] }}') ? 'active' : ''"
                                                         x-on:click="toggleSlot('{{ $date['carbon_date']->format('Y-m-d') }}', '{{ $timeSlot['timeslot_id'] }}', '{{ $timeSlot['timeslot_price'] }}', '{{ $timeSlot['promotion_price'] ?? 0 }}', '{{ $timeSlot['promotion_type'] ?? '' }}', '{{ \Carbon\Carbon::parse($timeSlot['start_time'])->format('H:i') }}', '{{ \Carbon\Carbon::parse($timeSlot['end_time'])->format('H:i') }}', '{{ $timeslotStatus }}', '{{ $room['pid'] }}', '{{ $room['pname'] }}', '{{ $timeSlot['timeslot_label'] }}')"
                                                         style="{{ $timeslotStatus == 'booked' ? 'background-color: #ff566b;' : '' }}">
                                                    </div>
                                                    @if($timeslotStatus == 'available' && !is_null($timeSlot['promotionId']) && $timeSlot['promotion_type'] == 'tuimu' && $isValidDate)
                                                        <img src="{{ asset('storage/tuimu.png') }}" alt="" class="absolute top-0 right-0 w-5 h-5 animated-img">
                                                    @endif
                                                </td>
                                            @endforeach
                                        @endforeach
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>

                        <!-- Summary -->
                        <div class="flex md:flex-row flex-col md:justify-between justify-start items-center mt-4 gap-4">
                            <div class="w-full text-left text-blue-500 font-semibold">
                                <span>
                                    Tổng tiền tạm tính: <span class="total-price" x-text="new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(totalAmount)"></span>
                                </span>
                                <template x-if="selectedSlots.length > 0 && discountAmount > 0">
                                    <p class="text-sm text-red-500 font-normal">Đã giảm thêm: <span x-text="new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(discountAmount)"></span></p>
                                </template>
                                <p class="text-red-500 text-xs">** Khách hàng được giảm thêm 5% khi chọn book 2 hoặc 3 khung giờ</p>
                            </div>

                            <div class="w-full text-right">
                                <button x-on:click="submitBooking" class="py-1 px-4 rounded-full font-bold bg-primary text-white w-full md:w-auto">Đặt phòng</button>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <p>Không có danh mục nào để hiển thị.</p>
    @endif
    <!-- Modal -->
    <div id="tuimuModal"
         class="fixed inset-0 bg-black/40 backdrop-blur-sm hidden z-50 flex items-center justify-center">
        <div class="bg-white p-6 rounded-lg shadow-xl w-1/2 relative">
            <button id="closeModal" class="absolute top-2 right-2 text-gray-500 hover:text-red-500 text-xl font-bold">
                &times;
            </button>
            <h2 class="text-xl font-bold mb-4 text-center text-rose-500">Thông tin túi mù</h2>
            <p class="text-gray-700">Đây là phần hiển thị thông tin túi mù khuyến mãi...</p>
        </div>
    </div>

    <script>
        document.querySelectorAll(".tab-link").forEach(button => {
            button.addEventListener("click", () => {
                document.querySelectorAll(".tab-pane").forEach(tab => tab.classList.add("hidden"));
                document.getElementById(button.dataset.tab).classList.remove("hidden");

                document.querySelectorAll(".tab-link").forEach(btn => btn.classList.remove("active-tab"));
                button.classList.add("active-tab");
            });
        });

        // Handle click on tuimu image
        document.querySelectorAll('.openModal').forEach(img => {
            img.addEventListener('click', (e) => {
                e.stopPropagation(); // Ngăn sự kiện lan ra div cha
                document.getElementById('tuimuModal').classList.remove('hidden');
            });
        });

        // Close modal
        document.getElementById('closeModal').addEventListener('click', () => {
            document.getElementById('tuimuModal').classList.add('hidden');
        });

        // Click outside to close
        document.getElementById('tuimuModal').addEventListener('click', (e) => {
            if (e.target.id === 'tuimuModal') {
                e.currentTarget.classList.add('hidden');
            }
        });
    </script>
</div>