@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData"/>
@section('content')

{{-- header đã bỏ, dùng compact bar của hero-section thay thế --}}
{{-- Compact search bar --}}
<div class="bg-teal-800 py-3 px-4 sticky top-0 z-[1200] mt-2 shadow-md">
    <div class="max-w-7xl mx-auto flex flex-wrap items-center gap-2">
        <a href="{{ url('/') }}" class="text-teal-200 hover:text-white transition-colors shrink-0">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
        </a>
        <div class="flex items-center gap-2 text-white/80 text-sm flex-1 min-w-0">
            <svg class="w-4 h-4 text-teal-300 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <span class="font-semibold text-white truncate">
                {{ $province ? 'Phòng tại ' . $province->name : 'Tìm kiếm phòng' }}
            </span>
        </div>
        <a href="{{ url('/') }}"
            class="shrink-0 text-xs text-teal-200 hover:text-white border border-teal-500 rounded-full px-3 py-1 transition-colors">
            Tìm kiếm lại
        </a>
    </div>
</div>

{{-- Main split layout --}}
<div class="flex h-[calc(100vh-112px)]" id="search-results-container">

    {{-- LEFT: Products grid (scrollable) --}}
    <div class="w-full lg:w-1/2 overflow-y-auto" id="products-col">
        @livewire('bladethemev1::search-results-grid')
    </div>

    {{-- RIGHT: Map (sticky) --}}
    <div class="hidden lg:block w-1/2 relative" id="map-col">
        <div id="search-map" class="w-full h-full"></div>

        {{-- Province info overlay --}}
        @if ($province)
        <div class="absolute top-4 left-4 right-4 z-10 pointer-events-none">
            <div class="bg-white/90 backdrop-blur-sm rounded-xl px-4 py-2.5 shadow-md inline-flex items-center gap-2">
                <svg class="w-4 h-4 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span class="text-sm font-semibold text-gray-800">{{ $province->name }}</span>
            </div>
        </div>
        @endif
    </div>
</div>
 @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
{{-- Leaflet Map --}}
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var lat  = {{ $mapLat }};
    var lng  = {{ $mapLng }};
    var zoom = {{ $mapZoom }};

    var map = L.map('search-map', {
        zoomControl: true,
        scrollWheelZoom: true,
    }).setView([lat, lng], zoom);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);

    @if ($province && $province->lat && $province->lng)
    var icon = L.divIcon({
        className: '',
        html: '<div style="background:#0f766e;width:36px;height:36px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.3)"></div>',
        iconSize: [36, 36],
        iconAnchor: [18, 36],
        popupAnchor: [0, -36],
    });

    L.marker([{{ $province->lat }}, {{ $province->lng }}], { icon: icon })
        .addTo(map)
        .bindPopup(
            '<div style="font-family:sans-serif;min-width:140px;">' +
            '<p style="font-weight:700;font-size:14px;color:#0f766e;margin:0 0 4px">{{ addslashes($province->name) }}</p>' +
            '<p style="font-size:12px;color:#6b7280;margin:0">365 HOME chi nhánh</p>' +
            '</div>'
        )
        .openPopup();
    @endif
});
</script>


@livewire('bladethemev1::notification')
@endsection
