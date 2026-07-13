@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

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

        /* Header ép fixed nên nội dung bên dưới cần padding-top bù lại — nhưng chiều cao
           header khác nhau giữa mobile (1 hàng gọn) và desktop (2 hàng: nav + tìm kiếm đầy
           đủ), nên phải chia theo breakpoint thay vì 1 giá trị cố định (không thì mobile bị
           dư khoảng trắng rất to, còn desktop lại thiếu và bị che tiêu đề). */
        .branch-page-main {
            padding-top: 96px;
        }

        @media (min-width: 1024px) {
            .branch-page-main {
                padding-top: 168px;
            }
        }

        /* Khung danh sách card phòng — tái dùng đúng khung của trang chủ (.home-card, ló 1 phần
           card tiếp theo trên mobile để gợi ý vuốt ngang). */
        .hide-scrollbar { -ms-overflow-style: none; }
        .hide-scrollbar::-webkit-scrollbar { display: none; }

        .home-card {
            flex: 0 0 calc(46vw - 20px);
            width: calc(46vw - 20px);
            max-width: calc(46vw - 20px);
        }
        @media (min-width: 768px) {
            .home-card {
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

        @media (min-width: 768px) {
            .room-wishlist-btn {
                width: 32px;
                height: 32px;
                top: 10px;
                right: 10px;
            }
            .room-wishlist-btn svg { width: 20px; height: 20px; }
        }

        @keyframes roomWishlistPop {
            0% { transform: scale(1); }
            35% { transform: scale(1.35); }
            100% { transform: scale(1); }
        }

        /* Trang chi nhánh: 2 cột — Cột 1 danh sách phòng, Cột 2 bảng đặt lịch khung giờ.
           Mobile (< 1024px): cột 1 (danh sách phòng) là bottom-sheet kéo lên/xuống nổi trên cột 2
           (bảng đặt lịch), giống hệt UI bottom-sheet đã có ở trang tìm kiếm (search.blade.php,
           #rooms-left-panel/#sheet-handle) — nhân bản đúng thông số (peek 54%/420px, full 94%,
           ngưỡng snap 0.62) sang đây bằng id riêng để không đụng code trang tìm kiếm đang chạy
           tốt. Desktop (≥1024px): giữ nguyên 2 cột như cũ (xem @media dưới). */
        .branch-columns { display: block; }

        @media (max-width: 1023.98px) {
            .branch-col-rooms {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 20;
                width: 100%;
                height: 54%;
                min-height: 420px;
                max-height: 94%;
                margin-bottom: 0;
                background: #fff;
                border-radius: 18px 18px 0 0;
                box-shadow: 0 -6px 24px rgba(0, 0, 0, .16);
                display: flex;
                flex-direction: column;
                transition: height .32s cubic-bezier(.32, .72, 0, 1);
            }
            .branch-col-rooms.sheet-full { height: 94%; }
            .branch-col-rooms.sheet-dragging { transition: none; }

            #room-sheet-handle {
                flex-shrink: 0;
                display: flex;
                justify-content: center;
                padding: 10px 0 6px;
                cursor: grab;
                touch-action: none;
            }
            #room-sheet-handle::before {
                content: '';
                width: 36px;
                height: 4px;
                border-radius: 3px;
                background: #d1d5db;
            }

            #room-sheet-scroll {
                flex: 1;
                min-height: 0;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                -ms-overflow-style: none;
                padding: 0 16px 16px;
            }
            #room-sheet-scroll::-webkit-scrollbar { display: none; }

            /* Danh sách phòng dạng lưới 2 cột (peek hiện trọn 4 card = 2 hàng) thay vì carousel
               cuộn ngang — #branch-rooms-root [x-ref="track"] vốn có style inline (flex ngang,
               cuộn tay) do JS tự sinh ra, cần !important để thắng (đúng pattern desktop bên dưới
               đã dùng cho lý do tương tự). */
            #branch-rooms-root [x-ref="track"] {
                display: grid !important;
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 10px !important;
                overflow: visible !important;
                scroll-snap-type: none !important;
            }
            #branch-rooms-root .home-card {
                width: 100% !important;
                max-width: 100% !important;
                flex: none !important;
            }

            /* Bảng đặt lịch (cột 2) cần đệm dưới để không bị sheet (position:fixed) che mất đoạn
               cuối — ước lượng theo chiều cao peek (54%) dư thêm 1 chút. */
            .branch-col-booking {
                padding-bottom: 58vh;
            }
        }

        @media (min-width: 1024px) {
            .branch-columns {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 32px;
                align-items: stretch;
            }
            .branch-col-rooms {
                margin-bottom: 0;
                position: sticky;
                top: 96px;
                /* align-items: stretch ở trên khiến cột này cao bằng cột lịch (cột 2) —
                   max-height chỉ để chặn không cho box cao hơn khung nhìn, tránh sticky
                   bị tràn khi màn hình thấp; vẫn cuộn được (overflow-y:auto) nhưng ẩn hẳn
                   thanh cuộn dọc cho gọn mắt. */
                max-height: calc(100vh - 120px);
                overflow-y: auto;
                scrollbar-width: none;
                -ms-overflow-style: none;
            }
            .branch-col-rooms::-webkit-scrollbar {
                display: none;
                width: 0;
                height: 0;
            }
            .branch-col-booking {
                max-width: 760px;
            }

            /* Danh sách phòng: carousel ngang (mobile) -> lưới 3 cột kiểu ảnh trên/chữ dưới
               (desktop), lấy theo đúng kiểu thẻ phòng của trang kết quả tìm kiếm
               (.branch-grid/.branch-card ở search.blade.php). */
            #branch-rooms-root [x-ref="track"] {
                display: grid !important;
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 20px 14px !important;
                overflow: visible !important;
                scroll-snap-type: none !important;
            }
            #branch-rooms-root .home-card {
                width: 100% !important;
                max-width: 100% !important;
                flex: none !important;
            }
            #branch-rooms-root .branch-carousel-nav { display: none !important; }
        }
    </style>

    <main class="branch-page-main" style="background:#fff; min-height:100vh;">
        <div class="w-full max-w-11xl mx-auto px-4 sm:px-6 pb-6">
            <div class="branch-columns">
                {{-- Cột 1: Danh sách phòng thuộc chi nhánh này. Desktop: danh sách dọc, sticky
                     cạnh bảng đặt lịch (như cũ). Mobile: bottom-sheet kéo lên/xuống (peek 4 card,
                     lưới 2 cột) — #room-sheet-handle/#room-sheet-scroll chỉ có ý nghĩa ở mobile
                     (CSS mobile-only phía trên biến .branch-col-rooms thành sheet); ở desktop
                     chúng chỉ là 2 div rỗng bọc bình thường, không ảnh hưởng layout desktop.
                     Tiêu đề chi nhánh nằm trong #room-sheet-scroll để cuộn cùng danh sách khi ở
                     mobile, cùng ngang hàng với "Chọn khung giờ tại..." của cột 2 ở desktop. --}}
                <div class="branch-col-rooms">
                    <div id="room-sheet-handle"></div>
                    <div id="room-sheet-scroll">
                        <div style="margin:0 0 16px;">
                            <h1 class="branch-col-heading" style="margin:0; font-size:20px; line-height:1.3; font-weight:800; color:#111827;">
                                {{ $branch->name }}
                            </h1>
                        </div>
                        <div id="branch-rooms-root" x-data="carouselNav()" x-init="init()" style="min-height:80px;"></div>
                    </div>
                </div>

                {{-- Cột 2: Bảng đặt lịch khung giờ của từng phòng --}}
                <div class="branch-col-booking">
                    <div style="margin:0 0 16px;">
                    
                    </div>

                    @if(empty($bookConfig['bookable_room_count']))
                        <div style="padding:40px 16px; text-align:center; border:1px solid #f3f4f6; border-radius:14px; background:#f9fafb;">
                            <p style="margin:0; color:#6b7280; font-size:14px;">Chi nhánh này chưa có phòng theo khung giờ để hiển thị.</p>
                        </div>
                    @else
                        @livewire('bladethemev1::book', ['config' => $bookConfig])
                    @endif
                </div>
            </div>
        </div>
    </main>

    @livewire('bladethemev1::footer')
    @livewire('bladethemev1::contact-link')
    @livewire('bladethemev1::notification')

    <script src="{{ asset('js/home-sections.js') }}?v={{ filemtime(public_path('js/home-sections.js')) }}"></script>
    <script>
        (function () {
            const root = document.getElementById('branch-rooms-root');
            if (!root) return;

            const branchSlug = @json($branch->slug);

            const emptyStateHtml = '<div style="padding:2.5rem 1rem; text-align:center;">'
                + '<svg style="width:40px;height:40px;color:#d1d5db;margin:0 auto 12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">'
                + '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'
                + '</svg>'
                + '<p style="color:#6b7280;font-size:14px;margin:0;">Không tìm thấy phòng nào phù hợp.</p>'
                + '</div>';

            const errorStateHtml = '<div style="padding:2.5rem 1rem; text-align:center;">'
                + '<p style="color:#6b7280;font-size:14px;margin:0;">Không tải được danh sách phòng. Vui lòng thử lại.</p>'
                + '</div>';

            const load = async () => {
                const token = localStorage.getItem('auth_token');
                const headers = { Accept: 'application/json' };
                if (token) headers.Authorization = 'Bearer ' + token;

                root.innerHTML = '<div style="padding:24px 0; text-align:center; font-size:13px; color:#6b7280;">Đang tải danh sách phòng...</div>';
                try {
                    const res = await fetch('/api/v1/search?province=' + encodeURIComponent(branchSlug) + '&per_page=100', { headers });
                    const json = await res.json();
                    const rooms = json.data || [];

                    if (!rooms.length) {
                        root.innerHTML = emptyStateHtml;
                        return;
                    }

                    const cards = rooms.map((room) => window.roomCardHtml ? window.roomCardHtml(room) : '').join('');

                    root.innerHTML = '<div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px;">'
                        + '<h2 class="branch-col-heading" style="font-size:16px; line-height:1.3; font-weight:800; color:#111827; margin:0;">Danh sách phòng <span style="color:#9ca3af; font-weight:600;">(' + rooms.length + ')</span></h2>'
                        + '<div class="branch-carousel-nav hidden lg:flex" style="align-items:center; gap:6px; flex-shrink:0;">'
                        + '<button type="button" class="carousel-nav-btn" aria-label="Trước" x-show="canScrollPrev" @click="prev()">'
                        + '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>'
                        + '</button>'
                        + '<button type="button" class="carousel-nav-btn" aria-label="Tiếp" x-show="canScrollNext" @click="next()">'
                        + '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>'
                        + '</button>'
                        + '</div>'
                        + '</div>'
                        + '<div x-ref="track" x-init="init()" style="display:flex; gap:14px; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none; padding-bottom:4px;" class="hide-scrollbar">'
                        + cards
                        + '</div>';

                    if (window.Alpine) {
                        window.Alpine.initTree(root);
                    }
                } catch (e) {
                    root.innerHTML = errorStateHtml;
                }
            };

            document.addEventListener('DOMContentLoaded', load);
            window.addEventListener('auth-state-changed', load);
            load();
        })();

        // ─── Bottom-sheet danh sách phòng kéo lên/xuống (mobile < 1024px) ──────────────
        // Nhân bản đúng cơ chế kéo đã có ở trang tìm kiếm (search.blade.php, #rooms-left-panel/
        // #sheet-handle) — id riêng (#branch-col-rooms không tồn tại, dùng class .branch-col-rooms
        // + #room-sheet-handle) để không đụng code trang tìm kiếm đang chạy tốt.
        (function () {
            var sheet = null, handle = null, dragging = false, startY = 0, startH = 0, containerH = 0;
            var FULL_THRESHOLD = 0.62;

            function isMobile() {
                return window.innerWidth < 1024;
            }

            function setSheetState(state) {
                if (!sheet) return;
                sheet.style.height = '';
                if (state === 'full') sheet.classList.add('sheet-full');
                else sheet.classList.remove('sheet-full');
            }

            function onStart(clientY) {
                if (!isMobile()) return;
                dragging = true;
                startY = clientY;
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
                setSheetState(ratio > FULL_THRESHOLD ? 'full' : 'peek');
            }

            function initSheet() {
                sheet = document.querySelector('.branch-col-rooms');
                handle = document.getElementById('room-sheet-handle');
                if (!sheet || !handle || handle.__bound) return;
                handle.__bound = true;

                handle.addEventListener('touchstart', function (e) {
                    onStart(e.touches[0].clientY);
                }, { passive: true });
                handle.addEventListener('touchmove', function (e) {
                    onMove(e.touches[0].clientY);
                }, { passive: true });
                handle.addEventListener('touchend', onEnd);

                handle.addEventListener('mousedown', function (e) {
                    onStart(e.clientY);
                    function mm(ev) { onMove(ev.clientY); }
                    function mu() {
                        onEnd();
                        document.removeEventListener('mousemove', mm);
                        document.removeEventListener('mouseup', mu);
                    }
                    document.addEventListener('mousemove', mm);
                    document.addEventListener('mouseup', mu);
                });

                handle.addEventListener('click', function () {
                    if (dragging) return;
                    var isFull = sheet.classList.contains('sheet-full');
                    setSheetState(isFull ? 'peek' : 'full');
                });
            }

            document.addEventListener('DOMContentLoaded', initSheet);
            document.addEventListener('livewire:navigated', initSheet);
        })();
    </script>
@endsection
