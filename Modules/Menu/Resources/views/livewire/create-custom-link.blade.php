<form wire:submit="save">
    <x-filament::section :heading="__('menu::menu-builder.custom_link')" id="create-custom-link">
        {{ $this->form }}

        <x-slot name="headerEnd">
            <x-filament::button type="submit">
                {{ __('menu::menu-builder.actions.add.label') }}
            </x-filament::button>
        </x-slot>
    </x-filament::section>
</form>
