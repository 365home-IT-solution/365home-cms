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

if (typeof window.roomCardHtml === 'undefined') {
    window.roomCardHtml = function (room) {
        const img = room.thumbnail_url || room.image_url || '';
        const href = '/room/' + room.slug + '/';
        const priceHtml = room.price
            ? '<span style="font-weight:700;">' + window.__homeVnd(room.price.amount) + '</span>'
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
            + (img ? '<img src="' + img + '" alt="" loading="lazy" style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">' : '')
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
    window.branchCardHtml = function (branch) {
        const img = branch.image_url || '';
        const href = '/branch/' + branch.slug;

        return '<a href="' + href + '" class="home-card" style="scroll-snap-align:start; display:flex; flex-direction:column; gap:8px; text-decoration:none;">'
            + '<div style="position:relative; padding-top:72%; overflow:hidden; background:#f3f4f6; border-radius:14px; flex-shrink:0;">'
            + (img ? '<img src="' + img + '" alt="" loading="lazy" style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">' : '')
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

            init() {
                this.$nextTick(() => {
                    const el = this.$refs.track;
                    if (!el) return;
                    this.check();
                    el.addEventListener('scroll', () => this.check(), { passive: true });
                    window.addEventListener('resize', () => this.check());
                    new MutationObserver(() => this.check()).observe(el, { childList: true });
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

if (typeof window.homeSections === 'undefined') {
    window.homeSections = function () {
        return {
            sections: [],
            roomTypes: [],
            loading: true,
            provinceName: localStorage.getItem('home_province_name') || '',

            init() {
                this.load();
                window.addEventListener('auth-state-changed', () => this.load());
                window.addEventListener('province-selected', (e) => {
                    this.provinceName = e.detail?.name || localStorage.getItem('home_province_name') || '';
                    this.load();
                });
            },

            load() {
                this.loading = true;
                const token = localStorage.getItem('auth_token');
                const provinceId = localStorage.getItem('home_province_id');
                const headers = { 'Accept': 'application/json' };
                if (token) headers['Authorization'] = 'Bearer ' + token;

                const url = '/api/v1/home' + (provinceId ? '?province_id=' + encodeURIComponent(provinceId) : '');

                fetch(url, { headers })
                    .then(res => res.json())
                    .then(data => {
                        const allowed = ['banner', 'promotion_list', 'suggestion_list', 'room_list'];
                        this.sections = (data?.home?.sections || [])
                            .filter(s => allowed.includes(s.type))
                            .sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0));
                        this.roomTypes = data?.home?.room_types || [];
                    })
                    .catch(() => { this.sections = []; this.roomTypes = []; })
                    .finally(() => { this.loading = false; });
            },
        };
    };
}
