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

        /* Trang này ép header thành fixed (kéo ra khỏi flow) nên banner quảng cáo app
           (components/header/app-banner.blade.php, mặc định position:sticky) cũng phải ép thành
           fixed theo, đặt ngay dưới header — "top" lấy từ --header-h (đo động bằng
           ResizeObserver trong chính app-banner.blade.php), không hardcode vì chiều cao header
           đổi theo breakpoint. z-index thấp hơn header 1 chút (1150) để luôn nằm dưới, không che
           menu/dropdown xổ ra từ header. */
        #app-store-banner {
            position: fixed !important;
            top: var(--header-h, 0px) !important;
            left: 0 !important;
            right: 0 !important;
            z-index: 1140 !important;
        }

        /* Header ép fixed nên nội dung bên dưới cần padding-top bù lại — nhưng chiều cao
           header khác nhau giữa mobile (1 hàng gọn) và desktop (2 hàng: nav + tìm kiếm đầy
           đủ), nên phải chia theo breakpoint thay vì 1 giá trị cố định (không thì mobile bị
           dư khoảng trắng rất to, còn desktop lại thiếu và bị che tiêu đề). Cộng thêm
           --app-banner-h (đo động, 0px nếu banner bị đóng/chưa hiện) để không bị banner che mất
           đoạn đầu nội dung. */
        .branch-page-main {
            padding-top: calc(96px + var(--app-banner-h, 0px));
        }

        @media (min-width: 1024px) {
            .branch-page-main {
                padding-top: calc(168px + var(--app-banner-h, 0px));
            }
        }

        /* Khung danh sách card phòng — tái dùng đúng khung của trang chủ (.home-card, ló 1 phần
           card tiếp theo trên mobile để gợi ý vuốt ngang). */
        .hide-scrollbar { -ms-overflow-style: none; }
        .hide-scrollbar::-webkit-scrollbar { display: none; }

        /* Skeleton loading cho danh sách phòng (#branch-rooms-root) trong lúc fetch
           /api/v1/search — thay cho dòng chữ "Đang tải..." cũ, tránh giật layout vì dùng đúng
           khung .home-card + x-ref="track" như card thật (grid 2 cột mobile/desktop đã định
           nghĩa sẵn ở #branch-rooms-root [x-ref="track"] phía dưới). Định nghĩa riêng
           (room-skel-shimmer, không dùng chung book-skel-shimmer của book/_skeleton.blade.php)
           vì khối lịch (component Livewire "book") chỉ render khi
           bookConfig['bookable_room_count'] khác rỗng — nếu chi nhánh chưa có phòng theo khung
           giờ thì style đó sẽ không tồn tại trên trang. */
        @keyframes room-skel-shimmer {
            0% { background-position: -400px 0; }
            100% { background-position: 400px 0; }
        }
        .room-skel {
            background: linear-gradient(90deg, #eef0f2 25%, #f7f8f9 37%, #eef0f2 63%);
            background-size: 800px 100%;
            animation: room-skel-shimmer 1.4s ease-in-out infinite;
            border-radius: 6px;
            display: block;
        }
        .room-skel-img {
            position: relative;
            padding-top: 72%;
            border-radius: 14px;
        }

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

        /* Trang chi nhánh: mobile (< 1024px) hiển thị Lịch đặt phòng trước, danh sách card phòng
           dạng lưới thường (không phải bottom-sheet) ngay bên dưới — dùng flex-direction:column +
           order để đảo thứ tự hiển thị mà không phải đổi thứ tự HTML (rooms vẫn đứng trước booking
           trong DOM, chỉ đổi order để giữ được thứ tự cột như cũ ở desktop). Desktop (≥1024px):
           giữ nguyên 2 cột như cũ (xem @media dưới). */
        .branch-columns {
            display: flex;
            flex-direction: column;
            gap: 32px;
        }
        .branch-col-rooms { order: 2; }
        .branch-col-booking { order: 1; }

        @media (max-width: 1023.98px) {
            /* Danh sách phòng dạng lưới 2 cột thường thay vì carousel cuộn ngang —
               #branch-rooms-root [x-ref="track"] vốn có style inline (flex ngang, cuộn tay) do JS
               tự sinh ra, cần !important để thắng (đúng pattern desktop bên dưới đã dùng cho lý
               do tương tự). */
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
        }

        @media (min-width: 1024px) {
            /* Cột danh sách phòng thu hẹp lại (đủ chỗ cho 2 card/hàng thay vì 3), cột lịch đặt
               phòng giãn rộng ra chiếm phần còn lại — minmax để cột phòng không co quá hẹp khi
               màn hình nhỏ (vẫn đủ chỗ cho 2 card) nhưng cũng không phình quá to trên màn rộng. */
            .branch-columns {
                display: grid;
                grid-template-columns: minmax(340px, 420px) minmax(0, 1fr);
                gap: 32px;
                align-items: stretch;
            }
            .branch-col-rooms {
                order: 0;
                margin-bottom: 0;
                min-width: 0;
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
                order: 0;
                /* min-width:0 (+ track minmax(0, 1fr) ở .branch-columns) — grid item mặc định có
                   automatic minimum size = min-content của nội dung bên trong, KHÔNG phải 0. Nội
                   dung lịch (Swiper carousel, cột "Thời gian"...) có min-content khá rộng (nhiều ô
                   giờ cố định 78px, slide kế bên hé lộ...) nên nếu để mặc định, cột này (và cả
                   .branch-columns/trang) bị đẩy phình ra ngoài viewport, sinh thanh cuộn ngang cho
                   TOÀN TRANG dù đã đặt max-width — chứ không co gọn vào đúng track đã cấp. Set
                   min-width:0 buộc cột (và track) co lại đúng bề rộng được cấp; phần nội dung dư
                   (khung giờ 6+, slide kế bên) đã có sẵn overflow-x:auto ở bên trong
                   (.book-dt-slots-scroll/.book-dt-slots-header-row, book/_styles.blade.php) để tự
                   cuộn ngang trong đúng khung cột 2, không tràn ra ngoài nữa. */
                min-width: 0;
                /* Rộng hơn mốc 760px cũ nhưng vẫn phải chặn trần — bảng lịch bên trong dùng
                   Swiper (book/_desktop-grid.blade.php) tự chia chiều rộng mỗi khung giờ theo
                   chiều rộng cột này; để cột giãn tự do theo 1fr (có thể tới hơn 1000-1500px trên
                   màn hình rộng) khiến mỗi ô khung giờ bị kéo dãn thành vệt mỏng gần như vô hình
                   thay vì pill vuông vắn như thiết kế.
                   1100px: ở breakpoint Swiper rộng nhất (slidesPerView 2.1, spaceBetween 20 —
                   xem mountBookDtSwiper() trong public/js/home-sections.js), mỗi thẻ phòng
                   (slide) cần tối thiểu ~430px để đủ chỗ cho cả 5 khung giờ (mỗi ô tối thiểu
                   78px + gap 6px + padding 16px) không bị cắt/cuộn ngang — 960px cũ cho slide
                   chỉ ~397px nên khung giờ thứ 5 bị hụt. 1100px cho slide ~460px, đủ dư cho 5
                   khung giờ mà không kéo ô quá rộng như lúc bỏ hẳn max-width. */
                max-width: 1100px;
            }

            /* Danh sách phòng: carousel ngang (mobile) -> lưới 2 cột (desktop, khớp cột đã thu
               hẹp ở trên) kiểu ảnh trên/chữ dưới, lấy theo đúng kiểu thẻ phòng của trang kết quả
               tìm kiếm (.branch-grid/.branch-card ở search.blade.php). */
            #branch-rooms-root [x-ref="track"] {
                display: grid !important;
                grid-template-columns: repeat(2, 1fr) !important;
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
                     cạnh bảng đặt lịch (như cũ). Mobile: hiển thị ngay bên dưới Lịch đặt phòng
                     dạng lưới 2 cột thường (order:2, xem CSS phía trên), không còn là bottom-sheet
                     nổi lên. --}}
                <div class="branch-col-rooms">
                    <div id="branch-rooms-root" x-data="carouselNav()" x-init="init()" style="min-height:80px;"></div>
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

            // Skeleton: cùng khung .home-card + x-ref="track" như card thật (tự thừa hưởng grid
            // responsive 2 cột định nghĩa ở #branch-rooms-root [x-ref="track"] phía trên) để
            // không bị giật layout khi dữ liệu thật tải xong và thay thế vào.
            const skeletonCardHtml = '<div class="home-card" style="display:flex; flex-direction:column; gap:8px;">'
                + '<div class="room-skel room-skel-img"></div>'
                + '<div style="padding:0 2px; display:flex; flex-direction:column; gap:6px;">'
                + '<div class="room-skel" style="height:13px; width:85%;"></div>'
                + '<div class="room-skel" style="height:13px; width:45%;"></div>'
                + '</div></div>';
            const skeletonHtml = '<div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px;">'
                + '<div class="room-skel" style="height:18px; width:150px;"></div>'
                + '</div>'
                + '<div x-ref="track" style="display:flex; gap:14px; overflow-x:auto; padding-bottom:4px;" class="hide-scrollbar">'
                + Array(4).fill(skeletonCardHtml).join('')
                + '</div>';

            const load = async () => {
                const token = localStorage.getItem('auth_token');
                const headers = { Accept: 'application/json' };
                if (token) headers.Authorization = 'Bearer ' + token;

                root.innerHTML = skeletonHtml;
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
    </script>
@endsection
