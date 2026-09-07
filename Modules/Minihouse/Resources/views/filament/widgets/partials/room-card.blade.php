{{-- $room: mảng 1 phòng từ RoomOccupancyMapWidget. $extraClass (tuỳ chọn): thêm class cho khung ngoài (VD w-24 cho khu "chưa gán vị trí"). Dựa vào toggleRoom()/isSelected() khai báo ở x-data CẤP TOÀ NHÀ (room-occupancy-map.blade.php) — không tự có state riêng nữa. --}}
@php
    $isRented = $room['status'] === \Modules\Minihouse\App\Models\Room::STATUS_RENTED;
    $isRepair = $room['status'] === \Modules\Minihouse\App\Models\Room::STATUS_REPAIR;

    $color = match (true) {
        $isRepair => 'warning',
        $room['status'] === \Modules\Minihouse\App\Models\Room::STATUS_EMPTY => 'success',
        $room['debt'] > 0 => 'danger',
        default => 'primary',
    };
    $bgClass = match ($color) {
        'success' => 'border-success-200 bg-success-50 dark:border-success-500/20 dark:bg-success-500/10',
        'danger'  => 'border-danger-200 bg-danger-50 dark:border-danger-500/20 dark:bg-danger-500/10',
        'warning' => 'border-warning-200 bg-warning-50 dark:border-warning-500/20 dark:bg-warning-500/10',
        default   => 'border-primary-200 bg-primary-50 dark:border-primary-500/20 dark:bg-primary-500/10',
    };
    $textClass = match ($color) {
        'success' => 'text-success-700 dark:text-success-400',
        'danger'  => 'text-danger-700 dark:text-danger-400',
        'warning' => 'text-warning-700 dark:text-warning-400',
        default   => 'text-primary-700 dark:text-primary-400',
    };
@endphp

@if ($isRented)
    {{-- Đang có khách ở — đi thẳng Sửa hợp đồng, không chọn được (chọn nhiều chỉ áp dụng phòng
    trống/đang sửa). --}}
    <a
        href="{{ $room['contractHref'] }}"
        title="{{ $room['tenant'] }}"
        class="flex min-h-[4.5rem] w-full flex-col items-center justify-center gap-0.5 rounded-lg border p-1.5 text-center transition hover:-translate-y-0.5 hover:shadow-md {{ $bgClass }} {{ $extraClass ?? '' }}"
    >
        <span class="text-sm font-bold {{ $textClass }}">{{ $room['code'] }}</span>
        <span class="w-full truncate text-[11px] text-gray-600 dark:text-gray-300">{{ $room['tenant'] }}</span>
        @if ($room['debt'] > 0)
            <span class="text-xs font-bold text-danger-600 dark:text-danger-400">
                {{ number_format($room['debt'], 0, ',', '.') }}đ
            </span>
        @endif
    </a>
@else
    {{-- Trống / Đã khoá — bấm để chọn (1 phòng bình thường, nhiều phòng khi đang "Chọn nhiều"), các
    hành động hiện ở HEADER của card toà nhà (xem room-occupancy-map.blade.php), không nổi menu ngay
    trên ô này nữa. --}}
    <button
        type="button"
        @click="toggleRoom({{ $room['id'] }})"
        :style="isSelected({{ $room['id'] }}) ? 'box-shadow: 0 0 0 2px rgba(var(--primary-500), 1);' : ''"
        title="{{ $isRepair ? 'Đã khoá' : 'Trống' }} — bấm để chọn"
        class="flex min-h-[4.5rem] w-full flex-col items-center justify-center gap-0.5 rounded-lg border p-1.5 text-center transition hover:-translate-y-0.5 hover:shadow-md {{ $bgClass }} {{ $extraClass ?? '' }}"
    >
        <span class="text-sm font-bold {{ $textClass }}">{{ $room['code'] }}</span>
        <span class="text-[11px] text-gray-400 dark:text-gray-500">{{ $isRepair ? 'Đã khoá' : 'Trống' }}</span>
    </button>
@endif
