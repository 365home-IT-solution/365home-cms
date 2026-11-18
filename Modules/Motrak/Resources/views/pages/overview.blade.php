<x-filament-panels::page>
    <div wire:poll.30s="loadMarkers" class="space-y-4">
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <span class="font-medium">Trạng thái kết nối Motrak:</span>
            <x-filament::badge :color="$connection['color']">{{ $connection['label'] }}</x-filament::badge>
            @if ($connection['last_success_at'])
                <span class="text-gray-500 dark:text-gray-400">Đồng bộ thành công gần nhất: {{ \Carbon\Carbon::parse($connection['last_success_at'])->diffForHumans() }}</span>
            @endif
            @if ($settingsUrl)
                <a href="{{ $settingsUrl }}" class="text-primary-600 hover:underline dark:text-primary-400">Cấu hình Motrak</a>
            @endif
        </div>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            @foreach ($stats as $s)
                <x-filament::section compact>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $s['label'] }}</div>
                    <div class="mt-1 text-2xl font-semibold">{{ number_format($s['value']) }}</div>
                </x-filament::section>
            @endforeach
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <div style="width: 16rem">
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model.live.debounce.400ms="search" placeholder="Tìm biển số / IMEI / Vehicle ID" />
                </x-filament::input.wrapper>
            </div>
            <div style="width: 18rem">
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="filter">
                        @foreach ($filters as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
            <span class="text-sm text-gray-500 dark:text-gray-400">{{ count($markers) }} xe có vị trí · tự cập nhật từ cơ sở dữ liệu mỗi 30 giây</span>
        </div>

        @include('motrak::partials.map', ['markers' => $markers, 'wireProperty' => 'markers', 'height' => 560])

        <p class="text-xs text-gray-500 dark:text-gray-400">
            Màu thanh biển số: xanh lá = đang di chuyển, xanh dương = đang dừng, đỏ = mất kết nối (isConnected = false, dữ liệu còn mới), xám = dữ liệu quá cũ.
            Chỉ hiển thị vị trí đã đồng bộ về 365home; không gọi Motrak trực tiếp từ trình duyệt.
        </p>
    </div>
</x-filament-panels::page>
