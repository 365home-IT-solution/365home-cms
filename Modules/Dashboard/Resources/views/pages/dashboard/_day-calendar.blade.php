{{-- View "Lịch" › "Theo ngày" — lịch tháng của từng phòng ĐẶT THEO NGÀY (styles=2) trong chi nhánh đang xem, cùng kiểu
     lịch phía khách (BladeThemeV1 livewire/book/_daily-cards.blade.php + window.dailyRoomCalendar() trong
     public/js/home-sections.js — cách tính giá từng đêm giữ nguyên), thêm phần admin: đơn ở từng đêm (bấm mở popup đơn),
     mốc nhận/trả phòng, công suất tháng, khoá / mở khoá khoảng ngày và mở trang tạo đơn với đúng khoảng ngày đã chọn.
     Dữ liệu: GET /admin/api/daily-calendar (Modules\Dashboard\App\Services\DailyCalendarService) — tải theo chi nhánh,
     không nằm trong poll room-cards vì tính giá ~400 ngày mỗi phòng. Khung chung (carousel chi nhánh/phòng, popup đơn,
     popup Thống kê) dùng lại của chế độ "Khung giờ" trong _scripts.blade.php.
     Script bọc IIFE như các @script khác của Dashboard: Livewire chạy nội dung qua Alpine.evaluate(), mở đầu bằng var/function
     sẽ lỗi cú pháp. --}}
@script
<script>
(function() {
var RC_DAY_MODE_KEY = 'rc_cal_mode';
var rcDayToday = new Date();
rcDayToday.setHours(0, 0, 0, 0);

window._rcDayMonth   = window._rcDayMonth || { y: rcDayToday.getFullYear(), m: rcDayToday.getMonth() };
window._rcDayCache   = window._rcDayCache || {};
window._rcDaySel     = window._rcDaySel || { pid: null, start: null, end: null };

function rcDayIso(y, m, d) {
    return y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
}

function rcDayAddDays(iso, n) {
    var d = new Date(iso + 'T00:00:00');
    d.setDate(d.getDate() + n);
    return rcDayIso(d.getFullYear(), d.getMonth(), d.getDate());
}

function rcDayFmt(iso) {
    var p = iso.split('-');
    return p[2] + '/' + p[1];
}

function rcDayVnd(n) {
    return Number(n || 0).toLocaleString('vi-VN') + 'đ';
}

// Icon nhận phòng (mũi tên vào cửa) / trả phòng (mũi tên ra khỏi cửa) — Heroicons arrow-right-end/start-on-rectangle.
var RC_DAY_ICON_IN  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15M12 9l3 3m0 0-3 3m3-3H2.25"/></svg>';
var RC_DAY_ICON_OUT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"/></svg>';
var RC_DAY_ICON_LOCK = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>';
window.RC_DAY_ICON_IN = RC_DAY_ICON_IN;
window.RC_DAY_ICON_OUT = RC_DAY_ICON_OUT;

function rcDayInRanges(iso, ranges) {
    return (ranges || []).some(function(r) { return iso >= r.start && iso <= r.end; });
}

function rcDayNotify(message, ok) {
    if (window.FilamentNotification) {
        var n = new window.FilamentNotification().title(message);
        (ok ? n.success() : n.danger()).send();
    } else {
        window.alert(message);
    }
}

// Cùng công thức priceFor() của window.dailyRoomCalendar() phía khách: giá ngày đặc biệt > giá bảng giá theo thứ > giá
// theo thứ > giá gốc, trừ % khuyến mãi, cộng phụ thu.
function rcDayPrice(cal, iso) {
    var base;
    if (Object.prototype.hasOwnProperty.call(cal.datePrices || {}, iso) && cal.datePrices[iso] !== null) {
        base = cal.datePrices[iso];
    } else if (Object.prototype.hasOwnProperty.call(cal.stayPrices || {}, iso)) {
        base = cal.stayPrices[iso];
    } else {
        var dow = new Date(iso + 'T00:00:00').getDay();
        base = (cal.dayPrices || {})[dow] > 0 ? cal.dayPrices[dow] : Number(cal.pricePerNight || 0);
    }
    var surcharge = ((cal.surchargeData || {})[iso] || []).reduce(function(sum, s) {
        return sum + (s.type === 'increase_percentage' ? Math.round(base * s.value / 100) : Math.round(s.value));
    }, 0);
    var disc = (cal.promoDiscounts || {})[iso] || 0;
    return {
        base: base + surcharge,
        final: (disc > 0 ? Math.round(base * (1 - disc / 100)) : base) + surcharge,
        promo: disc > 0 || !!(cal.promoLabels || {})[iso],
    };
}

// Trạng thái 1 ngày của 1 phòng. Phòng đơn: ngày bị chiếm tính cả ngày trả phòng của lượt trước — đúng luật
// OrderForm::getOccupiedDaysForProduct() (trang tạo đơn sẽ từ chối nếu chọn trùng), trừ hôm nay đã qua giờ trả phòng.
// Biến thể: chỉ đêm đã hết sạch phòng (calendar.bookedRanges đã tính sẵn ở server).
function rcDayState(room, iso, todayIso) {
    var cal       = room.calendar || {};
    var orders    = (room.nights || {})[iso] || [];
    var checkouts = (room.checkouts || {})[iso] || 0;
    var fullBlock = rcDayInRanges(iso, cal.adminBlockedRanges);
    var partial   = (room.blocked_ranges || []).filter(function(r) {
        return room.is_variant && r.rooms && r.rooms < room.capacity && iso >= r.start && iso <= r.end;
    }).reduce(function(s, r) { return s + Number(r.rooms); }, 0);

    var occupied;
    if (room.is_variant) {
        occupied = rcDayInRanges(iso, cal.bookedRanges);
    } else {
        var checkoutPassed = iso === todayIso && checkouts > 0 && !orders.length
            && new Date().toTimeString().slice(0, 5) >= (room.checkout_time || '12:00');
        occupied = orders.length > 0 || (checkouts > 0 && !checkoutPassed);
    }

    var used = orders.reduce(function(s, o) { return s + (o.quantity || 1); }, 0);

    return {
        orders: orders,
        checkouts: checkouts,
        checkins: orders.filter(function(o) { return o.checkin === iso; }).length,
        fullBlock: fullBlock,
        partial: partial,
        occupied: occupied,
        used: used,
        past: iso < todayIso,
        unavailable: occupied || fullBlock,
    };
}

// Khoảng nhận $start → trả $end có trống không — cùng luật crosses của lịch khách + luật ngày chiếm ở rcDayState().
function rcDayRangeOk(room, start, end, todayIso) {
    var last = room.is_variant ? rcDayAddDays(end, -1) : end;
    for (var i = start; i <= last; i = rcDayAddDays(i, 1)) {
        if (rcDayState(room, i, todayIso).occupied) return false;
    }
    var cal = room.calendar || {};
    return !(cal.adminBlockedRanges || []).some(function(r) { return r.start <= end && r.end >= start; });
}

function rcDayRoomById(pid) {
    var key   = window._rcDayCurrentKey;
    var cache = key && window._rcDayCache[key];
    if (!cache || !cache.data) return null;
    return (cache.data.rooms || []).find(function(r) { return String(r.product_id) === String(pid); }) || null;
}

// Bật giao diện của 1 chế độ (nút, chú thích, điều khiển tháng/số ngày) — không lưu lựa chọn.
window.rcCalApplyModeUi = function(mode) {
    window._rcCalMode = mode === 'day' ? 'day' : 'slot';
    document.querySelectorAll('#ta-rc-cal-mode-toggle .ta-rc-cal-mode-btn').forEach(function(b) {
        b.classList.toggle('active', b.dataset.mode === window._rcCalMode);
    });
    document.querySelectorAll('#ta-rc-cal-view [data-cal-mode]').forEach(function(el) {
        el.style.display = el.dataset.calMode === window._rcCalMode ? '' : 'none';
    });
    var wrap = document.getElementById('ta-rc-cal-grid-wrap');
    if (wrap) wrap.classList.toggle('is-day-mode', window._rcCalMode === 'day');
};

// Gọi từ rcCalRenderBranches() mỗi lần vẽ: ghi số phòng mỗi loại của chi nhánh đang chọn lên nút chuyển, và tự chuyển
// sang chế độ còn lại nếu chi nhánh không có phòng nào của chế độ đang xem (vd Cerasoi chỉ có phòng theo ngày).
window.rcCalSyncMode = function() {
    var counts = { slot: 0, day: 0 };
    (window.__rcRoomsData || []).forEach(function(r) {
        if (r.branch !== window._rcCalActiveBranch) return;
        if (r.styles === 2) counts.day++;
        else if (r.timeslot_grid && r.timeslot_grid.rows && r.timeslot_grid.rows.length) counts.slot++;
    });
    document.querySelectorAll('#ta-rc-cal-mode-toggle .ta-rc-cal-mode-btn').forEach(function(b) {
        var n = counts[b.dataset.mode];
        b.textContent = (b.dataset.mode === 'day' ? 'Theo ngày' : 'Khung giờ') + ' (' + n + ')';
        b.disabled = n === 0;
    });
    var other = window._rcCalMode === 'day' ? 'slot' : 'day';
    if (!counts[window._rcCalMode] && counts[other]) window.rcCalApplyModeUi(other);
};

window.rcCalSetMode = function(mode) {
    try { localStorage.setItem(RC_DAY_MODE_KEY, mode === 'day' ? 'day' : 'slot'); } catch (e) {}
    window.rcCalApplyModeUi(mode);
    window.rcCalRenderBranches();
    window.rcCalRenderGrid();
};

window.rcDayShiftMonth = function(dir) {
    var cur = window._rcDayMonth;
    if (dir === 0) {
        cur = { y: rcDayToday.getFullYear(), m: rcDayToday.getMonth() };
    } else {
        var d = new Date(cur.y, cur.m + dir, 1);
        // Dữ liệu đơn có từ đầu tháng trước tới 12 tháng sau (DailyCalendarService::occupancy()).
        var min = new Date(rcDayToday.getFullYear(), rcDayToday.getMonth() - 1, 1);
        var max = new Date(rcDayToday.getFullYear(), rcDayToday.getMonth() + 12, 1);
        if (d < min || d > max) return;
        cur = { y: d.getFullYear(), m: d.getMonth() };
    }
    window._rcDayMonth = cur;
    window.rcDayRender();
};

window.rcDayRender = function(force) {
    var wrap = document.getElementById('ta-rc-cal-grid-wrap');
    if (!wrap) return;

    var label = document.getElementById('ta-rc-day-month-label');
    if (label) {
        label.textContent = new Date(window._rcDayMonth.y, window._rcDayMonth.m, 1)
            .toLocaleDateString('vi-VN', { month: 'long', year: 'numeric' });
    }

    var branch = window._rcCalActiveBranch;
    var metas  = (window.__rcRoomsData || []).filter(function(r) { return r.branch === branch && r.styles === 2; });

    if (!metas.length) {
        wrap.innerHTML = '<div class="ta-rc-cal-empty">Chi nhánh này chưa có phòng đặt theo ngày để hiển thị.</div>';
        window.rcCalUpdateRoomsPageInfo(0);
        return;
    }

    var ids = metas.map(function(r) { return String(r.product_id); }).sort();
    var key = ids.join(',');
    window._rcDayCurrentKey = key;
    var cache = window._rcDayCache[key];

    // Poll room-cards (2 phút) gọi lại hàm này — tải lại lịch nếu dữ liệu đã cũ hơn 60 giây.
    var stale = !cache || force || (Date.now() - cache.at > 60000);
    if (stale && !(cache && cache.loading)) {
        window._rcDayCache[key] = { at: cache ? cache.at : 0, data: cache ? cache.data : null, loading: true };
        fetch('/admin/api/daily-calendar?' + ids.map(function(id) { return 'ids[]=' + encodeURIComponent(id); }).join('&'), { credentials: 'same-origin' })
            .then(function(r) { return r.ok ? r.json() : Promise.reject(); })
            .then(function(data) {
                window._rcDayCache[key] = { at: Date.now(), data: data, loading: false };
                if (window._rcCalMode === 'day' && window._rcDayCurrentKey === key) window.rcDayRender();
            })
            .catch(function() {
                window._rcDayCache[key] = { at: Date.now(), data: cache ? cache.data : null, loading: false };
                if (!cache || !cache.data) {
                    wrap.innerHTML = '<div class="ta-rc-cal-empty">Không tải được lịch theo ngày. <button type="button" class="ta-rc-cal-nav-btn" onclick="rcDayRender(true)">Thử lại</button></div>';
                }
            });
    }

    cache = window._rcDayCache[key];
    if (!cache || !cache.data) {
        wrap.innerHTML = '<div class="ta-rc-cal-empty">Đang tải lịch theo ngày...</div>';
        return;
    }

    var prevTrack      = document.getElementById('ta-rc-cal-rooms-track');
    var prevScrollLeft = prevTrack ? prevTrack.scrollLeft : 0;

    var metaById = {};
    metas.forEach(function(r) { metaById[String(r.product_id)] = r; });
    var rooms = (cache.data.rooms || []).filter(function(r) { return metaById[String(r.product_id)]; });
    window.rcCalUpdateRoomsPageInfo(rooms.length);

    var todayIso = cache.data.today || rcDayIso(rcDayToday.getFullYear(), rcDayToday.getMonth(), rcDayToday.getDate());

    wrap.innerHTML = '<div class="ta-rc-cal-rooms-track" id="ta-rc-cal-rooms-track">'
        + rooms.map(function(room) { return rcDayRoomHtml(room, metaById[String(room.product_id)], todayIso); }).join('')
        + '</div>';

    if (prevScrollLeft) {
        var newTrack = document.getElementById('ta-rc-cal-rooms-track');
        if (newTrack) newTrack.scrollLeft = prevScrollLeft;
    }
};

function rcDayRoomHtml(room, meta, todayIso) {
    var esc  = window._escAttr;
    var pid  = esc(String(room.product_id));
    var y    = window._rcDayMonth.y, m = window._rcDayMonth.m;
    var days = new Date(y, m + 1, 0).getDate();
    var pad  = (new Date(y, m, 1).getDay() + 6) % 7;
    var sel  = window._rcDaySel.pid === String(room.product_id) ? window._rcDaySel : { start: null, end: null };

    var bookedNights = 0, blockedNights = 0, revenueNights = 0;

    var cells = '';
    ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'].forEach(function(d) { cells += '<span class="ta-rc-day-dow">' + d + '</span>'; });
    for (var p = 0; p < pad; p++) cells += '<span></span>';

    for (var d = 1; d <= days; d++) {
        var iso   = rcDayIso(y, m, d);
        var st    = rcDayState(room, iso, todayIso);
        var price = rcDayPrice(room.calendar || {}, iso);
        var cls   = ['ta-rc-day-cell'];
        var title = [rcDayFmt(iso)];

        bookedNights  += room.is_variant ? st.used : (st.orders.length ? 1 : 0);
        blockedNights += st.fullBlock ? room.capacity : st.partial;
        if (st.orders.length) revenueNights++;

        if (st.past) cls.push('is-past');
        if (iso === todayIso) cls.push('is-today');

        if (st.orders.length && (!room.is_variant || st.occupied)) {
            cls.push('is-booked');
            if (st.orders.every(function(o) { return o.status === 'pending'; })) cls.push('is-pending');
        } else if (st.fullBlock) {
            cls.push('is-blocked');
            title.push('Đã khoá');
        } else {
            if (st.occupied) cls.push('is-checkout-only');
            if (price.promo && !st.past && !st.unavailable) cls.push('is-promo');
        }

        // Khoảng đang chọn — dải nền liền từ ngày nhận tới ngày trả như lịch khách (::before), số ngày vẫn là vòng riêng.
        if (sel.start && iso === sel.start) cls.push('is-sel-start');
        if (sel.start && sel.end) {
            if (iso === sel.start) cls.push('has-range');
            if (iso === sel.end) cls.push('is-sel-end');
            if (iso > sel.start && iso < sel.end) cls.push('is-in-range');
        }

        // Quanh vòng số ngày: icon nhận phòng (trên trái, xanh) / trả phòng (trên phải, cam) kèm số lượt nếu > 1, chấm đỏ =
        // có ghi chú; biến thể: số phòng đã đặt (bấm để xem đơn), khoá một phần số phòng.
        var badges = '';
        if (st.checkins) {
            badges += '<span class="ta-rc-day-io is-in" title="' + st.checkins + ' lượt nhận phòng (' + esc(room.checkin_time || '14:00') + ')">' + RC_DAY_ICON_IN + (st.checkins > 1 ? '<b>' + st.checkins + '</b>' : '') + '</span>';
        }
        if (st.checkouts) {
            badges += '<span class="ta-rc-day-io is-out" title="' + st.checkouts + ' lượt trả phòng (' + esc(room.checkout_time || '12:00') + ')">' + RC_DAY_ICON_OUT + (st.checkouts > 1 ? '<b>' + st.checkouts + '</b>' : '') + '</span>';
        }
        // Biến thể: 1 nhãn dưới vòng = số phòng có khách (bấm xem đơn) · số phòng đang khoá.
        if (st.used || st.partial) {
            badges += '<span class="ta-rc-day-count' + (st.used ? '' : ' is-partial') + '"'
                + (st.used ? ' onclick="rcDayShowOrders(event, \'' + pid + '\', \'' + iso + '\')"' : '')
                + ' title="' + (st.used ? st.used + '/' + room.capacity + ' phòng có khách — bấm xem đơn' : '') + (st.partial ? (st.used ? '; ' : '') + 'khoá ' + st.partial + '/' + room.capacity + ' phòng' : '') + '">'
                + (st.used ? st.used + '/' + room.capacity : '') + (st.used && st.partial ? ' · ' : '') + (st.partial ? '<i>' + RC_DAY_ICON_LOCK + st.partial + '</i>' : '')
                + '</span>';
        }
        if (st.orders.some(function(o) { return o.has_note; })) badges += '<i class="ta-rc-day-note" title="Có ghi chú"></i>';

        st.orders.forEach(function(o) {
            title.push(o.status_label + ' — ' + (o.buyer_name || 'Khách') + ' (' + o.order_code + ', ' + rcDayFmt(o.checkin) + ' → ' + rcDayFmt(o.checkout) + ')');
        });
        if (st.checkouts && !st.orders.length) title.push(st.checkouts + ' khách trả phòng (' + (room.checkout_time || '12:00') + ')');
        if (!st.past && !st.fullBlock && !(st.orders.length && !room.is_variant)) {
            title.push('Giá: ' + rcDayVnd(price.final) + (price.final < price.base ? ' (gốc ' + rcDayVnd(price.base) + ')' : ''));
            if ((room.calendar.promoLabels || {})[iso]) title.push('KM: ' + room.calendar.promoLabels[iso]);
        }

        cells += '<button type="button" class="' + cls.join(' ') + '" title="' + esc(title.join('\n')) + '" onclick="rcDayClick(\'' + pid + '\', \'' + iso + '\')">'
            + '<span class="ta-rc-day-num">' + d + '</span>'
            + badges
            + '</button>';
    }

    var hasSel = !!sel.start;
    var info   = hasSel ? rcDaySelInfo(room, sel, todayIso) : null;

    var actions = '<div class="ta-rc-cal-room-actions">'
        + '<div class="ta-rc-cal-room-actions-row cols-2">'
            + '<a href="' + esc(meta.edit_url || '#') + '" class="ta-rc-cal-room-action-btn" target="_blank" rel="noopener">Xem phòng</a>'
            + '<button type="button" class="ta-rc-cal-room-action-btn" onclick="rcCalOpenViewPopup(\'' + pid + '\', \'' + esc(room.room_name) + '\', \'' + esc(meta.edit_url || '') + '\')">Thống kê</button>'
        + '</div>'
        + '<div class="ta-rc-cal-room-actions-row cols-3">'
            + '<button type="button" class="ta-rc-cal-room-action-btn" title="Khoá các ngày đang chọn"' + (info && info.lockable ? '' : ' disabled') + ' onclick="rcDayBlock(\'' + pid + '\')">Khoá ngày</button>'
            + '<button type="button" class="ta-rc-cal-room-action-btn is-unlock" title="Mở khoá các ngày đang chọn"' + (info && info.blocked ? '' : ' disabled') + ' onclick="rcDayUnblock(\'' + pid + '\')">Mở khoá</button>'
            + '<button type="button" class="ta-rc-cal-room-action-btn is-primary" title="Nhận ngày bắt đầu, trả ngày kết thúc"' + (info && info.bookable ? '' : ' disabled') + ' onclick="rcDayBook(\'' + pid + '\')">Đặt phòng</button>'
        + '</div>'
        + '</div>';

    var foot;
    if (hasSel) {
        var total = 0;
        for (var i = sel.start; i < info.checkout; i = rcDayAddDays(i, 1)) total += rcDayPrice(room.calendar || {}, i).final;
        foot = '<div class="ta-rc-day-foot is-picked">'
            + '<span><b>' + rcDayFmt(sel.start) + '</b>' + (sel.end ? ' → <b>' + rcDayFmt(sel.end) + '</b>' : ' <em>(bấm ngày kết thúc)</em>')
            + ' · ' + info.days + ' ngày' + (info.blocked ? ' · ' + info.blocked + ' đang khoá' : '')
            + (info.bookable ? '<br><em>Đặt: ' + info.nights + ' đêm, trả ' + rcDayFmt(info.checkout) + '</em>' : '')
            + (room.is_variant && info.free > 0
                ? '<br><label class="ta-rc-day-lock-qty">Khoá <input type="number" min="1" max="' + info.free + '" value="' + Math.min(info.free, rcDayLockRooms(pid) || info.free) + '" oninput="rcDaySetLockRooms(\'' + pid + '\', this.value)" onclick="event.stopPropagation()"> / ' + info.free + ' phòng còn trống</label>'
                : '')
            + '</span>'
            + (info.bookable ? '<b class="ta-rc-day-total">' + rcDayVnd(total) + '</b>' : '')
            + '<button type="button" class="ta-rc-day-clear" onclick="rcDayClearSel()" aria-label="Bỏ chọn">✕</button>'
            + '</div>';
    } else {
        var capacityNights = days * room.capacity;
        var pct = capacityNights ? Math.round(bookedNights / capacityNights * 100) : 0;
        foot = '<div class="ta-rc-day-foot">'
            + '<span>Công suất tháng: <b>' + pct + '%</b> (' + bookedNights + '/' + capacityNights + ' đêm)</span>'
            + (blockedNights ? '<span>Khoá ' + blockedNights + (room.is_variant ? ' phòng-đêm' : ' đêm') + '</span>' : '')
            + '</div>';
    }

    return '<div class="ta-rc-cal-room-block ta-rc-day-block">'
        + '<h4 class="ta-rc-cal-room-name">' + esc(room.room_name)
        + (room.is_variant ? ' <span class="ta-rc-day-variant">' + room.capacity + ' phòng</span>' : '')
        + '</h4>'
        + actions
        + '<div class="ta-rc-day-grid">' + cells + '</div>'
        + foot
        + '</div>';
}

// Ngày có khách ở (không khoá/mở khoá được) — phòng đơn: có đơn trong đêm; biến thể: đêm đã hết phòng.
function rcDayHasGuest(room, st) {
    return st.orders.length > 0 && (!room.is_variant || st.occupied);
}

// Khoảng đang chọn: ngày bắt đầu..kết thúc (tính cả 2 đầu) cho Khoá/Mở khoá; Đặt phòng thì nhận ngày bắt đầu, trả ngày
// kết thúc (chỉ chọn 1 ngày → 1 đêm).
function rcDaySelInfo(room, sel, todayIso) {
    var last = sel.end || sel.start, days = 0, blocked = 0, guest = false, free = room.capacity;
    for (var i = sel.start; i <= last; i = rcDayAddDays(i, 1)) {
        var st = rcDayState(room, i, todayIso);
        days++;
        if (st.fullBlock || st.partial) blocked++;
        if (rcDayHasGuest(room, st)) guest = true;
        // Biến thể: số phòng còn khoá thêm được = ít nhất trong các ngày (đã trừ phòng có khách + phòng đang khoá).
        free = Math.min(free, st.fullBlock ? 0 : room.capacity - st.used - st.partial);
    }
    free = Math.max(0, free);
    var checkout = sel.end || rcDayAddDays(sel.start, 1);
    return {
        last: last,
        days: days,
        blocked: blocked,
        free: free,
        lockable: room.is_variant ? free > 0 : (!guest && blocked < days),
        checkout: checkout,
        nights: Math.round((new Date(checkout + 'T00:00:00') - new Date(sel.start + 'T00:00:00')) / 86400000),
        bookable: rcDayRangeOk(room, sel.start, checkout, todayIso),
    };
}

function rcDayNoGuestBetween(room, start, end, todayIso) {
    for (var i = start; i <= end; i = rcDayAddDays(i, 1)) {
        if (rcDayHasGuest(room, rcDayState(room, i, todayIso))) return false;
    }
    return true;
}

// Bấm lần 1 = ngày bắt đầu, lần 2 (ngày sau đó) = ngày kết thúc; ngày trống hay đang khoá đều chọn được để Khoá/Mở khoá
// đúng khoảng đó. Ngày có khách: bấm mở đơn (trừ khi làm ngày trả hợp lệ cho Đặt phòng).
window.rcDayClick = function(pid, iso) {
    var room = rcDayRoomById(pid);
    if (!room) return;
    var cache    = window._rcDayCache[window._rcDayCurrentKey];
    var todayIso = cache.data.today;
    var st       = rcDayState(room, iso, todayIso);
    var sel      = window._rcDaySel;
    var mine     = sel.pid === String(pid);

    if (mine && sel.start && !sel.end && iso > sel.start
        && (rcDayNoGuestBetween(room, sel.start, iso, todayIso) || rcDayRangeOk(room, sel.start, iso, todayIso))) {
        sel.end = iso;
        window.rcDayRender();
        return;
    }

    if (rcDayHasGuest(room, st)) {
        rcDayOpenOrders(st.orders);
        return;
    }

    if (st.past) return;

    window._rcDaySel = { pid: String(pid), start: iso, end: null };
    window.rcDayRender();
};

window.rcDayClearSel = function() {
    window._rcDaySel = { pid: null, start: null, end: null };
    window.rcDayRender();
};

// Biến thể phòng còn chỗ: bấm số "2/5" trong ô để xem các đơn của đêm đó.
window.rcDayShowOrders = function(e, pid, iso) {
    e.stopPropagation();
    var room = rcDayRoomById(pid);
    if (room) rcDayOpenOrders((room.nights || {})[iso] || []);
};

// 1 đơn → mở thẳng popup đơn của chế độ Khung giờ; nhiều đơn (biến thể phòng) → danh sách để chọn.
function rcDayOpenOrders(orders) {
    var seen = {};
    orders = orders.filter(function(o) { return !seen[o.order_id] && (seen[o.order_id] = true); });
    if (!orders.length) return;
    if (orders.length === 1) {
        window.rcCalOpenOrderPopup(orders[0].order_id);
        return;
    }

    var overlay = document.getElementById('ta-rc-order-popup');
    var body    = document.getElementById('ta-rc-order-popup-body');
    var badges  = document.getElementById('ta-rc-order-popup-head-badges');
    if (!overlay || !body) return;
    if (badges) badges.innerHTML = '';
    body.innerHTML = '<div class="ta-rc-day-order-list">' + orders.map(function(o) {
        return '<button type="button" onclick="rcCalOpenOrderPopup(' + Number(o.order_id) + ')">'
            + '<i style="background:' + window._escAttr(o.color) + '"></i>'
            + '<span><b>' + window._escAttr(o.buyer_name || 'Khách') + '</b> · ' + window._escAttr(o.order_code) + '</span>'
            + '<span>' + rcDayFmt(o.checkin) + ' → ' + rcDayFmt(o.checkout) + ' · ' + window._escAttr(o.status_label) + '</span>'
            + '</button>';
    }).join('') + '</div>';
    overlay.style.display = 'flex';
}

function rcDayPost(url, payload) {
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify(payload),
        credentials: 'same-origin',
    }).then(function(r) {
        return r.json().catch(function() { return {}; }).then(function(d) {
            if (r.ok && d.ok) return d;
            var errors = d.errors ? Object.values(d.errors).flat() : [];
            throw new Error(errors[0] || d.message || 'Không lưu được, vui lòng thử lại.');
        });
    });
}

function rcDayRangeLabel(start, last) {
    return start === last ? 'ngày ' + rcDayFmt(start) : rcDayFmt(start) + ' → ' + rcDayFmt(last);
}

// Biến thể phòng: số phòng muốn khoá (ô nhập dưới lịch), giữ theo phòng qua các lần vẽ lại; mặc định = hết số còn trống.
window._rcDayLockRooms = window._rcDayLockRooms || {};
function rcDayLockRooms(pid) {
    return parseInt(window._rcDayLockRooms[String(pid)], 10) || 0;
}
window.rcDaySetLockRooms = function(pid, value) {
    window._rcDayLockRooms[String(pid)] = value;
};

// Khoá / mở khoá đúng các ngày bắt đầu..kết thúc đang chọn — server cộng/trừ theo từng ngày rồi gộp lại các khoảng
// (DailyCalendarService::block()/unblock()), mở khoá giữa 1 khoảng khoá dài thì khoảng đó tách đôi.
function rcDaySaveBlock(pid, action) {
    var room = rcDayRoomById(pid);
    var sel  = window._rcDaySel;
    if (!room || sel.pid !== String(pid) || !sel.start) return;
    var info  = rcDaySelInfo(room, sel, window._rcDayCache[window._rcDayCurrentKey].data.today);
    var label = rcDayRangeLabel(sel.start, info.last);
    var block = action === 'block';
    var payload = { start: sel.start, end: info.last };
    var what    = '';
    if (block && room.is_variant) {
        var rooms = rcDayLockRooms(pid) || info.free;
        if (rooms < 1 || rooms > info.free) {
            rcDayNotify('Số phòng khoá phải từ 1 đến ' + info.free + '.', false);
            return;
        }
        payload.rooms = rooms;
        what = ' ' + rooms + '/' + room.capacity + ' phòng';
    }
    if (block && !window.confirm('Khoá' + what + ' ' + label + ' (' + info.days + ' ngày)? Khách sẽ không đặt được ' + (what ? 'số phòng này' : 'những ngày này') + '.')) return;

    rcDayPost('/admin/api/rooms/' + pid + (block ? '/block-days' : '/unblock-days'), payload)
        .then(function() {
            rcDayNotify((block ? 'Đã khoá' + what + ' ' : 'Đã mở khoá ') + label, true);
            delete window._rcDayLockRooms[String(pid)];
            window._rcDaySel = { pid: null, start: null, end: null };
            window.rcDayRender(true);
        })
        .catch(function(e) { rcDayNotify(e.message, false); });
}

window.rcDayBlock = function(pid) { rcDaySaveBlock(pid, 'block'); };
window.rcDayUnblock = function(pid) { rcDaySaveBlock(pid, 'unblock'); };

// Mở trang tạo đơn với đúng phòng + khoảng ngày (CreateOrder::dailyRangeFromQuery()) — chưa chọn ngày trả thì 1 đêm.
window.rcDayBook = function(pid) {
    var sel  = window._rcDaySel;
    var meta = (window.__rcRoomsData || []).find(function(r) { return String(r.product_id) === String(pid); });
    if (sel.pid !== String(pid) || !sel.start || !meta || !meta.book_url) return;
    var checkout = sel.end || rcDayAddDays(sel.start, 1);
    if (!rcDayRangeOk(rcDayRoomById(pid), sel.start, checkout, window._rcDayCache[window._rcDayCurrentKey].data.today)) return;
    window.open(meta.book_url + (meta.book_url.indexOf('?') === -1 ? '?' : '&') + 'checkin=' + sel.start + '&checkout=' + checkout, '_blank');
};

// Chế độ mặc định: lần chọn trước của admin; lần đầu thì "Khung giờ", trừ khi tài khoản chỉ có phòng theo ngày.
(function() {
    var saved = null;
    try { saved = localStorage.getItem(RC_DAY_MODE_KEY); } catch (e) {}
    var rooms   = window.__rcRoomsData || [];
    var hasSlot = rooms.some(function(r) { return (r.styles || 1) === 1; });
    var hasDay  = rooms.some(function(r) { return r.styles === 2; });
    var mode    = saved === 'day' || saved === 'slot' ? saved : (hasSlot || !hasDay ? 'slot' : 'day');
    var calView = document.getElementById('ta-rc-cal-view');

    window.rcCalApplyModeUi(mode);
    if (calView && calView.style.display !== 'none') {
        window.rcCalRenderBranches();
        window.rcCalRenderGrid();
    }
})();
})();
</script>
@endscript

<style>
    .ta-rc-cal-branches-nav .ta-rc-cal-mode-toggle {
        margin-right: auto;
    }

    .ta-rc-cal-mode-toggle {
        display: inline-flex;
        padding: 3px;
        gap: 2px;
        border: 1px solid var(--ta-line);
        border-radius: 999px;
        background: var(--ta-panel);
    }

    .ta-rc-cal-mode-btn {
        font: 600 12px 'Inter', ui-sans-serif, sans-serif;
        color: var(--ta-ink-mute);
        background: transparent;
        border: 0;
        border-radius: 999px;
        padding: 5px 14px;
        cursor: pointer;
    }

    .ta-rc-cal-mode-btn:disabled {
        opacity: .4;
        cursor: not-allowed;
    }

    .ta-rc-cal-mode-btn.active {
        background: var(--ta-cal-primary);
        color: #fff;
    }

    .ta-rc-cal-dot.blocked {
        background: repeating-linear-gradient(135deg, var(--ta-line) 0 3px, var(--ta-panel) 3px 6px);
        border: 1px solid var(--ta-line);
    }

    .ta-rc-day-legend-io {
        display: inline-flex;
    }

    .ta-rc-day-legend-io svg {
        width: 14px;
        height: 14px;
    }

    .ta-rc-day-legend-io.is-in {
        color: #059669;
    }

    .ta-rc-day-legend-io.is-out {
        color: #ea580c;
    }

    .ta-rc-day-month-label {
        min-width: 120px;
        text-align: center;
        font: 700 13px 'Inter', ui-sans-serif, sans-serif;
        color: var(--ta-ink-title);
        text-transform: capitalize;
    }

    .ta-rc-cal-grid-wrap.is-day-mode {
        display: block;
        max-height: none;
    }

    .ta-rc-day-block {
        min-width: 290px;
    }

    .ta-rc-day-variant {
        margin-left: 6px;
        padding: 1px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
        font-style: normal;
        background: #ecfdf5;
        color: #047857;
    }

    .ta-rc-day-grid {
        display: grid;
        grid-template-columns: repeat(7, minmax(0, 1fr));
        row-gap: 4px;
        margin-top: 6px;
        padding: 8px 6px 4px;
        border: 1px solid var(--ta-line);
        border-radius: 14px;
        background: var(--ta-panel);
    }

    .ta-rc-day-dow {
        padding: 2px 0 6px;
        text-align: center;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .04em;
        color: var(--ta-ink-faint);
    }

    /* Ô ngày — cùng kiểu lịch khách (BladeThemeV1 _daily-cards.scss .daily-cal__day): số ngày trong vòng tròn 34px
       tách rời nhau, ::before = dải nền khoảng đang chọn, ::after = viền cầu vồng ngày khuyến mãi. */
    .ta-rc-day-cell {
        position: relative;
        height: 42px;
        padding: 0;
        border: 0;
        background: none;
        font-family: inherit;
        cursor: pointer;
        -webkit-appearance: none;
        appearance: none;
    }

    .ta-rc-day-cell::before {
        content: '';
        position: absolute;
        top: 4px;
        bottom: 4px;
        left: 0;
        right: 0;
    }

    .ta-rc-day-num {
        position: relative;
        z-index: 1;
        width: 34px;
        height: 34px;
        margin: 0 auto;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        font-size: 13px;
        font-weight: 600;
        color: var(--ta-ink-title);
        font-variant-numeric: tabular-nums;
        transition: background .15s, color .15s;
    }

    /* :where() giữ độ ưu tiên thấp để màu đã đặt/đang chọn không bị hover đè. */
    .ta-rc-day-cell:where(:hover:not(.is-past, .is-checkout-only)) .ta-rc-day-num {
        background: var(--ta-line-soft);
    }

    .ta-rc-day-cell.is-today .ta-rc-day-num {
        box-shadow: inset 0 0 0 1.5px var(--ta-ink-faint);
    }

    .ta-rc-day-cell.is-past {
        cursor: default;
    }

    .ta-rc-day-cell.is-past .ta-rc-day-num {
        color: var(--ta-ink-faint);
        font-weight: 500;
        opacity: .6;
    }

    .ta-rc-day-cell.is-booked .ta-rc-day-num,
    .ta-rc-day-cell.is-booked:hover .ta-rc-day-num {
        background: var(--ta-cal-primary);
        color: #fff;
        opacity: 1;
    }

    .ta-rc-day-cell.is-booked.is-pending .ta-rc-day-num {
        background: #f59e0b;
    }

    .ta-rc-day-cell.is-past.is-booked {
        cursor: pointer;
    }

    .ta-rc-day-cell.is-past.is-booked .ta-rc-day-num {
        opacity: .5;
    }

    .ta-rc-day-cell.is-blocked .ta-rc-day-num {
        background: repeating-linear-gradient(135deg, var(--ta-line) 0 3px, var(--ta-panel) 3px 6px);
        color: var(--ta-ink-mute);
    }

    .ta-rc-day-cell.is-blocked:not(.is-sel-start, .is-sel-end):hover .ta-rc-day-num {
        box-shadow: inset 0 0 0 1.5px var(--ta-ink-mute);
    }

    /* Ngày trả phòng của lượt trước (phòng đơn) — nửa vòng tô màu, chưa nhận khách mới được trong ngày. */
    .ta-rc-day-cell.is-checkout-only {
        cursor: default;
    }

    .ta-rc-day-cell.is-checkout-only .ta-rc-day-num {
        background: linear-gradient(135deg, color-mix(in srgb, var(--ta-cal-primary) 30%, transparent) 0 50%, transparent 50%);
    }

    .ta-rc-day-cell.is-promo .ta-rc-day-num {
        background: var(--ta-panel);
    }

    .ta-rc-day-cell.is-promo::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 34px;
        height: 34px;
        margin: -17px 0 0 -17px;
        border-radius: 50%;
        background: linear-gradient(270deg, #ff4d4d, #ffb84d, #70ff70, #70ffff, #7070ff, #ff70e0, #ff4d4d);
        background-size: 200% 200%;
        animation: ta-rc-cal-borderflow 15s linear infinite;
        filter: blur(3px);
    }

    .ta-rc-day-cell.is-in-range::before,
    .ta-rc-day-cell.is-sel-start.has-range::before,
    .ta-rc-day-cell.is-sel-end::before {
        background: rgba(17, 24, 39, .08);
    }

    .ta-rc-day-cell.is-sel-start.has-range::before {
        left: 50%;
    }

    .ta-rc-day-cell.is-sel-end::before {
        right: 50%;
    }

    .ta-rc-day-cell.is-in-range:not(.is-blocked) .ta-rc-day-num {
        background: transparent;
    }

    .ta-rc-day-cell.is-sel-start .ta-rc-day-num,
    .ta-rc-day-cell.is-sel-end .ta-rc-day-num {
        background: #111827;
        color: #fff;
        box-shadow: 0 2px 6px rgba(17, 24, 39, .25);
    }

    .ta-rc-day-cell.is-sel-start::after,
    .ta-rc-day-cell.is-sel-end::after,
    .ta-rc-day-cell.is-in-range::after {
        display: none;
    }

    /* Icon nhận (trên trái) / trả phòng (trên phải) — nền trắng tròn để đọc được cả trên vòng đã đặt. */
    .ta-rc-day-io {
        position: absolute;
        z-index: 2;
        top: -1px;
        display: inline-flex;
        align-items: center;
        gap: 1px;
        height: 15px;
        min-width: 15px;
        padding: 0 2px;
        border-radius: 999px;
        background: var(--ta-panel);
        box-shadow: 0 0 0 1px var(--ta-line), 0 1px 2px rgba(0, 0, 0, .08);
        font-size: 8.5px;
        font-weight: 800;
        line-height: 1;
    }

    .ta-rc-day-io svg {
        width: 11px;
        height: 11px;
    }

    .ta-rc-day-io.is-in {
        right: calc(50% + 9px);
        color: #059669;
    }

    .ta-rc-day-io.is-out {
        left: calc(50% + 9px);
        color: #ea580c;
    }

    .ta-rc-day-lock-qty {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-top: 6px;
        color: var(--ta-ink-title);
        font-weight: 600;
    }

    .ta-rc-day-lock-qty input {
        width: 52px;
        padding: 3px 6px;
        border: 1px solid var(--ta-line);
        border-radius: 7px;
        background: var(--ta-panel);
        color: var(--ta-ink-title);
        font: inherit;
    }

    .ta-rc-day-lock-qty input:focus {
        outline: none;
        border-color: var(--ta-cal-primary);
    }

    .ta-rc-day-count {
        position: absolute;
        z-index: 2;
        bottom: 0;
        left: 50%;
        transform: translateX(-50%);
        padding: 0 4px;
        border-radius: 999px;
        font-size: 8.5px;
        font-weight: 700;
        line-height: 12px;
        white-space: nowrap;
        background: #ecfdf5;
        color: #047857;
        box-shadow: 0 0 0 1.5px var(--ta-panel);
    }

    .ta-rc-day-count.is-partial {
        background: #fffbeb;
        color: #b45309;
    }

    .ta-rc-day-count i {
        display: inline-flex;
        align-items: center;
        gap: 1px;
        font-style: normal;
        color: #b45309;
    }

    .ta-rc-day-count i svg {
        width: 9px;
        height: 9px;
    }

    .ta-rc-day-count {
        display: inline-flex;
        align-items: center;
    }

    .ta-rc-day-note {
        position: absolute;
        z-index: 2;
        top: 2px;
        left: 50%;
        margin-left: -3.5px;
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #ef4444;
        box-shadow: 0 0 0 1.5px var(--ta-panel);
    }

    .ta-rc-day-foot {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 4px 10px;
        min-height: 36px;
        margin-top: 8px;
        padding: 8px 10px;
        border-radius: 10px;
        background: var(--ta-bg);
        font-size: 11.5px;
        color: var(--ta-ink-mute);
    }

    .ta-rc-day-foot b {
        color: var(--ta-ink-title);
    }

    .ta-rc-day-foot em {
        font-style: normal;
        color: var(--ta-ink-faint);
    }

    .ta-rc-day-foot.is-picked {
        flex-wrap: nowrap;
        border: 1px solid var(--ta-cal-primary);
    }

    .ta-rc-day-total {
        margin-left: auto;
        white-space: nowrap;
        color: var(--ta-cal-primary) !important;
    }

    .ta-rc-day-clear {
        flex-shrink: 0;
        border: 0;
        background: transparent;
        color: var(--ta-ink-mute);
        cursor: pointer;
        font-size: 13px;
    }

    .ta-rc-day-order-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .ta-rc-day-order-list button {
        display: grid;
        grid-template-columns: 10px 1fr;
        gap: 2px 10px;
        align-items: center;
        padding: 10px 12px;
        text-align: left;
        border: 1px solid var(--ta-line);
        border-radius: 10px;
        background: var(--ta-panel);
        color: var(--ta-ink-title);
        font: 500 13px 'Inter', ui-sans-serif, sans-serif;
        cursor: pointer;
    }

    .ta-rc-day-order-list button:hover {
        border-color: var(--ta-cal-primary);
    }

    .ta-rc-day-order-list i {
        grid-row: span 2;
        width: 10px;
        height: 10px;
        border-radius: 50%;
    }

    .ta-rc-day-order-list span + span {
        font-size: 12px;
        color: var(--ta-ink-mute);
    }

    @media (max-width: 640px) {
        .ta-rc-cal-branches-nav {
            flex-wrap: wrap;
        }

        .ta-rc-day-block {
            flex-basis: 100%;
            min-width: 0;
        }
    }
</style>
