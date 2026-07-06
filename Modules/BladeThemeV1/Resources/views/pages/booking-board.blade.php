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
    </style>

    <main style="background:#fff; min-height:100vh; padding-top:80px;">
        <div class="w-full max-w-11xl mx-auto px-4 sm:px-6 pb-6">
            <div style="margin:10px 0 14px;">
                <p style="margin:0 0 4px; font-size:12px; font-weight:700; color:#0f766e; text-transform:uppercase; letter-spacing:.08em;">Chi nhánh</p>
                <h1 style="margin:0; font-size:24px; line-height:1.25; font-weight:800; color:#111827;">{{ $branch->name }}</h1>
            </div>

            {{-- Danh sách phòng thuộc chi nhánh này — dùng chung khung carousel của trang chủ --}}
            <div id="branch-rooms-root" x-data="carouselNav()" x-init="init()" style="min-height:80px;"></div>

            <div style="margin:36px 0 16px;">
                <h2 style="margin:0; font-size:20px; line-height:1.3; font-weight:800; color:#111827;">
                    Chọn khung giờ tại {{ $branch->name }}
                </h2>
            </div>

            @if(empty($bookConfig['bookable_room_count']))
                <div style="padding:40px 16px; text-align:center; border:1px solid #f3f4f6; border-radius:14px; background:#f9fafb;">
                    <p style="margin:0; color:#6b7280; font-size:14px;">Chi nhánh này chưa có phòng theo khung giờ để hiển thị.</p>
                </div>
            @else
                @livewire('bladethemev1::book', ['config' => $bookConfig])
            @endif
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

                    root.innerHTML = '<div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px;">'
                        + '<h2 style="font-size:1.1rem; font-weight:800; color:#111827; margin:0; text-transform:uppercase; letter-spacing:.02em;">Danh sách phòng</h2>'
                        + '<div class="hidden lg:flex" style="align-items:center; gap:6px; flex-shrink:0;">'
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
