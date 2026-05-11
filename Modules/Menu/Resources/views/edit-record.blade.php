<x-filament-panels::page @class([
    'fi-resource-edit-record-page',
    'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    'fi-resource-record-' . $record->getKey(),
])>
    @capture($form)
        <x-filament-panels::form id="form" :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
            wire:submit="save">
            {{ $this->form }}

            <x-filament-panels::form.actions :actions="$this->getCachedFormActions()" :full-width="$this->hasFullWidthFormActions()" />
        </x-filament-panels::form>
    @endcapture

    @php
        $relationManagers = $this->getRelationManagers();
        $hasCombinedRelationManagerTabsWithContent = $this->hasCombinedRelationManagerTabsWithContent();
    @endphp

    @if (!$hasCombinedRelationManagerTabsWithContent || !count($relationManagers))
        {{ $form() }}
    @endif

    @if (count($relationManagers))
        <x-filament-panels::resources.relation-managers :active-locale="isset($activeLocale) ? $activeLocale : null" :active-manager="$this->activeRelationManager ??
            ($hasCombinedRelationManagerTabsWithContent ? null : array_key_first($relationManagers))" :content-tab-label="$this->getContentTabLabel()"
            :content-tab-icon="$this->getContentTabIcon()" :content-tab-position="$this->getContentTabPosition()" :managers="$relationManagers" :owner-record="$record" :page-class="static::class">
            @if ($hasCombinedRelationManagerTabsWithContent)
                <x-slot name="content">
                    {{ $form() }}
                </x-slot>
            @endif
        </x-filament-panels::resources.relation-managers>
    @endif

    <div class="flex flex-menu gap-4" wire:ignore>
        <div class="flex flex-col gap-4 basis-one">
            @foreach (\Modules\Menu\App\Filament\FilamentMenuBuilderPlugin::get()->getMenuPanels() as $menuPanel)
                <livewire-menu::panel :menu="$record" :menuPanel="$menuPanel" />
            @endforeach

            <livewire-menu::create-custom-link :menu="$record" />
        </div>
        <div class="basis-two">
            <x-filament::section>
                <livewire-menu::menu-items :menu="$record" />
            </x-filament::section>
        </div>
    </div>

    <x-filament-panels::page.unsaved-data-changes-alert />
</x-filament-panels::page>
