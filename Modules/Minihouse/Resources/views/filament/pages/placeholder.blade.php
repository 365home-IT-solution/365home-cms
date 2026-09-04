<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            {{ $pageDescription ?? 'Tính năng đang được xây dựng' }}
        </x-slot>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            @foreach ($items as $item)
                <div class="flex items-start gap-3 rounded-lg border border-gray-200 p-4 dark:border-white/10">
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
                        <x-filament::icon icon="heroicon-o-squares-2x2" class="h-5 w-5" />
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ $item['title'] }}</div>
                        <div class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $item['description'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6 rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-500 dark:bg-white/5 dark:text-gray-400">
            Đây là khung giao diện — dữ liệu và thao tác thật (thêm/sửa/xoá) sẽ được bổ sung ở bước tiếp theo.
        </div>
    </x-filament::section>
</x-filament-panels::page>
