<x-filament-panels::page>
    {{-- Nút "Tô đen khung giờ" + modal tích hợp --}}
    <livewire:book::block-timeslot-modal />

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex justify-end gap-3">
            <x-filament::button type="submit" color="primary">
                Lưu cài đặt
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>