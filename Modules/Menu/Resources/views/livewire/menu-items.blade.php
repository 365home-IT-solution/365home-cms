<div>
    @if($this->menuItems->isNotEmpty())
        <ul
            ax-load
            ax-load-src="{{ asset('js/thuongna/filament-menu-builder/components/filament-menu-builder.js') }}"
            x-data="menuBuilder({ parentId: 0 })"
            x-init="
                let link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = '{{ asset('css/thuongna/filament-menu-builder/filament-menu-builder-styles.css') }}';
                document.head.appendChild(link);
            "
            class="space-y-2"
        >
            @foreach($this->menuItems as $menuItem)
                <x-menu::menu-item
                    :item="$menuItem"
                />
            @endforeach
        </ul>
    @else
        <x-filament-tables::empty-state
            icon="heroicon-o-document"
            :heading="trans('menu::menu-builder.items.empty.heading')"
        />
    @endif

    <x-filament-actions::modals />
</div>