// Trang tìm kiếm phòng (/s/{location}) — tải kết quả qua GraphQL (POST /api/graphql, pilot
// Automatic Persisted Queries — xem crystalline-toasting-sunrise.md) bằng JS thuần thay vì
// Livewire, giống cách trang chủ đang dùng /api/v1/home + home-sections.js. Card
// phòng tái dùng thẳng window.roomCardHtml() (đã có sẵn nút yêu thích) để đồng nhất giao diện
// với trang chủ. Mỗi phòng thuộc về 1 chi nhánh riêng — được API gắn kèm (room.branch) — nên ở
// đây nhóm lại theo chi nhánh, mỗi chi nhánh 1 dòng riêng, bên dưới là các card phòng của chi
// nhánh đó.
(function () {
    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function parseLocationFromPath() {
        var m = window.location.pathname.match(/^\/s\/([^\/?#]+)/);
        return m ? decodeURIComponent(m[1]) : '';
    }

    // Field kiểu Int trong SearchFiltersInput (xem app/GraphQL/SearchSchema.php) — GraphQL ép kiểu
    // chặt, string "2" bị từ chối (khác REST tự nới lỏng), nên phải Number() trước khi gửi.
    var INT_FILTER_KEYS = ['adults', 'month', 'year', 'ward_code', 'province_id', 'page', 'per_page'];

    function buildSearchFilters() {
        var params = new URLSearchParams(window.location.search);
        var filters = {};
        var location = parseLocationFromPath();
        if (location) filters.province = location;
        ['type', 'buoi', 'overnight', 'checkin', 'checkout', 'time_type', 'date', 'time_from', 'time_to', 'adults'].forEach(function (key) {
            var v = params.get(key);
            if (!v) return;
            filters[key] = INT_FILTER_KEYS.indexOf(key) !== -1 ? parseInt(v, 10) : v;
        });
        // per_page=100 (tối đa API cho phép) — trang tìm kiếm không phân trang, hiển thị hết
        // phòng phù hợp trong 1 tỉnh/chi nhánh giống trải nghiệm cũ.
        filters.per_page = 100;
        return filters;
    }

    // ─── Pilot GraphQL + Automatic Persisted Queries (APQ) ─────────────────────
    // Chỉ trang này (kết quả tìm kiếm) dùng GraphQL — xem
    // /Users/nitert/.claude/plans/crystalline-toasting-sunrise.md để biết bối cảnh. Query cố
    // định, không đổi lúc chạy, nên hash SHA-256 được tính sẵn 1 lần (không cần Web Crypto API).
    // Nếu sửa SEARCH_QUERY, phải tính lại hash: python3 -c "import hashlib; print(hashlib.sha256('<query>'.encode()).hexdigest())"
    var SEARCH_QUERY = 'query SearchRooms($filters: SearchFiltersInput) { search(filters: $filters) { data { id slug name thumbnail_url thumbnail { thumb card wide full width height } room_style badge { label type bg_color text_color } price { amount unit_label } rating wishlist_status is_available latitude longitude address branch { id name slug } distance } meta { current_page last_page per_page total province_name type_name } } }';
    var SEARCH_QUERY_HASH = '9f56302ac2ce1299109233eff90c709b2139de575d20e8fccc48255b109c0105';

    async function graphqlRequest(body, headers) {
        var res = await fetch('/api/graphql', {
            method: 'POST',
            headers: Object.assign({ 'Content-Type': 'application/json' }, headers),
            body: JSON.stringify(body),
        });
        return res.json();
    }

    // Gửi hash trước (payload nhỏ); nếu server chưa cache được query (PersistedQueryNotFound)
    // thì gửi lại 1 lần kèm query text đầy đủ, giống Apollo Automatic Persisted Queries.
    async function graphqlSearch(filters, headers) {
        var json = await graphqlRequest({
            variables: { filters: filters },
            extensions: { persistedQuery: { version: 1, sha256Hash: SEARCH_QUERY_HASH } },
        }, headers);

        var notFound = json.errors && json.errors.some(function (e) { return e.message === 'PersistedQueryNotFound'; });
        if (notFound) {
            json = await graphqlRequest({
                query: SEARCH_QUERY,
                variables: { filters: filters },
                extensions: { persistedQuery: { version: 1, sha256Hash: SEARCH_QUERY_HASH } },
            }, headers);
        }

        return {
            data: (json.data && json.data.search && json.data.search.data) || [],
            meta: (json.data && json.data.search && json.data.search.meta) || {},
        };
    }

    // ?view=branches (từ "Xem tất cả" của block Gợi ý điểm đến loại Chi nhánh) — liệt kê chi
    // nhánh của khu vực thay vì phòng.
    function isBranchesView() {
        return new URLSearchParams(window.location.search).get('view') === 'branches';
    }

    function buildBranchesApiUrl() {
        var apiParams = new URLSearchParams();
        var location = parseLocationFromPath();
        if (location) apiParams.set('province', location);
        return '/api/v1/search/branches?' + apiParams.toString();
    }

    // Skeleton hiện ngay khi bắt đầu tải /api/v1/search/branches (trước đây #search-results-body
    // để trống trơn cho tới khi fetch xong) — dùng đúng khung .branches-track/.home-card như card
    // thật (CSS shimmer .branch-skel* ở search.blade.php) nên không giật layout khi thay bằng
    // renderBranchesResults(). Số lượng 4 card ~ khớp 2 hàng đầu tiên của lưới 2 cột.
    function renderBranchesSkeleton() {
        var card = '<div class="home-card" style="display:flex; flex-direction:column; gap:8px;">'
            + '<div class="branch-skel branch-skel-img"></div>'
            + '<div class="branch-skel" style="height:13px; width:70%;"></div>'
            + '</div>';
        return '<div class="branches-track">' + new Array(4).fill(card).join('') + '</div>';
    }

    function renderBranchesResults(branches) {
        if (!branches.length) {
            return '<div style="padding:3rem 1rem;text-align:center;">'
                + '<svg style="width:48px;height:48px;color:#d1d5db;margin:0 auto 12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">'
                + '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'
                + '</svg>'
                + '<p style="color:#6b7280;font-size:14px;margin:0;">Không tìm thấy chi nhánh nào phù hợp.</p>'
                + '</div>';
        }

        var cards = branches.map(function (branch) {
            return window.branchCardHtml(branch);
        }).join('');

        // .branches-track = nền chung kiểu segmented control (CSS ở search.blade.php quyết định
        // layout lưới + trạng thái active/inactive của từng card bên trong).
        return '<div class="branches-track">' + cards + '</div>';
    }

    // Bấm 1 card chi nhánh (panel trái/bottom-sheet) — KHÔNG điều hướng ngay nữa. Thay vào đó pan
    // bản đồ bên phải tới đúng khu vực chi nhánh đó và mở popup (ảnh + nút "Xem chi tiết →" mới
    // thật sự điều hướng sang /branch/{slug}, xem window.__panToBranch ở search.blade.php). Ở
    // mobile, sheet đang ở trạng thái "full" (94%) thì che gần hết bản đồ — thu về "peek" trước
    // để người dùng thấy được popup vừa mở.
    function slugFromBranchCard(cardEl) {
        try {
            return decodeURIComponent(new URL(cardEl.href, window.location.origin).pathname.replace(/^\/branch\//, ''));
        } catch (err) {
            return '';
        }
    }

    document.addEventListener('click', function (e) {
        if (!isBranchesView()) return;
        var card = e.target.closest('#search-results-body a.home-card');
        if (!card) return;
        e.preventDefault();

        var slug = slugFromBranchCard(card);
        if (!slug) return;

        if (window.innerWidth < 768 && typeof window.__setSheetState === 'function') {
            window.__setSheetState('peek');
        }
        if (typeof window.__panToBranch === 'function') {
            window.__panToBranch(slug);
        }
    });

    function chipHtml(label, tone) {
        var colors = tone === 'muted'
            ? 'color:#6b7280;background:#f9fafb;border:1px solid #e5e7eb;'
            : 'color:#0f766e;background:#f0fdf4;border:1px solid #bbf7d0;';
        return '<span style="display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;' + colors + 'padding:3px 10px;border-radius:99px;">' + escapeHtml(label) + '</span>';
    }

    function renderBranchesHeader(meta) {
        var total = meta.total || 0;
        var title = meta.province_name
            ? total + ' chi nhánh tại ' + meta.province_name
            : 'Không tìm thấy chi nhánh nào phù hợp.';

        return '<p class="search-count-title"' + (meta.province_name ? '' : ' style="font-weight:500;color:#6b7280;"') + '>' + escapeHtml(title) + '</p>';
    }

    function renderHeader(meta, params) {
        var total = meta.total || 0;
        var typeParam = params.get('type');
        var title = meta.province_name
            ? total + ' phòng tại ' + meta.province_name
            : (total > 0 ? (typeParam === 'homestay' ? total + ' phòng' : total + ' phòng trên toàn quốc') : '');
        var titleHtml = title
            ? '<p class="search-count-title">' + escapeHtml(title) + '</p>'
            : '<p class="search-count-title" style="font-weight:500;color:#6b7280;">Không tìm thấy phòng nào phù hợp.</p>';

        var chips = '';
        var buoi = params.get('buoi');
        var typeParam = params.get('type');
        if (buoi === '1') chips += chipHtml('Theo giờ');
        else if (buoi === '2') chips += chipHtml('Theo ngày');
        if (meta.type_name && typeParam !== 'homestay') chips += chipHtml(meta.type_name);
        var checkin = params.get('checkin');
        var checkout = params.get('checkout');
        if (checkin) chips += chipHtml(checkin + (checkout ? ' → ' + checkout : ''), 'muted');

        var chipsHtml = chips ? '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:7px;">' + chips + '</div>' : '';

        return titleHtml + chipsHtml;
    }

    // Skeleton hiện ngay khi bắt đầu gọi GraphQL search phòng (trước đây #search-results-body để
    // trống trơn cho tới khi fetch xong) — dùng đúng khung .branch-grid/.branch-card như card
    // thật (CSS shimmer .search-skel* ở search.blade.php) nên không giật layout khi thay bằng
    // renderResults(). 2 "hàng" giả lập kiểu nhóm-theo-chi-nhánh thật (không biết trước sẽ có bao
    // nhiêu chi nhánh/phòng nên chỉ dựng khung chung chung).
    function renderSearchSkeleton() {
        var card = '<div class="branch-card"><div class="home-card" style="display:flex; flex-direction:column; gap:8px;">'
            + '<div class="search-skel search-skel-img"></div>'
            + '<div class="search-skel" style="height:13px; width:85%;"></div>'
            + '<div class="search-skel" style="height:13px; width:45%;"></div>'
            + '</div></div>';
        var row = '<div class="room-slider" style="margin-bottom:4px;"><div class="branch-grid">' + new Array(4).fill(card).join('') + '</div></div>';
        return row + row;
    }

    function roomTimeLabel(room) {
        var label = room.price && room.price.unit_label ? room.price.unit_label : '';
        return label.replace(/^\/\s*/, '');
    }

    function branchHeaderHtml(branch, count) {
        return '<div style="padding:14px 4px 8px;">'
            + '<a href="/branch/' + encodeURIComponent(branch.slug) + '" style="display:inline-flex;align-items:center;gap:5px;text-decoration:none;">'
            + '<span style="font-size:14px;font-weight:700;color:#111827;">' + escapeHtml(branch.name) + '</span>'
            + '<span style="font-size:12px;font-weight:500;color:#9ca3af;">(' + count + ')</span>'
            + '</a>'
            + '</div>';
    }

    function roomsGridHtml(rooms) {
        var cards = rooms.map(function (room) {
            return '<div class="branch-card">' + window.roomCardHtml(room) + '</div>';
        }).join('');

        return '<div class="room-slider" style="margin-bottom:4px;">'
            + '<div class="branch-grid">' + cards + '</div>'
            + '<button type="button" class="room-slider-nav room-slider-prev" aria-label="Phòng trước" onclick="window.__roomSliderNav(this, -1)">'
            + '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>'
            + '</button>'
            + '<button type="button" class="room-slider-nav room-slider-next" aria-label="Phòng tiếp theo" onclick="window.__roomSliderNav(this, 1)">'
            + '<svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>'
            + '</button>'
            + '</div>';
    }

    // Nhóm theo chi nhánh (room.branch.id) — phòng không có chi nhánh (tìm kiếm toàn quốc, không
    // xác định được tỉnh) gom vào 1 nhóm chung, hiển thị phẳng không có dòng tiêu đề. Trả về danh
    // sách phòng ĐÃ THEO ĐÚNG THỨ TỰ HIỂN THỊ TRÊN DOM (nhóm theo chi nhánh làm xáo trộn thứ tự
    // gốc) — dùng chung cho cả renderResults() và buildMapData() để 2 bên luôn khớp vị trí nhau
    // (initSearchMap() ghép .branch-card với dữ liệu bản đồ theo index).
    function groupRoomsByBranch(rooms) {
        var branchOrder = [];
        var branchGroups = {};
        var noBranchRooms = [];

        rooms.forEach(function (room) {
            if (!room.branch) {
                noBranchRooms.push(room);
                return;
            }
            var key = room.branch.id;
            if (!branchGroups[key]) {
                branchGroups[key] = { branch: room.branch, rooms: [] };
                branchOrder.push(key);
            }
            branchGroups[key].rooms.push(room);
        });

        var orderedGroups = branchOrder.map(function (key) { return branchGroups[key]; });
        var orderedRooms = orderedGroups.reduce(function (acc, group) { return acc.concat(group.rooms); }, []).concat(noBranchRooms);

        return { groups: orderedGroups, noBranchRooms: noBranchRooms, orderedRooms: orderedRooms };
    }

    function renderResults(grouped) {
        if (!grouped.orderedRooms.length) {
            return '<div style="padding:3rem 1rem;text-align:center;">'
                + '<svg style="width:48px;height:48px;color:#d1d5db;margin:0 auto 12px;" fill="none" stroke="currentColor" viewBox="0 0 24 24">'
                + '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'
                + '</svg>'
                + '<p style="color:#6b7280;font-size:14px;margin:0;">Không tìm thấy phòng nào phù hợp.</p>'
                + '</div>';
        }

        var html = '';
        grouped.groups.forEach(function (group) {
            html += branchHeaderHtml(group.branch, group.rooms.length) + roomsGridHtml(group.rooms);
        });
        if (grouped.noBranchRooms.length) {
            html += roomsGridHtml(grouped.noBranchRooms);
        }

        return html;
    }

    // Giữ nguyên vị trí kể cả khi phòng không có toạ độ (lat/lng null) — initSearchMap() tự bỏ
    // qua pin cho phần tử không có toạ độ nhưng vẫn cần đúng index để khớp với .branch-card.
    function buildMapData(orderedRooms) {
        return orderedRooms.map(function (room) {
            return {
                slug: room.slug,
                name: room.name,
                image: room.thumbnail_url,
                price: room.price ? room.price.amount : null,
                time: roomTimeLabel(room),
                url: '/room/' + room.slug + '/',
                lat: room.latitude || null,
                lng: room.longitude || null,
                address: room.address,
            };
        });
    }

    function reinitMap() {
        var mapEl = document.getElementById('search-map');
        if (mapEl && mapEl._leaflet_id && window.__searchMapInstance) {
            window.__searchMapInstance.remove();
            window.__searchMapInstance = null;
        }
        window.__activeBranchCard = null;
        window.__popups = {};
        if (typeof window.initSearchMap === 'function') {
            window.initSearchMap();
        }
    }

    async function loadSearchResults() {
        var mount = document.getElementById('search-results-mount');
        if (!mount) return;

        var headerEl = mount.querySelector('.search-count-header');
        var bodyEl = mount.querySelector('#search-results-body');
        if (headerEl) headerEl.innerHTML = '<p class="search-count-title" style="font-weight:500;color:#6b7280;">Đang tải kết quả...</p>';

        var params = new URLSearchParams(window.location.search);
        var token = localStorage.getItem('auth_token');
        var headers = { Accept: 'application/json' };
        if (token) headers.Authorization = 'Bearer ' + token;

        // ?view=branches — liệt kê chi nhánh của khu vực (từ "Gợi ý điểm đến" loại Chi nhánh),
        // không cần bản đồ/slider phòng như luồng tìm phòng thông thường bên dưới.
        if (isBranchesView()) {
            if (bodyEl) bodyEl.innerHTML = renderBranchesSkeleton();
            var branches = [];
            var branchMeta = {};
            try {
                var bRes = await fetch(buildBranchesApiUrl(), { headers: headers });
                var bJson = await bRes.json();
                branches = bJson.data || [];
                branchMeta = bJson.meta || {};
            } catch (e) {
                console.error('branches fetch error', e);
            }
            if (headerEl) headerEl.innerHTML = renderBranchesHeader(branchMeta);
            if (bodyEl) bodyEl.innerHTML = renderBranchesResults(branches);

            // Panel bên phải luôn là bản đồ ở đây (xem search.blade.php) — ghim mỗi chi nhánh
            // (kèm latitude/longitude từ API /v1/search/branches) thay vì bản đồ phòng thường.
            if (typeof window.initBranchesMap === 'function') {
                window.initBranchesMap(branches);
            }
            return;
        }

        if (bodyEl) bodyEl.innerHTML = renderSearchSkeleton();

        var rooms = [];
        var meta = {};
        try {
            var result = await graphqlSearch(buildSearchFilters(), headers);
            rooms = result.data;
            meta = result.meta;
        } catch (e) {
            console.error('search results fetch error', e);
        }

        var grouped = groupRoomsByBranch(rooms);

        if (headerEl) headerEl.innerHTML = renderHeader(meta, params);
        if (bodyEl) bodyEl.innerHTML = renderResults(grouped);

        var mapDataEl = document.getElementById('branch-map-data');
        if (mapDataEl) mapDataEl.textContent = JSON.stringify(buildMapData(grouped.orderedRooms));

        reinitMap();
        if (typeof window.__roomSliderInit === 'function') window.__roomSliderInit();
    }

    // Khi vào trang qua Livewire.navigate() (SPA — bấm nút Tìm kiếm ở hero-section, không phải
    // load trang cứng), Livewire chèn lại các <script src> trong <body> (không có data-navigate-
    // track/defer) và fetch/thực thi CHÚNG BẤT ĐỒNG BỘ, nhưng lại bắn sự kiện 'livewire:navigated'
    // gần như ngay sau khi swap xong DOM — KHÔNG đợi script này tải/chạy xong. Kết quả: sự kiện
    // 'livewire:navigated' bên dưới thường bắn ra TRƯỚC KHI dòng addEventListener kịp đăng ký,
    // nên loadSearchResults() không bao giờ được gọi — trang kẹt mãi ở "Đang tải kết quả...".
    // Sửa bằng cách gọi thẳng loadSearchResults() ngay khi script này thực thi (không đợi sự
    // kiện nào) nếu DOM đã sẵn sàng — đúng cả 2 trường hợp: script chạy sau khi DOM đã dựng xong
    // (SPA nav, hoặc <script> đặt cuối trang khi load cứng) thì gọi ngay; script chạy TRƯỚC khi
    // DOM dựng xong (readyState vẫn 'loading', hiếm khi xảy ra với script cuối body) thì đợi
    // DOMContentLoaded như cũ. Vẫn giữ listener 'livewire:navigated' làm lớp phòng hộ thêm — gọi
    // lại loadSearchResults() không hại gì (idempotent, chỉ fetch + render lại).
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadSearchResults);
    } else {
        loadSearchResults();
    }
    document.addEventListener('livewire:navigated', loadSearchResults);

    // Lớp phòng hộ thứ 2, độc lập hẳn với vòng đời sự kiện Livewire: đã quan sát được
    // 'livewire:navigated' KHÔNG bắn lại mỗi lần điều hướng nếu 2 URL cùng route /s/{location?}
    // (chỉ khác query string) — Livewire có thể coi đây là "cùng trang", không tính là 1 lần
    // "navigated" đầy đủ. Thay vì phụ thuộc sự kiện, theo dõi trực tiếp window.location.href mỗi
    // khi DOM có thay đổi (MutationObserver trên toàn document.body) — hễ URL đổi so với lần gọi
    // gần nhất thì gọi lại loadSearchResults() ngay, bất kể do script này re-run, do sự kiện
    // Livewire, hay do bất kỳ cơ chế nào khác. Đăng ký đúng 1 lần (bám vào window, không phải
    // closure của IIFE) để tồn tại xuyên suốt phiên trang, không bị mất dù script có re-run hay
    // không ở các lần điều hướng sau.
    if (typeof window.__searchResultsUrlWatcherBound === 'undefined') {
        window.__searchResultsUrlWatcherBound = true;
        var lastSearchUrl = window.location.href;
        var checkSearchUrlChange = function () {
            if (window.location.pathname.indexOf('/s/') !== 0 && window.location.pathname !== '/s') return;
            if (window.location.href === lastSearchUrl) return;
            lastSearchUrl = window.location.href;
            if (typeof window.__loadSearchResults === 'function') window.__loadSearchResults();
        };
        new MutationObserver(checkSearchUrlChange).observe(document.documentElement, { childList: true, subtree: true });
        window.addEventListener('popstate', checkSearchUrlChange);
    }
    // window.__loadSearchResults luôn trỏ về bản loadSearchResults MỚI NHẤT của lần script này
    // chạy gần nhất — quan trọng vì mỗi lần <script src> được Livewire chèn lại, IIFE tạo closure
    // mới, còn MutationObserver ở trên chỉ đăng ký 1 lần duy nhất và gọi qua window nên luôn cần
    // trỏ tới bản hàm hiện hành (không giữ tham chiếu cứng tới closure cũ, đã có thể lỗi thời).
    window.__loadSearchResults = loadSearchResults;

    // ─── Nút prev/next cho slide ngang trên mobile (thay cho vuốt tay) ─────────────
    if (typeof window.__roomSliderNav === 'undefined') {
        window.__roomSliderNav = function (btn, dir) {
            var grid = btn.closest('.room-slider')?.querySelector('.branch-grid');
            if (!grid) return;
            grid.scrollBy({ left: dir * grid.clientWidth, behavior: 'smooth' });
        };

        window.__roomSliderUpdateNavState = function (slider) {
            var grid = slider.querySelector('.branch-grid');
            var prev = slider.querySelector('.room-slider-prev');
            var next = slider.querySelector('.room-slider-next');
            if (!grid || !prev || !next) return;
            var max = grid.scrollWidth - grid.clientWidth;
            prev.disabled = grid.scrollLeft <= 4;
            next.disabled = max <= 4 || grid.scrollLeft >= max - 4;
        };

        window.__roomSliderInit = function () {
            document.querySelectorAll('.room-slider').forEach(function (slider) {
                if (slider.__sliderBound) return;
                slider.__sliderBound = true;
                var grid = slider.querySelector('.branch-grid');
                if (!grid) return;
                window.__roomSliderUpdateNavState(slider);
                grid.addEventListener('scroll', function () {
                    window.__roomSliderUpdateNavState(slider);
                }, { passive: true });
                window.addEventListener('resize', function () {
                    window.__roomSliderUpdateNavState(slider);
                });
            });
        };
    }
})();
