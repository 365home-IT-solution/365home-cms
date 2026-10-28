// ── Carousel 2 phòng/hàng cho lịch đặt phòng (book/_desktop-grid.blade.php) — cả 2 phòng đang
// hiện ra đều "active" (chọn được khung giờ). Bấm Trước/Sau chỉ trượt 1 phòng mỗi lần (không
// phải cả cặp): phòng thứ 2 đôn lên vị trí đầu, phòng thứ 1 ẩn đi, lộ ra 1 phòng mới ở vị trí
// sau — không nhảy cả cặp 2 phòng như carousel thường (xem slidesPerGroup: 1 bên dưới).
// Đặt ở đây (thay vì 1 <script> nằm trong chính view của Book) vì view đó chỉ xuất hiện trong DOM
// SAU KHI Livewire morph vào (Book mount với config rỗng trước, nạp chi nhánh đầu tiên qua sự
// kiện 'load-branch' — xem homeBookingBoard() bên dưới) — trình duyệt KHÔNG tự thực thi <script>
// được chèn qua DOM patching kiểu đó, chỉ có directive Alpine (x-init) mới được Livewire+Alpine
// tự động re-init trên nội dung mới morph vào. File này thì load bình thường qua <script src>
// ngay từ đầu ở mọi trang dùng Book.php (flash-sale.blade.php, search.blade.php,
// booking-board.blade.php) nên luôn sẵn sàng trước khi x-init cần gọi tới.
if (typeof window.mountBookDtSwiper === 'undefined') {
    // idempotent: destroy instance cũ trước khi tạo mới — an toàn khi Livewire morph lại DOM của
    // carousel (đổi chi nhánh, đổi tỉnh...) làm mất hết class active/next/prev do Swiper gán ra.
    window.mountBookDtSwiper = function (wrapEl) {
        if (typeof Swiper === 'undefined' || !wrapEl) return;
        const container = wrapEl.querySelector('.book-dt-swiper');
        if (!container || !container.querySelector('.swiper-slide')) return;
        if (container.swiper) container.swiper.destroy(true, true);

        // Vòng lặp chỉ có ý nghĩa khi còn phòng nằm ngoài cặp đang hiển thị (>2 phòng) — 1-2
        // phòng thì luôn vừa đủ 1 "trang", loop vô nghĩa (Swiper cũng không cho loop khi số
        // slide ít hơn slidesPerView*2).
        const slideCount = container.querySelectorAll('.swiper-slide').length;
        const enableLoop = slideCount > 2;

        new Swiper(container, {
            loop: enableLoop,
            grabCursor: true,
            observer: true,
            observeParents: true,
            slidesPerView: 2,
            slidesPerGroup: 1,
            spaceBetween: 16,
            breakpoints: {
                1536: { spaceBetween: 20 },
            },
            navigation: {
                nextEl: wrapEl.querySelector('.book-dt-nav-next'),
                prevEl: wrapEl.querySelector('.book-dt-nav-prev'),
            },
            pagination: {
                el: wrapEl.querySelector('.book-dt-pagination'),
                clickable: true,
            },
        });
    };
}

// Carousel lịch phòng theo ngày (livewire/book/_daily-cards.blade.php) — cùng kiểu nút Trước/Sau với bảng
// khung giờ. KHÔNG bật loop: loop nhân bản slide, bản sao không có state Alpine riêng (ngày đang chọn).
if (typeof window.mountDailySwiper === 'undefined') {
    window.mountDailySwiper = function (wrapEl) {
        if (typeof Swiper === 'undefined' || !wrapEl) return;
        const container = wrapEl.querySelector('.daily-swiper');
        if (!container || !container.querySelector('.swiper-slide')) return;
        if (container.swiper) container.swiper.destroy(true, true);

        new Swiper(container, {
            observer: true,
            observeParents: true,
            // Giống bảng khung giờ: 2 phòng mỗi hàng (mobile 1 phòng, hé phòng kế tiếp), trượt 1 phòng/lần.
            // Mobile: lề 10px 2 bên cho thẳng hàng chú thích (px-2.5) như bảng khung giờ mobile. Vuốt trên
            // lịch vẫn trượt được (Swiper tự bỏ click sau khi kéo — preventClicks), chạm nhẹ thì chọn ngày.
            slidesPerView: 1.08,
            slidesPerGroup: 1,
            spaceBetween: 10,
            slidesOffsetBefore: 10,
            slidesOffsetAfter: 10,
            watchOverflow: true,
            threshold: 6,
            breakpoints: {
                768: { slidesPerView: 2, spaceBetween: 16, slidesOffsetBefore: 0, slidesOffsetAfter: 0 },
                1536: { slidesPerView: 2, spaceBetween: 20, slidesOffsetBefore: 0, slidesOffsetAfter: 0 },
            },
            navigation: {
                nextEl: wrapEl.querySelector('.daily-nav-next'),
                prevEl: wrapEl.querySelector('.daily-nav-prev'),
            },
            pagination: {
                el: wrapEl.querySelector('.daily-pagination'),
                clickable: true,
            },
        });
    };
}

// Hook toàn cục (đăng ký 1 lần): mỗi khi Livewire morph lại 1 vùng chứa (hoặc nằm trong)
// .book-dt-wrap — ví dụ đổi chi nhánh, hoặc lần đầu nạp dữ liệu sau khi mount rỗng — gắn lại
// Swiper để không bị mất class active/next/prev do morph ghi đè DOM.
// Dùng "morphed" (bắn 1 LẦN sau khi TOÀN BỘ cây DOM của component đã morph xong), KHÔNG dùng
// "morph.updated" (bắn cho TỪNG node bị thay đổi trong cây — bảng lịch có thể có hàng trăm ô
// thay đổi trong 1 lượt render, tức mountBookDtSwiper() — destroy() + new Swiper() — sẽ chạy
// lặp lại hàng trăm lần, có lần chạy giữa lúc DOM còn đang morph dở, dễ để lại state Swiper
// (transform/width lệch) không khớp DOM cuối cùng).
if (typeof window.__bookDtMorphHookRegistered === 'undefined') {
    window.__bookDtMorphHookRegistered = true;
    document.addEventListener('livewire:init', () => {
        Livewire.hook('morphed', ({ el }) => {
            const wraps = el.classList && el.classList.contains('book-dt-wrap')
                ? [el]
                : (el.querySelectorAll ? Array.from(el.querySelectorAll('.book-dt-wrap')) : []);
            const ancestorWrap = el.closest ? el.closest('.book-dt-wrap') : null;
            if (ancestorWrap && !wraps.includes(ancestorWrap)) wraps.push(ancestorWrap);
            wraps.forEach((wrapEl) => window.mountBookDtSwiper(wrapEl));

            const dailyWraps = el.classList && el.classList.contains('daily-wrap')
                ? [el]
                : (el.querySelectorAll ? Array.from(el.querySelectorAll('.daily-wrap')) : []);
            const dailyAncestor = el.closest ? el.closest('.daily-wrap') : null;
            if (dailyAncestor && !dailyWraps.includes(dailyAncestor)) dailyWraps.push(dailyAncestor);
            dailyWraps.forEach((wrapEl) => window.mountDailySwiper(wrapEl));
        });
    });
}

if (typeof window.__homeVnd === 'undefined') {
    window.__homeVnd = function (amount) {
        return Number(amount || 0).toLocaleString('vi-VN') + 'đ';
    };
}

if (typeof window.__heartOutlineSvg === 'undefined') {
    window.__heartOutlineSvg = '<svg xmlns="http://www.w3.org/2000/svg" fill="rgba(38,38,38,0.55)" viewBox="0 0 24 24" stroke-width="1" stroke="#fff" style="width:25px;height:25px;display:block;filter:drop-shadow(0 1px 2px rgba(0,0,0,.6));"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" /></svg>';
}

if (typeof window.__heartSolidSvg === 'undefined') {
    window.__heartSolidSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#ef4444" style="width:25px;height:25px;display:block;filter:drop-shadow(0 1px 2px rgba(0,0,0,.45));"><path d="m11.645 20.91-.007-.003-.022-.012a15.247 15.247 0 0 1-.383-.218 25.18 25.18 0 0 1-4.244-3.17C4.688 15.36 2.25 12.174 2.25 8.25 2.25 5.322 4.714 3 7.688 3A5.5 5.5 0 0 1 12 5.052 5.5 5.5 0 0 1 16.313 3c2.973 0 5.437 2.322 5.437 5.25 0 3.925-2.438 7.111-4.739 9.256a25.175 25.175 0 0 1-4.244 3.17 15.247 15.247 0 0 1-.383.219l-.022.012-.007.004-.003.001a.752.752 0 0 1-.704 0l-.003-.001Z" /></svg>';
}

if (typeof window.__homeToggleWishlist === 'undefined') {
    window.__homeToggleWishlist = function (productId, btnEl) {
        const token = localStorage.getItem('auth_token');
        if (!token) {
            window.dispatchEvent(new CustomEvent('open-auth-modal'));
            return;
        }
        const filled = btnEl.getAttribute('data-active') === '1';
        const next = !filled;
        btnEl.setAttribute('data-active', next ? '1' : '0');
        btnEl.innerHTML = next ? window.__heartSolidSvg : window.__heartOutlineSvg;
        btnEl.classList.remove('room-wishlist-pop');
        void btnEl.offsetWidth;
        btnEl.classList.add('room-wishlist-pop');

        fetch('/api/wishlist/' + productId + '/toggle', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + token,
                'Accept': 'application/json',
            },
        }).catch(() => {
            btnEl.setAttribute('data-active', filled ? '1' : '0');
            btnEl.innerHTML = filled ? window.__heartSolidSvg : window.__heartOutlineSvg;
        });
    };
}

// Escape chuỗi trước khi đặt vào thuộc tính alt="..." của card (tên phòng/chi nhánh do admin nhập).
if (typeof window.__homeAltAttr === 'undefined') {
    window.__homeAltAttr = function (text) {
        return String(text || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    };
}

if (typeof window.roomCardHtml === 'undefined') {
    window.roomCardHtml = function (room) {
        // Prefer the pre-generated "card" conversion (480px, avif, ~72 quality — see
        // Product::registerMediaConversions) over thumbnail_url, which is the full-size original
        // (often 900KB-1.2MB) — these cards only render at 170-380px wide. Falls back to the wider
        // preset, then the original, for the rare record whose conversion hasn't finished generating.
        const img = room.thumbnail?.card || room.thumbnail?.wide || room.thumbnail_url || room.image_url || '';
        // Có đủ loại hình (room.type_slug, gắn kèm sẵn từ API — xem BuildsRoomCard::mapRoom()) +
        // chi nhánh + khu vực (room.branch) thì dùng URL canonical
        // /{type}/{province_slug}/{branch_slug}/{room_slug} (nối tiếp silo loại hình/khu vực/chi
        // nhánh); không thì rơi về URL phẳng /room/{slug}/ (server tự 301 sang canonical nếu xác
        // định được — xem BladeThemeV1Controller::renderProductDetail()).
        const roomTypeUrlSlug = window.__typeUrlSlug(room.type_slug);
        const href = (roomTypeUrlSlug && room.branch && room.branch.slug && room.branch.province_slug)
            ? '/' + roomTypeUrlSlug + '/' + room.branch.province_slug + '/' + room.branch.slug + '/' + room.slug + '/'
            : '/room/' + room.slug + '/';
        // original_amount: giá gốc khi đang giảm (Flash Sale phòng theo ngày — HomeController::fetchRegionFlashSaleRooms()).
        const oldPriceHtml = room.price && room.price.original_amount > room.price.amount
            ? '<span style="color:#9ca3af; font-size:11px; text-decoration:line-through; margin-right:4px;">' + window.__homeVnd(room.price.original_amount) + '</span>'
            : '';
        const priceHtml = room.price
            ? oldPriceHtml + '<span style="font-weight:700;">' + window.__homeVnd(room.price.amount) + '</span>'
                + '<span style="color:#9ca3af; font-size:11px;"> ' + (room.price.unit_label || '') + '</span>'
            : '<span style="font-weight:700;">Liên hệ</span>';

        const ratingHtml = room.rating
            ? '<span style="font-size:12px; color:#374151; white-space:nowrap; display:flex; align-items:center; gap:2px; flex-shrink:0;">'
                + '<svg style="width:12px;height:12px;color:#f59e0b;flex-shrink:0;" viewBox="0 0 20 20" fill="currentColor"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>'
                + Number(room.rating).toFixed(1) + '</span>'
            : '';

        const roomTypeSlug = room.room_type?.slug || room.type_slug || room.type || '';
        const isHomestay = String(roomTypeSlug).toLowerCase() === 'homestay' || String(room.type).toLowerCase() === 'homestay';
        const badgeHtml = !isHomestay && room.badge && room.badge.label
            ? '<div style="position:absolute; top:8px; left:8px; background:' + (room.badge.bg_color || '#ef4444') + '; color:' + (room.badge.text_color || '#fff') + '; font-size:10px; font-weight:800; padding:3px 9px; border-radius:99px; z-index:2;">' + room.badge.label + '</div>'
            : '';

        const unavailableHtml = room.is_available === false
            ? '<div style="position:absolute; inset:0; background:rgba(255,255,255,.55); display:flex; align-items:center; justify-content:center; z-index:2;">'
                + '<span style="background:#111827; color:#fff; font-size:11px; font-weight:700; padding:4px 12px; border-radius:99px;">Tạm hết phòng</span></div>'
            : '';

        // alt mô tả cho Google Images/screen reader: tên phòng + chi nhánh (vd "PIXEL - 254 Xuân Thủy").
        const imgAlt = window.__homeAltAttr([room.name, room.branch && room.branch.name].filter(Boolean).join(' - '));

        const wishlistActive = room.wishlist_status === true;
        const wishlistHtml = '<button type="button" class="room-wishlist-btn" data-active="' + (wishlistActive ? '1' : '0') + '"'
                + ' aria-label="Yêu thích"'
                + ' onclick="event.preventDefault(); event.stopPropagation(); window.__homeToggleWishlist(\'' + room.id + '\', this)"'
                + '>'
                + (wishlistActive ? window.__heartSolidSvg : window.__heartOutlineSvg)
                + '</button>';

        return '<a href="' + href + '" class="home-card" style="position:relative; scroll-snap-align:start; display:flex; flex-direction:column; gap:8px; text-decoration:none;">'
            + '<div style="position:relative; padding-top:72%; overflow:hidden; background:#f3f4f6; border-radius:14px; flex-shrink:0;">'
            + badgeHtml + wishlistHtml + unavailableHtml
            + (img ? '<img src="' + img + '" alt="' + imgAlt + '" loading="lazy" style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">' : '')
            + '</div>'
            + '<div style="padding:0 2px; display:flex; flex-direction:column; gap:3px;">'
            + '<p style="font-size:13px; font-weight:600; color:#111827; margin:0; overflow:hidden; white-space:nowrap; text-overflow:ellipsis;">' + (room.name || '') + '</p>'
            + '<div style="display:flex; align-items:center; justify-content:space-between; gap:6px;">'
            + '<p style="font-size:13px; color:#111827; margin:0; white-space:nowrap;">' + priceHtml + '</p>'
            + ratingHtml
            + '</div></div></a>';
    };
}

if (typeof window.branchCardHtml === 'undefined') {
    // locationOverride — khu vực đã biết trước từ trang gọi (vd location trên path của trang tìm
    // kiếm, xem parseLocationFromPath() trong search-results.js) — ưu tiên hơn branch.location từ
    // API vì luôn khớp đúng trang đang xem. Có đủ khu vực + loại hình (branch.type_url_slug, gắn
    // kèm sẵn từ API — xem SearchController::branches()/HomeController::getSuggestionBranches())
    // thì dùng URL canonical /{type}/{location}/{slug} (tốt cho SEO local hơn), không thì rơi về
    // URL phẳng /chi-nhanh/{slug} (server tự 301 sang canonical nếu xác định được — xem
    // BladeThemeV1Controller::renderBookingBoard()).
    window.branchCardHtml = function (branch, locationOverride) {
        // Same "card" preset preference as roomCardHtml — see docs/image-thumbnails-fe-guide.md.
        const img = branch.thumbnail?.card || branch.thumbnail?.wide || branch.image_url || '';
        const location = locationOverride || branch.location || '';
        const href = (location && branch.type_url_slug)
            ? '/' + branch.type_url_slug + '/' + location + '/' + branch.slug
            : '/chi-nhanh/' + branch.slug;

        return '<a href="' + href + '" class="home-card" style="scroll-snap-align:start; display:flex; flex-direction:column; gap:8px; text-decoration:none;">'
            + '<div style="position:relative; padding-top:72%; overflow:hidden; background:#f3f4f6; border-radius:14px; flex-shrink:0;">'
            + (img ? '<img src="' + img + '" alt="' + window.__homeAltAttr(branch.name) + '" loading="lazy" style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">' : '')
            + '</div>'
            + '<p style="font-size:13px; font-weight:600; color:#111827; margin:0; overflow:hidden; white-space:nowrap; text-overflow:ellipsis;">' + (branch.name || '') + '</p>'
            + '</a>';
    };
}

if (typeof window.carouselNav === 'undefined') {
    window.carouselNav = function () {
        return {
            canScrollPrev: false,
            canScrollNext: false,
            checkFrame: null,

            init() {
                this.$nextTick(() => {
                    const el = this.$refs.track;
                    if (!el) return;
                    el.addEventListener('scroll', () => this.scheduleCheck(), { passive: true });
                    window.addEventListener('resize', () => this.scheduleCheck(), { passive: true });
                    new MutationObserver(() => this.scheduleCheck()).observe(el, { childList: true });
                    // ResizeObserver bắt luôn trường hợp track đổi từ display:none (x-show="false"
                    // lúc chưa có dữ liệu) sang hiện thật — lúc đó childList có thể đã đổi trước khi
                    // box thật sự hiện ra nên MutationObserver một mình đo hụt (ra 0x0). Callback
                    // của nó chạy SAU bước layout nên gọi check() thẳng, không ép reflow; nó cũng
                    // tự bắn 1 lần ngay khi observe() nên thay luôn cho lần đo đầu tiên.
                    if (typeof ResizeObserver !== 'undefined') {
                        new ResizeObserver(() => this.check()).observe(el);
                    } else {
                        this.scheduleCheck();
                    }
                });
            },

            // Đo SAU khi frame hiện tại đã layout + paint xong (rAF rồi setTimeout 0), không đo
            // ngay trong rAF: rAF chạy TRƯỚC bước layout, đọc scrollWidth lúc DOM vừa bị Alpine
            // đổi sẽ ép trình duyệt tính layout đồng bộ (Lighthouse "Forced reflow").
            scheduleCheck() {
                if (this.checkFrame !== null) return;
                this.checkFrame = requestAnimationFrame(() => {
                    setTimeout(() => {
                        this.checkFrame = null;
                        this.check();
                    }, 0);
                });
            },

            check() {
                const el = this.$refs.track;
                if (!el) return;
                const max = el.scrollWidth - el.clientWidth;
                this.canScrollPrev = max > 4 && el.scrollLeft > 4;
                this.canScrollNext = max > 4 && el.scrollLeft < max - 4;
            },

            prev() {
                this.$refs.track.scrollBy({ left: -260, behavior: 'smooth' });
            },

            next() {
                this.$refs.track.scrollBy({ left: 260, behavior: 'smooth' });
            },
        };
    };
}

if (typeof window.__roomTypeImageMap === 'undefined') {
    window.__roomTypeImageMap = {
        homestay: 'homestay.jpg',
        villa: 'villa.jpg',
        motel: 'motel.jpg',
        mini_house: 'minihouse.jpg',
        hotel: 'hotel.jpg',
        apartment: 'apartment.jpg',
    };
}

if (typeof window.__roomTypeImage === 'undefined') {
    window.__roomTypeImage = function (type) {
        const file = window.__roomTypeImageMap[type.slug];
        if (file) return '/images/room-types/' + file;
        return type.icon_url || '';
    };
}

// Icon đứng cạnh/trên tên loại hình dịch vụ ở hàng nút "Loại hình dịch vụ" (xem flash-sale.blade.php).
if (typeof window.__roomTypeIconMap === 'undefined') {
    window.__roomTypeIconMap = {
        homestay: '/images/homestay-176.webp',
        hotel: '/images/hotel-176.webp',
        motel: '/images/motel-176.webp',
        villa: '/images/villa-176.webp',
        apartment: '/images/apartment-176.webp',
        mini_house: '/images/minihouse-176.webp',
    };
}

if (typeof window.__roomTypeIcon === 'undefined') {
    window.__roomTypeIcon = function (type) {
        return window.__roomTypeIconMap[type.slug] || type.icon_url || '';
    };
}

// Slug URL rút gọn (segment đầu path, vd /khach-san/...) <=> RoomType.slug thật trong DB (vd
// 'hotel') — PHẢI khớp NGUYÊN VĂN với BranchBookConfig::TYPE_URL_MAP (PHP) — sửa 1 bên phải sửa
// cả 2, không có nguồn chung nào khác giữa PHP/JS. Dùng để dựng href "Xem tất cả"/roomtype-card
// (flash-sale.blade.php) và suy filter 'type' từ pathname (search-results.js: getTypeParam()).
if (typeof window.__typeUrlMap === 'undefined') {
    window.__typeUrlMap = {
        homestay: 'homestay',
        hotel: 'khach-san',
        mini_house: 'mini-house',
        villa: 'villa',
        motel: 'nha-nghi',
        apartment: 'chung-cu',
    };
}

if (typeof window.__typeDbSlugMap === 'undefined') {
    window.__typeDbSlugMap = Object.keys(window.__typeUrlMap).reduce(function (acc, dbSlug) {
        acc[window.__typeUrlMap[dbSlug]] = dbSlug;
        return acc;
    }, {});
}

if (typeof window.__typeUrlSlug === 'undefined') {
    window.__typeUrlSlug = function (dbSlug) {
        return window.__typeUrlMap[dbSlug] || null;
    };
}

// Livewire can morph/rebuild the DOM right after the page hydrates (see comment in
// homeBookingBoard() below), which makes Alpine re-run x-init on the same logical widget —
// firing its initial fetch twice, back-to-back, for the exact same data (confirmed in a
// PageSpeed network-dependency trace: /api/v1/home, /api/v1/provinces, and
// /api/v1/search/branches each requested twice). Rather than guessing which of the two Alpine
// instances ends up bound to the final DOM (skipping the "wrong" one would leave that widget
// stuck on its loading skeleton forever), share a single in-flight request across any calls to
// the same URL that overlap in time — every caller still gets the real response, only the
// redundant network round-trip is removed.
if (typeof window.__dedupeFetch === 'undefined') {
    window.__dedupeFetch = function (url, opts) {
        window.__inflightFetches = window.__inflightFetches || {};
        const key = url + '|' + JSON.stringify((opts && opts.headers) || {});
        if (window.__inflightFetches[key]) return window.__inflightFetches[key];
        const promise = fetch(url, opts).finally(() => { delete window.__inflightFetches[key]; });
        window.__inflightFetches[key] = promise;
        return promise;
    };
}

if (typeof window.__afterPageIdle === 'undefined') {
    window.__afterPageIdle = function (callback) {
        const run = () => {
            if ('requestIdleCallback' in window) {
                requestIdleCallback(callback, { timeout: 2500 });
            } else {
                setTimeout(callback, 500);
            }
        };
        if (document.readyState === 'complete') run();
        else window.addEventListener('load', run, { once: true });
    };
}

if (typeof window.homeSections === 'undefined') {
    window.homeSections = function (initialHome) {
        initialHome = initialHome || {};
        const initialBanner = initialHome.banner || null;
        return {
            sections: initialBanner ? [initialBanner] : [],
            roomTypes: initialHome.room_types || [],
            bannerSection: initialBanner,
            loading: true,
            provinceName: localStorage.getItem('home_province_name') || '',
            // Khu vực đang xem: khách đã chọn (localStorage) hoặc khu vực mặc định của Lịch đặt phòng.
            provinceId: localStorage.getItem('home_province_id') || initialHome.booking?.active_province_id || null,
            provinces: initialHome.booking?.provinces || [],

            // Thẻ "Loại hình dịch vụ" luôn hiện đủ; bấm vào thì mở trang loại hình ĐÃ lọc theo khu vực
            // đang xem (/{type}/{province_slug} — route product.search.type). Chưa biết khu vực → /{type}.
            roomTypeHref(typePath) {
                const province = this.provinces.find(p => String(p.id) === String(this.provinceId));
                return province?.slug ? typePath + '/' + province.slug : typePath;
            },

            init() {
                // Banner + room types are already in the initial HTML. Keep the full home API off
                // the critical path; it only fills sections below the fold.
                if (initialBanner && 'IntersectionObserver' in window) {
                    const boundary = document.querySelector('[data-home-sections-boundary]');
                    if (boundary) {
                        const observer = new IntersectionObserver((entries) => {
                            if (!entries.some(entry => entry.isIntersecting)) return;
                            observer.disconnect();
                            this.load();
                        });
                        observer.observe(boundary);
                    } else {
                        window.__afterPageIdle(() => this.load());
                    }
                } else if (initialBanner) {
                    window.__afterPageIdle(() => this.load());
                } else this.load();
                window.addEventListener('auth-state-changed', () => this.load());
                window.addEventListener('province-selected', (e) => {
                    this.provinceName = e.detail?.name || localStorage.getItem('home_province_name') || '';
                    this.provinceId = String(e.detail?.id || localStorage.getItem('home_province_id') || this.provinceId || '') || null;
                    if (e.detail?.id && e.detail?.slug && !this.provinces.some(p => String(p.id) === String(e.detail.id))) {
                        this.provinces = this.provinces.concat([{ id: e.detail.id, slug: e.detail.slug }]);
                    }
                    this.load();
                });
            },

            load() {
                this.loading = true;
                const token = localStorage.getItem('auth_token');
                const provinceId = this.provinceId;
                const headers = { 'Accept': 'application/json' };
                if (token) headers['Authorization'] = 'Bearer ' + token;

                // group=branch: có khu vực thì server chỉ giữ banner của Trang App, danh sách phòng chia
                // mỗi chi nhánh của khu vực 1 hàng (tiêu đề = tên chi nhánh) — xem HomeController::branchGroupedHome().
                const url = '/api/v1/home?group=branch' + (provinceId ? '&province_id=' + encodeURIComponent(provinceId) : '');

                window.__dedupeFetch(url, { headers })
                    .then(res => res.json())
                    .then(data => {
                        // "Gợi ý cho bạn" (suggestion_list) không hiển thị trên web — chỉ app dùng.
                        const allowed = ['banner', 'promotion_list', 'room_list'];
                        this.sections = (data?.home?.sections || [])
                            .filter(s => allowed.includes(s.type))
                            .sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0));
                        this.roomTypes = data?.home?.room_types || [];
                        // Banner đầu tiên được kéo ra hiển thị chung hàng với loại hình dịch vụ
                        // (2 cột center-mode carousel) — vẫn giữ nguyên trong `sections` để branch
                        // banner ở vòng lặp bên dưới có thể so sánh và bỏ qua nó (tránh render
                        // trùng), phòng khi CMS cấu hình nhiều hơn 1 block banner.
                        this.bannerSection = this.sections.find(s => s.type === 'banner' && s.items && s.items.length) || null;
                        this.$nextTick(() => {
                            this.mountRoomTypeSwiper();
                            this.mountBannerSwiper();
                        });
                    })
                    .catch(() => { this.sections = []; this.roomTypes = []; this.bannerSection = null; })
                    .finally(() => { this.loading = false; });
            },

            // Peek rất nhỏ ở 2 bên (chỉ đủ hé 1 chút mép ảnh kế cận để biết còn ảnh khác) — ảnh
            // chính gần như lấp đầy trọn cột. Dùng chung cho cả 2 carousel.
            _centerBreakpoints: {
                0: { slidesPerView: 1.04, spaceBetween: 6 },
                480: { slidesPerView: 1.05, spaceBetween: 6 },
                640: { slidesPerView: 1.05, spaceBetween: 8 },
                768: { slidesPerView: 1.06, spaceBetween: 8 },
                1024: { slidesPerView: 1.04, spaceBetween: 8 },
                1280: { slidesPerView: 1.05, spaceBetween: 8 },
                1536: { slidesPerView: 1.06, spaceBetween: 10 },
            },

            mountRoomTypeSwiper() {
                this._mountCenterSwiper(this.$refs.roomTypeSwiperEl, {
                    breakpoints: this._centerBreakpoints,
                    loop: this.roomTypes.length > 3,
                    navId: 'hs-roomtype',
                });
            },

            mountBannerSwiper() {
                if (!this.bannerSection) return;
                this._mountCenterSwiper(this.$refs.bannerSwiperEl, {
                    breakpoints: this._centerBreakpoints,
                    // Banner tự động chuyển slide — bật loop khi có từ 2 banner trở lên để tự
                    // quay vòng lại từ đầu thay vì dừng khựng ở slide cuối.
                    loop: this.bannerSection.items.length > 1,
                    navId: 'hs-banner',
                    autoplay: { delay: 4000, disableOnInteraction: false, pauseOnMouseEnter: true },
                });
            },

            // Dùng chung cho cả 2 carousel "Center Mode" (loại hình dịch vụ + banner). Mỗi lần
            // dữ liệu load lại (đổi tỉnh, đăng nhập/xuất) sẽ destroy instance cũ rồi tạo mới —
            // đơn giản và an toàn hơn cố gắng update() từng phần, tần suất reload thấp nên không
            // đáng lo hiệu năng.
            _mountCenterSwiper(container, opts) {
                if (typeof Swiper === 'undefined' || !container) return;
                if (container.swiper) container.swiper.destroy(true, true);
                if (!container.querySelector('.swiper-slide')) return;

                new Swiper(container, {
                    centeredSlides: true,
                    slidesPerView: opts.breakpoints[0].slidesPerView,
                    spaceBetween: opts.breakpoints[0].spaceBetween,
                    loop: opts.loop,
                    autoplay: opts.autoplay || false,
                    grabCursor: true,
                    observer: true,
                    observeParents: true,
                    watchOverflow: true,
                    navigation: {
                        nextEl: '#' + opts.navId + '-next',
                        prevEl: '#' + opts.navId + '-prev',
                    },
                    pagination: {
                        el: '#' + opts.navId + '-pagination',
                        clickable: true,
                    },
                    breakpoints: opts.breakpoints,
                });
            },
        };
    };
}

// Lịch đặt phòng THEO NGÀY của từng phòng ở Lịch đặt phòng trực tuyến (livewire/book/_daily-cards.blade.php).
// Cùng cách tính giá / ngày kín với lịch ở trang chi tiết phòng (components/product-detail/daterange-picker
// .blade.php — dữ liệu từ Modules\BladeThemeV1\Support\DailyRoomCalendar), nhưng không gắn Livewire: chọn
// xong ngày nhận/trả thì "Đặt phòng" mở trang chi tiết phòng với đúng khoảng ngày đó (booking_preselect).
if (typeof window.dailyRoomCalendar === 'undefined') {
    window.dailyRoomCalendar = function (cfg, roomUrl) {
        cfg = cfg || {};
        const today = new Date();
        const inRanges = (iso, ranges) => (ranges || []).some(r => iso >= r.start && iso <= r.end);
        return {
            startDate: null,
            endDate: null,
            hoveredDate: null,
            viewYear: today.getFullYear(),
            viewMonth: today.getMonth(),
            pricePerNight: Number(cfg.pricePerNight || 0),
            bookedRanges: cfg.bookedRanges || [],
            adminBlockedRanges: cfg.adminBlockedRanges || [],
            datePrices: cfg.datePrices || {},
            stayPrices: cfg.stayPrices || {},
            dayPrices: cfg.dayPrices || {},
            promoDiscounts: cfg.promoDiscounts || {},
            promoLabels: cfg.promoLabels || {},
            surchargeData: cfg.surchargeData || {},

            prevMonth() { if (this.viewMonth === 0) { this.viewMonth = 11; this.viewYear--; } else this.viewMonth--; },
            nextMonth() { if (this.viewMonth === 11) { this.viewMonth = 0; this.viewYear++; } else this.viewMonth++; },
            get canPrev() { return this.viewYear > today.getFullYear() || (this.viewYear === today.getFullYear() && this.viewMonth > today.getMonth()); },
            isoDate(y, m, d) { return y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0'); },
            monthName() { return new Date(this.viewYear, this.viewMonth, 1).toLocaleDateString('vi-VN', { month: 'long', year: 'numeric' }); },
            daysInMonth() { return new Date(this.viewYear, this.viewMonth + 1, 0).getDate(); },
            firstDay() { const d = new Date(this.viewYear, this.viewMonth, 1).getDay(); return d === 0 ? 6 : d - 1; },
            iso(d) { return this.isoDate(this.viewYear, this.viewMonth, d); },
            isPast(d) { const t = new Date(); t.setHours(0, 0, 0, 0); return new Date(this.viewYear, this.viewMonth, d) < t; },
            isToday(d) { return d === today.getDate() && this.viewMonth === today.getMonth() && this.viewYear === today.getFullYear(); },
            isBooked(d) { return inRanges(this.iso(d), this.bookedRanges); },
            isBlocked(d) { return inRanges(this.iso(d), this.adminBlockedRanges); },
            isUnavailable(d) { return this.isBooked(d) || this.isBlocked(d); },
            isDisabled(d) { return this.isPast(d) || this.isUnavailable(d); },
            inRange(d) {
                const end = this.endDate || this.hoveredDate, i = this.iso(d);
                return !!this.startDate && !!end && i > this.startDate && i < end && !this.isUnavailable(d);
            },
            hasPromo(d) { const i = this.iso(d); return !this.isDisabled(d) && (this.promoDiscounts[i] !== undefined || !!this.promoLabels[i]); },

            selectDay(d) {
                if (this.isDisabled(d)) return;
                const i = this.iso(d);
                if (!this.startDate || this.endDate || i <= this.startDate) { this.startDate = i; this.endDate = null; return; }
                // Không cho khoảng ngày đè lên ngày đã có đơn / admin khoá (giống trang chi tiết phòng).
                const crosses = this.bookedRanges.some(r => r.start < i && r.end > this.startDate)
                    || this.adminBlockedRanges.some(r => r.start <= i && r.end >= this.startDate);
                if (crosses) { this.startDate = i; this.endDate = null; return; }
                this.endDate = i;
                // Mỗi lúc chỉ 1 phòng đang chọn (1 bottom sheet trên mobile) — báo các lịch khác bỏ chọn.
                window.dispatchEvent(new CustomEvent('daily-range-picked', { detail: { url: roomUrl } }));
            },
            init() {
                window.addEventListener('daily-range-picked', (e) => {
                    if (e.detail?.url !== roomUrl) this.clear();
                });
            },
            clear() { this.startDate = null; this.endDate = null; this.hoveredDate = null; },

            // Giao diện từng ô ngày nằm hết ở CSS (_daily-cards.scss) — ở đây chỉ gắn class trạng thái.
            dayClass(d) {
                const i = this.iso(d), end = this.endDate || this.hoveredDate;
                return {
                    'is-past': this.isPast(d),
                    'is-blocked': this.isBlocked(d),
                    'is-booked': !this.isBlocked(d) && this.isBooked(d),
                    'is-start': i === this.startDate,
                    'is-end': !!this.endDate && i === this.endDate,
                    'has-range': !!this.startDate && !!end && end > this.startDate,
                    'is-range': this.inRange(d),
                    'is-hover-end': !this.endDate && !!this.startDate && i === this.hoveredDate && i > this.startDate,
                    'is-today': this.isToday(d),
                    'is-promo': this.hasPromo(d),
                };
            },

            basePriceFor(i) {
                if (Object.prototype.hasOwnProperty.call(this.datePrices, i) && this.datePrices[i] !== null) return this.datePrices[i];
                // Giá theo thứ của bảng giá (server tính theo từng ngày); ngày đặc biệt ở trên luôn thắng.
                if (Object.prototype.hasOwnProperty.call(this.stayPrices, i)) return this.stayPrices[i];
                const dow = new Date(i + 'T00:00:00').getDay();
                return this.dayPrices[dow] > 0 ? this.dayPrices[dow] : this.pricePerNight;
            },
            surchargeFor(i) {
                const base = this.basePriceFor(i);
                return (this.surchargeData[i] || []).reduce((sum, s) => sum + (s.type === 'increase_percentage' ? Math.round(base * s.value / 100) : Math.round(s.value)), 0);
            },
            priceFor(i) {
                const p = this.basePriceFor(i), disc = this.promoDiscounts[i] || 0;
                return (disc > 0 ? Math.round(p * (1 - disc / 100)) : p) + this.surchargeFor(i);
            },
            nights() {
                const out = [];
                if (!this.startDate || !this.endDate) return out;
                const d = new Date(this.startDate + 'T00:00:00'), end = new Date(this.endDate + 'T00:00:00');
                while (d < end) { out.push(this.isoDate(d.getFullYear(), d.getMonth(), d.getDate())); d.setDate(d.getDate() + 1); }
                return out;
            },
            get nightCount() { return this.nights().length; },
            get total() { return this.nights().reduce((s, i) => s + this.priceFor(i), 0); },
            get originalTotal() { return this.nights().reduce((s, i) => s + this.basePriceFor(i) + this.surchargeFor(i), 0); },
            get savings() { return Math.max(0, this.originalTotal - this.total); },
            get rangePromos() {
                const seen = {};
                return this.nights().filter(i => this.promoLabels[i] && !seen[this.promoLabels[i]] && (seen[this.promoLabels[i]] = true))
                    .map(i => ({ label: this.promoLabels[i], discount: this.promoDiscounts[i] }));
            },
            fmt(i) { if (!i) return '--'; const p = i.split('-'); return p[2] + '/' + p[1]; },
            vnd(n) { return Number(n || 0).toLocaleString('vi-VN') + 'đ'; },

            book() {
                if (!this.startDate || !this.endDate) return;
                try { sessionStorage.setItem('booking_preselect', JSON.stringify({ checkin: this.startDate, checkout: this.endDate })); } catch (e) {}
                window.location.href = roomUrl + (roomUrl.includes('?') ? '&' : '?')
                    + 'checkin=' + this.startDate + '&checkout=' + this.endDate;
            },
        };
    };
}

if (typeof window.homeBookingBoard === 'undefined') {
    window.homeBookingBoard = function (initialBooking) {
        initialBooking = initialBooking || {};
        // Chi nhánh chưa có phòng nào (room_count = 0, đếm phòng đang bật bán — xem
        // SearchController::branchesDataForProvince()) thì không hiện ở Lịch đặt phòng. Lọc ở đây vì
        // API /api/v1/search/branches dùng chung với trang tìm kiếm/app.
        const withRooms = (list) => (list || []).filter(b => b.room_count === undefined || Number(b.room_count) > 0);
        const initialBranches = withRooms(initialBooking.default_branches);
        return {
            provinces: initialBooking.provinces || [],
            branches: initialBranches,
            activeProvinceId: initialBooking.active_province_id || null,
            activeBranchSlug: initialBranches[0]?.slug || null,
            loadedBranchSlug: initialBooking.default_book_config
                ? (initialBooking.default_branches?.[0]?.slug || null)
                : null,
            loadingBranches: false,

            init() {
                this.activeProvinceId = localStorage.getItem('home_province_id') || this.activeProvinceId;
                // Bảng lịch là chức năng chính: phải dispatch chi nhánh mặc định ngay khi Alpine
                // khởi tạo để Livewire nạp lưới khung giờ, không chờ người dùng cuộn/chọn lại.
                this.loadInitialData();
                window.addEventListener('province-selected', (e) => {
                    const id = String(e.detail?.id || localStorage.getItem('home_province_id') || '');
                    if (id && id !== this.activeProvinceId) {
                        this.activeProvinceId = id;
                        this.loadBranches();
                    }
                });
            },

            loadInitialData() {
                // Nếu đã biết tỉnh từ lần trước (localStorage), gọi thẳng /api/v1/search/branches
                // song song với /api/v1/provinces thay vì đợi provinces trả về rồi mới gọi — 2 API
                // này không phụ thuộc nhau khi đã có sẵn provinceId. Chưa biết tỉnh (khách lần đầu,
                // không có localStorage) thì gọi /api/v1/provinces kèm cờ with_default_branches=1
                // — API gộp luôn chi nhánh của tỉnh mặc định vào CÙNG response, thay vì phải đợi
                // xong rồi mới gọi tiếp branches riêng (bớt 1 vòng round-trip nối tiếp trong
                // network-dependency chain — xem ProvinceController::index()).
                if (this.activeProvinceId) {
                    // Dữ liệu tỉnh đã được nhúng trong HTML; chỉ gọi API khi cache/response cũ
                    // không có seed để giữ nguyên fallback an toàn.
                    if (!this.provinces.length) this.loadProvinces();
                    const seededProvince = String(initialBooking.active_province_id || '');
                    if (!this.branches.length || String(this.activeProvinceId) !== seededProvince) {
                        this.loadBranches();
                    } else if (this.branches[0]) {
                        this.selectBranch(this.branches[0]);
                    }
                } else {
                    this.loadProvinces(true);
                }
            },

            loadProvinces(withDefaultBranches) {
                const url = '/api/v1/provinces' + (withDefaultBranches ? '?with_default_branches=1' : '');
                window.__dedupeFetch(url)
                    .then(res => res.json())
                    .then(data => {
                        this.provinces = data.provinces || [];
                        // Chỉ tự set tỉnh mặc định + nạp chi nhánh ở đây khi lúc init() CHƯA có
                        // activeProvinceId (khách lần đầu) — nếu đã gọi loadBranches() ở nhánh
                        // "đã biết tỉnh" trong init() thì bỏ qua, tránh gọi trùng.
                        if (!this.activeProvinceId && this.provinces.length) {
                            // default_province_id: tỉnh có chi nhánh đặt theo khung giờ (server chọn,
                            // xem App\Support\HomeDefaultBranch) — không có thì về tỉnh đầu như cũ.
                            this.activeProvinceId = String(data.default_province_id || this.provinces[0].id);
                            if (data.default_branches) {
                                // Server đã gộp sẵn chi nhánh của tỉnh mặc định vào response này —
                                // dùng thẳng, không cần gọi thêm /api/v1/search/branches nữa.
                                this.branches = withRooms(data.default_branches.data);
                                if (this.branches.length) {
                                    this.selectBranch(this.branches[0]);
                                }
                            } else {
                                // Fallback an toàn — phòng trường hợp with_default_branches không
                                // được gửi kèm hoặc server không trả default_branches vì lý do nào
                                // đó, vẫn nạp được chi nhánh theo cách cũ (thêm 1 round-trip).
                                this.loadBranches();
                            }
                        }
                    })
                    .catch(() => { this.provinces = []; });
            },

            // Đồng bộ localStorage + bắn 'province-selected' giống location-modal.blade.php, để
            // phần còn lại của trang chủ (home-sections) cũng phản ứng nhất quán nếu user đổi
            // tỉnh từ đúng widget này.
            selectProvince(p) {
                if (String(p.id) === this.activeProvinceId) return;
                this.activeProvinceId = String(p.id);
                localStorage.setItem('home_province_id', p.id);
                localStorage.setItem('home_province_name', p.name);
                window.dispatchEvent(new CustomEvent('province-selected', { detail: p }));
                this.loadBranches();
            },

            loadBranches() {
                if (!this.activeProvinceId) return;
                this.loadingBranches = true;
                this.branches = [];
                window.__dedupeFetch('/api/v1/search/branches?province_id=' + encodeURIComponent(this.activeProvinceId))
                    .then(res => res.json())
                    .then(data => {
                        this.branches = withRooms(data.data);
                        if (this.branches.length) {
                            this.selectBranch(this.branches[0]);
                        }
                    })
                    .catch(() => { this.branches = []; })
                    .finally(() => { this.loadingBranches = false; });
            },

            // Chặn dispatch 'load-branch' lặp lại cho cùng 1 chi nhánh — init() có thể chạy 2 lần
            // (Alpine re-init khi Livewire morph dựng lại DOM của <section> này lúc trang chủ mới
            // hydrate xong), mỗi lần gọi loadProvinces() → loadBranches() → selectBranch() riêng.
            // Không có guard này, 2 lần dispatch gần như đồng thời làm Book morph lại 2 lần liên
            // tiếp, gây giật/nhảy chiều cao khung lịch (và cả trang) trong chốc lát lúc mới vào trang.
            selectBranch(b) {
                this.activeBranchSlug = b.slug;
                if (this.loadedBranchSlug === b.slug) return;
                this.loadedBranchSlug = b.slug;
                window.Livewire.dispatch('load-branch', { slug: b.slug });
            },

            // Nút prev/next của hàng chi nhánh (home-booking-board.blade.php) gọi hàm này thay vì
            // carouselNav().prev()/next() thuần cuộn — bấm prev/next giờ tự động active luôn chi
            // nhánh liền trước/sau (không chỉ cuộn cho xem), đồng thời cuộn thẻ đó vào giữa khung
            // nhìn. Dùng document.querySelector() (DOM thường, không phải this.$el/$nextTick của
            // Alpine) vì nút prev/next nằm trong x-data="carouselNav()" lồng bên trong — magic
            // $el/$nextTick của Alpine phân giải theo x-data GẦN NHẤT tại chỗ directive được viết
            // (tức carouselNav, không phải homeBookingBoard nơi stepBranch được định nghĩa), nên
            // gọi qua nút bấm thực tế bị lệch scope và không cuộn (đã xác nhận bằng test: gọi thẳng
            // qua Alpine.$data() thì chạy đúng, còn bấm nút thật thì scrollLeft luôn đứng yên ở 0).
            // Các thẻ chi nhánh luôn có sẵn trong DOM (x-for không gỡ bỏ khi đổi active), nên không
            // cần chờ tick nào cả — query và cuộn ngay trong cùng lệnh gọi đồng bộ.
            stepBranch(dir) {
                if (!this.branches.length) return;
                const idx = this.branches.findIndex(b => b.slug === this.activeBranchSlug);
                const newIdx = Math.max(0, Math.min(this.branches.length - 1, (idx === -1 ? 0 : idx) + dir));
                const target = this.branches[newIdx];
                if (!target) return;
                this.selectBranch(target);
                const el = document.querySelector('[data-branch-slug="' + target.slug + '"]');
                if (el) el.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
            },
        };
    };
}
