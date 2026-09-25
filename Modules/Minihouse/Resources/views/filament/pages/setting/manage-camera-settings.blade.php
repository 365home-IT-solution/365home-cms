<x-filament-panels::page>
    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <label class="text-sm font-medium text-gray-950 dark:text-white">Toà nhà cần cấu hình</label>
        <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">
            Mỗi Toà nhà có thể dùng 1 server Frigate/go2rtc hoàn toàn riêng — chọn đúng toà nhà trước khi sửa bên dưới.
        </p>
        <select
            wire:model.live="buildingId"
            class="fi-select-input block w-full rounded-lg border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
        >
            <option value="">— Chọn Toà nhà —</option>
            @foreach ($this->buildingOptions() as $id => $name)
                <option value="{{ $id }}" @selected($buildingId === (string) $id)>{{ $name }}</option>
            @endforeach
        </select>
    </div>

    @if ($buildingId)
        <form wire:submit="save">
            {{ $this->form }}

            <div class="mt-6">
                <x-filament::button type="submit">
                    Lưu cấu hình
                </x-filament::button>
            </div>
        </form>
    @else
        <div class="rounded-xl border border-gray-200 bg-white p-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
            Chọn 1 Toà nhà ở trên để xem/sửa cấu hình camera của toà nhà đó.
        </div>
    @endif
</x-filament-panels::page>
