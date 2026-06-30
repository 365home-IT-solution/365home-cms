@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    {{-- Header: ẩn mặc định, chỉ hiện khi mở expanded hero form --}}
    <div x-data="{ shown: false }"
         @hero-form-open.window="shown = true"
         @hero-form-close.window="shown = false"
         x-show="shown"
         style="display:none;">
        @livewire('bladethemev1::header')
    </div>

    <script>
        window.__heroAlwaysCompact = true;
    </script>
    @livewire('bladethemev1::hero-section', ['noBanner' => true])
   

    <style>
        #main-header-bar { z-index: 1050 !important; }
        #search-layout {
            display: flex;
            height: calc(100vh - 112px);
        }

        #rooms-left-panel {
            width: 100%;
            height: 100%;
            overflow-y: auto;
            border-right: 1px solid #f3f4f6;
            flex-shrink: 0;
        }

        #map-col {
            display: none;
            position: relative;
            flex-shrink: 0;
        }

        @media (min-width: 1024px) {
            #rooms-left-panel {
                width: 40%;
            }

            #map-col {
                display: block;
                width: 60%;
            }
        }

        #search-map {
            width: 100%;
            height: 100%;
        }

        /* Branch marker pin */
        .bpm {
            background: #fff;
            border: 2px solid #0f766e;
            border-radius: 20px;
            padding: 5px 11px;
            font-size: 12px;
            font-weight: 700;
            color: #0f766e;
            cursor: pointer;
            white-space: nowrap;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .18);
            transform: translate(-50%, -50%);
            position: relative;
            transition: transform .12s, background .12s, color .12s, box-shadow .12s;
            line-height: 1.2;
        }

        .bpm.active,
        .bpm:hover {
            background: #0f766e;
            color: #fff;
            transform: translate(-50%, -50%) scale(1.15);
            box-shadow: 0 4px 18px rgba(15, 118, 110, .38);
            z-index: 9000 !important;
        }

        /* Leaflet popup — Airbnb style */
        .leaflet-popup-content-wrapper {
            border-radius: 14px !important;
            padding: 0 !important;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0,0,0,.18), 0 1px 6px rgba(0,0,0,.10) !important;
            border: 1px solid rgba(0,0,0,.06) !important;
        }

        .leaflet-popup-content {
            margin: 0 !important;
            width: 244px !important;
            line-height: 1 !important;
        }

        .leaflet-popup-tip-container {
            display: none !important;
        }

        .leaflet-popup-close-button {
            position: absolute !important;
            top: 7px !important;
            right: 7px !important;
            background: rgba(0,0,0,.42) !important;
            border-radius: 50% !important;
            width: 22px !important;
            height: 22px !important;
            padding: 0 !important;
            color: #fff !important;
            font-size: 16px !important;
            line-height: 22px !important;
            text-align: center;
            z-index: 20;
            backdrop-filter: blur(4px);
        }

        /* Popup slide arrows */
        .bpop-arr {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: rgba(255,255,255,.95);
            border: none;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 6px rgba(0,0,0,.22);
            color: #111;
            z-index: 5;
            transition: background .15s, transform .15s;
        }
        .bpop-arr:hover {
            background: #fff;
            transform: translateY(-50%) scale(1.1);
        }

        .bpop-arr-l { left: 7px; }
        .bpop-arr-r { right: 7px; }

        .bpop-ct {
            position: absolute;
            bottom: 7px;
            right: 7px;
            background: rgba(0,0,0,.48);
            color: #fff;
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 10px;
            font-weight: 600;
            backdrop-filter: blur(3px);
        }
    </style>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <div id="search-layout">

        <div id="rooms-left-panel">
            @livewire('bladethemev1::search-results-grid')
        </div>

        <div id="map-col">
            <div id="search-map"></div>

            @if (isset($province) && $province)
                <div style="position:absolute;top:1rem;left:1rem;z-index:10;pointer-events:none;">
                    <div
                        style="background:rgba(255,255,255,.92);backdrop-filter:blur(4px);border-radius:12px;padding:7px 14px;box-shadow:0 2px 8px rgba(0,0,0,.1);display:inline-flex;align-items:center;gap:6px;">
                        <svg style="width:14px;height:14px;color:#0f766e;flex-shrink:0;" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <span style="font-size:13px;font-weight:700;color:#111827;">{{ $province->name }}</span>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        onload="typeof initSearchMap === 'function' && initSearchMap()"></script>

    <script>
        var __searchMapConfig = {
            lat: {{ $mapLat ?? 16.0 }},
            lng: {{ $mapLng ?? 106.0 }},
            zoom: {{ $mapZoom ?? 6 }},
            @if (isset($province) && $province && $province->lat && $province->lng)
                provinceLat: {{ $province->lat }},
                provinceLng: {{ $province->lng }},
            @else
                provinceLat: null,
                provinceLng: null,
            @endif
        };

        var __mapInitRetries = 0;
        var __activeBranchCard = null;

        // ─── Popup slide data ───────────────────────────────────────────────────────
        var __popups = {};

        window.__bpopNav = function(popId, dir) {
            var p = __popups[popId];
            if (!p || p.rooms.length === 0) return;
            p.idx = (p.idx + dir + p.rooms.length) % p.rooms.length;
            __bpopRender(popId);
        };

        function __bpopRender(popId) {
            var p = __popups[popId];
            if (!p || p.rooms.length === 0) return;
            var room = p.rooms[p.idx];

            var imgWrap = document.getElementById(popId + '-imgwrap');
            if (imgWrap) {
                if (room.image) {
                    imgWrap.innerHTML = '<img src="' + room.image +
                        '" style="width:100%;height:100%;object-fit:cover;display:block;" loading="lazy"/>';
                } else {
                    imgWrap.innerHTML =
                        '<div style="display:flex;align-items:center;justify-content:center;height:100%;background:#f3f4f6;">' +
                        '<svg style="width:32px;height:32px;color:#d1d5db;" fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
                        '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>' +
                        '</svg></div>';
                }
            }

            var nameEl = document.getElementById(popId + '-name');
            if (nameEl) nameEl.textContent = room.name || '';

            var priceEl = document.getElementById(popId + '-price');
            if (priceEl) {
                priceEl.innerHTML = room.price ?
                    Number(room.price).toLocaleString('vi-VN') + 'đ' +
                    (room.time ? '<span style="font-size:12px;font-weight:400;color:#6b7280;"> /' + room.time + '</span>' :
                        '') :
                    'Liên hệ';
            }

            var btnEl = document.getElementById(popId + '-btn');
            if (btnEl) btnEl.href = room.url || '#';

            var ctEl = document.getElementById(popId + '-ct');
            if (ctEl) ctEl.textContent = (p.idx + 1) + '/' + p.rooms.length;
        }

        function buildPopupHtml(popId, rooms) {
            var hasMany = rooms.length > 1;
            var arrows = hasMany ?
                '<button class="bpop-arr bpop-arr-l" onclick="__bpopNav(\'' + popId + '\',-1)">&#8249;</button>' +
                '<button class="bpop-arr bpop-arr-r" onclick="__bpopNav(\'' + popId + '\',1)">&#8250;</button>' +
                '<div id="' + popId + '-ct" class="bpop-ct"></div>' : '';

            return '<div style="background:#fff;">'
                + '<div style="position:relative;height:138px;overflow:hidden;background:#f3f4f6;">'
                +   '<div id="' + popId + '-imgwrap" style="width:100%;height:100%;"></div>'
                +   arrows
                + '</div>'
                + '<div style="padding:10px 12px 12px;">'
                +   '<p id="' + popId + '-name" style="font-size:12.5px;font-weight:700;color:#111827;margin:0 0 3px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.4;"></p>'
                +   '<p id="' + popId + '-price" style="font-size:14px;font-weight:800;color:#0f766e;margin:0 0 9px;"></p>'
                +   '<a id="' + popId + '-btn" href="#" '
                +     'style="display:block;background:#0f766e;color:#fff;font-size:12px;font-weight:700;text-align:center;padding:7px 10px;border-radius:8px;text-decoration:none;letter-spacing:.01em;" '
                +     'onclick="return this.href!==\'#\'">Xem phòng →</a>'
                + '</div>'
                + '</div>';
        }

        // ─── Map init ────────────────────────────────────────────────────────────────
        function initSearchMap() {
            if (typeof L === 'undefined') {
                if (__mapInitRetries++ < 25) setTimeout(initSearchMap, 200);
                return;
            }
            __mapInitRetries = 0;

            var mapEl = document.getElementById('search-map');
            if (!mapEl) return;
            if (mapEl._leaflet_id) {
                if (window.__searchMapInstance) window.__searchMapInstance.invalidateSize();
                return;
            }

            // Đọc branch data từ JSON script tag (an toàn, không bị HTML encode)
            var branchData = [];
            var dataEl = document.getElementById('branch-map-data');
            if (dataEl) {
                try {
                    branchData = JSON.parse(dataEl.textContent);
                } catch (e) {
                    console.error('branch data parse error', e);
                }
            }

            var cfg = __searchMapConfig;
            var map = L.map(mapEl, {
                    zoomControl: true,
                    scrollWheelZoom: true
                })
                .setView([cfg.lat, cfg.lng], cfg.zoom);
            window.__searchMapInstance = map;

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            }).addTo(map);

            var cards = document.querySelectorAll('.branch-card');
            var bounds = [];

            branchData.forEach(function(branch, idx) {
                var lat = parseFloat(branch.lat);
                var lng = parseFloat(branch.lng);
                var rooms = branch.rooms || [];
                var roomCount = branch.room_count || rooms.length;

                var hasCoords = lat && lng && !isNaN(lat) && !isNaN(lng);

                var card = cards[idx] || null;

                // Hàm highlight card bên trái
                function activateCard() {
                    cards.forEach(function(c) {
                        c.style.borderColor = '#f3f4f6';
                    });
                    if (card) {
                        card.style.borderColor = '#0f766e';
                    }

                    // Reset marker cũ
                    if (__activeBranchCard && __activeBranchCard.__leafletPin) {
                        var oldEl = __activeBranchCard.__leafletPin.getElement();
                        if (oldEl) {
                            var b = oldEl.querySelector('.bpm');
                            if (b) b.classList.remove('active');
                        }
                    }
                    __activeBranchCard = card;
                }

                // Click trên card bên trái
                if (card) {
                    card.addEventListener('click', function() {
                        activateCard();
                        if (hasCoords) lm.openPopup();
                    });
                }

                if (!hasCoords) return;
                bounds.push([lat, lng]);

                // Unique popup id
                var popId = 'bp' + idx;
                __popups[popId] = {
                    rooms: rooms,
                    idx: 0
                };

                // Marker
                var pinHtml = '<div class="bpm">' + roomCount + ' phòng</div>';
                var lm = L.marker([lat, lng], {
                    icon: L.divIcon({
                        className: '',
                        html: pinHtml,
                        iconSize: null,
                        iconAnchor: [0, 0]
                    }),
                    zIndexOffset: 0
                }).addTo(map);

                if (card) card.__leafletPin = lm;

                // Popup
                var popupHtml = buildPopupHtml(popId, rooms);
                lm.bindPopup(popupHtml, {
                    maxWidth: 244,
                    minWidth: 244,
                    closeButton: true,
                    autoPan: true,
                    autoPanPaddingTopLeft: L.point(16, 16),
                    autoPanPaddingBottomRight: L.point(16, 16),
                    offset: L.point(0, -6)
                });

                // Render popup content sau khi DOM sẵn sàng
                lm.on('popupopen', function() {
                    __popups[popId].idx = 0;
                    __bpopRender(popId);

                    // Đưa marker vào giữa map, offset lên trên ~30% để popup hiện bên trên không bị cắt
                    var mapSize = map.getSize();
                    var markerPx = map.latLngToContainerPoint([lat, lng]);
                    var popupH = 280; // chiều cao popup ước tính
                    var targetY = mapSize.y / 2 + popupH / 2 - 10;
                    var dy = markerPx.y - targetY;
                    if (Math.abs(dy) > 20) {
                        map.panBy([0, dy], { animate: true, duration: 0.35, easeLinearity: 0.5 });
                    }

                    // Activate pin style
                    var pinEl = lm.getElement();
                    if (pinEl) {
                        var b = pinEl.querySelector('.bpm');
                        if (b) b.classList.add('active');
                    }
                    activateCard();
                    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                });

                lm.on('popupclose', function() {
                    var pinEl = lm.getElement();
                    if (pinEl) {
                        var b = pinEl.querySelector('.bpm');
                        if (b) b.classList.remove('active');
                    }
                });
            });

            // Fit to markers
            if (bounds.length > 0) {
                try {
                    map.fitBounds(bounds, {
                        padding: [50, 50],
                        maxZoom: 14
                    });
                } catch (e) {
                    if (cfg.provinceLat) map.setView([cfg.provinceLat, cfg.provinceLng], 12);
                }
            } else if (cfg.provinceLat) {
                map.setView([cfg.provinceLat, cfg.provinceLng], 12);
            }

            setTimeout(function() {
                map.invalidateSize();
            }, 150);
        }

        document.addEventListener('DOMContentLoaded', initSearchMap);
        document.addEventListener('livewire:navigated', function() {
            var mapEl = document.getElementById('search-map');
            if (mapEl && mapEl._leaflet_id && window.__searchMapInstance) {
                window.__searchMapInstance.remove();
                window.__searchMapInstance = null;
            }
            __activeBranchCard = null;
            __popups = {};
            initSearchMap();
        });
    </script>
 @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection
