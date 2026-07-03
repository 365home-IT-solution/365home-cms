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
        #main-header-bar {
            z-index: 1150 !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
        }
        /* Mobile (< md): bản đồ full màn hình phía sau, phía trên là bottom-sheet chứa danh sách chi
           nhánh có thể kéo lên/xuống. Kéo xuống (peek) → chi nhánh dạng slide ngang. Kéo lên hết
           (full) → chi nhánh xếp 1 cột.
           Desktop/iPad (>= md): xếp ngang như cũ — danh sách bên trái, bản đồ cố định bên phải. */
        #search-layout {
            display: flex;
            flex-direction: column;
            position: relative;
            height: calc(100vh - 60px);
            overflow: hidden;
        }

        #map-col {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
        }

        #rooms-left-panel {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 20;
            width: 100%;
            height: 48%;
            min-height: 260px;
            max-height: 94%;
            background: #fff;
            border-radius: 18px 18px 0 0;
            box-shadow: 0 -6px 24px rgba(0, 0, 0, .16);
            display: flex;
            flex-direction: column;
            transition: height .32s cubic-bezier(.32, .72, 0, 1);
        }

        #rooms-left-panel.sheet-dragging {
            transition: none;
        }

        #rooms-left-panel.sheet-full {
            height: 94%;
        }

        #sheet-handle {
            flex-shrink: 0;
            display: flex;
            justify-content: center;
            padding: 10px 0 6px;
            cursor: grab;
            touch-action: none;
        }

        #sheet-handle::before {
            content: '';
            width: 36px;
            height: 4px;
            border-radius: 3px;
            background: #d1d5db;
        }

        #sheet-scroll {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* Base .branch-grid (slide ngang mobile / lưới 2 cột desktop) đã khai báo trong
           livewire.search-results-grid — dùng chung cho mọi trang. Ở đây chỉ override thêm khi
           bottom-sheet của trang này được kéo full màn hình: chi nhánh xếp 1 cột. */
        #rooms-left-panel.sheet-full .branch-grid {
            display: flex;
            flex-direction: column;
            overflow: visible;
        }

        #rooms-left-panel.sheet-full .branch-grid .branch-card {
            width: 100%;
            max-width: none;
        }

        @media (min-width: 768px) {
            #search-layout {
                flex-direction: row;
                height: calc(100vh - 112px);
                overflow: visible;
            }

            #rooms-left-panel {
                position: static;
                width: 40%;
                height: 100%;
                max-height: none;
                min-height: 0;
                border-right: 1px solid #f3f4f6;
                border-radius: 0;
                box-shadow: none;
                flex-shrink: 0;
                transition: none;
            }

            #sheet-handle {
                display: none;
            }

            #map-col {
                position: relative;
                inset: auto;
                width: 60%;
                height: 100%;
            }

            /* Desktop luôn giữ lưới 2 cột (đã set ở component dùng chung), kể cả khi DOM còn
               mang class .sheet-full từ lúc ở mobile */
            #rooms-left-panel.sheet-full .branch-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
                overflow: visible;
            }

            #rooms-left-panel.sheet-full .branch-grid .branch-card {
                width: auto;
                max-width: none;
                scroll-snap-align: none;
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

        /* Badge tròn hiển thị số phòng ngay sau giá */
        .bpm-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 16px;
            height: 16px;
            padding: 0 3px;
            margin-left: 5px;
            border-radius: 50%;
            background:#0f766e;
            color: white;
            font-size: 10px;
            font-weight: 800;
            line-height: 1;
        }

        .bpm.active .bpm-count,
        .bpm:hover .bpm-count {
            background:#fff;
            color: #0f766e ;
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
            <div id="sheet-handle" class="md:hidden"></div>
            <div id="sheet-scroll">
                @livewire('bladethemev1::search-results-grid')
            </div>
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

        // ─── Bottom-sheet kéo lên/xuống (mobile < md) ──────────────────────────────────
        (function() {
            var sheet = null,
                handle = null,
                dragging = false,
                startY = 0,
                startH = 0,
                containerH = 0;
            var FULL_THRESHOLD = 0.62;

            function isMobile() {
                return window.innerWidth < 768;
            }

            window.__setSheetState = function(state) {
                if (!sheet) return;
                sheet.style.height = '';
                if (state === 'full') sheet.classList.add('sheet-full');
                else sheet.classList.remove('sheet-full');
            };

            function onStart(clientY) {
                if (!isMobile()) return;
                dragging = true;
                startY = clientY;
                containerH = sheet.parentElement.getBoundingClientRect().height;
                startH = sheet.getBoundingClientRect().height;
                sheet.classList.add('sheet-dragging');
            }

            function onMove(clientY) {
                if (!dragging) return;
                var dy = startY - clientY;
                var newH = startH + dy;
                var minH = containerH * 0.14;
                var maxH = containerH * 0.94;
                if (newH < minH) newH = minH;
                if (newH > maxH) newH = maxH;
                sheet.style.height = newH + 'px';
            }

            function onEnd() {
                if (!dragging) return;
                dragging = false;
                sheet.classList.remove('sheet-dragging');
                var h = sheet.getBoundingClientRect().height;
                var ratio = containerH > 0 ? h / containerH : 0;
                window.__setSheetState(ratio > FULL_THRESHOLD ? 'full' : 'peek');
            }

            function initSheet() {
                sheet = document.getElementById('rooms-left-panel');
                handle = document.getElementById('sheet-handle');
                if (!sheet || !handle || handle.__bound) return;
                handle.__bound = true;

                handle.addEventListener('touchstart', function(e) {
                    onStart(e.touches[0].clientY);
                }, { passive: true });
                handle.addEventListener('touchmove', function(e) {
                    onMove(e.touches[0].clientY);
                }, { passive: true });
                handle.addEventListener('touchend', onEnd);

                handle.addEventListener('mousedown', function(e) {
                    onStart(e.clientY);
                    function mm(ev) {
                        onMove(ev.clientY);
                    }
                    function mu() {
                        onEnd();
                        document.removeEventListener('mousemove', mm);
                        document.removeEventListener('mouseup', mu);
                    }
                    document.addEventListener('mousemove', mm);
                    document.addEventListener('mouseup', mu);
                });

                handle.addEventListener('click', function() {
                    if (dragging) return;
                    var isFull = sheet.classList.contains('sheet-full');
                    window.__setSheetState(isFull ? 'peek' : 'full');
                });
            }

            document.addEventListener('DOMContentLoaded', initSheet);
            document.addEventListener('livewire:navigated', function() {
                if (handle) handle.__bound = false;
                initSheet();
            });
        })();

        // ─── Kéo bằng tay (chuột/chạm) cho slide chi nhánh ngang (.branch-grid, mobile peek) ──
        (function() {
            var grid = null,
                startX = 0,
                scrollStart = 0,
                dragged = false;
            var DRAG_THRESHOLD = 6;

            function isHorizontalMode(g) {
                if (window.innerWidth >= 768) return false;
                var panel = document.getElementById('rooms-left-panel');
                return !(panel && panel.classList.contains('sheet-full'));
            }

            function onDown(e) {
                var g = e.target.closest && e.target.closest('.branch-grid');
                if (!g || !isHorizontalMode(g)) return;
                grid = g;
                startX = e.clientX;
                scrollStart = grid.scrollLeft;
                dragged = false;
                // Chưa bật chế độ kéo (không tắt pointer-events của card) ở bước này —
                // nếu bật ngay từ pointerdown, một cú chạm/tap đơn giản cũng sẽ mất khả năng
                // bắn sự kiện click trên card (do card bị pointer-events:none quá sớm), khiến
                // không thể "chọn để hiện bản đồ" được nữa. Chỉ khi thực sự vượt ngưỡng di
                // chuyển (kéo thật) mới coi là drag.
            }

            function onMove(e) {
                if (!grid) return;
                var dx = e.clientX - startX;
                if (!dragged && Math.abs(dx) > DRAG_THRESHOLD) {
                    dragged = true;
                    grid.classList.add('branch-grid-dragging');
                }
                if (dragged) {
                    grid.scrollLeft = scrollStart - dx;
                    e.preventDefault();
                }
            }

            function onUp() {
                if (!grid) return;
                grid.classList.remove('branch-grid-dragging');
                if (dragged) {
                    var g = grid;
                    var suppressClick = function(ev) {
                        ev.stopPropagation();
                        ev.preventDefault();
                        g.removeEventListener('click', suppressClick, true);
                    };
                    g.addEventListener('click', suppressClick, true);
                    setTimeout(function() {
                        g.removeEventListener('click', suppressClick, true);
                    }, 0);
                }
                grid = null;
            }

            document.addEventListener('pointerdown', onDown);
            document.addEventListener('pointermove', onMove, { passive: false });
            document.addEventListener('pointerup', onUp);
            document.addEventListener('pointercancel', onUp);
        })();

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

            var bookingEl = document.getElementById(popId + '-booking');
            if (bookingEl) bookingEl.href = p.bookingUrl || '#';

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
                +   '<a id="' + popId + '-booking" href="#" '
                +     'style="display:block;margin-top:7px;background:#111827;color:#fff;font-size:12px;font-weight:800;text-align:center;padding:7px 10px;border-radius:8px;text-decoration:none;letter-spacing:.01em;" '
                +     'onclick="return this.href!==\'#\'">Đặt phòng chi nhánh →</a>'
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
                var roomPrices = rooms
                    .map(function(r) { return parseFloat(r.price); })
                    .filter(function(p) { return !isNaN(p) && p > 0; });
                var minPrice = roomPrices.length ? Math.min.apply(null, roomPrices) : null;

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
                        // Trên mobile, nếu sheet đang kéo full (che hết bản đồ) thì thu gọn lại
                        // để người dùng thấy pin vừa được kích hoạt trên bản đồ.
                        var sheetEl = document.getElementById('rooms-left-panel');
                        if (window.innerWidth < 768 && window.__setSheetState && sheetEl &&
                            sheetEl.classList.contains('sheet-full')) {
                            window.__setSheetState('peek');
                        }
                    });
                }

                if (!hasCoords) return;
                bounds.push([lat, lng]);

                // Unique popup id
                var popId = 'bp' + idx;
                __popups[popId] = {
                    rooms: rooms,
                    idx: 0,
                    bookingUrl: branch.booking_url || '#'
                };

                // Marker
                var pinLabel = minPrice
                    ? 'Từ ' + Number(minPrice).toLocaleString('vi-VN') + 'đ'
                    : 'Liên hệ';
                var pinHtml = '<div class="bpm">' + pinLabel + '<span class="bpm-count">' + roomCount + '</span></div>';
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
                    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
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
