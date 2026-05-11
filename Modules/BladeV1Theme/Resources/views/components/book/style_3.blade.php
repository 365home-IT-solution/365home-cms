<div wire:ignore class="w-full mx-auto px-8">
    <h2 class="mt-4 mb-2 text-center text-4xl font-bold">Lịch đặt phòng</h2>
    {{--     Chú thích      --}}
    <div class="grid grid-cols-2 lg:flex justify-center items-center gap-8 text-sm font-medium mb-4">
        <div class="flex items-center gap-1">
            <span class="w-4 h-4 bg-[#ff566b] rounded"></span> Đã Đặt <!--- blocked --->
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
    {{--     Bảng đặt phòng      --}}
    <div class="overflow-auto border w-full max-h-[350px] shadow bg-white">
        <table class="w-full text-[10px]  text-center min-w-[900px] overflow-y-auto ">
            <thead class="bg-gray-100">
            <tr>
                <th colspan="2" class="py-2 px-3 min-w-[150px] border">Chi nhánh</th>
                <th colspan="24" class="py-2 px-3 min-w-[150px] border">
                    {{--Home - {{ $grandChild->name }}, {{ $roomTimeSlots[0]['c2'] ?? '' }}--}}
                </th>
            </tr>
            <tr>
                <th colspan="2" class="py-2 px-3 min-w-[150px] border">Tên phòng</th>
                @foreach($roomTimeSlots as $room)
                    <th colspan="{{ count($room['time_slots']) }}" class="py-2 px-3 border {{ in_array($room['pname'], ['Cook & Chill', 'Pink Paradise', 'GameHub']) ? 'bg-yellow-100' : '' }}">
                        {{ $room['pname'] }}
                    </th>
                @endforeach
            </tr>
            <tr>
                <th class="py-2 px-3 border min-w-[60px]">Thứ</th>
                <th class="py-2 px-3 border min-w-[80px]">Ngày</th>
                @foreach($roomTimeSlots as $room)
                    @foreach($room['time_slots'] as $timeSlot)
                        <th class="py-2 px-3 border min-w-[90px]">
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
                    @foreach($roomTimeSlots as $room)
                        @foreach($room['time_slots'] as $timeSlot)
                            @php
                                $slotStartAt = \Carbon\Carbon::parse($timeSlot['start_at']);
                                $slotEndAt = \Carbon\Carbon::parse($timeSlot['end_at']);
                                $isValidDate = $date['carbon_date']->between($slotStartAt, $slotEndAt, true);
                            @endphp
                            <td class="p-1 border-2 p-2 relative overflow-visible {{ in_array($room['pname'], ['Cook & Chill', 'Pink Paradise', 'GameHub']) ? 'bg-yellow-100' : '' }}">
                                @if($isValidDate)
                                    <div class="w-full selectable {{ !is_null($timeSlot['promotionId']) && in_array($timeSlot['promotion_type'], ['fixed', 'percentage']) ? 'promo' : '' }}"
                                         data-room-id="{{ $room['pid'] }}"
                                         data-room-name="{{ $room['pname'] }}"
                                         data-timeslot-id="{{ $timeSlot['timeslot_id'] }}"
                                         data-timeslot-label="{{ $timeSlot['timeslot_label'] }}"
                                         data-timeslot-price="{{ $timeSlot['timeslot_price'] }}"
                                         data-promotion-price="{{ $timeSlot['promotion_price'] ?? 0 }}"
                                         data-promotion-type="{{ $timeSlot['promotion_type'] ?? '' }}"
                                         data-date="{{ $date['carbon_date']->format('Y-m-d') }}"
                                         data-timeslot-status="{{ $timeSlot['timeslot_status'] }}"
                                         style="{{ $timeSlot['timeslot_status'] == 'booked' ? 'background-color: #ff566b;' : '' }}">
                                    </div>
                                    @if($timeSlot['timeslot_status'] == 'available' && !is_null($timeSlot['promotionId']) && $timeSlot['promotion_type'] == 'tuimu')
                                        <img src="{{ asset('storage/tuimu.png') }}" alt="" class="absolute top-0 right-0 w-5 h-5 animated-img">
                                    @endif
                                @else
                                    <div class="w-full selectable"
                                         data-room-id="{{ $room['pid'] }}"
                                         data-room-name="{{ $room['pname'] }}"
                                         data-timeslot-id="{{ $timeSlot['timeslot_id'] }}"
                                         data-timeslot-price="{{ $timeSlot['timeslot_price'] }}"
                                         data-promotion-price="{{ $timeSlot['promotion_price'] ?? 0 }}"
                                         data-promotion-type="{{ $timeSlot['promotion_type'] ?? '' }}"
                                         data-date="{{ $date['carbon_date']->format('Y-m-d') }}"
                                         data-timeslot-status="inactive"
                                         style="background-color: #ffffff;"></div> <!-- Inactive cell -->
                                @endif
                            </td>
                        @endforeach
                    @endforeach
                </tr>
            @endforeach
            </tbody>

        </table>
    </div>
    {{--      Tính      --}}
    <div class="flex md:flex-row flex-col md:justify-between justify-start items-center mt-4 gap-4">
        <div class="w-full text-left mt-5 text-blue-500 font-semibold">
            Tổng tiền tạm tính: <span class="total-price">0 đ</span>
            <p class="text-red-500 text-xs">** Khách hàng được giảm thêm 5% khi chọn book 2 hoặc 3 khung giờ</p>
        </div>

        <div class="w-full text-right">
            <button class="py-1 px-4 rounded-full font-bold bg-primary text-white w-full md:w-auto">Đặt phòng</button>
        </div>
    </div>

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

        document.addEventListener('DOMContentLoaded', () => {
            const selectableCells = document.querySelectorAll('.selectable');

            selectableCells.forEach(cell => {
                cell.addEventListener('click', () => {
                    const roomId = cell.getAttribute('data-room-id');
                    const dateKey = cell.getAttribute('data-date');
                    const currentActiveRooms = getCurrentActiveRooms();

                    // Nếu ô này đã active, cho phép bỏ chọn
                    if (cell.classList.contains('active')) {
                        cell.classList.remove('active');
                        updateTotalPrice();
                        return;
                    }

                    // Nếu chưa có phòng nào được chọn, cho phép chọn
                    if (currentActiveRooms.length === 0) {
                        cell.classList.add('active');
                        updateTotalPrice();
                        return;
                    }

                    // Nếu đã có phòng được chọn, chỉ cho phép chọn cùng phòng
                    if (currentActiveRooms.includes(roomId)) {
                        // Kiểm tra logic ngày: phải chọn hết khung giờ available của ngày hiện tại
                        if (canSelectThisTimeSlot(roomId, dateKey)) {
                            cell.classList.add('active');
                            updateTotalPrice();
                        } else {
                            console.log('Bạn phải chọn hết tất cả khung giờ available của ngày hiện tại trước khi chọn ngày tiếp theo!');
                            // alert('Vui lòng chọn hết tất cả khung giờ available của ngày hiện tại trước!');
                        }
                    } else {
                        console.log('Bạn chỉ có thể chọn khung giờ của cùng một phòng!');
                        // alert('Vui lòng bỏ chọn khung giờ hiện tại trước khi chọn phòng khác!');
                    }
                });
            });

            // Khởi tạo hiển thị tổng tiền
            updateTotalPrice();
        });

        // Kiểm tra có thể chọn khung giờ này không (logic ngày)
        function canSelectThisTimeSlot(roomId, dateKey) {
            const selectedDates = getSelectedDatesForRoom(roomId);

            // Nếu chưa chọn ngày nào, cho phép chọn bất kỳ ngày nào
            if (selectedDates.length === 0) {
                return true;
            }

            // Nếu đang chọn cùng ngày hiện tại, luôn cho phép
            if (selectedDates.includes(dateKey)) {
                return true;
            }

            // Nếu muốn chọn ngày mới, kiểm tra xem đã chọn hết khung giờ available của các ngày trước chưa
            for (let selectedDate of selectedDates) {
                if (!isDateFullySelected(roomId, selectedDate)) {
                    return false; // Chưa chọn hết khung giờ available của ngày này
                }
            }

            return true; // Đã chọn hết khung giờ available của tất cả ngày trước đó
        }

        // Lấy danh sách các ngày đã chọn cho một phòng
        function getSelectedDatesForRoom(roomId) {
            const selectedCells = document.querySelectorAll(`.selectable.active[data-room-id="${roomId}"]`);
            const dates = [];

            selectedCells.forEach(cell => {
                const dateKey = cell.getAttribute('data-date');
                if (dateKey && !dates.includes(dateKey)) {
                    dates.push(dateKey);
                }
            });

            return dates.sort(); // Sắp xếp theo thứ tự ngày
        }

        // Kiểm tra xem một ngày của phòng đã được chọn hết khung giờ available chưa
        function isDateFullySelected(roomId, dateKey) {
            // Lấy tất cả ô available của phòng và ngày này (không phải booked, không phải inactive)
            const availableCells = document.querySelectorAll(
                `.selectable[data-room-id="${roomId}"][data-date="${dateKey}"]`
            );

            const availableActiveCells = [];
            const selectedCells = [];

            availableCells.forEach(cell => {
                // Kiểm tra xem ô này có phải là available không (không có background đỏ = booked)
                const style = cell.getAttribute('style') || '';
                const isBooked = style.includes('background-color: #ff566b') || style.includes('background-color:#ff566b');
                const isInactive = style.includes('background-color: #ffffff') || style.includes('background-color:#ffffff');

                if (!isBooked && !isInactive) {
                    availableActiveCells.push(cell);
                    if (cell.classList.contains('active')) {
                        selectedCells.push(cell);
                    }
                }
            });

            // Nếu số ô đã chọn = số ô available thì đã chọn hết
            return selectedCells.length === availableActiveCells.length && availableActiveCells.length > 0;
        }

        // Lấy danh sách các phòng đang có ô được chọn
        function getCurrentActiveRooms() {
            const activeCells = document.querySelectorAll('.selectable.active');
            const activeRooms = [];

            activeCells.forEach(cell => {
                const roomId = cell.getAttribute('data-room-id');
                if (roomId && !activeRooms.includes(roomId)) {
                    activeRooms.push(roomId);
                }
            });

            return activeRooms;
        }

        // Cập nhật tổng tiền
        function updateTotalPrice() {
            const selectedCells = document.querySelectorAll('.selectable.active');
            let totalPrice = 0;
            const selectedCount = selectedCells.length;

            if (selectedCount === 0) {
                document.querySelector('.total-price').textContent = '0 đ';
                return;
            }

            selectedCells.forEach(cell => {
                const basePrice = parseInt(cell.getAttribute('data-timeslot-price')) || 0;
                const hasPromotion = cell.classList.contains('promo');
                const promotionPrice = parseInt(cell.getAttribute('data-promotion-price')) || 0;
                const promotionType = cell.getAttribute('data-promotion-type');

                let cellPrice = basePrice;

                // Áp dụng khuyến mãi nếu có
                if (hasPromotion && promotionPrice > 0) {
                    if (promotionType === 'fixed') {
                        cellPrice = basePrice - promotionPrice;
                    } else if (promotionType === 'percentage') {
                        cellPrice = basePrice - (basePrice * promotionPrice / 100);
                    }
                }

                totalPrice += cellPrice;
            });

            // Áp dụng giảm giá 5% nếu chọn từ 2-3 khung giờ
            if (selectedCount >= 2 && selectedCount <= 3) {
                totalPrice = totalPrice * 0.95; // Giảm 5%
            }

            // Định dạng số tiền
            const formattedPrice = new Intl.NumberFormat('vi-VN').format(Math.round(totalPrice));
            document.querySelector('.total-price').textContent = formattedPrice + ' đ';
        }

        // Xóa tất cả selections
        function clearAllSelections() {
            document.querySelectorAll('.selectable.active').forEach(activeCell => {
                activeCell.classList.remove('active');
            });
            updateTotalPrice();
        }

        // Lấy tất cả các ô đã được chọn
        function getSelectedCells() {
            return document.querySelectorAll('.selectable.active');
        }

        // Lấy thông tin các khung giờ đã chọn
        function getSelectedTimeSlots() {
            const selectedCells = document.querySelectorAll('.selectable.active');
            const selectedData = [];

            selectedCells.forEach(cell => {
                selectedData.push({
                    roomId: cell.getAttribute('data-room-id'),
                    timeslotId: cell.getAttribute('data-timeslot-id'),
                    element: cell
                });
            });

            return selectedData;
        }


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