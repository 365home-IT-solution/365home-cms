<div x-data="{ open: @entangle('open') }" @click.outside="open = false" class="relative -m-1.5 ml-2">
    @if ($this->originalUser())
        <button
            type="button"
            wire:click="switchBack"
            title="Quay lại {{ $this->originalUser()->fullname }}"
            class="fi-icon-btn relative flex items-center justify-center border dark:border-none rounded-lg bg-warning-50 dark:bg-warning-500/10 transition duration-75 focus-visible:ring-2 h-7 px-2 gap-1 text-warning-600 hover:text-warning-700 focus-visible:ring-primary-600 dark:text-warning-400 fi-color-warning text-xs font-medium whitespace-nowrap"
        >
            <x-heroicon-o-arrow-uturn-left class="w-4 h-4 shrink-0" />
            <span>Quay lại {{ $this->originalUser()->fullname }}</span>
        </button>
    @else
        <button
            type="button"
            wire:click="toggle"
            title="Chuyển đổi tài khoản"
            class="fi-icon-btn relative flex items-center justify-center border dark:border-none rounded-lg bg-gray-50 dark:bg-gray-800 transition duration-75 focus-visible:ring-2 h-7 w-7 text-gray-400 hover:text-gray-500 focus-visible:ring-primary-600 dark:text-gray-500 dark:hover:text-gray-400 dark:focus-visible:ring-primary-500 fi-color-gray"
        >
            <x-heroicon-o-arrow-path-rounded-square class="w-4 h-4 fi-icon-btn-icon" />
        </button>

        <div
            x-show="open"
            x-cloak
            x-transition
            class="absolute left-0 top-full mt-2 w-72 rounded-xl bg-white dark:bg-gray-900 shadow-lg ring-1 ring-gray-950/5 dark:ring-white/10 z-50 overflow-hidden"
        >
            @if ($step === 'list')
                <div class="p-3 border-b border-gray-100 dark:border-white/10">
                    <p class="text-sm font-medium text-gray-950 dark:text-white">Chuyển đổi tài khoản</p>
                    @if (auth()->user()?->isSuperAdmin())
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Tìm theo tên hoặc email..."
                            class="mt-2 w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm focus:border-primary-500 focus:ring-primary-500"
                        />
                    @endif
                </div>

                <div class="max-h-72 overflow-y-auto">
                    @php($candidates = $this->candidates())
                    @forelse ($candidates as $candidate)
                        <button
                            type="button"
                            wire:click="selectUser('{{ $candidate->id }}')"
                            class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-white/5 flex flex-col"
                        >
                            <span class="text-gray-950 dark:text-white">{{ $candidate->fullname }}</span>
                            <span class="text-xs text-gray-500">{{ $candidate->email }}</span>
                        </button>
                    @empty
                        <p class="px-3 py-4 text-sm text-gray-500 text-center">Không có tài khoản nào để chuyển đổi.</p>
                    @endforelse
                </div>
            @else
                @php($target = $this->selectedUser())
                <div class="p-3">
                    <button type="button" wire:click="backToList" class="text-xs text-gray-500 hover:text-gray-700 flex items-center gap-1 mb-2">
                        <x-heroicon-o-chevron-left class="w-3 h-3" />
                        Quay lại danh sách
                    </button>

                    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $target?->fullname }}</p>
                    <p class="text-xs text-gray-500 mb-3">{{ $target?->email }}</p>

                    <form wire:submit="switch">
                        <input
                            type="password"
                            wire:model="password"
                            placeholder="Mật khẩu"
                            autofocus
                            class="w-full rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-800 text-sm focus:border-primary-500 focus:ring-primary-500"
                        />
                        @if ($passwordError)
                            <p class="mt-1 text-xs text-danger-600">{{ $passwordError }}</p>
                        @endif

                        <button
                            type="submit"
                            class="mt-3 w-full rounded-lg bg-primary-600 hover:bg-primary-500 text-white text-sm font-medium py-1.5"
                        >
                            Chuyển đổi
                        </button>
                    </form>
                </div>
            @endif
        </div>
    @endif
</div>
