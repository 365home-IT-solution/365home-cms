@extends('bladethemev1::layouts.master')

<x-bladethemev1::seo :seoData="$seoData" />

@section('content')
    @livewire('bladethemev1::header')
    @livewire('bladethemev1::drawer-menu')

    {{-- Info Banner --}}
    <section class="bg-blue-50 border-b border-blue-200 px-4 py-6 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <div class="flex items-start gap-4">
                <svg class="h-6 w-6 text-blue-600 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 5v8a2 2 0 01-2 2h-5l-5 4v-4H4a2 2 0 01-2-2V5a2 2 0 012-2h12a2 2 0 012 2zm-11-1a1 1 0 11-2 0 1 1 0 012 0zM10 7a1 1 0 100-2 1 1 0 000 2zm3 1a1 1 0 110-2 1 1 0 010 2z" clip-rule="evenodd"/>
                </svg>
                <div>
                    <h3 class="font-semibold text-blue-900">Mẹo: Sử dụng danh sách yêu thích hiệu quả</h3>
                    <p class="text-sm text-blue-800 mt-1">Lưu các phòng bạn thích vào danh sách yêu thích để dễ dàng so sánh, theo dõi giá cả và đặt phòng nhanh chóng mà không cần tìm kiếm lại.</p>
                </div>
            </div>
        </div>
    </section>

    <div class="min-h-screen bg-gray-50 px-4 py-6 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <div class="mb-8 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Danh sách yêu thích</h1>
                    <p class="text-base text-gray-600 mt-2">Lưu các phòng bạn thích để xem lại sau, so sánh và đặt phòng một cách dễ dàng.</p>
                </div>
            </div>

            <div id="favorites-root" class="min-h-[240px]"></div>
        </div>
    </div>

    {{-- Benefits Section --}}
    <section class="bg-white py-12 px-4 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <h2 class="text-2xl font-bold text-gray-900 mb-8">Tại sao nên lưu danh sách yêu thích?</h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="text-center">
                    <div class="flex justify-center mb-4">
                        <svg class="h-12 w-12 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Dễ dàng so sánh</h3>
                    <p class="text-gray-600">So sánh các phòng yêu thích, giá cả, tiện nghi và vị trí để chọn phòng phù hợp nhất cho bạn.</p>
                </div>

                <div class="text-center">
                    <div class="flex justify-center mb-4">
                        <svg class="h-12 w-12 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Theo dõi giá</h3>
                    <p class="text-gray-600">Nhận thông báo khi giá các phòng yêu thích thay đổi hoặc có khuyến mãi mới.</p>
                </div>

                <div class="text-center">
                    <div class="flex justify-center mb-4">
                        <svg class="h-12 w-12 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Đặt phòng nhanh</h3>
                    <p class="text-gray-600">Tiếp cận danh sách yêu thích nhanh chóng và đặt phòng chỉ trong vài cú nhấp chuột.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Tips Section --}}
    <section class="bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-7xl">
            <h2 class="text-2xl font-bold text-gray-900 mb-8">Mẹo quản lý danh sách yêu thích</h2>

            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-gray-200">
                    <div class="p-6">
                        <h3 class="font-semibold text-gray-900 mb-3">Tìm phòng theo điều kiện</h3>
                        <p class="text-gray-600 text-sm">Sử dụng bộ lọc trên trang tìm kiếm để tìm phòng phù hợp với ngân sách, vị trí và tiện nghi bạn mong muốn, sau đó lưu vào danh sách yêu thích.</p>
                    </div>

                    <div class="p-6">
                        <h3 class="font-semibold text-gray-900 mb-3">Theo dõi các chi nhánh yêu thích</h3>
                        <p class="text-gray-600 text-sm">Lưu các phòng từ các chi nhánh mà bạn thường đến để theo dõi và so sánh dễ dàng theo từng khu vực.</p>
                    </div>

                    <div class="p-6">
                        <h3 class="font-semibold text-gray-900 mb-3">Kiểm tra thường xuyên</h3>
                        <p class="text-gray-600 text-sm">Ghé thăm danh sách yêu thích của bạn định kỳ để kiểm tra các thay đổi giá, khuyến mãi mới và tính khả dụng.</p>
                    </div>

                    <div class="p-6">
                        <h3 class="font-semibold text-gray-900 mb-3">Chia sẻ với bạn bè</h3>
                        <p class="text-gray-600 text-sm">Chia sẻ link phòng yêu thích với bạn bè để cùng so sánh, thảo luận và quyết định đặt phòng chung.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

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

        /* Skeleton loading trong lúc loadFavorites() gọi API — thay cho dòng chữ "Đang tải..."
           cũ, dùng đúng khung .branch-grid/.branch-card/.home-card như card thật nên không giật
           layout khi thay thế bằng nội dung thật. */
        @keyframes fav-skel-shimmer {
            0% { background-position: -400px 0; }
            100% { background-position: 400px 0; }
        }
        .fav-skel {
            background: linear-gradient(90deg, #eef0f2 25%, #f7f8f9 37%, #eef0f2 63%);
            background-size: 800px 100%;
            animation: fav-skel-shimmer 1.4s ease-in-out infinite;
            border-radius: 6px;
            display: block;
        }
        .fav-skel-img { padding-top: 72%; border-radius: 14px; }
    </style>

    <script src="{{ asset('js/home-sections.min.js') }}?v={{ filemtime(public_path('js/home-sections.min.js')) }}"></script>

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

            // Skeleton: dùng đúng khung .branch-grid/.branch-card như card thật để không giật
            // layout khi loadFavorites() thay thế bằng nội dung thật.
            const skeletonHtml = (() => {
                const card = '<div class="branch-card"><div class="home-card" style="display:flex; flex-direction:column; gap:8px;">'
                    + '<div class="fav-skel fav-skel-img"></div>'
                    + '<div class="fav-skel" style="height:13px; width:85%;"></div>'
                    + '<div class="fav-skel" style="height:13px; width:45%;"></div>'
                    + '</div></div>';
                return '<div class="room-slider"><div class="branch-grid">' + Array(4).fill(card).join('') + '</div></div>';
            })();

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

                root.innerHTML = skeletonHtml;
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
