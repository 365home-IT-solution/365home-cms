<div
    x-data="homeSections()"
    x-init="init()"
    x-cloak
>
    {{-- Skeleton toàn trang chủ trong lúc load() (public/js/home-sections.js) đang gọi
         /api/v1/home — trước đây "loading" chỉ là state nội bộ không được dùng ở template nên
         suốt lúc chờ tải, mọi section (banner/loại hình dịch vụ/flash sale/gợi ý/danh sách phòng)
         đều x-show/x-if theo "section.rooms.length" v.v. nên KHÔNG có gì hiện ra — trang chủ
         trắng trơn 1 khoảng. Không biết trước CMS sẽ trả về bao nhiêu/loại section nào nên dùng 2
         khối carousel thẻ chung chung (giống hình dạng Flash Sale/Danh sách phòng thật) làm
         placeholder, đủ để người dùng thấy trang "đang có nội dung" thay vì trống trơn. --}}
    <div x-show="loading" x-cloak>
        <template x-for="i in 2" :key="'hs-skel-' + i">
            <section class="py-4 bg-white">
                <div class="w-full max-w-7xl mx-auto px-4 sm:px-6">
                    <div class="hs-skel hs-skel-title" style="width:180px; height:20px; margin-bottom:14px;"></div>
                    <div style="display:flex; gap:14px; overflow-x:hidden;">
                        <template x-for="j in 4" :key="'hs-skel-card-' + i + '-' + j">
                            <div class="home-card" style="display:flex; flex-direction:column; gap:8px;">
                                <div class="hs-skel hs-skel-img"></div>
                                <div class="hs-skel" style="height:13px; width:85%;"></div>
                                <div class="hs-skel" style="height:13px; width:45%;"></div>
                            </div>
                        </template>
                    </div>
                </div>
            </section>
        </template>
    </div>

    {{-- ============== LOẠI HÌNH DỊCH VỤ + BANNER (2 cột, mỗi cột 1 Center Mode Carousel) ==============
         Khung tìm kiếm đè lên banner (hero-banner-form-overlay) là 1 INSTANCE ĐỘC LẬP, tách biệt
         hoàn toàn với thanh tìm kiếm trong header (hàng 2, header-main.blade.php) — 2 khung cùng
         hiện song song trên trang chủ, không chia sẻ state (xem skipHeaderPill ở HeroSection.php
         + _banner-form.blade.php). --}}
    <section class="py-4 bg-white hs-banner-section-margin" x-show="(roomTypes && roomTypes.length) || (bannerSection && bannerSection.items && bannerSection.items.length)">
        {{-- Không còn px-4/sm:px-6 ở đây — Banner giờ full-bleed sát mép màn hình ở MỌI kích thước
             (kể cả mobile). Cột "Loại hình dịch vụ" bên dưới tự thêm lại padding riêng cho nó
             (xem Cột 2) vì vẫn cần khoảng đệm 2 bên như trước. --}}
        <div class="w-full max-w-7xl mx-auto hs-merged-grid">

                {{-- Cột 1: Banner — luôn đứng trước Loại hình dịch vụ (thứ tự DOM tự nhiên, không
                     cần đảo theo breakpoint nữa). --}}
                <div class="min-w-0 center-carousel-wrap" x-show="bannerSection && bannerSection.items && bannerSection.items.length">
                    <template x-if="bannerSection && bannerSection.items && bannerSection.items.length">
                        <div>
                            <div class="swiper center-carousel" x-ref="bannerSwiperEl">
                                <div class="swiper-wrapper">
                                    <template x-for="banner in bannerSection.items" :key="'banner-' + (banner.url || banner.image_url)">
                                        <div class="swiper-slide">
                                            <a :href="banner.url || '#'" class="banner-card" :style="{ pointerEvents: banner.url ? 'auto' : 'none' }">
                                                <img :src="banner.image_url" :alt="banner.title || ''">
                                            </a>
                                        </div>
                                    </template>
                                </div>
                                <div class="swiper-pagination" id="hs-banner-pagination"></div>
                            </div>
                        </div>
                    </template>
                    {{-- Khung tìm kiếm nổi đè lên nửa dưới banner — instance riêng, độc lập với
                         thanh tìm kiếm trong header (skipHeaderPill=true: không teleport pill gọn,
                         không đồng bộ tab/trạng thái ghim với header). id dùng để header
                         (header-main.blade.php) đo vị trí, biết khi nào đã cuộn qua khỏi khung này
                         mới hiện thanh tìm kiếm riêng của header. --}}
                    <div id="hero-banner-search-overlay" class="hidden lg:block hero-banner-form-overlay">
                        @livewire('bladethemev1::hero-section', [
                            'headerRow' => true,
                            'skipHeaderPill' => true,
                        ], key('hero-section-banner-overlay'))
                    </div>
                </div>

                {{-- Cột 2: Loại hình dịch vụ — luôn đứng NGAY DƯỚI banner (thứ tự DOM tự nhiên) ở
                     mọi kích thước màn hình. Khác Cột 1 (banner, full-bleed sát mép), cột này tự
                     thêm lại px-4/sm:px-6 — GIỮ NGUYÊN ở desktop (không lg:px-0) để thẳng hàng với
                     các section khác (Lần đầu khám phá, Lịch đặt phòng trực tuyến...) đều dùng
                     đúng pattern px-4 sm:px-6 này, không riêng gì banner mới cần full-bleed. --}}
                <template x-if="roomTypes && roomTypes.length">
                    <div class="min-w-0 px-4 sm:px-6 hs-roomtype-col-spacing">
                        {{-- Thẻ nền xám nhạt, icon lớn TRÊN tên (giống nhau mọi kích thước màn
                             hình) — nút prev/next tròn nổi 2 bên hàng thẻ (carouselNav() dùng chung). --}}
                        <div x-data="carouselNav()" x-init="init()">
                            {{-- Tiêu đề: ẩn ở desktop (yêu cầu bỏ, cho thẻ nằm gần thanh tìm kiếm
                                 banner hơn) — vẫn giữ ở mobile. --}}
                            <div class="hs-roomtype-heading" style="margin-bottom:14px;">
                                <h2 class="text-xl font-bold text-gray-900">Loại hình dịch vụ</h2>
                            </div>
                            <div style="position:relative;">
                                {{-- roomtype-nav-desktop-always: ở desktop LUÔN hiện cả 2 nút (kể cả
                                     lúc đang ở đầu/cuối carousel, giống mẫu tham khảo) — CSS
                                     !important đè lên display:none mà x-show gắn qua inline style,
                                     chỉ trong phạm vi @media desktop; mobile vẫn giữ nguyên hành vi
                                     ẩn/hiện theo canScrollPrev/canScrollNext như cũ. --}}
                                <button type="button" class="roomtype-nav roomtype-nav-prev roomtype-nav-desktop-always" aria-label="Trước" x-show="canScrollPrev" @click="prev()">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                </button>
                                <button type="button" class="roomtype-nav roomtype-nav-next roomtype-nav-desktop-always" aria-label="Tiếp" x-show="canScrollNext" @click="next()">
                                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                </button>
                                {{-- px-1: box-shadow của .roomtype-card cần 1 chút khoảng đệm hai bên,
                                     nếu không bị overflow-x-hidden của track cắt cụt (thẻ đầu/cuối
                                     trông như bị che 1 phần). gap-6: giãn cách giữa các thẻ nhiều hơn
                                     (thẻ đã to hơn — xem .roomtype-card). --}}
                                <div x-ref="track" class="flex gap-6 py-2 px-1 overflow-x-hidden" style="scroll-snap-type:x mandatory;">
                                    <template x-for="type in roomTypes" :key="'roomtype-mobile-' + type.id">
                                        <a :href="'{{ route('product.search') }}?type=' + type.slug" class="roomtype-card" style="scroll-snap-align:start;">
                                            <img :src="window.__roomTypeIcon(type)" alt="" class="roomtype-card-icon" loading="lazy" onerror="this.style.display='none'">
                                            <span class="roomtype-card-label" x-text="type.name"></span>
                                        </a>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

            </div>
    </section>

    {{-- ============== LẦN ĐẦU KHÁM PHÁ (2 cột tĩnh: ảnh chào mừng + banner giới thiệu app) ==============
         Section tĩnh, không phụ thuộc dữ liệu CMS/sections — đặt ngay dưới "Các chi nhánh tại..."
         phía trên, luôn hiện (không có x-if/x-show) vì nội dung cố định, không cần chờ dữ liệu. --}}
    <section class="py-6 bg-white">
        <div class="w-full max-w-7xl mx-auto px-4 sm:px-6">
            <h2 class="text-2xl font-bold text-gray-900 mb-4">Lần đầu khám phá</h2>
            {{-- 2 ảnh có tỉ lệ gốc khác hẳn nhau (svg minh hoạ dạng đứng, png banner dạng ngang) —
                 dùng background-image (bg-cover + chiều cao cố định qua .explore-card) thay vì thẻ
                 <img> để 2 cột LUÔN bằng chiều cao nhau, ảnh tự crop cho vừa khung. --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="explore-card" style="background-image: url('{{ asset('images/welcome_joyer_1.svg') }}');">
                    <div class="explore-card-content">
                        <p class="explore-card-title" style="color:#0e3a5c;">Thành viên mới? Quà chất đang đợi!</p>
                        <p class="explore-card-subtitle" style="color:#0e3a5c;">Nhận coupon giảm 20.000đ với người dùng mới</p>
                        <button type="button" class="explore-card-btn" style="background:var(--color-primary);" @click="window.dispatchEvent(new CustomEvent('open-auth-modal'))">Đăng ký ngay</button>
                    </div>
                </div>
                {{-- background-position lệch sang phải: chữ + nội dung nằm bên trái, phần đồ hoạ
                     (thẻ giá/coupon) của ảnh nền dồn về bên phải, tránh đè lên chữ. --}}
                <div class="explore-card" style="background-image: url('{{ asset('images/banner-guest-mobile.png') }}'); background-position: right center;">
                    <div class="explore-card-content">
                        <p class="explore-card-title" style="color:#fff;">Nhận ưu đãi liền tay khi tải app!</p>
                        <p class="explore-card-subtitle" style="color:rgba(255,255,255,.88);">Sử dụng ứng dụng để săn deals mỗi ngày</p>
                        <div style="display:flex; align-items:center; gap:10px; margin-top:2px;">
                            <a href="https://apps.apple.com/us/app/365-home/id6781598163" target="_blank" rel="noopener">
                                <img src="{{ asset('images/applestore.png') }}" alt="Tải trên App Store" class="explore-card-badge">
                            </a>
                            <a href="https://play.google.com/store/apps/details?id=com.home365.app" target="_blank" rel="noopener">
                                <img src="{{ asset('images/googleplay.png') }}" alt="Tải trên Google Play" class="explore-card-badge">
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- "Lịch đặt phòng trực tuyến" — đặt ngay SAU "Lần đầu khám phá" theo yêu cầu (trước đây đã bỏ
         hẳn khỏi trang chủ, giờ thêm lại đúng vị trí này). Partial Blade thuần (không phải Livewire
         component riêng) — tự quản lý state qua Alpine homeBookingBoard() (định nghĩa trong
         public/js/home-sections.js), bên trong nhúng lại đúng Livewire\Book component đã dùng ở
         trang /branch/{slug} và panel ?view=branches của trang tìm kiếm. --}}
    @include('bladethemev1::livewire.home-booking-board')

    {{-- "Các chi nhánh tại..." — đặt ngay dưới "Lịch đặt phòng trực tuyến".
         Livewire component riêng (BranchSuggestion) — query thẳng DB, không còn qua API
         /api/v1/home (dùng chung với app mobile) như bản cũ (_branch-suggestion-section.blade.php,
         dựa vào Alpine x-for lọc trên `sections` load từ API). Vẫn cập nhật lại theo đúng khu vực
         đang chọn qua sự kiện 'province-selected' (xem branch-suggestion.blade.php). --}}
    @livewire('bladethemev1::branch-suggestion')

    <template x-for="section in sections" :key="section.type + '-' + section.id">
        <div>
            {{-- ============== BANNER (chỉ hiện nếu CMS cấu hình >1 block banner — block đầu
                 tiên đã hiển thị ở cột 2 phía trên rồi) ============== --}}
            <template x-if="section.type === 'banner' && section.items && section.items.length && (!bannerSection || section.id !== bannerSection.id)">
                <section class="py-4 bg-white">
                    <div class="w-full max-w-7xl mx-auto px-4 sm:px-6" style="position:relative;" x-data="carouselNav()" x-init="init()">
                        <div x-ref="track" style="display:flex; gap:14px; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none;" class="hide-scrollbar">
                            <template x-for="banner in section.items" :key="'banner-' + (banner.url || banner.image_url)">
                                <a :href="banner.url || '#'" class="banner-card legacy-banner-card"
                                    :style="{ pointerEvents: banner.url ? 'auto' : 'none', scrollSnapAlign: 'start', flexShrink: 0 }">
                                    <img :src="banner.image_url" :alt="banner.title || ''">
                                </a>
                            </template>
                        </div>

                        <button type="button" class="carousel-nav-btn" aria-label="Trước" x-show="canScrollPrev" @click="prev()"
                            style="position:absolute; left:8px; top:calc(50% - 16px); box-shadow:0 2px 8px rgba(0,0,0,.18);">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <button type="button" class="carousel-nav-btn" aria-label="Tiếp" x-show="canScrollNext" @click="next()"
                            style="position:absolute; right:8px; top:calc(50% - 16px); box-shadow:0 2px 8px rgba(0,0,0,.18);">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                </section>
            </template>

            {{-- ============== PROMOTION LIST (phòng khuyến mãi) ============== --}}
            <template x-if="section.type === 'promotion_list' && section.rooms && section.rooms.length">
                <section class="py-4 bg-white">
                    <div class="w-full max-w-7xl mx-auto px-4 sm:px-6" x-data="carouselNav()" x-init="init()">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px;">
                            <div style="display:flex; align-items:center; gap:8px; min-width:0;">
                                <img x-show="section.icon_url" :src="section.icon_url" alt="" style="width:20px;height:20px;object-fit:contain;flex-shrink:0;">
                                <h2 class="hs-section-title" style="font-weight:800; color:#111827; margin:0; text-transform:uppercase; letter-spacing:.02em;" x-text="section.title || 'Flash Sale'"></h2>
                                <a :href="section.view_all_url || '{{ route('product.search') }}'" style="text-decoration:none; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; border:1px solid #e5e7eb; color:#1f2937; transition:all 0.2s; flex-shrink:0;" class="view-all-link hidden lg:flex" @mouseenter="$el.style.backgroundColor='#f3f4f6'; $el.style.borderColor='#d1d5db'" @mouseleave="$el.style.backgroundColor='transparent'; $el.style.borderColor='#e5e7eb'">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:20px; height:20px;">
                                      <path fill-rule="evenodd" d="M16.72 7.72a.75.75 0 0 1 1.06 0l3.75 3.75a.75.75 0 0 1 0 1.06l-3.75 3.75a.75.75 0 1 1-1.06-1.06l2.47-2.47H3a.75.75 0 0 1 0-1.5h16.19l-2.47-2.47a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </a>
                            </div>
                            <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                                <a :href="section.view_all_url || '{{ route('product.search') }}'" style="text-decoration:none; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; border:1px solid #e5e7eb; color:#1f2937; transition:all 0.2s; flex-shrink:0;" class="view-all-link flex lg:hidden" @mouseenter="$el.style.backgroundColor='#f3f4f6'; $el.style.borderColor='#d1d5db'" @mouseleave="$el.style.backgroundColor='transparent'; $el.style.borderColor='#e5e7eb'">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:20px; height:20px;">
                                      <path fill-rule="evenodd" d="M16.72 7.72a.75.75 0 0 1 1.06 0l3.75 3.75a.75.75 0 0 1 0 1.06l-3.75 3.75a.75.75 0 1 1-1.06-1.06l2.47-2.47H3a.75.75 0 0 1 0-1.5h16.19l-2.47-2.47a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </a>
                                <div class="hidden lg:flex" style="align-items:center; gap:6px; flex-shrink:0;">
                                    <button type="button" class="carousel-nav-btn" aria-label="Trước" x-show="canScrollPrev" @click="prev()">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                    </button>
                                    <button type="button" class="carousel-nav-btn" aria-label="Tiếp" x-show="canScrollNext" @click="next()">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div x-ref="track" style="display:flex; gap:14px; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none; padding-bottom:4px;" class="hide-scrollbar">
                            <template x-for="room in section.rooms" :key="'promo-' + room.id">
                                <div x-html="roomCardHtml(room)"></div>
                            </template>
                        </div>
                    </div>
                </section>
            </template>

            {{-- ============== SUGGESTION LIST ============== --}}
            {{-- "Gợi ý cho bạn" theo chi nhánh (suggestion_type === 'branch') được render RIÊNG ở
                 component Livewire BranchSuggestion (@livewire('bladethemev1::branch-suggestion')
                 phía trên — query thẳng DB, không qua API /api/v1/home) — nên loại trừ ở đây để
                 tránh hiện lặp lại 2 lần (API /api/v1/home vẫn trả về cả block branch này trong
                 `sections`, chỉ là không dùng nó để render nữa). Loại "theo phòng"
                 (suggestion_type === 'room') không bị ảnh hưởng, vẫn hiện bình thường ở đúng vị trí
                 cũ trong vòng lặp sections (vẫn lấy từ API như cũ). --}}
            <template x-if="section.type === 'suggestion_list' && section.suggestion_type !== 'branch' && section.items && section.items.length">
                <section class="py-4 bg-white">
                    <div class="w-full max-w-7xl mx-auto px-4 sm:px-6" x-data="carouselNav()" x-init="init()">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px;">
                            <div style="display:flex; align-items:center; gap:12px;">
                                <h2 class="hs-section-title" style="font-weight:800; color:#111827; margin:0;">Gợi ý cho bạn</h2>
                                <a :href="section.view_all_url || '{{ route('product.search') }}'" style="text-decoration:none; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; border:1px solid #e5e7eb; color:#1f2937; transition:all 0.2s; flex-shrink:0;" class="view-all-link hidden lg:flex" @mouseenter="$el.style.backgroundColor='#f3f4f6'; $el.style.borderColor='#d1d5db'" @mouseleave="$el.style.backgroundColor='transparent'; $el.style.borderColor='#e5e7eb'">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:20px; height:20px;">
                                      <path fill-rule="evenodd" d="M16.72 7.72a.75.75 0 0 1 1.06 0l3.75 3.75a.75.75 0 0 1 0 1.06l-3.75 3.75a.75.75 0 1 1-1.06-1.06l2.47-2.47H3a.75.75 0 0 1 0-1.5h16.19l-2.47-2.47a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </a>
                            </div>
                            <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                                <a :href="section.view_all_url || '{{ route('product.search') }}'" style="text-decoration:none; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; border:1px solid #e5e7eb; color:#1f2937; transition:all 0.2s; flex-shrink:0;" class="view-all-link flex lg:hidden" @mouseenter="$el.style.backgroundColor='#f3f4f6'; $el.style.borderColor='#d1d5db'" @mouseleave="$el.style.backgroundColor='transparent'; $el.style.borderColor='#e5e7eb'">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:20px; height:20px;">
                                      <path fill-rule="evenodd" d="M16.72 7.72a.75.75 0 0 1 1.06 0l3.75 3.75a.75.75 0 0 1 0 1.06l-3.75 3.75a.75.75 0 1 1-1.06-1.06l2.47-2.47H3a.75.75 0 0 1 0-1.5h16.19l-2.47-2.47a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </a>
                                <div class="hidden lg:flex" style="align-items:center; gap:6px; flex-shrink:0;">
                                    <button type="button" class="carousel-nav-btn" aria-label="Trước" x-show="canScrollPrev" @click="prev()">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                    </button>
                                    <button type="button" class="carousel-nav-btn" aria-label="Tiếp" x-show="canScrollNext" @click="next()">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div x-ref="track" style="display:flex; gap:14px; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none; padding-bottom:4px;" class="hide-scrollbar">
                            <template x-for="item in section.items" :key="'sugg-' + (item.id ?? item.slug)">
                                <div x-html="section.suggestion_type === 'branch' ? branchCardHtml(item) : roomCardHtml(item)"></div>
                            </template>
                        </div>
                    </div>
                </section>
            </template>

            {{-- ============== ROOM LIST ============== --}}
            {{-- "Danh sách phòng - [chi nhánh]" (display_mode 'fixed' — các khối phòng cố định
                 theo chi nhánh cấu hình sẵn trong CMS): CHỈ hiện khi CHƯA chọn khu vực (provinceName
                 rỗng) — lúc đó "Các chi nhánh"/Flash Sale/"Gợi ý theo phòng" đều tự ẩn (API trả rỗng
                 khi không có province, xem HomeController::buildPromotionList()/buildSuggestionList()),
                 nên hiện danh sách phòng cố định này thay thế. Khi ĐÃ chọn khu vực, ẩn khối này lại
                 để nhường chỗ cho 3 khối theo khu vực kia. Khối room_list kiểu 'by_region' (nếu có,
                 hiện chưa dùng) không bị ảnh hưởng, luôn hiện bình thường. --}}
            <template x-if="section.type === 'room_list' && section.rooms && section.rooms.length">
                <section class="py-4 bg-white" :class="section.display_mode === 'fixed' ? (provinceName ? 'hidden' : '') : ''">
                    <div class="w-full max-w-7xl mx-auto px-4 sm:px-6" x-data="carouselNav()" x-init="init()">
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; gap:12px;">
                            <div style="display:flex; align-items:center; gap:12px;">
                                <div style="min-width:0;">
                                    <h2 class="hs-section-title hs-roomlist-title" style="font-weight:800; color:#111827; margin:0; text-transform:uppercase; letter-spacing:.02em;" x-text="section.title"></h2>
                                    <p x-show="section.subtitle" style="font-size:12px; color:#9ca3af; margin:2px 0 0;" x-text="section.subtitle"></p>
                                </div>
                                <a :href="section.view_all_url || '{{ route('product.search') }}'" style="text-decoration:none; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; border:1px solid #e5e7eb; color:#1f2937; transition:all 0.2s; flex-shrink:0;" class="view-all-link hidden lg:flex" @mouseenter="$el.style.backgroundColor='#f3f4f6'; $el.style.borderColor='#d1d5db'" @mouseleave="$el.style.backgroundColor='transparent'; $el.style.borderColor='#e5e7eb'">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:20px; height:20px;">
                                      <path fill-rule="evenodd" d="M16.72 7.72a.75.75 0 0 1 1.06 0l3.75 3.75a.75.75 0 0 1 0 1.06l-3.75 3.75a.75.75 0 1 1-1.06-1.06l2.47-2.47H3a.75.75 0 0 1 0-1.5h16.19l-2.47-2.47a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </a>
                            </div>
                            <div style="display:flex; align-items:center; gap:6px; flex-shrink:0;">
                                <a :href="section.view_all_url || '{{ route('product.search') }}'" style="text-decoration:none; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; border:1px solid #e5e7eb; color:#1f2937; transition:all 0.2s; flex-shrink:0;" class="view-all-link flex lg:hidden" @mouseenter="$el.style.backgroundColor='#f3f4f6'; $el.style.borderColor='#d1d5db'" @mouseleave="$el.style.backgroundColor='transparent'; $el.style.borderColor='#e5e7eb'">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:20px; height:20px;">
                                      <path fill-rule="evenodd" d="M16.72 7.72a.75.75 0 0 1 1.06 0l3.75 3.75a.75.75 0 0 1 0 1.06l-3.75 3.75a.75.75 0 1 1-1.06-1.06l2.47-2.47H3a.75.75 0 0 1 0-1.5h16.19l-2.47-2.47a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                    </svg>
                                </a>
                                <div class="hidden lg:flex" style="align-items:center; gap:6px; flex-shrink:0;">
                                    <button type="button" class="carousel-nav-btn" aria-label="Trước" x-show="canScrollPrev" @click="prev()">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                                    </button>
                                    <button type="button" class="carousel-nav-btn" aria-label="Tiếp" x-show="canScrollNext" @click="next()">
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div x-ref="track" style="display:flex; gap:14px; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none; padding-bottom:4px;" class="hide-scrollbar">
                            <template x-for="room in section.rooms" :key="'room-' + room.id">
                                <div x-html="roomCardHtml(room)"></div>
                            </template>
                        </div>
                    </div>
                </section>
            </template>
        </div>
    </template>

    <style>
        {{-- scrollbar-width:none (Firefox) thiếu trước đây — chỉ có -ms-overflow-style (IE/Edge cũ)
             và ::-webkit-scrollbar (Chrome/Safari), nên vẫn còn hiện thanh scroll ngang ở Firefox
             desktop. Vẫn giữ overflow-x:auto (không đổi thành hidden) để cuộn/vuốt tay vẫn hoạt
             động bình thường — chỉ ẩn thanh scroll hiển thị, không chặn tương tác cuộn. --}}
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .hide-scrollbar::-webkit-scrollbar { display: none; }

        /* Tiêu đề section trang chủ ("Flash Sale", "Gợi ý cho bạn", "Danh sách phòng...", "Các chi
           nhánh tại..." ở branch-suggestion.blade.php — dùng chung class này) — mobile dùng đúng
           cỡ text-2xl của Tailwind (1.5rem/24px), giữ nguyên 1.1rem ở desktop (>=1024px) như trước. */
        .hs-section-title {
            font-size: 1.5rem;
        }
        @media (min-width: 1024px) {
            .hs-section-title { font-size: 1.1rem; }
        }

        /* "Danh sách phòng - [chi nhánh]" (room_list, display_mode 'fixed') — tiêu đề lấy tên chi
           nhánh động, có thể dài, in hoa + letter-spacing nên ở mobile 1.5rem (như các tiêu đề
           section khác) dễ bị to/tràn dòng. Chỉ thu nhỏ riêng khối này ở mobile, desktop vẫn dùng
           chung 1.1rem như hs-section-title. */
        @media (max-width: 1023px) {
            .hs-roomlist-title { font-size: 1.15rem; }
        }

        /* Skeleton toàn trang chủ trong lúc /api/v1/home đang tải — xem khối x-show="loading"
           ở đầu file. */
        @keyframes hs-skel-shimmer {
            0% { background-position: -400px 0; }
            100% { background-position: 400px 0; }
        }
        .hs-skel {
            background: linear-gradient(90deg, #eef0f2 25%, #f7f8f9 37%, #eef0f2 63%);
            background-size: 800px 100%;
            animation: hs-skel-shimmer 1.4s ease-in-out infinite;
            border-radius: 6px;
            display: block;
        }
        .hs-skel-title { border-radius: 6px; }
        .hs-skel-img { padding-top: 72%; border-radius: 14px; }

        /* Hàng gộp loại hình dịch vụ + banner: 1 cột trên mobile, 2 cột không đều (banner rộng
           hơn) từ lg trở lên. Dùng CSS thuần thay vì Tailwind grid-cols-[2fr_3fr] vì class tuỳ ý
           kiểu này chỉ được biên dịch nếu chạy build ngay sau khi thêm — an toàn hơn khi để trong
           style block gộp sẵn ở đây, không phụ thuộc thời điểm build lại assets. */
        .hs-merged-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 35px;
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

        .room-type-card {
            position: relative;
            display: block;
            width: 100%;
            height: 100%;
            text-decoration: none;
            overflow: hidden;
            border-radius: 20px;
            min-height: 220px;
            background: #e5e7eb;
            /* Bóng đổ ra ngoài để thẻ nổi nhẹ trên nền trắng, kết hợp inset: 1 viền sáng mảnh
               phía trong (cảm giác "kính cường lực" cao cấp) + 1 vùng tối inset ở đáy làm nền
               chữ tương phản tốt hơn mà không cần lớp gradient phủ quá đậm như trước. */
            box-shadow:
                0 1px 2px rgba(17, 24, 39, .04),
                0 10px 24px -12px rgba(17, 24, 39, .28),
                inset 0 0 0 1px rgba(255, 255, 255, .10),
                inset 0 -46px 34px -22px rgba(0, 0, 0, .38);
        }
        @media (min-width: 768px) {
            .room-type-card {
                min-height: 300px;
            }
        }
        .room-type-card .rtc-bg {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .room-type-card .rtc-gradient {
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(0,0,0,.58) 0%, rgba(0,0,0,.16) 42%, rgba(0,0,0,0) 70%);
            pointer-events: none;
        }
        .room-type-card .rtc-title {
            position: absolute;
            left: 16px;
            right: 16px;
            bottom: 14px;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: .01em;
            color: #fff;
            text-shadow: 0 1px 6px rgba(0,0,0,.5);
            line-height: 1.25;
            z-index: 2;
        }

        /* Ảnh banner CMS thường có tỉ lệ rất ngang (~10:3) — dùng aspect-ratio đúng tỉ lệ đó +
           object-fit:contain (thay vì chiều cao cố định + cover) để LUÔN thấy đủ 100% nội dung
           ảnh ở MỌI kích thước màn hình, không bị cắt mất chữ/khuyến mãi 2 bên. Chấp nhận có
           khoảng nền trống (background-color) nếu ảnh không khớp tuyệt đối tỉ lệ này. */
        .banner-card {
            display: block;
            position: relative;
            width: 100%;
            aspect-ratio: 10 / 3;
            border-radius: 12px;
            overflow: hidden;
            background: #e5e7eb;
        }
        .banner-card img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        /* Banner "dự phòng" (chỉ dùng khi CMS có >1 block banner — block đầu đã hiển thị ở cột 2
           của hàng center-mode phía trên) — vẫn dùng carousel cuộn thường như trước, cần lại
           kích thước cố định theo flex-basis vì không nằm trong Swiper. */
        .legacy-banner-card {
            flex: 0 0 calc(100vw - 32px);
            width: calc(100vw - 32px);
            max-width: calc(100vw - 32px);
        }
        @media (min-width: 640px) {
            .legacy-banner-card {
                flex-basis: calc((100vw - 62px) / 2);
                width: calc((100vw - 62px) / 2);
                max-width: calc((100vw - 62px) / 2);
            }
        }
        @media (min-width: 1024px) {
            .legacy-banner-card {
                flex-basis: calc((100vw - 76px) / 3);
                width: calc((100vw - 76px) / 3);
                max-width: calc((100vw - 76px) / 3);
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

        /* Ở mobile, rule chung "button { min-width/min-height: 44px }" (chuẩn kích thước chạm
           tối thiểu) thắng riêng 2 thuộc tính min-width/min-height vì .carousel-nav-btn không tự
           khai báo — khiến nút to hơn 32px đã set. Chỉnh còn 35px, đè luôn min-width/min-height
           để không bị rule chung ghi đè lại. */
        @media (max-width: 767.98px) {
            .carousel-nav-btn {
                width: 35px;
                height: 35px;
                min-width: 35px;
                min-height: 35px;
            }
        }
        .carousel-nav-btn.swiper-button-disabled {
            opacity: .35;
            pointer-events: none;
            cursor: default;
        }

        /* Nút prev/next đè thẳng lên khung ảnh (không nằm ngoài lề nữa), mặc định ẩn — chỉ hiện
           khi hover vào carousel (desktop). Mobile giữ ẩn hẳn, tương tác chính là vuốt. */
        .center-carousel-wrap {
            position: relative;
        }
        /* Khung tìm kiếm nổi đè lên nửa dưới banner (khôi phục theo yêu cầu) — khung thẻ trắng bo
           góc + đổ bóng, z-index cao hơn pagination dots (4) để không bị che. Bản thân thanh tìm
           kiếm bên trong đã tự có nền trắng + shadow riêng của nó (_banner-form.blade.php) nên
           trông như 1 viên thuốc nổi bên trong khung thẻ này. */
        .hero-banner-form-overlay {
            position: absolute;
            left: 50%;
            bottom: -25%;
            transform: translateX(-50%);
            z-index: 10;
            width: min(94%, 960px);
            background: #fff;
            border-radius: 28px;
            padding: 18px 16px;
            box-shadow: 0 14px 44px rgba(0, 0, 0, .18);
        }

        /* Thẻ "Loại hình dịch vụ" — nền xám nhẹ, không box-shadow (bỏ theo yêu cầu), icon lớn phía
           trên tên, giống nhau ở mọi kích thước màn hình (không còn phân biệt mobile/desktop như
           bản pill trước đây). */
        .roomtype-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 14px;
            flex-shrink: 0;
            width: 150px;
            background: #f5f5f6;
            border-radius: 20px;
            padding: 26px 14px;
            text-decoration: none;
            transition: background-color .15s ease;
        }
        .roomtype-card:hover { background: #ececee; }
        .roomtype-card-icon {
            width: 72px;
            height: 72px;
            object-fit: contain;
        }
        .roomtype-card-label {
            font-size: 14.5px;
            font-weight: 600;
            color: #111827;
            text-align: center;
            line-height: 1.3;
        }
        @media (min-width: 1024px) {
            /* Đúng 5 thẻ/hàng ở desktop — width tính theo % bề rộng track (không cố định px) để
               luôn vừa đủ 5 thẻ dù màn hình rộng hẹp khác nhau; track dùng gap-6 (24px), 5 thẻ có
               4 khoảng gap nên trừ 4*24px trước khi chia 5. Thẻ thứ 6 trở đi vẫn tràn ra ngoài,
               cuộn được qua carousel (canScrollNext) như thiết kế hiện tại. */
            .roomtype-card { width: calc((100% - 96px) / 5); padding: 32px 16px; }
            .roomtype-card-icon { width: 88px; height: 88px; }
            .roomtype-card-label { font-size: 16px; }
        }

        /* Nút prev/next tròn nổi 2 bên hàng thẻ, giống mẫu tham khảo — luôn có thể hiện ở mọi kích
           thước màn hình (x-show theo canScrollPrev/canScrollNext thật, không giới hạn breakpoint). */
        .roomtype-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            z-index: 5;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: #fff;
            border: none;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .14);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            padding: 0;
        }
        .roomtype-nav svg { width: 20px; height: 20px; color: #374151; }
        .roomtype-nav-prev { left: -14px; }
        .roomtype-nav-next { right: -14px; }

        /* Ở desktop, luôn hiện cả 2 nút pre/next (kể cả lúc đang ở đầu/cuối carousel) — đè lên
           display:none mà x-show gắn qua inline style (canScrollPrev/canScrollNext), chỉ áp dụng
           từ 1024px trở lên; dưới mức đó vẫn ẩn/hiện theo đúng khả năng cuộn thật như cũ. */
        @media (min-width: 1024px) {
            .roomtype-nav-desktop-always { display: flex !important; }
        }

        /* Khoảng cách dưới section Loại hình dịch vụ + Banner — chỉ desktop (dùng CSS thuần thay
           vì lg:mb-6 của Tailwind, class đó chưa từng dùng ở đâu khác trong site nên không có
           trong file CSS đã build sẵn). */
        @media (min-width: 1024px) {
            .hs-banner-section-margin { margin-bottom: 24px; }
        }

        /* Tiêu đề "Loại hình dịch vụ": ẩn ở desktop theo yêu cầu trước đó — vẫn giữ ở mobile.
           Cột này cần thêm margin-top để né khung tìm kiếm nổi đè lên banner (bottom:-25%,
           .hero-banner-form-overlay) — đã khôi phục lại theo yêu cầu, chỉ desktop (overlay chỉ
           hiện lg:block). */
        @media (min-width: 1024px) {
            .hs-roomtype-heading { display: none; }
            .hs-roomtype-col-spacing { margin-top: 90px; }
        }

        /* "Lần đầu khám phá": 2 cột luôn cùng 1 chiều cao cố định (ảnh crop vừa khung bằng
           background-size:cover) dù ảnh gốc có tỉ lệ khác nhau (svg minh hoạ dạng đứng vs png
           banner dạng ngang) — thấp hơn bản trước, chỉ đủ cho tiêu đề + phụ đề + nút. */
        .explore-card {
            width: 100%;
            height: 150px;
            border-radius: 16px;
            background-color: #f3f4f6;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            display: flex;
            align-items: center;
        }
        @media (min-width: 1024px) {
            .explore-card { height: 190px; }
        }
        .explore-card-content {
            max-width: 62%;
            padding: 0 20px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        @media (min-width: 1024px) {
            .explore-card-content { padding: 0 28px; }
        }
        .explore-card-title {
            font-size: 15px;
            font-weight: 800;
            line-height: 1.3;
            margin: 0;
        }
        @media (min-width: 1024px) {
            .explore-card-title { font-size: 17px; }
        }
        .explore-card-subtitle {
            font-size: 12.5px;
            line-height: 1.35;
            margin: 0;
        }
        @media (min-width: 1024px) {
            .explore-card-subtitle { font-size: 13px; }
        }
        {{-- Mobile: nút nhỏ lại (padding/font giảm) cho vừa khung .explore-card chỉ cao 150px —
             kích thước cũ (8px 18px / 13px) áp dụng mọi nơi từng bị to so với khung mobile hẹp,
             chỉ hợp với khung 190px ở desktop (>=1024px), nên chuyển thành mobile-first: nhỏ mặc
             định, phóng to lại đúng kích thước cũ từ 1024px trở lên. --}}
        .explore-card-btn {
            align-self: flex-start;
            margin-top: 4px;
            padding: 6px 14px;
            border-radius: 9999px;
            color: #fff;
            font-size: 11.5px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: opacity .15s ease;
        }
        .explore-card-btn:hover {
            opacity: .9;
        }
        @media (min-width: 1024px) {
            .explore-card-btn {
                padding: 8px 18px;
                font-size: 13px;
            }
        }
        .explore-card-badge {
            height: 26px;
            width: auto;
            display: block;
        }
        @media (min-width: 1024px) {
            .explore-card-badge { height: 42px; }
        }

        /* Center Mode Carousel (loại hình dịch vụ + banner): slide đang active nổi bật (scale +
           full opacity), 2 bên mờ/nhỏ hơn. Slide peek nằm hoàn toàn trong chiều rộng container
           nhờ slidesPerView lẻ (vd 1.08) — KHÔNG cần overflow:visible (sẽ làm tràn ngang cả trang). */
        .center-carousel {
            overflow: hidden;
        }
        .center-carousel .swiper-slide {
            opacity: .55;
            transform: scale(.92);
            transition: transform .35s ease, opacity .35s ease;
        }
        .center-carousel .swiper-slide-active {
            opacity: 1;
            transform: scale(1);
        }
        /* Carousel indicator (dots) nằm ngay trong khung ảnh thay vì hàng riêng bên dưới. */
        .center-carousel .swiper-pagination {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 10px;
            z-index: 4;
        }
        .center-carousel .swiper-pagination-bullet {
            background: #fff;
            opacity: .6;
            box-shadow: 0 1px 3px rgba(0,0,0,.45);
        }
        .center-carousel .swiper-pagination-bullet-active {
            background: #fff;
            opacity: 1;
        }
        /* Loại hình dịch vụ có tiêu đề chữ nằm ở đáy ảnh (.rtc-title, bottom:14px) — đẩy dots lên
           cao hơn 1 chút để không đè lên chữ. */
        #hs-roomtype-pagination {
            bottom: 40px;
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
            .room-wishlist-btn:hover {
                transform: scale(1.08);
            }
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

    <script src="{{ asset('js/home-sections.js') }}?v={{ filemtime(public_path('js/home-sections.js')) }}"></script>
</div>
