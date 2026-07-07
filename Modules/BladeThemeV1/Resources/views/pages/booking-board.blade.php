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
           Mobile: xếp dọc, cột 1 ở trên, cột 2 ở dưới (thứ tự DOM mặc định). */
        .branch-columns { display: block; }
        .branch-col-rooms { margin-bottom: 28px; }

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
                {{-- Cột 1: Danh sách phòng thuộc chi nhánh này — dùng chung khung carousel của
                     trang chủ (carousel ngang trên mobile, danh sách dọc trên desktop).
                     Tiêu đề chi nhánh chuyển vào đây (thay vì nằm riêng phía trên 2 cột) để
                     ngang hàng với "Chọn khung giờ tại..." của cột 2. --}}
                <div class="branch-col-rooms">
                    <div style="margin:0 0 16px;">
                        <h1 class="branch-col-heading" style="margin:0; font-size:20px; line-height:1.3; font-weight:800; color:#111827;">
                            {{ $branch->name }}
                        </h1>
                    </div>
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

    <script src="{{ asset('js/home-sections.js') }}"></script>
    <script>
        (function () {
            const root = document.getElementById('branch-rooms-root');
            if (!root) return;

            const branchSlug = @json($branch->slug);

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
                        root.innerHTML = '';
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
                    root.innerHTML = '';
                }
            };

            document.addEventListener('DOMContentLoaded', load);
            window.addEventListener('auth-state-changed', load);
            load();
        })();
    </script>
@endsection
