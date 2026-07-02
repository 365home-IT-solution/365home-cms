if (typeof window.__homeVnd === 'undefined') {
    window.__homeVnd = function (amount) {
        return Number(amount || 0).toLocaleString('vi-VN') + 'đ';
    };
}

if (typeof window.__homeToggleWishlist === 'undefined') {
    window.__homeToggleWishlist = function (productId, iconEl) {
        const token = localStorage.getItem('auth_token');
        if (!token) {
            window.dispatchEvent(new CustomEvent('open-auth-modal'));
            return;
        }
        const filled = iconEl.getAttribute('data-active') === '1';
        iconEl.setAttribute('data-active', filled ? '0' : '1');
        iconEl.style.color = filled ? '#9ca3af' : '#ef4444';

        fetch('/api/wishlist/' + productId + '/toggle', {
            method: 'POST',
            headers: {
                'Authorization': 'Bearer ' + token,
                'Accept': 'application/json',
            },
        }).catch(() => {
            iconEl.setAttribute('data-active', filled ? '1' : '0');
            iconEl.style.color = filled ? '#ef4444' : '#9ca3af';
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

        const badgeHtml = room.badge && room.badge.label
            ? '<div style="position:absolute; top:8px; left:8px; background:' + (room.badge.bg_color || '#ef4444') + '; color:' + (room.badge.text_color || '#fff') + '; font-size:10px; font-weight:800; padding:3px 9px; border-radius:99px; z-index:2;">' + room.badge.label + '</div>'
            : '';

        const unavailableHtml = room.is_available === false
            ? '<div style="position:absolute; inset:0; background:rgba(255,255,255,.55); display:flex; align-items:center; justify-content:center; z-index:2;">'
                + '<span style="background:#111827; color:#fff; font-size:11px; font-weight:700; padding:4px 12px; border-radius:99px;">Tạm hết phòng</span></div>'
            : '';

        const wishlistHtml = (room.wishlist_status === true || room.wishlist_status === false)
            ? '<button type="button" onclick="event.preventDefault(); event.stopPropagation(); window.__homeToggleWishlist(\'' + room.id + '\', this.firstElementChild)"'
                + ' style="position:absolute; top:6px; right:6px; z-index:3; width:28px; height:28px; border-radius:50%; background:rgba(255,255,255,.85); border:none; display:flex; align-items:center; justify-content:center; cursor:pointer;">'
                + '<svg data-active="' + (room.wishlist_status ? '1' : '0') + '" style="width:16px;height:16px; color:' + (room.wishlist_status ? '#ef4444' : '#9ca3af') + ';" viewBox="0 0 20 20" fill="currentColor"><path d="M9.653 16.915l-.005-.003-.019-.01a20.759 20.759 0 01-1.162-.682 22.045 22.045 0 01-2.582-1.9C4.045 12.733 2 10.352 2 7.5 2 5.015 4.015 3 6.5 3c1.256 0 2.371.514 3.5 1.51C11.129 3.514 12.244 3 13.5 3 15.985 3 18 5.015 18 7.5c0 2.852-2.044 5.233-3.885 6.82a22.049 22.049 0 01-3.744 2.582l-.019.01-.005.003h-.001a.752.752 0 01-.696 0h-.001z"/></svg>'
                + '</button>'
            : '';

        return '<a href="' + href + '" style="position:relative; flex:0 0 190px; scroll-snap-align:start; display:flex; flex-direction:column; gap:8px; text-decoration:none;">'
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

        return '<a href="' + href + '" style="flex:0 0 190px; scroll-snap-align:start; display:flex; flex-direction:column; gap:8px; text-decoration:none;">'
            + '<div style="position:relative; padding-top:72%; overflow:hidden; background:#f3f4f6; border-radius:14px; flex-shrink:0;">'
            + (img ? '<img src="' + img + '" alt="" loading="lazy" style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover;">' : '')
            + '</div>'
            + '<p style="font-size:13px; font-weight:600; color:#111827; margin:0; overflow:hidden; white-space:nowrap; text-overflow:ellipsis;">' + (branch.name || '') + '</p>'
            + '</a>';
    };
}

if (typeof window.homeSections === 'undefined') {
    window.homeSections = function () {
        return {
            sections: [],
            loading: true,

            init() {
                this.load();
                window.addEventListener('auth-state-changed', () => this.load());
            },

            load() {
                this.loading = true;
                const token = localStorage.getItem('auth_token');
                const headers = { 'Accept': 'application/json' };
                if (token) headers['Authorization'] = 'Bearer ' + token;

                fetch('/api/v1/home', { headers })
                    .then(res => res.json())
                    .then(data => {
                        const allowed = ['promotion_list', 'suggestion_list', 'room_list'];
                        this.sections = (data?.home?.sections || [])
                            .filter(s => allowed.includes(s.type))
                            .sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0));
                    })
                    .catch(() => { this.sections = []; })
                    .finally(() => { this.loading = false; });
            },
        };
    };
}
