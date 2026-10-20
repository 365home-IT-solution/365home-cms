{{-- Bản đồ nền: cấu hình + thư viện đọc PMTiles + lớp nền dùng chung (js/map-base.js). Nạp SAU leaflet. --}}
<script>window.__mapConfig = @json(['pmtilesUrl' => config('services.map.pmtiles_url'), 'flavor' => config('services.map.flavor')]);</script>
@if (config('services.map.pmtiles_url'))
    <script src="{{ asset('js/protomaps-leaflet.js') }}"></script>
@endif
{{-- Bản .min (Semrush "unminified JavaScript"). Sửa js/map-base.js xong phải build lại:
     node_modules/.bin/esbuild --minify public/js/map-base.js --outfile=public/js/map-base.min.js --}}
<script src="{{ asset('js/map-base.min.js') }}?v={{ filemtime(public_path('js/map-base.min.js')) }}"></script>
