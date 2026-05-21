<div class="bg-white" x-data="{ showModal: false, slotPickerOpen: false }">
    @if(session('booking_conflict_error'))
    <div
        x-data="{ show: true }"
        x-show="show"
        x-init="setTimeout(() => show = false, 12000)"
        x-transition:leave="transition ease-in duration-500"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-4"
        class="fixed top-4 left-1/2 -translate-x-1/2 z-[9999] w-full max-w-xl px-4"
    >
        <div class="flex items-start gap-3 bg-red-600 text-white rounded-xl shadow-2xl px-5 py-4">
            <svg class="w-6 h-6 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
            <div class="flex-1 text-sm font-medium leading-snug">
                {{ session('booking_conflict_error') }}
            </div>
            <button @click="show = false" class="text-white/70 hover:text-white ml-2">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </div>
    @endif

    <div class="md:px-8 px-4 mx-auto booking-confirmation-modal">
        <div id="seo-data" data-seo-title="{{ $product->name ?? 'Phòng' }}"
             data-seo-description="{{ $short_description ?? 'Phòng' }}"
             data-seo-keyword="{{ $product->seo_keywords ?? 'Phòng' }}">
        </div>
        <div class="py-6">
            <div>
                <div class="flex flex-wrap lg:flex-nowrap gap-0 lg:gap-5">
                    <div class="w-full lg:w-2/3">
                        <div class="p-3 overflow-hidden rounded-none lg:rounded-lg bg-white">
                            
                            <h1 class="text-6xl font-extrabold mb-4 text-gray-900 transition-all duration-300 hover:text-primary-500">
                                {{ $product->name ?? 'Tên sản phẩm không có' }}
                            </h1>
                            
                            {{-- Ảnh phòng --}}
                            @php
                                $mainImg     = $product->hasMedia('Ảnh bìa') ? $product->getFirstMedia('Ảnh bìa')->getUrl() : 'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?w=900&q=80';
                                $galleryImgs = $mediaSecond ?? collect([]);
                                $allImgs     = collect([$mainImg])->merge($galleryImgs->map(fn($m) => $m->getUrl()));
                                $totalImgCount = $allImgs->count();
                                $videoSettingPd = is_array($product->setting_video_room) ? $product->setting_video_room : [];
                                $hasVideoPd     = !empty(trim($videoSettingPd['url'] ?? ''));
                            @endphp

                            <style>
                                /* --- single image --- */
                                .pd-single-wrap {
                                    position: relative; border-radius: 16px; overflow: hidden;
                                    margin-bottom: 1.5rem; height: 420px;
                                }
                                .pd-single-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }

                                /* --- gallery grid (multiple) --- */
                                .pd-gallery-grid {
                                    display: grid;
                                    grid-template-columns: 3fr 2fr;
                                    grid-template-rows: 200px 200px;
                                    gap: 6px;
                                    border-radius: 16px;
                                    overflow: hidden;
                                    margin-bottom: 1.5rem;
                                }
                                .pd-gallery-grid .pd-main { grid-row: 1 / 3; position: relative; overflow: hidden; }
                                .pd-gallery-grid .pd-sub  { overflow: hidden; position: relative; }
                                .pd-gallery-grid img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .35s; cursor: pointer; }
                                .pd-gallery-grid img:hover { transform: scale(1.04); }

                                /* --- view / action btns row --- */
                                .pd-view-btn {
                                    position: relative;
                                    display: inline-flex; align-items: center; gap: 6px;
                                    background: rgba(255,255,255,.92); backdrop-filter: blur(4px);
                                    border: 1px solid rgba(0,0,0,.12); border-radius: 10px;
                                    padding: 7px 14px; font-size: 13px; font-weight: 600; color: #222;
                                    cursor: pointer; transition: all .2s;
                                    box-shadow: 0 2px 8px rgba(0,0,0,.12);
                                }
                                .pd-view-btn:hover { background: #fff; box-shadow: 0 4px 16px rgba(0,0,0,.18); }

                                /* --- lightbox overlay --- */
                                .pd-lightbox-overlay {
                                    position: fixed; inset: 0; z-index: 9999;
                                    background: rgba(0,0,0,.92);
                                    display: flex; flex-direction: column;
                                    align-items: center; justify-content: center;
                                }
                                .pd-lightbox-main {
                                    flex: 1; display: flex; align-items: center; justify-content: center;
                                    width: 100%; padding: 0 60px; box-sizing: border-box; min-height: 0;
                                }
                                .pd-lightbox-main img {
                                    max-width: 100%; max-height: calc(100vh - 180px);
                                    object-fit: contain; border-radius: 8px;
                                    user-select: none; -webkit-user-drag: none;
                                }
                                .pd-lightbox-arrow {
                                    position: absolute; top: 50%; transform: translateY(-50%);
                                    background: rgba(255,255,255,.15); border: none;
                                    color: #fff; border-radius: 50%; width: 46px; height: 46px;
                                    display: flex; align-items: center; justify-content: center;
                                    cursor: pointer; transition: background .2s; z-index: 2;
                                }
                                .pd-lightbox-arrow:hover { background: rgba(255,255,255,.3); }
                                .pd-lightbox-arrow.left  { left: 10px; }
                                .pd-lightbox-arrow.right { right: 10px; }
                                .pd-lightbox-close {
                                    position: absolute; top: 14px; right: 18px;
                                    background: none; border: none; color: #fff;
                                    font-size: 30px; line-height: 1; cursor: pointer; opacity: .8;
                                }
                                .pd-lightbox-close:hover { opacity: 1; }
                                .pd-lightbox-counter {
                                    color: rgba(255,255,255,.7); font-size: 13px;
                                    position: absolute; top: 20px; left: 50%; transform: translateX(-50%);
                                }
                                .pd-lightbox-thumbs {
                                    display: flex; gap: 8px; padding: 12px 16px;
                                    overflow-x: auto; max-width: 100%;
                                }
                                .pd-lightbox-thumbs::-webkit-scrollbar { height: 4px; }
                                .pd-lightbox-thumbs::-webkit-scrollbar-thumb { background: rgba(255,255,255,.3); border-radius: 4px; }
                                .pd-thumb {
                                    flex-shrink: 0; width: 72px; height: 54px;
                                    border-radius: 6px; overflow: hidden;
                                    border: 2px solid transparent; cursor: pointer; transition: border-color .2s;
                                }
                                .pd-thumb.active { border-color: #fff; }
                                .pd-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }

                                @media (max-width: 640px) {
                                    .pd-gallery-grid { grid-template-columns: 3fr 2fr; grid-template-rows: 150px 150px; }
                                    .pd-single-wrap { height: 260px; }
                                    .pd-lightbox-main { padding: 0 44px; }
                                }
                                @media (min-width: 768px) {
                                    .pd-mobile-only { display: none !important; }
                                }
                                @media (max-width: 767px) {
                                    .pd-from-book-hide { display: none !important; }
                                }
                            </style>

                            {{-- Alpine gallery component --}}
                            <div x-data="{
                                open: false,
                                current: 0,
                                images: {{ json_encode($allImgs->values()->all()) }},
                                show(idx) { this.current = idx; this.open = true; document.body.style.overflow = 'hidden'; },
                                close() { this.open = false; document.body.style.overflow = ''; },
                                prev() { this.current = (this.current - 1 + this.images.length) % this.images.length; },
                                next() { this.current = (this.current + 1) % this.images.length; },
                                onKey(e) {
                                    if (!this.open) return;
                                    if (e.key === 'ArrowLeft')  this.prev();
                                    if (e.key === 'ArrowRight') this.next();
                                    if (e.key === 'Escape')     this.close();
                                }
                            }" @keydown.window="onKey($event)">

                                {{-- Ảnh chính (ẩn trên mobile khi đến từ trang booking) --}}
                                <div class="pd-single-wrap{{ $fromBookingPage ? ' pd-from-book-hide' : '' }}">
                                    <img src="{{ $mainImg }}" alt="{{ $product->name ?? 'Ảnh chính' }}"
                                         @if($totalImgCount > 1) @click="show(0)" style="cursor:pointer;" @endif>
                                </div>

                                {{-- Nút hành động: Xem ảnh + Xem video (cạnh nhau) --}}
                                <div style="display:flex;gap:8px;margin-bottom:1.25rem;flex-wrap:wrap;">
                                    @if($totalImgCount > 1)
                                    <button type="button" class="pd-view-btn{{ $fromBookingPage ? ' pd-from-book-hide' : '' }}" @click="show(0)">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                                            <rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>
                                        </svg>
                                        Xem {{ $totalImgCount }} ảnh
                                    </button>
                                    @endif
                                    @if($hasVideoPd)
                                    <button type="button" class="pd-view-btn{{ $fromBookingPage ? ' pd-from-book-hide' : '' }}"
                                            style="background:var(--color-primary);color:#fff;border-color:transparent;box-shadow:0 3px 12px rgba(var(--color-primary-rgb),.35);"
                                            @click="$dispatch('pd-open-video')">
                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor">
                                            <circle cx="12" cy="12" r="10" fill="rgba(255,255,255,.25)"/>
                                            <polygon points="10,8 10,16 17,12" fill="white"/>
                                        </svg>
                                        Xem video phòng
                                    </button>
                                    @endif
                                    {{-- Nút xem thông tin phòng (bảng giá + tiện ích) — chỉ hiện khi đến từ trang book, ẩn trên desktop --}}
                                    @if($fromBookingPage)
                                    <div x-data="{ roomInfoOpen: false }" class="contents">
                                        <button type="button" class="pd-view-btn pd-mobile-only" @click="roomInfoOpen = true">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                                            </svg>
                                            Thông tin phòng
                                        </button>
                                        <template x-teleport="body">
                                            <div x-show="roomInfoOpen" x-cloak
                                                 x-transition:enter="transition ease-out duration-200"
                                                 x-transition:enter-start="opacity-0"
                                                 x-transition:enter-end="opacity-100"
                                                 x-transition:leave="transition ease-in duration-150"
                                                 x-transition:leave-start="opacity-100"
                                                 x-transition:leave-end="opacity-0"
                                                 @keydown.escape.window="roomInfoOpen = false"
                                                 style="position:fixed;inset:0;z-index:10001;">
                                                {{-- Backdrop --}}
                                                <div style="position:absolute;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);" @click="roomInfoOpen = false"></div>
                                                {{-- Slide panel --}}
                                                <div @click.stop
                                                     style="position:fixed;top:0;right:0;height:100%;width:min(100vw,680px);background:#fff;overflow-y:auto;z-index:2;box-shadow:-8px 0 40px rgba(0,0,0,.25);">
                                                    {{-- Header cố định --}}
                                                    <div style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;z-index:10;">
                                                        <div>
                                                            <h2 style="font-size:1.1rem;font-weight:700;color:#111827;margin:0;">{{ $product->name }}</h2>
                                                            <p style="font-size:0.8rem;color:#6b7280;margin:4px 0 0;">Tiện ích & Bảng giá phòng</p>
                                                        </div>
                                                        <button @click="roomInfoOpen = false"
                                                                style="background:none;border:1px solid #d1d5db;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:22px;color:#6b7280;line-height:1;">
                                                            &times;
                                                        </button>
                                                    </div>
                                                    {{-- Nội dung cuộn --}}
                                                    <div style="padding:20px;">
                                                        @include('bladethemev1::components.product-detail.amenities')
                                                        @include('bladethemev1::components.product-detail.pricing-room')
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                    <button type="button" class="pd-view-btn pd-mobile-only" @click="slotPickerOpen = true">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                                        </svg>
                                        Chọn lại khung giờ
                                    </button>
                                    @endif
                                </div>

                                {{-- === Lightbox modal (chỉ dùng khi nhiều ảnh) === --}}
                                @if ($totalImgCount > 1)
                                <div x-show="open" x-cloak
                                     style="position:fixed;inset:0;z-index:10000!important;background:rgba(0,0,0,.92);">

                                    {{-- Backdrop: click vùng tối để đóng --}}
                                    <div style="position:absolute;inset:0;z-index:0;" @click="close()"></div>

                                    {{-- Nút đóng --}}
                                    <button @click="close()" aria-label="Đóng"
                                            style="position:absolute;top:14px;right:18px;z-index:3;background:none;border:none;color:#fff;font-size:32px;line-height:1;cursor:pointer;opacity:.85;">&times;</button>

                                    {{-- Bộ đếm --}}
                                    <div x-text="(current + 1) + ' / ' + images.length"
                                         style="position:absolute;top:18px;left:50%;transform:translateX(-50%);z-index:3;color:rgba(255,255,255,.7);font-size:13px;white-space:nowrap;"></div>

                                    {{-- Vùng ảnh: top=50px (dưới header), bottom=90px (trên thumbs) --}}
                                    <div style="position:absolute;top:50px;bottom:90px;left:0;right:0;z-index:1;display:flex;align-items:center;justify-content:center;padding:0 60px;box-sizing:border-box;">

                                        <button @click.stop="prev()" aria-label="Trước"
                                                style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:50%;width:46px;height:46px;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:2;">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 18l-6-6 6-6"/></svg>
                                        </button>

                                        <img :src="images[current]" :alt="'Ảnh ' + (current + 1)"
                                             @click.stop
                                             style="max-width:100%;max-height:100%;object-fit:contain;border-radius:8px;user-select:none;display:block;">

                                        <button @click.stop="next()" aria-label="Tiếp"
                                                style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:50%;width:46px;height:46px;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:2;">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 18l6-6-6-6"/></svg>
                                        </button>
                                    </div>

                                    {{-- Thumbnails: ghim dưới cùng --}}
                                    <div style="position:absolute;bottom:0;left:0;right:0;height:90px;z-index:2;display:flex;align-items:center;gap:8px;padding:0 16px;overflow-x:auto;" @click.stop>
                                        <template x-for="(img, idx) in images" :key="idx">
                                            <div @click.stop="current = idx"
                                                 :style="current === idx ? 'border:2px solid #fff;' : 'border:2px solid transparent;'"
                                                 style="flex-shrink:0;width:72px;height:54px;border-radius:6px;overflow:hidden;cursor:pointer;">
                                                <img :src="img" :alt="'Thumb ' + (idx + 1)" style="width:100%;height:100%;object-fit:cover;display:block;">
                                            </div>
                                        </template>
                                    </div>
                                </div>
                                @endif

                            </div>{{-- end gallery x-data --}}

                            {{-- Video phòng (nút đã được render trong gallery, chỉ cần modal) --}}
                            @php $videoButtonInGallery = true; @endphp
                            @include('bladethemev1::components.product-detail.video-room')

                            {{-- Tiện nghi phòng (ẩn trên mobile khi đến từ trang booking, luôn hiện trên desktop) --}}
                            @if(!$fromBookingPage)
                            @include('bladethemev1::components.product-detail.amenities')
                           {{-- Dịch vụ phòng --}}
                            @include('bladethemev1::components.product-detail.additional-services')
                            @else
                            <div class="hidden md:block">
                            @include('bladethemev1::components.product-detail.amenities')
                            </div>
                            @include('bladethemev1::components.product-detail.additional-services')
                            @endif
                            @if($bookingStyle == 1)
                                {{-- Bảng giá phòng (ẩn trên mobile khi đến từ trang booking, luôn hiện trên desktop) --}}
                                @if(!$fromBookingPage)
                                @include('bladethemev1::components.product-detail.pricing-room')
                                @else
                                <div class="hidden md:block">
                                @include('bladethemev1::components.product-detail.pricing-room')
                                </div>
                                @endif

                                {{-- Thông báo có thể chọn lại khung giờ (chỉ hiện trên mobile) --}}
                                @if($fromBookingPage)
                                <div class="md:hidden" style="background:#f0fdf4;border:1px solid #86efac;border-radius:12px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:flex-start;gap:10px;">
                                    <svg style="flex-shrink:0;margin-top:1px;" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    <p style="font-size:0.85rem;color:#166534;font-weight:600;margin:0;">Khung giờ đã được chọn từ trang đặt phòng. Bạn có thể nhấn vào ô bên dưới để thay đổi nếu cần.</p>
                                </div>
                                @endif

                                @if($fromBookingPage)
                                {{-- Trên desktop: hiển thị inline. Trên mobile: modal fullscreen toggled bởi nút "Chọn lại khung giờ" --}}
                                <div x-data="{ isMd: window.matchMedia('(min-width:768px)').matches }"
                                     x-init="window.matchMedia('(min-width:768px)').addEventListener('change', e => isMd = e.matches)"
                                     x-show="isMd || slotPickerOpen"
                                     x-cloak
                                     x-transition:enter="transition ease-out duration-250"
                                     x-transition:enter-start="opacity-0"
                                     x-transition:enter-end="opacity-100"
                                     x-transition:leave="transition ease-in duration-150"
                                     x-transition:leave-start="opacity-100"
                                     x-transition:leave-end="opacity-0"
                                     @keydown.escape.window="if(!isMd) slotPickerOpen = false"
                                     :style="!isMd ? 'position:fixed;inset:0;z-index:10002;overflow-y:auto;background:rgba(0,0,0,.7);' : ''">
                                    <div @click.stop
                                         :style="!isMd ? 'background:#fff;min-height:100%;max-width:960px;margin:0 auto;' : ''">
                                        <div x-show="!isMd"
                                             style="position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;z-index:10;">
                                            <div>
                                                <h3 style="font-size:1.1rem;font-weight:700;color:#111827;margin:0;">Chọn lại khung giờ</h3>
                                                <p style="font-size:0.8rem;color:#6b7280;margin:4px 0 0;">{{ $product->name }}</p>
                                            </div>
                                            <button @click="slotPickerOpen = false"
                                                    style="background:none;border:1px solid #d1d5db;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:22px;color:#6b7280;line-height:1;">
                                                &times;
                                            </button>
                                        </div>
                                        <div :style="!isMd ? 'padding:20px;' : ''">
                                @endif

                            <!-- Chọn khung giờ -->
                            @php
                                $pdRoomConfig = $productColors[$product->id] ?? null;
                                $pdRoomBg     = $pdRoomConfig['color'] ?? '#4e6b4c';
                                $pdRoomText   = $pdRoomConfig['color_text'] ?? null;
                                if (!$pdRoomText) {
                                    $hex = ltrim($pdRoomBg, '#');
                                    if (strlen($hex) === 3) { $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
                                    $r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
                                    $pdRoomText = (0.299 * $r + 0.587 * $g + 0.114 * $b) > 128 ? '#111827' : '#ffffff';
                                }
                                $pdSlotCount      = count($timeSlots);
                                $pdSlotsPerPage   = 5;
                                $pdTotalSlotPages = (int) ceil($pdSlotCount / max($pdSlotsPerPage, 1));
                            @endphp
                            <div>
                            
                                {{-- ── Legend ── --}}
                                <div class="md:flex gap-4 text-sm font-medium mb-4 grid grid-cols-2 gap-8">
                                    <div class="flex items-center gap-1">
                                        <span class="w-4 h-4 bg-primary rounded"></span> Đã Đặt
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <span class="w-4 h-4 border border-primary rounded"></span> Còn Trống
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <span class="w-4 h-4 bg-tickGray rounded"></span> Đang chọn
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <span class="selectable-mini promo-mini w-4 h-4 rounded"></span> Khuyến mãi
                                    </div>
                                </div>

                                {{-- ── CSS cho lịch đặt phòng (selectable + mobile card) ── --}}
                                <style id="pd-booking-redesign">
                                    /* ── selectable pill ── */
                                    .selectable { position:relative; z-index:10; height:32px; padding:0 .5rem; font-weight:700; border-radius:999px !important; background:linear-gradient(135deg,#eef2ed,#e8f0e6) !important; border:1.5px solid #a8c4a0 !important; color:#4e6b4c !important; display:flex; align-items:center; justify-content:center; overflow:visible; transition:all .18s ease; }
                                    .selectable:hover:not([style*="pointer-events"]) { background:linear-gradient(135deg,#d4e4d2,#c8dcc6) !important; border-color:#6a8f68 !important; box-shadow:0 4px 12px rgba(78,107,76,.3); transform:translateY(-1px); }
                                    .selectable.active::after  { content:""; position:absolute; inset:0; border-radius:999px !important; background-color:var(--color-tickGray) !important; }
                                    .selectable.booked::after { content:""; position:absolute; inset:0; border-radius:999px !important;background:var(--order-color, #4e6b4c) !important; }
                                    .selectable.pending::after { content:""; position:absolute; inset:0; border-radius:999px !important;background:var(--order-color, #9ca3af) !important; opacity:0.75; }
                                    .selectable.past-time { border:1px solid #d1d5db; }
                                    .selectable.past-time::after { content:""; position:absolute; inset:0; border-radius:999px !important; background-color:#f3f4f6; }
                                    .selectable.blocked { cursor:not-allowed !important; pointer-events:none; }
                                    .selectable.blocked::after  { content:""; position:absolute; inset:0; border-radius:999px !important; background-color:#111827; z-index:5; }
                                    .selectable.blocked::before { content:"🔒"; position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); font-size:10px; z-index:10; pointer-events:none; }
                                    .selectable.promo::before { content:""; position:absolute; inset:0; border-radius:999px; background:linear-gradient(270deg,#f00,#f90,#3f0,#0ff,#30f,#f0c,#f00); background-size:300% 300%; animation:pdBorderFlow 10s linear infinite; z-index:-10; filter:blur(5px); }
                                    .selectable.promo::after   { content:""; position:absolute; inset:0; border-radius:999px; background:#fff; }
                                    .selectable-mini { position:relative; z-index:1; background:linear-gradient(135deg,#eef2ed,#e8f0e6) !important; border:1.5px solid #a8c4a0 !important; border-radius:999px !important; overflow:visible; }
                                    .promo-mini::before { content:""; position:absolute; top:-2px; left:-2px; right:-2px; bottom:-2px; border-radius:inherit; background:linear-gradient(270deg,#f44,#fb4,#7f7,#7ff,#77f,#f7e,#f44); background-size:200% 200%; animation:pdBorderFlow 15s linear infinite; z-index:-1; filter:blur(3px); }
                                    .promo-mini::after  { content:""; position:absolute; inset:0; border-radius:inherit; background:#fff; z-index:0; }
                                    .promotion-corner-image { position:absolute; top:-7px; right:-7px; z-index:30; width:18px; height:18px; overflow:hidden; animation:pdBounce 2s ease-in-out infinite; }
                                    .corner-img { width:100%; height:100%; object-fit:contain; transition:transform .3s; }
                                    .selectable:hover .corner-img { transform:scale(1.15); }
                                    .promotion-center-label { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); z-index:25; font-size:10px; font-weight:700; color:#4c1d95; white-space:nowrap; overflow:hidden; max-width:90%; text-shadow:0 1px 2px rgba(255,255,255,.8); animation:pdPulse 2.5s ease-in-out infinite; }
                                    @keyframes pdBorderFlow { 0%{background-position:0% 50%} 100%{background-position:300% 50%} }
                                    @keyframes pdBounce { 0%,100%{transform:translateY(0) scale(1)} 50%{transform:translateY(-3px) scale(1.05)} }
                                    @keyframes pdPulse { 0%,100%{opacity:1;transform:translate(-50%,-50%) scale(1)} 50%{opacity:.9;transform:translate(-50%,-50%) scale(1.05)} }
                                    /* ── Desktop card & sticky cols ── */
                                    .pd-book-card { background:#fff; border-radius:20px; box-shadow:0 8px 32px rgba(78,107,76,.12),0 2px 8px rgba(0,0,0,.04); border:1px solid #d4e4d2; }
                                    .pd-book-card-scroll { overflow:auto; max-height:500px; border-radius:20px; }
                                    thead { position:sticky; top:0; z-index:30; background: var(--pd-room-color, #4e6b4c) !important; }
                                    .sticky-col-header { position:sticky; left:0; z-index:40; background: var(--pd-room-color, #4e6b4c) !important; color: var(--pd-room-text, #ffffff) !important; }
                                    .sticky-col-thu  { left:0   !important; min-width:45px; }
                                    .sticky-col-ngay { left:45px !important; min-width:60px; }
                                    .sticky-col { position:sticky; z-index:20; background: var(--pd-room-color, #4e6b4c) !important; color: var(--pd-room-text, #ffffff) !important; }
                                    tbody .sticky-col-thu  { left:0    !important; }
                                    tbody .sticky-col-ngay { left:45px !important; }
                                    .pd-slot-td { background: var(--pd-room-color, #4e6b4c); }
                                    tbody tr:hover td { background-color: color-mix(in srgb, var(--pd-room-color, #4e6b4c) 85%, black) !important; }
                                    /* ── Mobile two-panel card ── */
                                    .pd-room-header { display:flex; align-items:center; justify-content:center; padding:18px 16px 14px; transition:background .4s; }
                                    .pd-room-name { font-family:'Georgia','Times New Roman',serif; font-style:italic; font-size:1.6rem; font-weight:700; color:inherit; text-shadow:0 2px 14px rgba(0,0,0,.25); line-height:1.1; margin:0; }
                                    .pd-room-sub  { color:inherit; opacity:.85; font-size:.8rem; font-weight:600; margin-top:5px; letter-spacing:.03em; }
                                    .pd-grid-header { display:flex; gap:10px; padding:0 14px; }
                                    .pd-col-header  { min-width:72px; width:72px; flex-shrink:0; height:46px; display:flex; align-items:center; justify-content:center; font-size:.7rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; border-bottom:1px solid rgba(128,128,128,.25); }
                                    .pd-slots-headers-wrap  { flex:1; min-width:0; }
                                    .pd-slots-header-row    { display:flex; gap:3px; padding:0 4px; height:46px; align-items:center; border-bottom:1px solid rgba(255,255,255,.15); }
                                    .pd-slot-th    { flex:1; text-align:center; font-size:.55rem; font-weight:700; letter-spacing:-.03em; line-height:1.15; min-width:0; }
                                    .pd-overnight-tag { display:block; font-size:.48rem; font-weight:600; background:rgba(0,0,0,.25); color:#fff; border-radius:3px; padding:1px 2px; margin-top:1px; }
                                    .pd-mobile-scroll { max-height:380px; overflow-y:auto; overflow-x:hidden; border-radius:0 0 20px 20px; }
                                    .pd-mobile-scroll::-webkit-scrollbar { width:4px; }
                                    .pd-mobile-scroll::-webkit-scrollbar-thumb { background:var(--pd-room-color,#4e6b4c); border-radius:4px; }
                                    .pd-grid-outer  { display:flex; gap:10px; padding:12px 14px 20px; align-items:flex-start; }
                                    .pd-dates-card  { border-radius:14px; min-width:72px; width:72px; flex-shrink:0; box-shadow:0 6px 24px rgba(0,0,0,.2); overflow:clip; background:var(--pd-room-color,#4e6b4c); }
                                    .pd-date-row    { height:38px; display:flex; flex-direction:column; align-items:center; justify-content:center; border-bottom:1px solid rgba(255,255,255,.12); gap:2px; padding:0 4px; }
                                    .pd-date-row:last-child { border-bottom:none; }
                                    .pd-date-day  { font-size:.62rem; color:var(--pd-room-text,#fff); opacity:.7; font-weight:500; line-height:1; }
                                    .pd-date-num  { font-size:.78rem; font-weight:700; color:var(--pd-room-text,#fff); line-height:1; }
                                    .pd-date-row.is-today .pd-date-day,
                                    .pd-date-row.is-today .pd-date-num { opacity:1; font-weight:800; }
                                    .pd-slots-outer { flex:1; min-width:0; }
                                    .pd-slots-card  { border-radius:14px; box-shadow:0 6px 24px rgba(0,0,0,.2); overflow:clip; max-width:100%; background:var(--pd-room-color,#4e6b4c); }
                                    .pd-slots-row   { display:flex; gap:3px; padding:4px; height:38px; align-items:center; border-bottom:1px solid rgba(255,255,255,.12); }
                                    .pd-slots-row:last-child { border-bottom:none; }
                                    .pd-slot-cell   { flex:1; min-width:0; display:flex; align-items:center; justify-content:center; }
                                    .pd-slot-cell .selectable { width:100%; height:22px !important; border-radius:999px !important; }
                                    /* ── Slot pagination strip ── */
                                    .slot-page-strip { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 14px; background:linear-gradient(135deg,#3a5239,#4e6b4c); }
                                    .slot-pg-btn  { display:inline-flex; align-items:center; gap:4px; background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.3); color:#fff; border-radius:999px; padding:4px 12px; font-size:.75rem; font-weight:600; cursor:pointer; transition:background .2s; }
                                    .slot-pg-btn:hover:not(:disabled)  { background:rgba(255,255,255,.3); }
                                    .slot-pg-btn:disabled { opacity:.4; cursor:not-allowed; }
                                    .slot-pg-info { color:rgba(255,255,255,.85); font-size:.75rem; font-weight:600; }
                                    /* ── blocked + promo: đặt cuối để thắng cascade (3-class > 2-class specificity) ── */
                                    .selectable.blocked.promo::after  { content:"" !important; position:absolute !important; inset:0 !important; border-radius:999px !important; background-color:#111827 !important; z-index:15 !important; display:block !important; animation:none !important; filter:none !important; }
                                    .selectable.blocked.promo::before { content:"🔒" !important; position:absolute !important; top:50% !important; left:50% !important; right:auto !important; bottom:auto !important; transform:translate(-50%,-50%) !important; font-size:10px !important; z-index:20 !important; pointer-events:none !important; background:none !important; filter:none !important; animation:none !important; opacity:1 !important; border-radius:0 !important; }
                                </style>

                                {{-- ── Shared Alpine state (mobile + desktop) ── --}}
                                <div x-data="{
                                        selectedSlots: @entangle('selectedSlots'),
                                        slotPage: 0,
                                        slotsPerPage: {{ $pdSlotsPerPage }},
                                        totalSlotPages: {{ $pdTotalSlotPages }},
                                        toggleSlot(date, timeslotId, price, originalPrice, basePrice, increaseAmount, promoDiscount, startTime, endTime, status, roomId, roomName, timeslotLabel, overNight) {
                                            const key = `${date}-${timeslotId}`;
                                            const index = this.selectedSlots.findIndex(slot => slot.key === key);
                                            if (index > -1) {
                                                this.selectedSlots.splice(index, 1);
                                            } else {
                                                this.selectedSlots.push({ key, date, startTime, endTime, price, originalPrice, basePrice, increaseAmount, promoDiscount, timeslotId, roomId, roomName, timeslotLabel, overNight });
                                            }
                                            @this.set('selectedSlots', this.selectedSlots);
                                        }
                                    }">

                                    {{-- ═════ MOBILE: Two-panel card (md:hidden) ═════ --}}
                                    <div class="md:hidden mb-4 rounded-[20px] overflow-hidden shadow-lg">

                                        {{-- Tiêu đề phòng --}}
                                        <div class="pd-room-header" style="background: {{ $pdRoomBg }}; color: {{ $pdRoomText }}; border-radius: 20px 20px 0 0;">
                                            <div class="text-center">
                                                <h3 class="pd-room-name">{{ $product->name }}</h3>
                                                <p class="pd-room-sub">{{ $categories['c3'] ?? '' }}{{ isset($categories['c2']) ? ', ' . $categories['c2'] : '' }}</p>
                                            </div>
                                        </div>

                                        {{-- Phân trang khung giờ (chỉ khi > 5 khung) --}}
                                        @if($pdSlotCount > $pdSlotsPerPage)
                                        <div class="slot-page-strip">
                                            <button class="slot-pg-btn" type="button"
                                                    @click="slotPage = Math.max(0, slotPage - 1)"
                                                    :disabled="slotPage === 0">&#8249; <span>Quay lại</span></button>
                                            <span class="slot-pg-info"
                                                  x-text="'Khung giờ ' + (slotPage * slotsPerPage + 1) + '–' + Math.min((slotPage + 1) * slotsPerPage, {{ $pdSlotCount }})"></span>
                                            <button class="slot-pg-btn" type="button"
                                                    @click="slotPage = Math.min(totalSlotPages - 1, slotPage + 1)"
                                                    :disabled="slotPage >= totalSlotPages - 1"><span>Xem thêm</span> &#8250;</button>
                                        </div>
                                        @endif

                                        {{-- Header cột giờ (ngoài scroll) --}}
                                        <div class="pd-grid-header" style="background: color-mix(in srgb, {{ $pdRoomBg }} 70%, black);">
                                            <div class="pd-col-header" style="color: {{ $pdRoomText }};">Ngày</div>
                                            <div class="pd-slots-headers-wrap">
                                                <div class="pd-slots-header-row">
                                                    @foreach($timeSlots as $timeSlot)
                                                    @php
                                                        $mhSt = \Carbon\Carbon::parse($timeSlot['start_time']);
                                                        $mhEt = \Carbon\Carbon::parse($timeSlot['end_time']);
                                                        $mhOv = $mhEt->isNextDay() || $mhEt->lt($mhSt) || ($timeSlot['over_night'] ?? 0) == 1;
                                                    @endphp
                                                    <div class="pd-slot-th" style="color: {{ $pdRoomText }};"
                                                         x-show="Math.floor({{ $loop->index }} / slotsPerPage) === slotPage">
                                                        {{ $mhSt->format('H:i') }}<br>{{ $mhEt->format('H:i') }}
                                                        @if($mhOv)<span class="pd-overnight-tag">Qua đêm</span>@endif
                                                    </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>

                                        {{-- Two-panel cuộn dọc --}}
                                        <div class="pd-mobile-scroll" style="--pd-room-color: {{ $pdRoomBg }}; --pd-room-text: {{ $pdRoomText }};">
                                            <div class="pd-grid-outer" style="background: {{ $pdRoomBg }};">

                                                {{-- Cột Ngày --}}
                                                <div class="pd-dates-card">
                                                    @foreach($dates as $date)
                                                    @php $mds = $date['carbon_date']->format('d/m'); @endphp
                                                    <div class="pd-date-row{{ $date['is_today'] ? ' is-today' : '' }}">
                                                        <span class="pd-date-day">{{ $date['day'] }}</span>
                                                        <span class="pd-date-num">{{ $mds }}</span>
                                                    </div>
                                                    @endforeach
                                                </div>

                                                {{-- Cột Khung giờ --}}
                                                <div class="pd-slots-outer">
                                                    <div class="pd-slots-card">
                                                        @foreach($dates as $date)
                                                        <div class="pd-slots-row">
                                                            @foreach($timeSlots as $timeSlot)
                                                            @php
                                                                $mPrice = $timeSlot['timeslot_price'] ?? 0;
                                                                $mClasses = ''; $mIsSelectable = true;
                                                                $mCurrentDT = \Carbon\Carbon::createFromFormat('d-m-Y H:i:s', $date['date'] . ' ' . $timeSlot['start_time']);
                                                                $mStatus = 'available';
                                                                foreach ($product->orderItems as $oItem) {
                                                                    $oIn  = \Carbon\Carbon::parse($oItem->checkin_date);
                                                                    $oOut = \Carbon\Carbon::parse($oItem->checkout_date);
                                                                    if ($mCurrentDT->between($oIn, $oOut)) {
                                                                        if ($oItem->order) { $mStatus = $oItem->order->status; }
                                                                        break;
                                                                    }
                                                                }
                                                                if ($mStatus === 'pending') { $mClasses .= ' pending'; $mIsSelectable = false; }
                                                                elseif (in_array($mStatus, ['paid', 'shipped'])) { $mClasses .= ' booked'; $mIsSelectable = false; }
                                                                $mOrderColor = null;
                                                                if ($mStatus !== 'available') {
                                                                    if (in_array($mStatus, ['paid', 'shipped', 'confirmed'])) {
                                                                    $mOrderColor = '#4e6b4c';
                                                                    } elseif ($mStatus === 'deposit') {
                                                                    $mOrderColor = '#3b82f6';
                                                                    } elseif ($mStatus === 'pending') {
                                                                    $mOrderColor = '#f97316';
                                                                    } else {
                                                                    $mOrderColor = '#94a3b8';
                                                                    }
                                                                }
                                                                if ($mIsSelectable && $date['is_today']) {
                                                                    $mNow = \Carbon\Carbon::now();
                                                                    $mSS  = \Carbon\Carbon::createFromFormat('d-m-Y H:i:s', $date['date'] . ' ' . $timeSlot['start_time']);
                                                                    $mSE  = \Carbon\Carbon::createFromFormat('d-m-Y H:i:s', $date['date'] . ' ' . $timeSlot['end_time']);
                                                                    if ($mSE->lt($mSS)) { $mSE->addDay(); }
                                                                    if ($mNow->gte($mSE)) { $mIsSelectable = false; $mClasses .= ' past-time'; }
                                                                }
                                                                $mRts = null;
                                                                foreach ($product->roomTimeSlots as $rts) {
                                                                    if ($rts->timeslot_id == $timeSlot['timeslot_id']) { $mRts = $rts; break; }
                                                                }
                                                                if (!$mRts) {
                                                                    $mRts = (object)['price' => $mPrice, 'promotions' => collect($timeSlot['promotions'] ?? []), 'settings' => null,
                                                                        'timeSlot' => (object)['start_time' => $timeSlot['start_time'], 'end_time' => $timeSlot['end_time']]];
                                                                }
                                                                $mSt = is_array($mRts->settings) ? $mRts->settings : (json_decode($mRts->settings, true) ?? []);
                                                                if (in_array($date['carbon_date']->toDateString(), $mSt['blocked_dates'] ?? [])) { $mIsSelectable = false; $mClasses .= ' blocked'; }
                                                                $mPD    = $this->calculateSlotPrice($mRts, $date['carbon_date']->format('Y-m-d'), $timeSlot['start_time']);
                                                                $mFinal = $mPD['final_price']; $mPAI = $mPD['price_after_increase'];
                                                                $mBase  = $mPD['original_price']; $mInc = $mPD['increase_amount'] ?? 0; $mPromo = $mPD['total_discount'];
                                                                $mHasD  = false; $mHasI = false;
                                                                foreach ($mPD['promotions'] ?? [] as $p) {
                                                                    if (in_array($p->type, ['percentage', 'fixed'])) { $mHasD = true; }
                                                                    if (in_array($p->type, ['increase_percentage', 'increase_fixed'])) { $mHasI = true; }
                                                                }
                                                                if ($mHasD) { $mClasses .= ' promo'; }
                                                                if ($mHasI && !$mHasD) { $mClasses .= ' promo-increase'; }
                                                            @endphp
                                                            <div class="pd-slot-cell"
                                                                 x-show="Math.floor({{ $loop->index }} / slotsPerPage) === slotPage">
                                                                <div class="selectable {{ $mClasses }}"
                                                                     :class="selectedSlots.some(s => s.key === '{{ $date['carbon_date']->format('Y-m-d') }}-{{ $timeSlot['timeslot_id'] }}') ? 'active' : ''"
                                                                    style="{{ !$mIsSelectable ? 'pointer-events:none;opacity:0.6;' : 'cursor:pointer;' }}{{ $mOrderColor ? '--order-color:'. $mOrderColor . ';' : '' }}"
                                                                    @click="toggleSlot('{{ $date['carbon_date']->format('Y-m-d') }}','{{ $timeSlot['timeslot_id'] }}','{{ $mFinal }}','{{$mPAI }}','{{ $mBase }}','{{ $mInc }}','{{ $mPromo }}','{{ \Carbon\Carbon::parse($timeSlot['start_time'])->format('H:i')}}','{{ \Carbon\Carbon::parse($timeSlot['end_time'])->format('H:i') }}','{{ $mStatus }}','{{ $product['id'] }}','{{$product['name'] }}','{{ $timeSlot['timeslot_label'] }}','{{ $timeSlot['over_night'] ?? 0 }}')">
                                                                </div>
                                                            </div>
                                                            @endforeach
                                                        </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>{{-- /mobile --}}

                                    {{-- ═════ DESKTOP: styled table (hidden md:block) ═════ --}}
                                    <div class="hidden md:block">
                                    <div class="pd-book-card" style="--pd-room-color: {{ $pdRoomBg }}; --pd-room-text: {{ $pdRoomText }};">
                                    <div class="pd-book-card-scroll">
                                    <table class="w-full text-[11px] text-center min-w-[400px] border-collapse">
                                        <thead class="sticky top-0 z-40">
                                        <tr>
                                            <th colspan="2" class="py-1 px-2 border sticky-col-header">Chi nhánh</th>
                                            <th colspan="{{ count($timeSlots) }}" class="py-1 px-2 border"
                                                style="background: {{ $pdRoomBg }}; color: {{ $pdRoomText }};">Home - {{ $categories['c3'] }}, {{$categories['c2']}}</th>
                                        </tr>
                                        <tr>
                                            <th colspan="2" class="py-1 px-2 border sticky-col-header"
                                                style="background: {{ $pdRoomBg }}; color: {{ $pdRoomText }};">Tên phòng</th>
                                            <th colspan="{{ count($timeSlots) }}" class="py-1 px-2 border"
                                                style="background: {{ $pdRoomBg }}; color: {{ $pdRoomText }};">{{ $product['name'] }}</th>
                                        </tr>
                                        <tr>
                                            <th class="py-1 px-2 border min-w-[45px] sticky-col-header sticky-col-thu">Thứ</th>
                                            <th class="py-1 px-2 border sticky-col-header sticky-col-ngay" style="border-right: 2px solid rgba(255,255,255,0.25);">Ngày</th>
                                            @foreach($timeSlots as $timeSlot)
                                                @php
                                                    $startTime = \Carbon\Carbon::parse($timeSlot['start_time']);
                                                    $endTime   = \Carbon\Carbon::parse($timeSlot['end_time']);
                                                    $isOvernight = $endTime->isNextDay() || $endTime->lt($startTime) || $timeSlot['over_night'] == 1;
                                                @endphp
                                                <th class="py-1 px-2 border min-w-[90px]"
                                                    style="background: {{ $pdRoomBg }}; color: {{ $pdRoomText }};">
                                                    {{ $startTime->format('H:i') }} - {{ $endTime->format('H:i') }}
                                                    <br>
                                                    @if($isOvernight)
                                                        <span class="text-xs" style="color: {{ $pdRoomText }};">(Qua đêm)</span>
                                                    @else
                                                        <svg class="w-4 h-4 inline" style="color: {{ $pdRoomText }};"
                                                             xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor">
                                                            <path d="M8 1a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 8 1ZM10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0ZM12.95 4.11a.75.75 0 1 0-1.06-1.06l-1.062 1.06a.75.75 0 0 0 1.061 1.062l1.06-1.061ZM15 8a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 15 8ZM11.89 12.95a.75.75 0 0 0 1.06-1.06l-1.06-1.062a.75.75 0 0 0-1.062 1.061l1.061 1.06ZM8 12a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 8 12ZM5.172 11.89a.75.75 0 0 0-1.061-1.062L3.05 11.89a.75.75 0 1 0 1.06 1.06l1.06-1.06ZM4 8a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 4 8ZM4.11 5.172A.75.75 0 0 0 5.173 4.11L4.11 3.05a.75.75 0 1 0-1.06 1.06l1.06 1.06Z"/>
                                                        </svg>
                                                    @endif
                                                </th>
                                            @endforeach
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach($dates as $date)
                                            <tr class="border-t">
                                                <td class="py-1 border sticky-col sticky-col-thu {{ $date['is_today'] ? 'font-extrabold' : '' }}"
                                                    style="{{ $date['is_today'] ? 'background: color-mix(in srgb, var(--pd-room-color) 60%, white) !important;' : '' }}">
                                                    {{ $date['day'] }}
                                                </td>
                                                <td class="py-1 border sticky-col sticky-col-ngay {{ $date['is_today'] ? 'font-extrabold' : '' }}"
                                                    style="border-right: 2px solid rgba(255,255,255,0.25); {{ $date['is_today'] ? 'background: color-mix(in srgb, var(--pd-room-color) 60%, white) !important;' : '' }}">
                                                    {{ $date['date'] }}
                                                </td>

                                                @foreach($timeSlots as $timeSlot)
                                                    @php
                                                        $price = $timeSlot['timeslot_price'] ?? 0;
                                                        $classes = '';
                                                        $isSelectable = true;
                                                        $finalPrice = $price;
                                                        $currentDateTime = \Carbon\Carbon::createFromFormat('d-m-Y H:i:s', $date['date'] . ' ' . $timeSlot['start_time']);
                                                        $status = 'available';
                                                        $matchedItem = null;
                                                        foreach ($product->orderItems as $orderItem) {
                                                            $checkin = \Carbon\Carbon::parse($orderItem->checkin_date);
                                                            $checkout = \Carbon\Carbon::parse($orderItem->checkout_date);

                                                            if ($currentDateTime->between($checkin, $checkout)) {
                                                                if ($orderItem->order) {
                                                                     $status = $orderItem->order->status;
                                                                }
                                                                $matchedItem = $orderItem;
                                                                break;
                                                            }
                                                        }
                                                        if ($status === 'pending') {
                                                            $classes .= ' pending';
                                                            $isSelectable = false;
                                                        }
                                                        elseif ($status === 'paid' || $status === 'shipped') {
                                                            $classes .= ' booked';
                                                            $isSelectable = false;
                                                        }
                                                        else {
                                                        }
                                                        $orderColor = null;
                                                        if ($matchedItem) {
                                                            if (in_array($status, ['paid', 'shipped', 'confirmed'])) 
                                                            {
                                                                $orderColor = '#4e6b4c';
                                                                } elseif ($status === 'deposit') {
                                                                $orderColor = '#3b82f6';
                                                                } elseif ($status === 'pending') {
                                                                $orderColor = '#f97316';
                                                                } else {
                                                                $orderColor = '#94a3b8';
                                                            }
                                                        }

                                                        if ($isSelectable && $date['day'] === 'Hôm nay') {
                                                        $slot_start_time = $timeSlot['start_time'];
                                                        $slot_end_time = $timeSlot['end_time'];


                                                        $now = \Carbon\Carbon::now();


                                                        $slotStartDateTime = \Carbon\Carbon::createFromFormat('d-m-Y H:i:s', $date['date'] . ' ' . $slot_start_time);
                                                        $slotEndDateTime = \Carbon\Carbon::createFromFormat('d-m-Y H:i:s', $date['date'] . ' ' . $slot_end_time);

                                                        if ($slotEndDateTime->lt($slotStartDateTime)) {
                                                            $slotEndDateTime->addDay();
                                                            }


                                                            if ($now->gte($slotEndDateTime)) {
                                                            $isSelectable = false;
                                                            $classes .= ' past-time';
                                                            }
                                                        }
                                                        $realRoomTimeSlot = null;
                                                            foreach ($product->roomTimeSlots as $rts) {
                                                                if ($rts->timeslot_id == $timeSlot['timeslot_id']) {
                                                                    $realRoomTimeSlot = $rts;
                                                                    break;
                                                                }
                                                            }

                                                             if (!$realRoomTimeSlot) {
                                                                $realRoomTimeSlot = (object)[
                                                                    'price' => $price,
                                                                    'promotions' => collect($timeSlot['promotions'] ?? []),
                                                                    'settings' => null,
                                                                    'timeSlot' => (object)[
                                                                        'start_time' => $timeSlot['start_time'],
                                                                        'end_time' => $timeSlot['end_time'],
                                                                    ]
                                                                ];
                                                            }

                                                            // --- Kiểm tra blocked dates ---
                                                            $rtsSettings = is_array($realRoomTimeSlot->settings)
                                                                ? $realRoomTimeSlot->settings
                                                                : (json_decode($realRoomTimeSlot->settings, true) ?? []);
                                                            $blockedDates = $rtsSettings['blocked_dates'] ?? [];
                                                            $slotDateYmd = $date['carbon_date']->toDateString();
                                                            if (in_array($slotDateYmd, $blockedDates)) {
                                                                $isSelectable = false;
                                                                $classes .= ' blocked';
                                                            }

                                                        $priceData = $this->calculateSlotPrice($realRoomTimeSlot, $date['carbon_date']->format('Y-m-d'),  $timeSlot['start_time']);
                                                        $finalPrice = $priceData['final_price'];
                                                        $originalPrice = $priceData['original_price'];
                                                        $priceAfterIncrease = $priceData['price_after_increase'];
                                                        $basePrice = $priceData['original_price'];        // ⭐ CẦN CÓ
                                                        $slotIncreaseAmount = $priceData['increase_amount'] ?? 0;  // ⭐ CẦN CÓ
                                                        $promoDiscount = $priceData['total_discount'];
                                                        $hasPromotion = $priceData['has_promotion'];
                                                        $isIncrease = $priceData['is_increase'];
                                                        $activePromotions = $priceData['promotions'] ?? [];

                                                       $hasDiscountPromotion = false;
                                                        $hasIncreasePromotion = false;
                                                        $discountPromotions = [];
                                                        $increasePromotions = [];
                                                         foreach ($activePromotions as $promo) {
                                                            if (in_array($promo->type, ['percentage', 'fixed'])) {
                                                                $hasDiscountPromotion = true;
                                                                $discountPromotions[] = $promo;
                                                            }
                                                            if (in_array($promo->type, ['increase_percentage', 'increase_fixed'])) {
                                                                $hasIncreasePromotion = true;
                                                                $increasePromotions[] = $promo;
                                                            }
                                                        }
                                                        $showPromotion = $hasDiscountPromotion;
                                                        if ($showPromotion) {
                                                            $classes .= ' promo';
                                                        }

                                                        if ($hasIncreasePromotion && !$hasDiscountPromotion) {
                                                            $classes .= ' promo-increase';
                                                        }

                                                        $displayPromotion = $increasePromotions[0] ?? null;

                                                        $timeslotStatus = $status;
                                                    @endphp

                                                    <td class="pd-slot-td border p-1.5 relative overflow-visible">
                                                        <div class="w-full selectable {{ $classes }}" 
                                                             :class="selectedSlots.some(slot => slot.key === '{{ $date['carbon_date']->format('Y-m-d') }}-{{ $timeSlot['timeslot_id'] }}') ? 'active' : ''"
                                                            style="{{ !$isSelectable ? 'pointer-events: none; opacity: 0.6;' : 'cursor: pointer;' }}{{ $orderColor ?'--order-color:' . $orderColor . ';' : '' }}"
                                                             x-on:click="toggleSlot(
                                                                '{{ $date['carbon_date']->format('Y-m-d') }}',
                                                                '{{ $timeSlot['timeslot_id'] }}',
                                                                '{{ $finalPrice }}',
                                                                '{{ $priceAfterIncrease }}',
                                                                '{{ $basePrice }}',
                                                                '{{ $slotIncreaseAmount }}',
                                                                '{{ $promoDiscount }}',
                                                                '{{ \Carbon\Carbon::parse($timeSlot['start_time'])->format('H:i') }}',
                                                                '{{ \Carbon\Carbon::parse($timeSlot['end_time'])->format('H:i') }}',
                                                                '{{ $timeslotStatus }}',
                                                                '{{ $product['id'] }}',
                                                                '{{ $product['name'] }}',
                                                                '{{ $timeSlot['timeslot_label'] }}',
                                                                '{{ $timeSlot['over_night'] ?? 0 }}'
                                                             )">

                                                            @if ($hasIncreasePromotion && $displayPromotion && $displayPromotion->image)
                                                                <div class="promotion-corner-image">
                                                                    <img src="{{ asset('storage/' . $displayPromotion->image) }}"
                                                                         alt="{{ $displayPromotion->name }}"
                                                                         class="corner-img">
                                                                </div>
                                                            @endif

                                                            @if ($hasIncreasePromotion && $displayPromotion && $displayPromotion->lable_client)
                                                                <div class="promotion-center-label">
                                                                    {{ $displayPromotion->lable_client }}
                                                                </div>
                                                            @endif

                                                            @if($showPromotion)
                                                                <span class="font-bold text-[11px] leading-tight relative z-20 text-red-600">
                                                                    <!-- {{ number_format($finalPrice / 1000, 0, ',', '.') }}K -->
                                                                </span>
                                                            @endif
                                                        </div>
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                        </tbody>

                                    </table>
                                    </div>{{-- /pd-book-card-scroll --}}
                                    </div>{{-- /pd-book-card --}}
                                    </div>{{-- /desktop --}}
                                </div>{{-- /shared x-data --}}

                                <div class="w-full mt-6 bg-gray-50 rounded-lg p-4 border-2 border-gray-200">
                                    <h3 class="text-lg font-bold mb-4 pb-2 border-b border-gray-300">Chi tiết thanh toán</h3>

                                    <span wire:loading wire:target="selectedSlots" class="text-gray-400 italic animate-pulse block text-center py-4">
                                        <svg class="animate-spin h-5 w-5 mx-auto mb-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Đang tính toán...
                                    </span>

                                    <div wire:loading.remove wire:target="selectedSlots" class="w-full text-left font-semibold space-y-2">

                                        {{-- Giá cơ bản --}}
                                        <p class="text-base text-gray-800 mb-2">
                                            Giá cơ bản: <span class="font-bold">{{ number_format($originalTotalAmount, 0, ',', '.') }}đ</span>
                                        </p>

                                            {{-- Chi tiết phụ thu --}}
                                            @if($increaseAmount > 0)
                                                <div class="ml-4 mb-2">
                                                    <p class="text-sm text-orange-600 font-semibold flex items-center gap-1.5">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                                                        Phụ thu:
                                                    </p>
                                                    {{-- Bạn có thể thêm chi tiết phụ thu ở đây nếu có --}}
                                                    <p class="text-sm text-orange-600 font-bold ml-4 mt-1 border-t border-orange-200 pt-1">
                                                        Tổng phụ thu: <span>+{{ number_format($increaseAmount, 0, ',', '.') }}đ</span>
                                                    </p>
                                                </div>
                                            @endif

                                            {{-- Phụ phí khách --}}
                                            @if($extraFee > 0)
                                                <div class="ml-4 mb-2">
                                                    <p class="text-sm text-orange-600 font-semibold flex items-center gap-1.5">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                                                        Phụ phí:
                                                    </p>
                                                    <p class="text-xs text-orange-500 ml-4">
                                                        • Phụ phí ({{ $guests - 2 }} khách):
                                                        <span>+{{ number_format($extraFee, 0, ',', '.') }}đ</span>
                                                    </p>
                                                </div>
                                            @endif

                                            {{-- Chi tiết khuyến mãi (CHỈ hiển thị nếu KHÔNG phải full booking) --}}
                                            @if($promoDiscountAmount > 0 && !$hasFullDayBooking)
                                                <div class="ml-4 mb-2">
                                                    <p class="text-sm text-red-600 font-semibold flex items-center gap-1.5">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
                                                        Khuyến mãi:
                                                    </p>
                                                    {{-- Chi tiết khuyến mãi từ promotions --}}
                                                    <p class="text-xs text-red-500 ml-4">
                                                        • Khuyến mãi áp dụng:
                                                        <span>-{{ number_format($promoDiscountAmount, 0, ',', '.') }}đ</span>
                                                    </p>
                                                    <p class="text-sm text-red-600 font-bold ml-4 mt-1 border-t border-red-200 pt-1">
                                                        Tổng khuyến mãi: <span>-{{ number_format($promoDiscountAmount, 0, ',', '.') }}đ</span>
                                                    </p>
                                                </div>
                                            @endif


                                        {{-- Giảm giá từ coupon (chỉ khi KHÔNG full booking) --}}
                                        @if(!$hasFullDayBooking && $appliedCoupon && $couponDiscountAmount > 0)
                                            <div class="ml-4 mb-2">
                                                <p class="text-sm text-green-600 font-semibold flex items-center gap-1.5">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/></svg>
                                                    Mã giảm giá:
                                                </p>
                                                <p class="text-xs text-green-500 ml-4">
                                                    • Mã giảm giá ({{ $appliedCoupon->code }}):
                                                    <span>-{{ number_format($couponDiscountAmount, 0, ',', '.') }}đ</span>
                                                </p>
                                            </div>
                                        @endif

                                            {{-- Giảm giá book nhiều giờ (CHỈ khi KHÔNG phải full booking) --}}
                                            @if(!$hasFullDayBooking && $bulkDiscountAmount > 0 && count($selectedSlots) >= 2)
                                                <p class="text-sm text-green-600 font-semibold ml-4 mb-2 flex items-center gap-1.5">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 18V6"/></svg>
                                                    Giảm giá đặt nhiều khung giờ:
                                                    <span>-{{ number_format($bulkDiscountAmount, 0, ',', '.') }}đ</span>
                                                </p>
                                            @endif


                                            @if(!$hasFullDayBooking && count($selectedSlots) >= 2)
                                                <div class="ml-4 mb-2 mt-1 flex items-center gap-2 bg-amber-50 border border-amber-300 rounded-lg px-3 py-2">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0 text-amber-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
                                                    <p class="text-sm text-amber-700 font-semibold">
                                                        Bạn được tặng: <span class="text-amber-800">2 Nước Ngọt + 1 Snack</span>
                                                        <span class="block text-xs font-normal text-amber-600 mt-0.5">Nhận tại quầy sau khi thanh toán</span>
                                                    </p>
                                                </div>
                                            @endif

                                            {{-- Giảm giá Full booking --}}
                                            @if($fullBookingDiscount > 0)
                                                <p class="text-sm font-bold ml-4 mb-2 flex items-center gap-1.5" style="color:#4e6b4c">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                                    Giảm giá đặt Full phòng:
                                                    <span>-{{ number_format($fullBookingDiscount, 0, ',', '.') }}đ</span>
                                                </p>
                                            @endif

                                            {{-- Thông báo khuyến mãi khi đặt full --}}
                                            @if($hasFullDayBooking)
                                                <div class="ml-4 mb-2 bg-red-50 p-3 rounded border border-red-200">
                                                    <p class="text-sm text-red-600 font-semibold mb-1 flex items-center gap-1.5">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
                                                        Khuyến mãi:
                                                    </p>
                                                    <p class="text-xs text-red-500 ml-4">
                                                        • Giảm giá khi đặt full khung giờ trong ngày:
                                                        <span class="font-bold">-{{ number_format($fullBookingDiscount, 0, ',', '.') }}đ</span>
                                                    </p>
                                                </div>
                                            @endif

                                            {{-- Dịch vụ thêm --}}
                                            @php
                                                $serviceTotal = 0;
                                                if (!empty($selectedServices) && $additionalServices) {
                                                    foreach ($selectedServices as $id => $qty) {
                                                        $service = $additionalServices->firstWhere('id', $id);
                                                        if ($service && $qty > 0) {
                                                            $serviceTotal += $service->price * $qty;
                                                        }
                                                    }
                                                }
                                                $serviceCount = array_sum($selectedServices ?? []);
                                            @endphp
                                            @if($serviceTotal > 0)
                                                <div class="ml-4 mb-2">
                                                    <p class="text-sm text-blue-600 font-semibold flex items-center gap-1.5">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                                                        Dịch vụ thêm ({{ $serviceCount }} dịch vụ):
                                                    </p>
                                                    <p class="text-xs text-blue-500 ml-4">
                                                        • Tổng dịch vụ:
                                                        <span>+{{ number_format($serviceTotal, 0, ',', '.') }}đ</span>
                                                    </p>
                                                </div>
                                            @endif

                                            {{-- Tổng tiền --}}
                                            <div class="mt-3 pt-3 border-t-2" style="border-color:#4e6b4c">
                                                <p class="text-xl font-bold flex items-center gap-2" style="color:#4e6b4c">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                                                    Tổng tiền tạm tính:
                                                    <span class="text-2xl">{{ number_format($totalAmount, 0, ',', '.') }}đ</span>
                                                </p>
                                            </div>

                                            {{-- Tổng tiết kiệm --}}
                                            @php
                                                $totalSaved = $promoDiscountAmount + $couponDiscountAmount + $bulkDiscountAmount + $fullBookingDiscount;
                                            @endphp
                                            @if($totalSaved > 0)
                                                <div class="bg-green-100 border-2 border-green-300 rounded-lg p-3 mt-3">
                                                    <div class="flex items-center justify-between">
                                                        <span class="text-green-700 font-medium flex items-center gap-2">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 5c-1.5 0-2.8 1.4-3 2-3.5-1.5-11-.3-11 5 0 1.8 0 3 2 4.5V20h4v-2h3v2h4v-4c1-.5 1.7-1 2-2h2v-4h-2c0-1-.5-1.5-1-2h0V5z"/><path d="M2 9v1a2 2 0 0 0 2 2h1"/><path d="M16 11h0"/></svg>
                                                            Bạn đã tiết kiệm:
                                                        </span>
                                                        <span class="text-green-700 font-bold text-lg">
                                                            {{ number_format($totalSaved, 0, ',', '.') }}đ
                                                        </span>
                                                    </div>
                                                </div>
                                            @endif

                                            {{-- Ghi chú --}}
                                            <div class="mt-2 text-xs text-gray-600 italic">
                                                @if(!$hasFullDayBooking)
                                                    <p class="flex items-center gap-1">
                                                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="8.01"/></svg>
                                                        Giảm thêm 5% khi đặt 2 khung giờ, 10% khi đặt 3+ khung giờ
                                                    </p>
                                                @endif
                                                @if($hasFullDayBooking)
                                                    <div>
                                                        <p class="font-semibold flex items-center gap-1" style="color:#4e6b4c">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                                            Đã áp dụng ưu đãi đặc biệt cho Full phòng!
                                                        </p>
                                                        <p class="text-orange-600 text-xs mt-1 flex items-center gap-1">
                                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="8.01"/></svg>
                                                            Các khuyến mãi giảm giá khác không áp dụng khi đặt full phòng
                                                        </p>
                                                    </div>
                                                @endif
                                            </div>
                                    </div>
                                </div>
                                {{-- Lưu ý giảm giá - CẬP NHẬT --}}
                                <div class="text-left mt-4">
                                    @if($hasFullDayBooking)
                                        <p class="text-primary text-sm font-bold italic">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 inline-block shrink-0 align-middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></svg>
                                        Chúc mừng! Bạn đã đặt FULL phòng và nhận được ưu đãi đặc biệt
                                        </p>
                                    @else
                                        <p class="text-red-600 text-sm italic">
                                            ** Khách hàng được giảm thêm 5% khi chọn 2 khung giờ, 10% khi chọn từ 3 khung giờ trở lên
                                        </p>
                                    @endif
                                </div>

                            </div>
                                @if($fromBookingPage)
                                        </div>{{-- /padding --}}
                                    </div>{{-- /modal container --}}
                                </div>{{-- /modal overlay --}}
                                @endif
                            @elseif(!$fromBookingPage && $bookingStyle == 2)
                            {{-- Style 2: Daterange picker hiển thị ngay dưới ảnh/tiện ích --}}
                            @php
                                $pricePerNight   = $product->price ?? 0;
                                $productDiscount = (int)($product->discount ?? 0);
                                $bookedRanges = \Modules\Payment\Entities\OrderItem::where('product_id', $product->id)
                                    ->whereNotNull('checkin_date')
                                    ->whereNotNull('checkout_date')
                                    ->whereHas('order', function ($q) {
                                    $q->where(function ($inner) {
                                    $inner->whereIn('status', ['paid', 'deposit', 'shipping', 'completed', 'confirmed'])
                                    ->orWhere(function ($pq) {
                                    $pq->where('status', 'pending')
                                    ->where(function ($eq) {
                                    $eq->whereNull('expired_at')
                                    ->orWhere('expired_at', '>', now());
                                    });
                                    });
                                    });
                                    })
                                    ->get()
                                    ->map(fn($it) => [
                                        'start' => \Carbon\Carbon::parse($it->checkin_date)->format('Y-m-d'),
                                        'end'   => \Carbon\Carbon::parse($it->checkout_date)->format('Y-m-d'),
                                    ])->values()->toArray();
                                $dayPriceMap = ['mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6,'sun'=>0];
                                $dayPrices = [];
                                foreach ($product->day_prices ?? [] as $k => $v) {
                                    if (isset($dayPriceMap[$k]) && (int)$v > 0) $dayPrices[$dayPriceMap[$k]] = (int)$v;
                                }
                                $datePrices = [];
                                $promoLabels = [];
                                $promoDiscounts = [];
                                $surchargeData = [];
                                $adminBlockedRanges = array_values(
                                    array_filter($product->room_config['blocked_ranges'] ?? [], fn($r) => !empty($r['start']) && !empty($r['end']))
                                );
                                $nowDt = now();
                                foreach ($product->roomTimeSlots ?? [] as $rts) {
                                    if ($rts->timeSlot && ($rts->timeSlot->type ?? '') === 'date' && !empty($rts->timeSlot->label)) {
                                        $dk = $rts->timeSlot->label;
                                        if ($rts->price !== null) {
                                            $datePrices[$dk] = (int) $rts->price;
                                        }
                                        $maxDisc = 0; $bestLbl = ''; $surcharges = [];
                                        foreach ($rts->promotions as $promo) {
                                            if (!$promo->is_active) continue;
                                            $inPeriod = (!$promo->start_at || $promo->start_at <= $nowDt)
                                                     && (!$promo->end_at   || $promo->end_at   >= $nowDt);
                                            if (!$inPeriod) continue;
                                            if ($promo->type === 'percentage') {
                                                if ((float)$promo->value > $maxDisc) {
                                                    $maxDisc = (float)$promo->value;
                                                    $bestLbl = $promo->lable_client ?: $promo->name;
                                                }
                                            } elseif (in_array($promo->type, ['increase_percentage', 'increase_fixed'])) {
                                                $surcharges[] = ['type' => $promo->type, 'value' => (float)$promo->value];
                                            }
                                        }
                                        if ($maxDisc > 0) { $promoDiscounts[$dk] = $maxDisc; $promoLabels[$dk] = $bestLbl; }
                                        if (!empty($surcharges)) { $surchargeData[$dk] = $surcharges; }
                                    }
                                }
                            @endphp
                            <div wire:ignore>
                            @include('bladethemev1::components.product-detail.daterange-picker', [
                                'pricePerNight'   => $pricePerNight,
                                'productDiscount' => $productDiscount,
                                'bookedRanges'    => $bookedRanges,
                                'adminBlockedRanges' => $adminBlockedRanges,
                                'datePrices'      => $datePrices,
                                'dayPrices'       => $dayPrices,
                                'promoLabels'     => $promoLabels,
                                'promoDiscounts'  => $promoDiscounts,
                                'surchargeData'   => $surchargeData,
                            ])
                            </div>
                            @endif
                        </div>
                    </div>

                    <div class="w-full lg:w-1/3" id="pd-booking-form">
                        {{-- Thông tin đặt phòng --}}
                         @include('bladethemev1::components.product-detail.infomation-book-room')
                    </div>
                </div>
            </div>
        </div>

        {{-- Mô tả phòng và Bình luận --}}
        @include('bladethemev1::components.product-detail.description-comment')

    </div>
</div>
@push('scripts')
<script>
    function processAndUpload(input, field) {
        const file = input.files[0];
        if (!file) return;

        const overlay = document.getElementById(`loading-${field}`);
        const statusText = document.getElementById(`status-${field}`);
        const progressBar = document.getElementById(`progress-${field}`);

        // 1. Hiển thị trạng thái đang xử lý
        overlay.classList.remove('hidden');
        statusText.innerText = "Đang tối ưu...";
        progressBar.style.width = "10%";

        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = function (e) {
            const img = new Image();
            img.src = e.target.result;
            img.onload = function () {
                // 2. Nén ảnh bằng Canvas
                const canvas = document.createElement('canvas');
                let width = img.width;
                let height = img.height;
                const max_size = 1200; // Giới hạn chiều dài nhất là 1200px

                if (width > height) {
                    if (width > max_size) { height *= max_size / width; width = max_size; }
                } else {
                    if (height > max_size) { width *= max_size / height; height = max_size; }
                }

                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, width, height);

                // Xuất ra file JPEG chất lượng 0.7 (giảm dung lượng ~10 lần)
                canvas.toBlob(function (blob) {
                    const safeFileName = file.name.replace(/\.[^/.]+$/, '') + '.jpg';
                    const compressedFile = new File([blob], safeFileName, { type: 'image/jpeg' });

                    statusText.innerText = "Đang tải lên...";

                    // 3. Hiển thị preview ngay lập tức bằng ObjectURL (không cần chờ server)
                    const previewUrl = URL.createObjectURL(blob);
                    const previewImg = document.getElementById(`preview-${field}`);
                    const placeholder = document.getElementById(`placeholder-${field}`);
                    const checkmark   = document.getElementById(`checkmark-${field}`);
                    if (previewImg)  { previewImg.src = previewUrl; previewImg.classList.remove('hidden'); }
                    if (placeholder) { placeholder.classList.add('hidden'); }
                    if (checkmark)   { checkmark.classList.remove('hidden'); }

                    statusText.innerText = "Đang tải lên...";

                    // 4. Sử dụng API của Livewire để upload thủ công
                @this.upload(field, compressedFile,
                    (uploadedName) => {
                        overlay.classList.add('hidden'); // Hoàn tất
                    },
                    () => {
                        overlay.classList.add('hidden');
                        // Ẩn preview nếu upload thất bại
                        if (previewImg)  { previewImg.classList.add('hidden'); previewImg.src = ''; }
                        if (placeholder) { placeholder.classList.remove('hidden'); }
                        if (checkmark)   { checkmark.classList.add('hidden'); }
                        alert('Lỗi tải lên, vui lòng thử lại.');
                    },
                    (event) => {
                        // Cập nhật % thanh tiến trình thực tế
                        progressBar.style.width = event.detail.progress + "%";
                    }
                );
                }, 'image/jpeg', 0.7);
            };
        };
    }
</script>
@if($fromBookingPage)
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.innerWidth < 768) {
            const el = document.getElementById('pd-booking-form');
            if (el) {
                setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'start' }), 300);
            }
        }
    });
</script>
@endif
@endpush