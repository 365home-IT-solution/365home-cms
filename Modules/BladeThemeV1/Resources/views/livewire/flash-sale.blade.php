<div
    x-data="homeSections()"
    x-init="init()"
    x-cloak
>
    {{-- ============== ROOM TYPE CARDS (loại hình dịch vụ) ============== --}}
    <template x-if="roomTypes && roomTypes.length">
        <section class="py-4 bg-white">
            <div class="w-full max-w-11xl mx-auto px-4 sm:px-6" x-data="carouselNav()" x-init="init()">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px;">
                    <div class="hidden lg:flex" style="align-items:center; gap:6px; flex-shrink:0;">
                        <button type="button" class="carousel-nav-btn" aria-label="Trước" x-show="canScrollPrev" @click="prev()">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <button type="button" class="carousel-nav-btn" aria-label="Tiếp" x-show="canScrollNext" @click="next()">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                </div>

                <div x-ref="track" style="display:flex; gap:14px; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none; padding-bottom:4px;" class="hide-scrollbar">
                    <template x-for="type in roomTypes" :key="'roomtype-' + type.id">
                        <a :href="'{{ route('product.search') }}?type=' + type.slug" class="room-type-card">
                            <img class="rtc-bg" :src="window.__roomTypeImage(type)" alt="" loading="lazy" onerror="this.style.display='none'">
                            <span class="rtc-gradient"></span>
                            <span class="rtc-arrow">
                                <svg viewBox="0 0 24 24" fill="none" stroke="#111827" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M7 7h10v10"/></svg>
                            </span>
                            <span class="rtc-title" x-text="type.name"></span>
                        </a>
                    </template>
                </div>
            </div>
        </section>
    </template>

    {{-- ============== BANNER ============== --}}
    <template x-if="bannerSection && bannerSection.items && bannerSection.items.length">
        <section class="py-4 bg-white">
            <div class="w-full max-w-11xl mx-auto px-4 sm:px-6" style="position:relative;" x-data="carouselNav()" x-init="init()">
                <div x-ref="track" style="display:flex; gap:14px; overflow-x:auto; scroll-snap-type:x mandatory; -webkit-overflow-scrolling:touch; scrollbar-width:none;" class="hide-scrollbar">
                    <template x-for="banner in bannerSection.items" :key="'banner-' + (banner.url || banner.image_url)">
                        <a :href="banner.url || '#'" class="banner-card"
                            :style="{ display: 'block', overflow: 'hidden', borderRadius: '12px', pointerEvents: banner.url ? 'auto' : 'none', scrollSnapAlign: 'start', flexShrink: 0 }">
                            <div style="position:relative; width:100%; padding-top:32%; border-radius:12px; overflow:hidden;">
                                <img :src="banner.image_url" :alt="banner.title || ''"
                                    style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">
                            </div>
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

    <template x-for="section in otherSections" :key="section.type + '-' + section.id">
        <div>
            {{-- ============== PROMOTION LIST (phòng khuyến mãi) ============== --}}
            <template x-if="section.type === 'promotion_list' && section.rooms && section.rooms.length">
                <section class="py-4 bg-white">
                    <div class="w-full max-w-11xl mx-auto px-4 sm:px-6" x-data="carouselNav()" x-init="init()">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px;">
                            <div style="display:flex; align-items:center; gap:8px; min-width:0;">
                                <img x-show="section.icon_url" :src="section.icon_url" alt="" style="width:20px;height:20px;object-fit:contain;flex-shrink:0;">
                                <h2 style="font-size:1.1rem; font-weight:800; color:#111827; margin:0; text-transform:uppercase; letter-spacing:.02em;" x-text="section.title || 'Flash Sale'"></h2>
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
            <template x-if="section.type === 'suggestion_list' && section.items && section.items.length">
                <section class="py-4 bg-white">
                    <div class="w-full max-w-11xl mx-auto px-4 sm:px-6" x-data="carouselNav()" x-init="init()">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px;">
                            <div style="display:flex; align-items:center; gap:12px;">
                                <h2 style="font-size:1.1rem; font-weight:800; color:#111827; margin:0;">Gợi ý cho bạn</h2>
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
            <template x-if="section.type === 'room_list' && section.rooms && section.rooms.length">
                <section class="py-4 bg-white">
                    <div class="w-full max-w-11xl mx-auto px-4 sm:px-6" x-data="carouselNav()" x-init="init()">
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; gap:12px;">
                            <div style="display:flex; align-items:center; gap:12px;">
                                <div style="min-width:0;">
                                    <h2 style="font-size:1.1rem; font-weight:800; color:#111827; margin:0; text-transform:uppercase; letter-spacing:.02em;" x-text="section.title"></h2>
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

        .room-type-card {
            position: relative;
            display: block;
            text-decoration: none;
            overflow: hidden;
            border-radius: 20px;
            min-height: 168px;
            background: #e5e7eb;
            flex: 0 0 calc(46vw - 20px);
            width: calc(46vw - 20px);
            max-width: calc(46vw - 20px);
            flex-shrink: 0;
            scroll-snap-align: start;
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
                flex: 0 0 240px;
                width: 240px;
                max-width: 240px;
                min-height: 190px;
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
        .room-type-card .rtc-arrow {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: rgba(255,255,255,.85);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2;
            box-shadow: 0 2px 6px rgba(17, 24, 39, .18);
        }
        .room-type-card .rtc-arrow svg { width: 15px; height: 15px; }
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

        .banner-card {
            /* Mobile: 1 banner / hàng */
            flex: 0 0 calc(100vw - 32px);
            width: calc(100vw - 32px);
            max-width: calc(100vw - 32px);
        }
        @media (min-width: 640px) {
            /* Tablet / màn vừa: 2 banner / hàng */
            .banner-card {
                flex-basis: calc((100vw - 62px) / 2);
                width: calc((100vw - 62px) / 2);
                max-width: calc((100vw - 62px) / 2);
            }
        }
        @media (min-width: 1024px) {
            /* Desktop: 3 banner / hàng */
            .banner-card {
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
