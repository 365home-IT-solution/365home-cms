@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    {{-- Header/menu DÙNG CHUNG với mọi trang khác của Home (không tự vẽ lại) — thiếu 2 dòng này là
         lý do trang trước đó không có logo/thanh tìm kiếm/nav trên cùng, trông "khác hẳn" phần còn
         lại của site dù card phòng đã style giống. Xem Modules\BladeThemeV1\Resources\views\pages\
         product\search.blade.php — trang tìm phòng Home cũng include đúng 2 component này ở đầu. --}}
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    <link rel="stylesheet" href="{{ asset('css/leaflet.min.css') }}" />

    {{-- Bố cục 2 cột — ĐÚNG TỈ LỆ trang tìm kiếm Homestay thật ở desktop (xem public/css/
         search-page.css dòng 270-316, @media(min-width:768px)): #rooms-left-panel width:46% (CỐ
         ĐỊNH theo %, không phải flex/1fr), #map-col flex:1 1 auto (chiếm phần còn lại), gap 40px.
         Bản trước dùng grid-cols-[1fr_420px] (list=flex, map=cố định 420px) — NGƯỢC hẳn tỉ lệ thật,
         khiến list quá rộng/map quá hẹp so với Homestay. --}}
    <div class="max-w-11xl mx-auto px-4 sm:px-6 py-6">
        <div class="md:flex items-start gap-10">
            <div class="md:w-[46%] md:flex-shrink-0 min-w-0">
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-900">Cho thuê phòng trọ theo tháng</h1>
                    <p class="text-gray-500 mt-1">Phòng trống, giá rõ ràng theo tháng — để lại thông tin, nhân viên tư vấn liên hệ lại ngay.</p>
                </div>

                @if ($rooms->isEmpty())
                    <div class="text-center py-16 text-gray-400">
                        <p class="text-lg">Không tìm thấy phòng trống phù hợp.</p>
                    </div>
                @else
                    {{-- Tổng số + gộp theo TOÀ NHÀ (địa chỉ + số lượng) — cùng bố cục trang tìm kiếm
                         Homestay (nhóm theo chi nhánh, xem search-results.js renderBranchGroup()),
                         chỉ khác là render SSR bằng Blade thay vì JS. Lưới card CỐ ĐỊNH 3 cột
                         (repeat(3,1fr), KHÔNG đổi theo breakpoint viewport) — đúng .branch-grid
                         thật (search-page.css dòng ~319), vì lưới nằm trong panel 46% chứ không
                         phải toàn màn hình, đổi cột theo viewport sẽ sai tỉ lệ card thật. Card dựng
                         lại ĐÚNG cấu trúc/style window.roomCardHtml() (home-sections.js). --}}
                    <h2 class="text-lg font-bold text-gray-900 mb-5">{{ $rooms->total() }} phòng</h2>

                    @foreach ($rooms->getCollection()->groupBy('building_id') as $buildingRooms)
                        <?php $buildingModel = $buildingRooms->first()->building; ?>
                        <div class="mb-8">
                            <h3 class="text-sm font-semibold text-gray-700 mb-3">
                                {{ $buildingModel?->address ?? $buildingModel?->name }}
                                <span class="text-gray-400 font-normal">({{ $buildingRooms->count() }})</span>
                            </h3>
                            <div class="grid grid-cols-3 gap-x-3.5 gap-y-5">
                                @foreach ($buildingRooms as $room)
                                    {{-- data-building-id/data-room-code: JS dùng để khớp card này với
                                         đúng marker + đúng phòng trong popup nhiều phòng khi rê chuột
                                         (xem script bên dưới — mirror hành vi mouseenter của
                                         search-map.js thật). --}}
                                    <a href="{{ url('/minihouse/' . $room->slug) }}" data-building-id="{{ $room->building_id }}" data-room-code="{{ $room->code }}" class="home-card mh-room-card" style="position:relative; display:flex; flex-direction:column; gap:8px; text-decoration:none; width:100%; max-width:none; flex:none;">
                                        <div style="position:relative; padding-top:72%; overflow:hidden; background:#f3f4f6; border-radius:14px; flex-shrink:0;">
                                            @if ($room->photos[0] ?? null)
                                                <img src="{{ $room->photos[0] }}" alt="Phòng {{ $room->code }}" loading="lazy" style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">
                                            @endif
                                        </div>
                                        <div style="padding:0 2px; display:flex; flex-direction:column; gap:3px;">
                                            <p style="font-size:13px; font-weight:600; color:#111827; margin:0; overflow:hidden; white-space:nowrap; text-overflow:ellipsis;">
                                                Phòng {{ $room->code }}
                                            </p>
                                            <div style="display:flex; align-items:center; justify-content:space-between; gap:6px;">
                                                <p style="font-size:13px; color:#111827; margin:0; white-space:nowrap;">
                                                    <span style="font-weight:700;">{{ number_format((float) $room->price, 0, ',', '.') }}đ</span>
                                                    <span style="color:#9ca3af; font-size:11px;"> /tháng</span>
                                                </p>
                                                @if ($room->area)
                                                    <span style="font-size:12px; color:#6b7280; white-space:nowrap;">{{ $room->area }}m²</span>
                                                @endif
                                            </div>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach

                    <div class="mt-4">
                        {{ $rooms->onEachSide(1)->links() }}
                    </div>
                @endif
            </div>

            {{-- Bản đồ — flex:1 (chiếm phần còn lại sau 46% bên trái), sticky theo header, ĐÚNG
                 hành vi #map-col thật. Leaflet + OpenStreetMap, cùng file js/leaflet.min.js đang
                 dùng ở trang tìm kiếm Homestay thật, ghim tĩnh 1 lần theo toà nhà đang hiện trong
                 trang (xem StorefrontController::index() — $mapMarkers), không cần bộ máy
                 real-time/API riêng như Home vì danh sách MiniHouse không đổi liên tục. --}}
            <div class="hidden md:block flex-1 min-w-0 sticky top-4 rounded-2xl overflow-hidden border border-gray-200" style="height:calc(100vh - 32px);">
                <div id="mh-map" style="width:100%; height:100%;"></div>
            </div>
        </div>
    </div>

    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')

    {{-- CSS marker/popup — COPY NGUYÊN từ bản đồ Homestay thật (public/css/search-page.css: .bpm,
         .bpm-count, .bpop-arr, .bpop-ct) — không load cả file đó vào đây để tránh đụng các rule
         #id khác của trang tìm kiếm Home, chỉ copy đúng các class marker/popup cần dùng. --}}
    <style>
        .bpm { background:#fff; border:2px solid #0f766e; border-radius:20px; padding:5px 11px; font-size:12px; font-weight:700; color:#0f766e; cursor:pointer; white-space:nowrap; box-shadow:0 2px 10px rgba(0,0,0,.18); transform:translate(-50%,-50%); position:relative; transition:transform .12s,background .12s,color .12s,box-shadow .12s; line-height:1.2; }
        .bpm.active, .bpm:hover { background:#0f766e; color:#fff; transform:translate(-50%,-50%) scale(1.15); box-shadow:0 4px 18px rgba(15,118,110,.38); z-index:9000 !important; }
        .bpm-count { display:inline-flex; align-items:center; justify-content:center; min-width:16px; height:16px; padding:0 3px; margin-left:5px; border-radius:50%; background:#0f766e; color:#fff; font-size:10px; }
        .bpm.active .bpm-count, .bpm:hover .bpm-count { background:#fff; color:#0f766e; }
        .bpop-arr { position:absolute; top:50%; transform:translateY(-50%); width:26px; height:26px; border-radius:50%; background:rgba(255,255,255,.95); border:none; font-size:15px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; z-index:5; }
        .bpop-arr:hover { background:#fff; transform:translateY(-50%) scale(1.1); }
        .bpop-arr-l { left:7px; }
        .bpop-arr-r { right:7px; }
        .bpop-ct { position:absolute; bottom:7px; right:7px; background:rgba(0,0,0,.48); color:#fff; font-size:10px; padding:2px 7px; border-radius:10px; font-weight:600; backdrop-filter:blur(3px); }
    </style>

    <script src="{{ asset('js/leaflet.min.js') }}"></script>
    <script>
        (function () {
            var mapEl = document.getElementById('mh-map');
            if (!mapEl || typeof L === 'undefined') return;

            var markers = @json($mapMarkers);
            var center = markers.length ? [markers[0].lat, markers[0].lng] : [16.0, 106.0];
            var zoom = markers.length ? 14 : 5;

            var map = L.map('mh-map').setView(center, zoom);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors',
                maxZoom: 19,
            }).addTo(map);

            function vndFormat(amount) {
                return Number(amount).toLocaleString('vi-VN') + 'đ';
            }

            // popupState[buildingId] = { rooms, idx } — cùng mô hình __popups của search-map.js thật,
            // giữ "phòng đang xem trong popup" riêng cho từng toà để rê chuột qua từng card cập nhật
            // đúng ảnh/giá mà không phải đóng-mở lại popup.
            var popupState = {};
            var pins = {};

            function buildPopupHtml(buildingId) {
                var hasMany = popupState[buildingId].rooms.length > 1;
                var arrows = hasMany
                    ? '<button class="bpop-arr bpop-arr-l" onclick="mhBpopNav(\'' + buildingId + '\',-1)">&#8249;</button>'
                        + '<button class="bpop-arr bpop-arr-r" onclick="mhBpopNav(\'' + buildingId + '\',1)">&#8250;</button>'
                        + '<div id="mh-pop-' + buildingId + '-ct" class="bpop-ct"></div>'
                    : '';

                return '<div style="background:#fff;">'
                    + '<div style="position:relative;height:138px;overflow:hidden;background:#f3f4f6;">'
                    +   '<div id="mh-pop-' + buildingId + '-img" style="width:100%;height:100%;"></div>'
                    +   arrows
                    + '</div>'
                    + '<div style="padding:10px 12px 12px;">'
                    +   '<p id="mh-pop-' + buildingId + '-name" style="font-size:12.5px;font-weight:700;color:#111827;margin:0 0 3px;"></p>'
                    +   '<p id="mh-pop-' + buildingId + '-price" style="font-size:14px;font-weight:800;color:#0f766e;margin:0 0 9px;"></p>'
                    +   '<a id="mh-pop-' + buildingId + '-btn" href="#" style="display:block;background:#0f766e;color:#fff;font-size:12px;font-weight:700;text-align:center;padding:7px 10px;border-radius:8px;text-decoration:none;">Xem phòng →</a>'
                    + '</div>'
                    + '</div>';
            }

            // mirror __bpopRender() của search-map.js thật — cập nhật đúng ảnh/tên/giá/link theo
            // idx hiện tại, không build lại toàn bộ popup (tránh giật khi bấm mũi tên).
            window.mhBpopRender = function (buildingId) {
                var state = popupState[buildingId];
                if (!state) return;
                var room = state.rooms[state.idx];

                var imgWrap = document.getElementById('mh-pop-' + buildingId + '-img');
                if (imgWrap) {
                    imgWrap.innerHTML = room.photo
                        ? '<img src="' + room.photo + '" style="width:100%;height:100%;object-fit:cover;display:block;" loading="lazy">'
                        : '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:12px;">Chưa có ảnh</div>';
                }

                var nameEl = document.getElementById('mh-pop-' + buildingId + '-name');
                if (nameEl) nameEl.textContent = 'Phòng ' + room.code;

                var priceEl = document.getElementById('mh-pop-' + buildingId + '-price');
                if (priceEl) priceEl.innerHTML = vndFormat(room.price) + '<span style="font-size:12px;font-weight:400;color:#6b7280;"> /tháng</span>';

                var btnEl = document.getElementById('mh-pop-' + buildingId + '-btn');
                if (btnEl) btnEl.href = room.url;

                var ctEl = document.getElementById('mh-pop-' + buildingId + '-ct');
                if (ctEl) ctEl.textContent = (state.idx + 1) + '/' + state.rooms.length;
            };

            window.mhBpopNav = function (buildingId, dir) {
                var state = popupState[buildingId];
                if (!state) return;
                state.idx = (state.idx + dir + state.rooms.length) % state.rooms.length;
                window.mhBpopRender(buildingId);
            };

            var bounds = [];
            markers.forEach(function (m) {
                popupState[m.buildingId] = { rooms: m.rooms, idx: 0 };

                var label = (m.rooms.length > 1 ? 'Từ ' : '') + vndFormat(m.minPrice);
                var pinHtml = '<div class="bpm">' + label + (m.rooms.length > 1 ? '<span class="bpm-count">' + m.rooms.length + '</span>' : '') + '</div>';

                var marker = L.marker([m.lat, m.lng], {
                    icon: L.divIcon({ className: '', html: pinHtml, iconSize: null, iconAnchor: [0, 0] }),
                }).addTo(map);

                marker.bindPopup(buildPopupHtml(m.buildingId), {
                    minWidth: 220, maxWidth: 244, closeButton: true, autoPan: true, offset: L.point(0, -6),
                });

                marker.on('popupopen', function () {
                    window.mhBpopRender(m.buildingId);
                    var pinEl = marker.getElement();
                    if (pinEl) { var b = pinEl.querySelector('.bpm'); if (b) b.classList.add('active'); }
                });
                marker.on('popupclose', function () {
                    var pinEl = marker.getElement();
                    if (pinEl) { var b = pinEl.querySelector('.bpm'); if (b) b.classList.remove('active'); }
                });

                pins[m.buildingId] = marker;
                bounds.push([m.lat, m.lng]);
            });

            if (bounds.length > 1) {
                map.fitBounds(bounds, { padding: [40, 40] });
            }

            // Rê chuột vào card phòng bên trái → mở đúng popup của toà đó, ở đúng phòng đang hover
            // (khớp theo data-room-code), và tự di chuyển bản đồ tới vị trí đó — ĐÚNG hành vi
            // mouseenter trên .branch-card của search-map.js thật.
            var activeCard = null;
            document.querySelectorAll('.mh-room-card').forEach(function (card) {
                var buildingId = card.getAttribute('data-building-id');
                var roomCode = card.getAttribute('data-room-code');
                var marker = pins[buildingId];
                if (!marker) return;

                card.addEventListener('mouseenter', function () {
                    if (activeCard && activeCard !== card) {
                        var prevMarker = pins[activeCard.getAttribute('data-building-id')];
                        if (prevMarker && prevMarker !== marker) prevMarker.closePopup();
                    }
                    activeCard = card;

                    var state = popupState[buildingId];
                    var idx = state.rooms.findIndex(function (r) { return r.code === roomCode; });
                    state.idx = idx >= 0 ? idx : 0;

                    marker.openPopup();
                    window.mhBpopRender(buildingId);
                    map.panTo(marker.getLatLng(), { animate: true, duration: 0.35 });
                });

                card.addEventListener('mouseleave', function () {
                    marker.closePopup();
                    if (activeCard === card) activeCard = null;
                });
            });
        })();
    </script>
@endsection
