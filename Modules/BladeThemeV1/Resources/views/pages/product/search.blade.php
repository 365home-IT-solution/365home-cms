@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    @php
        // ?view=branches — panel bên phải hiển thị danh sách phòng + bảng đặt khung giờ của chi
        // nhánh được chọn (thay cho bản đồ, không có ý nghĩa khi đang liệt kê chi nhánh). Livewire
        // Book component mount 1 lần với config rỗng, "load-branch" (search-results.js) nạp lại
        // dữ liệu chi nhánh mỗi khi người dùng bấm 1 card — xem Book::loadBranch(). /homestay/
        // {location} là URL rút gọn của /s/{location}?view=branches (không mang query ?view=) nên
        // ngầm định luôn ở chế độ này — cùng luật với isBranchesView() trong search-results.js.
        $isBranchesView = request()->query('view') === 'branches' || request()->is('homestay/*');
    @endphp

    <script>
        // Trang tìm kiếm có bản đồ sticky neo theo chiều cao header — nếu header tự thu gọn khi
        // cuộn (như các trang khác) thì bản đồ bị giật theo mỗi lần chiều cao đó đổi. Khoá header
        // ở trạng thái đầy đủ trong lúc ở trang này; trả lại hành vi bình thường khi rời trang.
        window.__headerAlwaysExpanded = true;
        document.addEventListener('livewire:navigating', function () {
            window.__headerAlwaysExpanded = false;
        }, { once: true });
    </script>
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    <style>
        /* Mobile (< md): bản đồ full màn hình phía sau, phía trên là bottom-sheet chứa danh sách chi
           nhánh có thể kéo lên/xuống. Kéo xuống (peek) → chi nhánh dạng slide ngang. Kéo lên hết
           (full) → chi nhánh xếp 1 cột.
           Desktop/iPad (>= md): xếp ngang như cũ — danh sách bên trái, bản đồ cố định bên phải. */
        #search-layout {
            display: flex;
            flex-direction: column;
            position: relative;
            height: calc(100vh - var(--search-header-h, 60px));
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

        /* ?view=branches: danh sách chi nhánh vẫn là bottom sheet nổi như bình thường, chỉ cao
           hơn (peek lớn hơn) để thấy trọn 4 card (2 hàng x 2 cột, card giữ nguyên kích thước bình
           thường — không thu nhỏ card để nhồi thêm cột) thay vì chỉ hé 1 hàng. #search-layout vẫn
           giữ đúng khung 100vh + overflow:hidden mặc định (map luôn hiện ở #map-col phía sau,
           không còn nhánh "panel đặt lịch" auto-height như trước) nên position kế thừa mặc định
           (absolute theo #search-layout) là đủ, không cần position:fixed riêng. CHỈ áp dụng < 768px
           — nếu để unscoped, "#rooms-left-panel.branches-peek" (1 id + 1 class) có độ đặc hiệu cao
           hơn rule desktop "#rooms-left-panel" trong @media(min-width:768px) (chỉ 1 id), nên sẽ đè
           chiều cao lên cả desktop bất kể thứ tự khai báo — phá layout 2 cột. */
        @media (max-width: 767px) {
            #rooms-left-panel.branches-peek {
                height: 54%;
                min-height: 420px;
            }
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
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        #sheet-scroll::-webkit-scrollbar {
            display: none;
        }

        /* Tiêu đề số lượng kết quả — mobile: thanh nhỏ dính trên cùng bottom-sheet. Desktop:
           tiêu đề lớn nằm trong luồng trang bình thường (không sticky), giống Airbnb. */
        .search-count-header {
            padding: 12px 14px 10px;
            border-bottom: 1px solid #f3f4f6;
            background: #fff;
            position: sticky;
            top: 0;
            z-index: 5;
        }

        .search-count-title {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: #111827;
        }

        @media (min-width: 768px) {
            .search-count-header {
                position: static;
                border-bottom: none;
                padding: 4px 4px 18px;
            }

            .search-count-title {
                font-size: 22px;
                font-weight: 800;
            }
        }

        /* Lề trái/phải khớp với .search-count-header ở cùng breakpoint để nội dung (card chi
           nhánh, danh sách phòng) thẳng hàng với tiêu đề phía trên, không bị thụt vào trong. */
        #search-results-body {
            padding: 10px 14px 32px;
        }
        @media (min-width: 768px) {
            #search-results-body {
                padding: 4px 4px 20px;
            }
        }

        /* Phòng — mặc định (mobile): slide ngang, mỗi lần 1 thẻ chiếm trọn khung hình, có nút
           prev/next thay vì vuốt tay. Từ md trở lên: lưới cố định 3 cột (kiểu Airbnb). Sheet kéo
           full màn hình (mobile) tự override thêm ở dưới: chi nhánh xếp 1 cột. */
        .room-slider {
            position: relative;
        }

        .branch-grid {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            overflow-y: hidden;
            scroll-snap-type: x mandatory;
            padding-bottom: 4px;
            -webkit-overflow-scrolling: touch;
            cursor: grab;
            touch-action: pan-y;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .branch-grid::-webkit-scrollbar {
            display: none;
        }

        .branch-grid.branch-grid-dragging {
            cursor: grabbing;
            scroll-snap-type: none;
            scroll-behavior: auto;
        }

        .branch-grid.branch-grid-dragging .branch-card {
            pointer-events: none;
        }

        .branch-grid .branch-card {
            flex: 0 0 100%;
            width: 100%;
            max-width: none;
            scroll-snap-align: start;
        }

        /* Card phòng dùng chung window.roomCardHtml() (giống trang chủ) — .home-card vốn có
           bề rộng cố định cho carousel ngang trên trang chủ; ở đây nó nằm trong .branch-card (đã
           được .branch-grid quyết định bề rộng theo slide/lưới) nên cho lấp đầy 100%. */
        .branch-card .home-card {
            width: 100%;
            max-width: none;
            flex: none;
        }

        .room-slider-nav {
            position: absolute;
            top: 36%;
            transform: translateY(-50%);
            z-index: 4;
            width: 30px;
            height: 30px;
            border: none;
            border-radius: 50%;
            background: rgba(255, 255, 255, .92);
            box-shadow: 0 1px 6px rgba(0, 0, 0, .22);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            cursor: pointer;
        }

        .room-slider-nav svg {
            width: 16px;
            height: 16px;
            color: #111827;
        }

        .room-slider-nav.room-slider-prev {
            left: 6px;
        }

        .room-slider-nav.room-slider-next {
            right: 6px;
        }

        .room-slider-nav[disabled] {
            opacity: 0;
            pointer-events: none;
        }

        .room-wishlist-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            z-index: 3;
            width: 28px;
            height: 28px;
            border: none;
            border-radius: 50%;
            background: transparent;
            box-shadow: none;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            cursor: pointer;
            transition: transform .15s ease;
        }
        .room-wishlist-btn:active { transform: scale(.86); }
        .room-wishlist-btn.room-wishlist-pop { animation: roomWishlistPop .3s ease; }

        @media (hover: hover) {
            .room-wishlist-btn:hover { transform: scale(1.08); }
        }

        @keyframes roomWishlistPop {
            0% { transform: scale(1); }
            35% { transform: scale(1.35); }
            100% { transform: scale(1); }
        }

        #rooms-left-panel.sheet-full .branch-grid {
            display: flex;
            flex-direction: column;
            overflow: visible;
        }

        /* Sheet kéo full màn hình: chi nhánh xếp 1 cột dọc, không còn slide ngang nên ẩn
           nút prev/next của .room-slider. */
        #rooms-left-panel.sheet-full .room-slider-nav {
            display: none;
        }

        #rooms-left-panel.sheet-full .branch-grid .branch-card {
            width: 100%;
            max-width: none;
        }

        @media (min-width: 768px) {
            /* Bỏ khung cao cố định + scroll riêng của mobile: desktop cuộn theo trang bình
               thường (1 thanh cuộn duy nhất), bản đồ dùng position:sticky để luôn hiện trong
               khung nhìn khi cuộn danh sách — giống cách Airbnb làm. */
            #search-layout {
                flex-direction: row;
                align-items: flex-start;
                height: auto;
                overflow: visible;
                gap: 40px;
            }

            #rooms-left-panel {
                position: static;
                width: 46%;
                height: auto;
                max-height: none;
                min-height: 0;
                border-right: none;
                border-radius: 0;
                box-shadow: none;
                flex-shrink: 0;
                transition: none;
            }

            #sheet-handle {
                display: none;
            }

            #sheet-scroll {
                flex: none;
                min-height: 0;
                overflow-y: visible;
            }

            #map-col {
                position: sticky;
                inset: auto;
                top: var(--search-header-h, 0px);
                align-self: flex-start;
                flex: 1 1 auto;
                width: auto;
                /* min-width mặc định của flex item là "auto" — cho phép nó nới rộng vượt khỏi
                   phần được flex-grow cấp phát để chứa vừa nội dung con (vd carousel phòng rộng
                   hơn khung nhìn ở panel chi nhánh), phá luôn layout sticky/bản đồ. Ép về 0 để
                   #map-col luôn bám đúng chiều rộng cột phải, phần con tự cuộn riêng. */
                min-width: 0;
                height: calc(100vh - var(--search-header-h, 0px) - 24px);
                padding: 4px 0 24px;
            }

            /* Desktop: lưới cố định 3 cột (giống Airbnb) */
            .branch-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 20px 14px;
                overflow: visible;
                cursor: default;
                touch-action: auto;
            }

            .branch-grid .branch-card {
                width: auto;
                max-width: none;
                scroll-snap-align: none;
            }

            .room-slider-nav {
                display: none;
            }

            /* Bo tròn bản đồ + hở lề (giống Airbnb) — chỉ áp dụng ở desktop, mobile bản đồ
               vẫn full màn hình phía sau bottom-sheet nên không bo góc. */
            #search-map {
                border-radius: 16px;
                overflow: hidden;
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

        .hide-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        .hide-scrollbar::-webkit-scrollbar {
            display: none;
        }

        /* Grid chi nhánh — bình thường, không nền/không đổ bóng. padding:0 để card thẳng hàng
           với tiêu đề "X chi nhánh tại..." phía trên (cùng mốc trái với #search-results-body).
           2 card/hàng ở mọi kích thước — không thu nhỏ card để nhồi thêm cột; muốn thấy nhiều
           chi nhánh hơn (4 card = 2 hàng) thì tăng CHIỀU CAO bottom sheet (xem .branches-peek). */
        #search-results-body .branches-track {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        #search-results-body .branches-track a.home-card {
            border-radius: 16px;
            -webkit-tap-highlight-color: transparent;
        }

        /* Skeleton loading cho danh sách card chi nhánh (?view=branches) trong lúc gọi
           /api/v1/search/branches — hiện ngay khi bắt đầu tải (search-results.js:
           renderBranchesSkeleton()) thay vì để #search-results-body trống trơn, dùng đúng khung
           .branches-track/.home-card như card thật nên không bị giật layout khi thay thế. */
        @keyframes branch-skel-shimmer {
            0% { background-position: -400px 0; }
            100% { background-position: 400px 0; }
        }
        .branch-skel {
            background: linear-gradient(90deg, #eef0f2 25%, #f7f8f9 37%, #eef0f2 63%);
            background-size: 800px 100%;
            animation: branch-skel-shimmer 1.4s ease-in-out infinite;
            border-radius: 6px;
            display: block;
        }
        .branch-skel-img {
            padding-top: 72%;
            border-radius: 16px;
        }

        /* Skeleton loading cho danh sách card PHÒNG (tìm kiếm thường, không phải ?view=branches)
           trong lúc gọi GraphQL search — hiện ngay khi bắt đầu tải (search-results.js:
           renderSearchSkeleton()) thay vì để #search-results-body trống trơn, dùng đúng khung
           .branch-grid/.branch-card/.home-card như card thật nên không bị giật layout khi thay
           bằng renderResults() thật. */
        @keyframes search-skel-shimmer {
            0% { background-position: -400px 0; }
            100% { background-position: 400px 0; }
        }
        .search-skel {
            background: linear-gradient(90deg, #eef0f2 25%, #f7f8f9 37%, #eef0f2 63%);
            background-size: 800px 100%;
            animation: search-skel-shimmer 1.4s ease-in-out infinite;
            border-radius: 6px;
            display: block;
        }
        .search-skel-img {
            padding-top: 72%;
            border-radius: 14px;
        }

        /* Danh sách phòng trong panel chi nhánh (#branch-detail-rooms) — carousel ngang, mỗi
           card kích thước cố định để tối đa ~3 card lọt khung nhìn, dư ra thì cuộn/bấm prev-next
           (carouselNav() trong home-sections.js) thay vì hiện hết theo lưới dọc dài vô hạn. */
        #branch-detail-rooms .home-card {
            flex: 0 0 calc(46vw - 20px);
            width: calc(46vw - 20px);
            max-width: calc(46vw - 20px);
        }
        @media (min-width: 768px) {
            #branch-detail-rooms .home-card {
                flex: 0 0 240px;
                width: 240px;
                max-width: 240px;
            }
        }

        .carousel-nav-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #f3f4f6;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            padding: 0;
            transition: background .15s ease;
        }
        .carousel-nav-btn:hover { background: #e5e7eb; }
        .carousel-nav-btn svg { width: 16px; height: 16px; color: #374151; }
    </style>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <div id="search-layout" class="md:max-w-11xl md:mx-auto md:px-6">

        <div id="rooms-left-panel" class="{{ $isBranchesView ? 'branches-peek' : '' }}">
            <div id="sheet-handle" class="md:hidden"></div>
            <div id="sheet-scroll">
                <div id="search-results-mount">
                    <div class="mt-0 md:mt-[30px] search-count-header">
                        <p class="search-count-title" style="font-weight:500;color:#6b7280;">Đang tải kết quả...</p>
                    </div>
                    <div id="search-results-body"></div>
                </div>
                <script type="application/json" id="branch-map-data">[]</script>
            </div>
        </div>

        {{-- Luôn hiện bản đồ ở đây, kể cả ?view=branches (trước đây bị thay bằng panel đặt lịch
             inline khi chọn 1 chi nhánh — đã bỏ, card chi nhánh giờ điều hướng thẳng sang
             /branch/{slug} thay vì load inline, xem search-results.js). --}}
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

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

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

        // ─── Đo chiều cao thực tế của header (#main-header-bar) và set CSS var ────────
        // #search-layout dùng calc(100vh - var(--search-header-h)) để lấp đầy đúng phần
        // còn lại bên dưới header — không đoán cứng 1 con số vì header co giãn theo cấu
        // hình logo/menu và theo mobile/desktop.
        (function() {
            function measureHeaderHeight() {
                var hdr = document.getElementById('main-header-bar');
                if (hdr && hdr.offsetHeight > 0) {
                    document.documentElement.style.setProperty('--search-header-h', hdr.offsetHeight + 'px');
                }
            }
            document.addEventListener('DOMContentLoaded', measureHeaderHeight);
            window.addEventListener('resize', measureHeaderHeight, { passive: true });
            document.addEventListener('livewire:navigated', measureHeaderHeight);
            setTimeout(measureHeaderHeight, 50);
            setTimeout(measureHeaderHeight, 300);
        })();

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
                // window.innerHeight chứ không phải sheet.parentElement — ở ?view=branches, sheet
                // là position:fixed theo viewport, còn #search-layout (parent) giờ cao tự nhiên
                // theo nội dung (bảng đặt khung giờ dài), không còn bằng chiều cao màn hình nữa.
                // Dùng parent height làm mốc % sẽ tính sai tỉ lệ khi kéo/nhấn mở full.
                containerH = window.innerHeight;
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
            // Không reset handle.__bound = false ở đây nữa — "livewire:navigated" có thể bắn ra
            // ngay cả khi #sheet-handle vẫn là ĐÚNG node cũ (không bị Livewire thay thế), khiến
            // initSheet() gắn listener touchstart/mousedown/click thêm 1 lần nữa lên cùng 1 phần
            // tử. 2 listener "click" cùng chạy trong 1 lần tap sẽ tự triệt tiêu nhau (bật full
            // rồi tắt ngay lập tức) — đúng như bug quan sát được. initSheet() tự the guard bằng
            // handle.__bound nên chỉ cần gọi lại, nó sẽ tự no-op nếu node chưa đổi; nếu Livewire
            // thực sự thay node mới thì node mới vốn dĩ chưa có __bound (undefined), không cần
            // reset tay.
            document.addEventListener('livewire:navigated', initSheet);
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

            // Đọc room data từ JSON script tag (an toàn, không bị HTML encode) — mỗi phần tử
            // là 1 phòng (đã lọc theo tiêu chí tìm kiếm), 1 pin trên bản đồ = 1 phòng.
            var roomData = [];
            var dataEl = document.getElementById('branch-map-data');
            if (dataEl) {
                try {
                    roomData = JSON.parse(dataEl.textContent);
                } catch (e) {
                    console.error('room data parse error', e);
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

            // Gom các phòng theo toạ độ (làm tròn 5 chữ số thập phân, sai số ~1m) để nhiều phòng ở
            // cùng 1 địa điểm chỉ có 1 pin duy nhất trên bản đồ — tránh đè chồng giá lên nhau. Bấm/
            // hover vào pin đó sẽ mở popup có nút prev/next (đã có sẵn trong buildPopupHtml khi
            // rooms.length > 1) để lướt qua từng phòng tại địa điểm này.
            var coordGroups = {};
            var groupOrder = [];
            roomData.forEach(function(room, idx) {
                var lat = parseFloat(room.lat);
                var lng = parseFloat(room.lng);
                var hasCoords = lat && lng && !isNaN(lat) && !isNaN(lng);
                var card = cards[idx] || null;
                if (!hasCoords) return;

                var key = lat.toFixed(5) + ',' + lng.toFixed(5);
                if (!coordGroups[key]) {
                    coordGroups[key] = { lat: lat, lng: lng, rooms: [], cards: [] };
                    groupOrder.push(key);
                }
                coordGroups[key].rooms.push(room);
                coordGroups[key].cards.push(card);
            });

            groupOrder.forEach(function(key, groupIdx) {
                var group = coordGroups[key];
                var lat = group.lat;
                var lng = group.lng;
                var rooms = group.rooms;
                var groupCards = group.cards;

                bounds.push([lat, lng]);

                var roomPrices = rooms
                    .map(function(r) { return parseFloat(r.price); })
                    .filter(function(p) { return !isNaN(p) && p > 0; });
                var minPrice = roomPrices.length ? Math.min.apply(null, roomPrices) : null;

                // Unique popup id
                var popId = 'bp' + groupIdx;
                __popups[popId] = {
                    rooms: rooms,
                    idx: 0
                };

                // Marker
                var pinLabel = minPrice
                    ? (rooms.length > 1 ? 'Từ ' : '') + Number(minPrice).toLocaleString('vi-VN') + 'đ'
                    : 'Liên hệ';
                var pinHtml = '<div class="bpm">' + pinLabel + '</div>';
                var lm = L.marker([lat, lng], {
                    icon: L.divIcon({
                        className: '',
                        html: pinHtml,
                        iconSize: null,
                        iconAnchor: [0, 0]
                    }),
                    zIndexOffset: 0
                }).addTo(map);

                // Hover trên card bên trái -> mở đúng phòng đó trong popup của pin cùng vị trí
                // (nhiều phòng dùng chung 1 pin + nút prev/next), không tô viền màu trên card list.
                // Card giờ là link điều hướng thẳng đến trang phòng nên dùng hover thay vì click.
                groupCards.forEach(function(card, roomIdxInGroup) {
                    if (!card) return;
                    card.__leafletPin = lm;
                    card.addEventListener('mouseenter', function() {
                        if (__activeBranchCard && __activeBranchCard.__leafletPin && __activeBranchCard.__leafletPin !== lm) {
                            __activeBranchCard.__leafletPin.closePopup();
                        }
                        __activeBranchCard = card;
                        __popups[popId].idx = roomIdxInGroup;
                        __bpopRender(popId);
                        lm.openPopup();
                    });
                    card.addEventListener('mouseleave', function() {
                        lm.closePopup();
                        if (__activeBranchCard === card) __activeBranchCard = null;
                    });
                });

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

                // Render popup content sau khi DOM sẵn sàng (giữ nguyên idx đã set từ hover ở trên;
                // nếu popup mở do bấm thẳng vào pin thì dùng idx hiện có, mặc định 0 lần đầu).
                lm.on('popupopen', function() {
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
                    var activeCard = groupCards[__popups[popId].idx] || groupCards[0];
                    __activeBranchCard = activeCard || null;
                    if (activeCard) activeCard.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
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

        // Không tự gọi initSearchMap() ở đây nữa — search-results.js gọi sau khi fetch xong dữ
        // liệu phòng và đổ vào #branch-map-data, tránh init bản đồ với dữ liệu rỗng rồi bị khoá
        // (initSearchMap có guard mapEl._leaflet_id, gọi lại lần 2 sẽ không dựng lại marker).

        // ─── Map cho ?view=branches — mỗi pin là 1 CHI NHÁNH (không phải phòng) ────────
        // Hàm riêng, không tái dùng initSearchMap(): dữ liệu/mục đích khác hẳn (không có giá
        // phòng, không cần gom toạ độ trùng + carousel nhiều phòng chung 1 điểm vì mỗi chi nhánh
        // vốn đã là 1 vị trí riêng biệt) — tách riêng để không đụng/rủi ro tới luồng tìm phòng
        // thường (initSearchMap) đang chạy tốt. search-results.js gọi hàm này sau khi fetch xong
        // danh sách chi nhánh (kèm latitude/longitude từ API /v1/search/branches).
        var __branchMapInitRetries = 0;
        function initBranchesMap(branches) {
            if (typeof L === 'undefined') {
                if (__branchMapInitRetries++ < 25) setTimeout(function () { initBranchesMap(branches); }, 200);
                return;
            }
            __branchMapInitRetries = 0;

            var mapEl = document.getElementById('search-map');
            if (!mapEl) return;
            if (mapEl._leaflet_id && window.__searchMapInstance) {
                window.__searchMapInstance.remove();
                window.__searchMapInstance = null;
            }

            var cfg = __searchMapConfig;
            var map = L.map(mapEl, { zoomControl: true, scrollWheelZoom: true }).setView([cfg.lat, cfg.lng], cfg.zoom);
            window.__searchMapInstance = map;

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            }).addTo(map);

            function esc(s) {
                return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }

            // Ghim theo slug — search-results.js dùng để lấy lại đúng marker khi bấm 1 card chi
            // nhánh ở panel trái (pan bản đồ tới khu vực đó + mở popup, xem window.__panToBranch).
            var branchMarkers = {};

            var bounds = [];
            (branches || []).forEach(function (branch) {
                var lat = parseFloat(branch.latitude);
                var lng = parseFloat(branch.longitude);
                if (!lat || !lng || isNaN(lat) || isNaN(lng)) return;
                bounds.push([lat, lng]);

                var pinLabel = branch.room_count != null ? branch.room_count + ' phòng' : '';
                var marker = L.marker([lat, lng], {
                    icon: L.divIcon({ className: '', html: '<div class="bpm">' + esc(pinLabel) + '</div>', iconSize: null })
                }).addTo(map);

                // Card trong popup: ảnh chi nhánh + nút "Xem chi tiết" điều hướng sang trang chi
                // tiết — khớp đúng nội dung branchCardHtml() (image_url/name/slug) đã có sẵn.
                var imgHtml = branch.image_url
                    ? '<img src="' + esc(branch.image_url) + '" alt="" style="width:100%;height:96px;object-fit:cover;border-radius:8px;display:block;margin-bottom:8px;">'
                    : '';
                // Đang xem theo khu vực (/s/{location} hoặc /homestay/{location}) thì dùng thẳng
                // URL canonical /homestay/{location}/{slug} (tốt cho SEO local hơn URL phẳng).
                var __locMatch = window.location.pathname.match(/^\/(?:s|homestay)\/([^\/?#]+)/);
                var branchHref = __locMatch
                    ? '/homestay/' + __locMatch[1] + '/' + encodeURIComponent(branch.slug)
                    : '/chi-nhanh/' + encodeURIComponent(branch.slug);
                var popupHtml = '<div style="padding:2px;min-width:190px;">'
                    + imgHtml
                    + '<p style="margin:0 0 8px;font-size:13px;font-weight:700;color:#111827;">' + esc(branch.name) + '</p>'
                    + '<a href="' + branchHref + '" style="display:block;background:#0f766e;color:#fff;font-size:12px;font-weight:700;text-align:center;padding:7px 10px;border-radius:8px;text-decoration:none;">Xem chi tiết →</a>'
                    + '</div>';
                marker.bindPopup(popupHtml, { maxWidth: 220, minWidth: 200, closeButton: true, autoPan: true });

                branchMarkers[branch.slug] = { marker: marker, lat: lat, lng: lng };
            });
            window.__branchMarkers = branchMarkers;

            if (bounds.length > 0) {
                try {
                    map.fitBounds(bounds, { padding: [50, 50], maxZoom: 14 });
                } catch (e) {
                    if (cfg.provinceLat) map.setView([cfg.provinceLat, cfg.provinceLng], 12);
                }
            } else if (cfg.provinceLat) {
                map.setView([cfg.provinceLat, cfg.provinceLng], 12);
            }

            setTimeout(function () { map.invalidateSize(); }, 150);
        }
        window.initBranchesMap = initBranchesMap;

        // Bấm 1 card chi nhánh ở panel trái (search-results.js) gọi hàm này thay vì điều hướng
        // ngay — pan/zoom bản đồ tới đúng khu vực chi nhánh đó rồi mở popup (ảnh + nút "Xem chi
        // tiết" mới thật sự điều hướng sang /branch/{slug}).
        window.__panToBranch = function (slug) {
            var entry = window.__branchMarkers && window.__branchMarkers[slug];
            if (!entry || !window.__searchMapInstance) return;
            window.__searchMapInstance.setView([entry.lat, entry.lng], 15, { animate: true });
            entry.marker.openPopup();
        };
    </script>

    <script src="{{ asset('js/home-sections.js') }}?v={{ filemtime(public_path('js/home-sections.js')) }}"></script>
    <script src="{{ asset('js/search-results.js') }}?v={{ filemtime(public_path('js/search-results.js')) }}"></script>
 @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')
@endsection
