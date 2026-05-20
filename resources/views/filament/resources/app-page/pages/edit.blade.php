<x-filament-panels::page>
    <div class="grid grid-cols-1 xl:grid-cols-[1fr_360px] gap-6 items-start">

        {{-- Form column --}}
        <div class="min-w-0">
            <x-filament-panels::form wire:submit="save">
                {{ $this->form }}

                <x-filament-panels::form.actions
                    :actions="$this->getCachedFormActions()"
                    :full-width="$this->hasFullWidthFormActions()"
                />
            </x-filament-panels::form>
        </div>

        {{-- JSON preview column --}}
        <div class="xl:sticky xl:top-4">
            <livewire:app-page-json-preview :page-id="$record->id" :key="'json-preview-' . $record->id" />
        </div>

    </div>
</x-filament-panels::page>
