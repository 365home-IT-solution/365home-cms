{{-- Bản đồ nền: cấu hình + thư viện đọc PMTiles + lớp nền dùng chung (js/map-base.js). Nạp SAU leaflet. --}}
<script>window.__mapConfig = @json(['pmtilesUrl' => config('services.map.pmtiles_url'), 'flavor' => config('services.map.flavor')]);</script>
@if (config('services.map.pmtiles_url'))
    <script src="{{ asset('js/protomaps-leaflet.js') }}"></script>
@endif
<script src="{{ asset('js/map-base.js') }}?v={{ filemtime(public_path('js/map-base.js')) }}"></script>
