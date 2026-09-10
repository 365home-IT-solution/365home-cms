{{-- $room: mảng 1 phòng từ RoomOccupancyMapWidget. $extraClass (tuỳ chọn): thêm class cho khung ngoài (VD w-24 cho khu "chưa gán vị trí"). Dựa vào toggleRoom()/isSelected() khai báo ở x-data CẤP TOÀ NHÀ (room-occupancy-map.blade.php) — không tự có state riêng nữa. --}}
@php
    $isRented   = $room['status'] === \Modules\Minihouse\App\Models\Room::STATUS_RENTED;
    $isReserved = $room['status'] === \Modules\Minihouse\App\Models\Room::STATUS_RESERVED;
    $isRepair   = $room['status'] === \Modules\Minihouse\App\Models\Room::STATUS_REPAIR;

    $color = match (true) {
        $isRepair => 'warning',
        $isReserved => 'info',
        $room['status'] === \Modules\Minihouse\App\Models\Room::STATUS_EMPTY => 'success',
        $room['debt'] > 0 => 'danger',
        default => 'primary',
    };
    $bgClass = match ($color) {
        'success' => 'border-success-200 bg-success-50 dark:border-success-500/20 dark:bg-success-500/10',
        'danger'  => 'border-danger-200 bg-danger-50 dark:border-danger-500/20 dark:bg-danger-500/10',
        'warning' => 'border-warning-200 bg-warning-50 dark:border-warning-500/20 dark:bg-warning-500/10',
        'info'    => 'border-info-200 bg-info-50 dark:border-info-500/20 dark:bg-info-500/10',
        default   => 'border-primary-200 bg-primary-50 dark:border-primary-500/20 dark:bg-primary-500/10',
    };
    $textClass = match ($color) {
        'success' => 'text-success-700 dark:text-success-400',
        'danger'  => 'text-danger-700 dark:text-danger-400',
        'warning' => 'text-warning-700 dark:text-warning-400',
        'info'    => 'text-info-700 dark:text-info-400',
        default   => 'text-primary-700 dark:text-primary-400',
    };

    // Gộp TOÀN BỘ thông tin trước đây hiện thẳng trên ô (khách thuê/đặt cọc/nợ/trạng thái) vào ĐÚNG
    // 1 chuỗi tooltip (title — tooltip có sẵn của trình duyệt, không cần thư viện gì thêm) — ô chỉ
    // còn hiện tên phòng + màu, không còn to nhỏ lệch nhau do tên khách dài ngắn khác nhau nữa.
    $tooltipLines = [$room['code']];

    if ($isRented || $isReserved) {
        $tooltipLines[] = $room['tenant'];

        if ($isReserved) {
            $tooltipLines[] = 'Đã đặt cọc — chưa tới ngày dọn vào';
        }

        if ($room['debt'] > 0) {
            $tooltipLines[] = 'Còn nợ: ' . number_format($room['debt'], 0, ',', '.') . 'đ';
        }
    } else {
        $tooltipLines[] = $isRepair ? 'Đã khoá' : 'Trống';
    }

    $tooltip = implode(' — ', $tooltipLines);
@endphp

@if ($isRented || $isReserved)
    {{-- Đang có khách ở HOẶC đã đặt cọc giữ chỗ (chưa tới ngày dọn vào) — cả 2 đều đã gắn hợp đồng
    hiệu lực nên đi thẳng Sửa hợp đồng đó, không chọn được (chọn nhiều chỉ áp dụng phòng
    trống/đang sửa) — xem RoomOccupancyMapWidget::syncRoom() phân biệt 2 trạng thái này theo start_date. --}}
    <a
        href="{{ $room['contractHref'] }}"
        title="{{ $tooltip }}"
        class="flex aspect-square w-full items-center justify-center rounded-lg border p-1.5 text-center transition hover:-translate-y-0.5 hover:shadow-md {{ $bgClass }} {{ $extraClass ?? '' }}"
    >
        <span class="text-sm font-bold {{ $textClass }}">{{ $room['code'] }}</span>
    </a>
@else
    {{-- Trống / Đã khoá — bấm để chọn (1 phòng bình thường, nhiều phòng khi đang "Chọn nhiều"), các
    hành động hiện ở HEADER của card toà nhà (xem room-occupancy-map.blade.php), không nổi menu ngay
    trên ô này nữa. --}}
    <button
        type="button"
        @click="toggleRoom({{ $room['id'] }})"
        :style="isSelected({{ $room['id'] }}) ? 'box-shadow: 0 0 0 2px rgba(var(--primary-500), 1);' : ''"
        title="{{ $tooltip }} — bấm để chọn"
        class="flex aspect-square w-full items-center justify-center rounded-lg border p-1.5 text-center transition hover:-translate-y-0.5 hover:shadow-md {{ $bgClass }} {{ $extraClass ?? '' }}"
    >
        <span class="text-sm font-bold {{ $textClass }}">{{ $room['code'] }}</span>
    </button>
@endif
