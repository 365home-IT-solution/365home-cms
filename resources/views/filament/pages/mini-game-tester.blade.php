<x-filament-panels::page>
    @if ($enabled)
        <iframe
            src="{{ route('game-tester') }}"
            title="Thử mini game"
            class="w-full rounded-xl bg-white ring-1 ring-gray-950/5 dark:ring-white/10"
            style="height: calc(100vh - 12rem); min-height: 640px;"
        ></iframe>
    @else
        <x-filament::section>
            <div class="text-sm text-gray-500 dark:text-gray-400">
                Trang thử đang tắt. Bấm “Bật trang thử” để dùng.
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
