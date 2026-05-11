@php
    extract($this->getViewData());
@endphp

<x-filament-panels::page>

<div class="ta-wrap">
<div class="ta-inner">

    @include('dashboard::pages.dashboard._header')

    @include('dashboard::pages.dashboard._kpi')


    @include('dashboard::pages.dashboard._room-cards')

    @include('dashboard::pages.dashboard._bottom-grid')

</div>
</div>

@include('dashboard::pages.dashboard._scripts')

@include('dashboard::pages.dashboard._styles')

</x-filament-panels::page>
