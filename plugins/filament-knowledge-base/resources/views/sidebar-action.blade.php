<ul class="px-6 fi-sidebar-nav-groups my-4 -mx-2 flex flex-col gap-y-7">
    <x-filament-panels::sidebar.item
        :icon="$icon"
        class="bg-gray-50 dark:bg-gray-800 rounded-lg"
        :url="$url"
        :should-open-url-in-new-tab="$shouldOpenUrlInNewTab">
        <x-filament::button
            class="!font-medium"
            style="box-shadow: none; background: none; padding:0;"
            color="gray"
            icon-size="lg"
            tag="span"
        >
            {{ $label }}
        </x-filament::button>
    </x-filament-panels::sidebar.item>
</ul>
