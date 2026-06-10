<x-filament-panels::page>
    {{-- Nút "Tô đen khung giờ" + modal tích hợp --}}
    <livewire:book::block-timeslot-modal />

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex justify-end gap-3">
            <x-filament::button type="submit" color="primary" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">Lưu cài đặt</span>
                <span wire:loading wire:target="save" class="flex items-center gap-2">
                    <svg class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                    </svg>
                    Đang lưu...
                </span>
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>