<div x-data="{ open: @entangle('open') }" @click.outside="open = false" class="relative -m-1.5 ml-2">
    <button
        type="button"
        wire:click="toggle"
        title="Chuyển đổi chi nhánh"
        class="fi-icon-btn relative flex items-center justify-center border dark:border-none rounded-lg bg-gray-50 dark:bg-gray-800 transition duration-75 focus-visible:ring-2 h-7 w-7 text-gray-400 hover:text-gray-500 focus-visible:ring-primary-600 dark:text-gray-500 dark:hover:text-gray-400 dark:focus-visible:ring-primary-500 fi-color-gray"
    >
        <x-heroicon-o-building-storefront class="w-4 h-4 fi-icon-btn-icon" />
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition
        class="absolute left-0 top-full mt-2 w-72 rounded-xl bg-white dark:bg-gray-900 shadow-lg ring-1 ring-gray-950/5 dark:ring-white/10 z-50 overflow-hidden"
    >
        <div class="p-3 border-b border-gray-100 dark:border-white/10 flex items-center justify-between">
            <p class="text-sm font-medium text-gray-950 dark:text-white">Chuyển đổi chi nhánh</p>
            <button type="button" wire:click="selectAll" class="text-xs text-primary-600 hover:text-primary-500">
                Chọn tất cả
            </button>
        </div>

        <div class="max-h-72 overflow-y-auto p-2 space-y-1">
            @forelse ($this->branches() as $branch)
                <label class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-gray-50 dark:hover:bg-white/5 cursor-pointer">
                    <input
                        type="checkbox"
                        wire:model="selected"
                        value="{{ $branch->id }}"
                        class="rounded border-gray-300 dark:border-gray-700 text-primary-600 focus:ring-primary-500"
                    />
                    <span class="text-sm text-gray-950 dark:text-white">{{ $branch->name }}</span>
                </label>
            @empty
                <p class="px-2 py-4 text-sm text-gray-500 text-center">Không có chi nhánh nào.</p>
            @endforelse
        </div>

        <div class="p-3 border-t border-gray-100 dark:border-white/10">
            <button
                type="button"
                wire:click="apply"
                class="w-full rounded-lg bg-primary-600 hover:bg-primary-500 text-white text-sm font-medium py-1.5"
            >
                Áp dụng
            </button>
        </div>
    </div>
</div>
