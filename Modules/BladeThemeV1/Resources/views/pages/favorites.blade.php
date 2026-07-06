@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    <div class="min-h-screen bg-gray-50 px-4 py-6 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-11xl">
            <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Danh sách yêu thích</h1>
                    <p class="text-sm text-gray-500">Lưu các phòng bạn thích để xem lại sau.</p>
                </div>
            </div>

            <div id="favorites-root" class="min-h-[240px]"></div>
        </div>
    </div>

    <style>
        /* Mỗi chi nhánh 1 dòng riêng, bên dưới là các phòng của chi nhánh đó — giống trang kết
           quả tìm kiếm: mobile vuốt ngang từng thẻ (Airbnb-style), desktop lưới cố định 3 cột. */
        .fav-branch-section { margin-bottom: 8px; }
        .fav-branch-header { padding: 14px 4px 8px; }
        .fav-branch-header a { display: inline-flex; align-items: center; gap: 5px; text-decoration: none; }
        .fav-branch-name { font-size: 14px; font-weight: 700; color: #111827; }
        .fav-branch-count { font-size: 12px; font-weight: 500; color: #9ca3af; }

        .room-slider { position: relative; }

        .branch-grid {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            overflow-y: hidden;
            scroll-snap-type: x mandatory;
            padding-bottom: 4px;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        .branch-grid::-webkit-scrollbar { display: none; }

        .branch-grid .branch-card {
            flex: 0 0 100%;
            width: 100%;
            max-width: none;
            scroll-snap-align: start;
        }

        /* .home-card vốn có bề rộng cố định cho carousel ngang trên trang chủ; ở đây nó nằm
           trong .branch-card (đã được .branch-grid quyết định bề rộng) nên cho lấp đầy 100%. */
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
        .room-slider-nav svg { width: 16px; height: 16px; color: #111827; }
        .room-slider-nav.room-slider-prev { left: 6px; }
        .room-slider-nav.room-slider-next { right: 6px; }
        .room-slider-nav[disabled] { opacity: 0; pointer-events: none; }

        @media (min-width: 768px) {
            .branch-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 20px 14px;
                overflow: visible;
            }
            .branch-grid .branch-card {
                width: auto;
                max-width: none;
                scroll-snap-align: none;
            }
            .room-slider-nav { display: none; }
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
    </style>

    <script src="{{ asset('js/home-sections.js') }}"></script>

    <script>
        (function () {
            const root = document.getElementById('favorites-root');
            if (!root) return;

            const token = localStorage.getItem('auth_token');
            const renderLoginPrompt = () => {
                root.innerHTML = `
                    <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center shadow-sm">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-rose-50 text-rose-500">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="currentColor" viewBox="0 0 24 24">
                                <path d="m11.645 20.91-.007-.003-.022-.012a15.247 15.247 0 0 1-.383-.218 25.18 25.18 0 0 1-4.244-3.17C4.688 15.36 2.25 12.174 2.25 8.25 2.25 5.322 4.714 3 7.688 3A5.5 5.5 0 0 1 12 5.052 5.5 5.5 0 0 1 16.313 3c2.973 0 5.437 2.322 5.437 5.25 0 3.925-2.438 7.111-4.739 9.256a25.175 25.175 0 0 1-4.244 3.17 15.247 15.247 0 0 1-.383.219l-.022.012-.007.004-.003.001a.752.752 0 0 1-.704 0l-.003-.001Z"/>
                            </svg>
                        </div>
                        <h2 class="mb-2 text-lg font-semibold text-gray-900">Bạn cần đăng nhập để xem danh sách yêu thích</h2>
                        <p class="mb-5 text-sm text-gray-500">Đăng nhập để lưu và quản lý các phòng bạn đã thích.</p>
                        <button type="button" id="favorite-login-btn" class="rounded-full bg-[var(--color-primary)] px-5 py-2.5 text-sm font-semibold text-white shadow-sm">Đăng nhập ngay</button>
                    </div>
                `;
                document.getElementById('favorite-login-btn')?.addEventListener('click', () => {
                    window.dispatchEvent(new CustomEvent('open-auth-modal'));
                });
            };

            const emptyStateHtml = `
                <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center shadow-sm">
                    <h2 class="mb-2 text-lg font-semibold text-gray-900">Chưa có phòng yêu thích nào</h2>
                    <p class="text-sm text-gray-500">Hãy lưu lại những phòng bạn thích để xem lại sau.</p>
                </div>
            `;

            // Nhóm phòng theo chi nhánh (room.branch.id) — phòng không xác định được chi nhánh
            // gom vào 1 nhóm chung, hiển thị phẳng không có dòng tiêu đề (giống trang tìm kiếm).
            const groupRoomsByBranch = (rooms) => {
                const branchOrder = [];
                const branchGroups = {};
                const noBranchRooms = [];

                rooms.forEach((room) => {
                    if (!room.branch) { noBranchRooms.push(room); return; }
                    const key = room.branch.id;
                    if (!branchGroups[key]) { branchGroups[key] = { branch: room.branch, rooms: [] }; branchOrder.push(key); }
                    branchGroups[key].rooms.push(room);
                });

                return { groups: branchOrder.map((key) => branchGroups[key]), noBranchRooms };
            };

            const branchHeaderHtml = (branch, count) => (
                '<div class="fav-branch-header">'
                    + '<a href="/branch/' + encodeURIComponent(branch.slug) + '">'
                    + '<span class="fav-branch-name">' + branch.name + '</span>'
                    + '<span class="fav-branch-count">(' + count + ')</span>'
                    + '</a></div>'
            );

            const roomsGridHtml = (rooms) => {
                const cards = rooms.map((room) => '<div class="branch-card">' + (window.roomCardHtml ? window.roomCardHtml(room) : '') + '</div>').join('');
                return '<div class="room-slider">'
                    + '<div class="branch-grid">' + cards + '</div>'
                    + '<button type="button" class="room-slider-nav room-slider-prev" aria-label="Phòng trước" onclick="window.__favSliderNav(this, -1)">'
                    + '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>'
                    + '</button>'
                    + '<button type="button" class="room-slider-nav room-slider-next" aria-label="Phòng tiếp theo" onclick="window.__favSliderNav(this, 1)">'
                    + '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>'
                    + '</button>'
                    + '</div>';
            };

            const updateSliderNav = (slider) => {
                const grid = slider.querySelector('.branch-grid');
                const prev = slider.querySelector('.room-slider-prev');
                const next = slider.querySelector('.room-slider-next');
                if (!grid || !prev || !next) return;
                const max = grid.scrollWidth - grid.clientWidth;
                prev.disabled = grid.scrollLeft <= 4;
                next.disabled = max <= 4 || grid.scrollLeft >= max - 4;
            };

            const initSliders = () => {
                root.querySelectorAll('.room-slider').forEach((slider) => {
                    const grid = slider.querySelector('.branch-grid');
                    if (!grid) return;
                    updateSliderNav(slider);
                    grid.addEventListener('scroll', () => updateSliderNav(slider), { passive: true });
                });
            };

            if (typeof window.__favSliderNav === 'undefined') {
                window.__favSliderNav = function (btn, dir) {
                    const grid = btn.closest('.room-slider')?.querySelector('.branch-grid');
                    if (!grid) return;
                    grid.scrollBy({ left: dir * grid.clientWidth, behavior: 'smooth' });
                };
            }

            const renderRooms = (rooms) => {
                if (!rooms.length) {
                    root.innerHTML = emptyStateHtml;
                    return;
                }

                const grouped = groupRoomsByBranch(rooms);
                let html = '';
                grouped.groups.forEach((group) => {
                    html += '<div class="fav-branch-section">'
                        + branchHeaderHtml(group.branch, group.rooms.length)
                        + roomsGridHtml(group.rooms)
                        + '</div>';
                });
                if (grouped.noBranchRooms.length) {
                    html += '<div class="fav-branch-section">' + roomsGridHtml(grouped.noBranchRooms) + '</div>';
                }

                root.innerHTML = html;
                initSliders();
            };

            const loadFavorites = async () => {
                if (!token) {
                    renderLoginPrompt();
                    return;
                }

                root.innerHTML = '<div class="py-10 text-center text-sm text-gray-500">Đang tải...</div>';
                try {
                    const res = await fetch('/api/wishlist', {
                        headers: {
                            'Accept': 'application/json',
                            'Authorization': 'Bearer ' + token,
                        }
                    });
                    const json = await res.json();
                    renderRooms(json.data || []);
                } catch (e) {
                    root.innerHTML = '<div class="rounded-2xl border border-red-200 bg-red-50 p-6 text-sm text-red-600">Không thể tải danh sách yêu thích lúc này.</div>';
                }
            };

            // Bỏ yêu thích ngay trên danh sách này thì xoá card khỏi lưới luôn (và cả dòng chi
            // nhánh nếu đó là phòng cuối cùng). Dùng capture phase vì nút tim tự gọi
            // stopPropagation() trong onclick của nó.
            root.addEventListener('click', (e) => {
                const btn = e.target.closest('.room-wishlist-btn');
                if (!btn || btn.getAttribute('data-active') !== '1') return;

                const card = btn.closest('.branch-card');
                if (!card) return;

                setTimeout(() => {
                    card.style.transition = 'opacity .25s ease, transform .25s ease';
                    card.style.opacity = '0';
                    card.style.transform = 'scale(.96)';
                    setTimeout(() => {
                        const slider  = card.closest('.room-slider');
                        const section = card.closest('.fav-branch-section');
                        card.remove();

                        if (slider) updateSliderNav(slider);
                        if (section && !section.querySelector('.branch-card')) section.remove();
                        if (!root.querySelector('.branch-card')) root.innerHTML = emptyStateHtml;
                    }, 250);
                }, 350);
            }, true);

            document.addEventListener('DOMContentLoaded', loadFavorites);
            window.addEventListener('auth-state-changed', loadFavorites);
            loadFavorites();
        })();
    </script>
@endsection
