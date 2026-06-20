{{-- Flatpickr: date picker with Vietnamese locale --}}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/vn.js"></script>

{{-- Script 1: global filter functions — defined immediately so onclick works before DOMContentLoaded --}}
@script
<script>
window._rcActiveBranch = '__all__';
window._rcActiveTime   = 'all';

window.rcApplyFilters = function() {
    var branch = window._rcActiveBranch || '__all__';
    var time   = window._rcActiveTime   || 'all';

    document.querySelectorAll('#ta-rc-tabs .ta-rc-tab').forEach(function(b) {
        b.classList.toggle('active', b.dataset.branch === branch);
    });
    document.querySelectorAll('#ta-rc-time-tabs .ta-rc-time-tab').forEach(function(b) {
        b.classList.toggle('active', b.dataset.time === time);
    });

    document.querySelectorAll('#ta-room-grid .ta-room-card').forEach(function(card) {
        var branchMatch  = branch === '__all__' || card.dataset.branch === branch;
        var visibleCount = 0;

        card.querySelectorAll('.ta-rc-order-item').forEach(function(item) {
            var show = time === 'all'
                || (time === 'deposit' ? item.dataset.status === 'deposit' : item.dataset.segment === time);
            item.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });

        var allOrderCount = card.querySelectorAll('.ta-rc-order-item').length;
        var emptyEl       = card.querySelector('.ta-rc-empty');
        var noMatchEl     = card.querySelector('.ta-rc-no-match');
        if (emptyEl)   emptyEl.style.display   = allOrderCount === 0 ? '' : 'none';
        if (noMatchEl) noMatchEl.style.display = (allOrderCount > 0 && visibleCount === 0) ? '' : 'none';

        var countEl = card.querySelector('.ta-rc-count');
        if (countEl) {
            countEl.textContent = visibleCount > 0 ? visibleCount + ' đơn' : 'Trống';
            countEl.classList.toggle('empty', visibleCount === 0);
        }

        card.style.display = branchMatch ? '' : 'none';
    });
};

window.rcSwitchTab = function(btn) {
    window._rcActiveBranch = btn.dataset.branch;
    window.rcApplyFilters();
};

window.rcSwitchTime = function(btn) {
    window._rcActiveTime = btn.dataset.time;
    window.rcApplyFilters();
};

// ── Order selection ───────────────────────────────────────────────
window._rcSelectedOrders = {};

window._escAttr = function(s) {
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
};

window.rcToggleOrder = function(cb) {
    var item = cb.closest('.ta-rc-order-item');
    if (!item) return;
    var orderData = {};
    try { orderData = JSON.parse(item.dataset.order || '{}'); } catch(e) { return; }
    var id = String(orderData.order_id || '');
    if (!id) return;
    var card   = item.closest('.ta-room-card');
    var nameEl = card ? card.querySelector('.ta-rc-name') : null;
    orderData.room_name   = nameEl ? nameEl.textContent.trim() : '';
    orderData.room_branch = card ? (card.dataset.branch || '') : '';
    if (cb.checked) {
        window._rcSelectedOrders[id] = orderData;
        item.classList.add('rc-sel');
    } else {
        delete window._rcSelectedOrders[id];
        item.classList.remove('rc-sel');
    }
    window.rcUpdateSelBtn();
};

window.rcUpdateSelBtn = function() {
    var count = Object.keys(window._rcSelectedOrders).length;
    var btn   = document.getElementById('ta-rc-view-btn');
    var cnt   = document.getElementById('ta-rc-sel-count');
    if (btn) btn.style.display = count > 0 ? '' : 'none';
    if (cnt) cnt.textContent   = count;
};

window.rcClearSelections = function() {
    window._rcSelectedOrders = {};
    document.querySelectorAll('.ta-rc-checkbox:checked').forEach(function(cb) {
        cb.checked = false;
        var it = cb.closest('.ta-rc-order-item');
        if (it) it.classList.remove('rc-sel');
    });
    window.rcUpdateSelBtn();
    window.rcCloseSelectedPopup();
};

window._rcMergeSlots = function(slotLabels) {
    if (!slotLabels) return { merged: '', totalMin: 0 };
    var parts = slotLabels.split(/,\s*/).map(function(s){ return s.trim(); }).filter(Boolean);
    var slots = parts.map(function(s) {
        var m = s.match(/^(\d{1,2}):(\d{2})\s*[-–]\s*(\d{1,2}):(\d{2})$/);
        if (!m) return null;
        var start = +m[1]*60 + +m[2], end = +m[3]*60 + +m[4];
        if (end <= start) end += 1440;
        return { start: start, end: end };
    }).filter(Boolean);
    if (!slots.length) return { merged: slotLabels, totalMin: 0 };
    slots.sort(function(a, b){ return a.start - b.start; });
    var totalMin = slots.reduce(function(s, sl){ return s + (sl.end - sl.start); }, 0);
    var merged = [{ start: slots[0].start, end: slots[0].end }];
    for (var i = 1; i < slots.length; i++) {
        var last = merged[merged.length - 1];
        if (slots[i].start - last.end <= 60) { last.end = Math.max(last.end, slots[i].end); }
        else { merged.push({ start: slots[i].start, end: slots[i].end }); }
    }
    function fmt(m) {
        var h = Math.floor(m % 1440 / 60), mi = m % 60;
        return String(h).padStart(2,'0') + ':' + String(mi).padStart(2,'0');
    }
    return {
        merged: merged.map(function(s){ return fmt(s.start) + ' - ' + fmt(s.end); }).join('  ·  '),
        totalMin: totalMin
    };
};

// ── Full-date slot display (popup) ───────────────────────────────
// slotRanges: [{start:'dd/mm/yyyy H:i', end:'...', start_ts:int, end_ts:int}, ...]
// Slots with gap ≤ 3h are merged into one range; larger gaps → separate lines.
window._rcBuildSlotDisplay = function(slotRanges) {
    if (!slotRanges || slotRanges.length === 0) return '';
    var MERGE_GAP = 3 * 60 * 60; // 3 hours in seconds
    var sorted = slotRanges.slice().sort(function(a, b) { return a.start_ts - b.start_ts; });
    var groups = [];
    sorted.forEach(function(slot) {
        if (groups.length === 0) {
            groups.push({ start: slot.start, end: slot.end, start_ts: slot.start_ts, end_ts: slot.end_ts });
        } else {
            var last = groups[groups.length - 1];
            if (slot.start_ts - last.end_ts <= MERGE_GAP) {
                if (slot.end_ts > last.end_ts) { last.end = slot.end; last.end_ts = slot.end_ts; }
            } else {
                groups.push({ start: slot.start, end: slot.end, start_ts: slot.start_ts, end_ts: slot.end_ts });
            }
        }
    });
    return groups.map(function(g) { return g.start + ' – ' + g.end; }).join('<br>');
};

// ── Shared popup renderer ─────────────────────────────────────
window._rcBuildPopupHtml = function(orders, roomList) {
    var _segLabels = { active: 'Đang ở', today: 'Hôm nay', upcoming: 'Sắp tới', overdue: 'Quá hạn', deposit: 'Đặt cọc' };
    var roomGroups = {}, roomOrder = [], roomBranch = {};

    // Pre-populate from roomList so empty rooms appear in order
    if (roomList) {
        roomList.forEach(function(r) {
            var key = r.name || '—';
            if (!roomGroups[key]) { roomGroups[key] = []; roomOrder.push(key); }
            roomBranch[key] = r.branch || '';
        });
    }

    orders.forEach(function(o) {
        var key = o.room_name || '—';
        if (!roomGroups[key]) { roomGroups[key] = []; roomOrder.push(key); }
        roomGroups[key].push(o);
        if (!roomBranch[key]) roomBranch[key] = o.room_branch || '';
    });

    return roomOrder.map(function(roomName) {
        var roomOrders = roomGroups[roomName];
        var branch = roomBranch[roomName] || '';
        var roomIconSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

        // Empty room: no orders
        if (roomOrders.length === 0) {
            return '<div class="ta-room-card" style="margin:0;opacity:.65;">' +
                '<div class="ta-rc-head">' +
                    '<div class="ta-rc-icon">' + roomIconSvg + '</div>' +
                    '<div class="ta-rc-info" style="flex:1;min-width:0;">' +
                        '<div class="ta-rc-name">' + roomName + '</div>' +
                        (branch ? '<div class="ta-rc-branch">' + branch + '</div>' : '') +
                    '</div>' +
                    '<div class="ta-rc-count empty">Trống</div>' +
                '</div>' +
            '</div>';
        }

        var firstOrder = roomOrders[0];
        var note = (firstOrder.deposit_room || '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        var ordersHtml = roomOrders.map(function(o) {
            var timeStr = '';
            if (o.slot_ranges && o.slot_ranges.length > 0) {
                timeStr = window._rcBuildSlotDisplay(o.slot_ranges);
            } else if (o.slot_labels) {
                timeStr = window._rcMergeSlots(o.slot_labels).merged || '';
            } else if (o.checkin) {
                timeStr = o.checkin + (o.checkout ? ' – ' + o.checkout : '');
            }
            var slotCount = o.slot_count ? parseInt(o.slot_count, 10) : 0;
            var segLabel = _segLabels[o.segment] || '';
            var sc = o.status_color || '#6B7280';
            var createdFmt = o.created_at_fmt || '';

            var row = '<div class="ta-pop-order-row seg-' + (o.segment || 'upcoming') + '">';

            // Line 1: name (left) + badges (right)
            row += '<div class="ta-pop-order-top">';
            row += '<div class="ta-pop-order-name-wrap">';
            if (o.is_new) row += '<span class="ta-rc-new-badge">M&#7899;i</span>';
            row += '<span class="ta-pop-gname">' + (o.buyer_name || 'Khách') + '</span>';
            row += '</div>';
            row += '<div class="ta-pop-order-badges">';
            if (segLabel) row += '<span class="ta-seg-badge ' + (o.segment || '') + '">' + segLabel + '</span>';
            if (o.status_label) row += '<span class="ta-pop-status-pill" style="background:' + sc + '22;color:' + sc + ';border-color:' + sc + '44;">' + o.status_label + '</span>';
            row += '</div>';
            row += '</div>';

            // Line 2: time + slot + created (muted meta)
            var metaParts = [];
            if (timeStr) metaParts.push('<span class="ta-pop-trange">' + timeStr + '</span>');
            if (slotCount > 0) metaParts.push('<span class="ta-pop-scount">' + slotCount + ' khung</span>');
            if (createdFmt) metaParts.push('<span class="ta-pop-created-badge">Tạo: ' + createdFmt + '</span>');
            if (metaParts.length) row += '<div class="ta-pop-order-meta">' + metaParts.join('') + '</div>';

            // Line 3: note for admin
            if (o.note_for_admin) {
                row += '<div class="ta-pop-order-note">' + o.note_for_admin.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</div>';
            }

            row += '</div>';
            return row;
        }).join('');

        var hasActive = roomOrders.some(function(o) { return o.segment === 'active'; });
        return '<div class="ta-room-card" style="margin:0;">' +
            '<div class="ta-rc-head">' +
                '<div class="ta-rc-icon' + (hasActive ? ' active' : '') + '">' + roomIconSvg + '</div>' +
                '<div class="ta-rc-info" style="flex:1;min-width:0;">' +
                    '<div class="ta-rc-name">' + roomName + '</div>' +
                    (branch ? '<div class="ta-rc-branch">' + branch + '</div>' : '') +
                '</div>' +
                '<div style="display:flex;align-items:center;gap:6px;">' +
                    '<div class="ta-rc-count">' + roomOrders.length + ' đơn</div>' +
                    '<button class="ta-pop-deposit-btn" onclick="rcToggleDeposit(this)">' + (note ? 'Sửa ghi chú' : '+ Ghi chú') + '</button>' +
                '</div>' +
            '</div>' +
            '<div class="ta-pop-room-orders">' + ordersHtml + '</div>' +
            '<div class="ta-pop-deposit"' + (note ? '' : ' style="display:none"') + ' data-order-id="' + firstOrder.order_id + '">' +
                '<div class="ta-pop-deposit-display">' + note + '</div>' +
                '<div class="ta-pop-deposit-edit" style="display:none">' +
                    '<textarea class="ta-pop-deposit-input" rows="2" placeholder="Nhập ghi chú...">' + note + '</textarea>' +
                    '<div class="ta-pop-deposit-btns">' +
                        '<button class="ta-pop-deposit-save" onclick="rcSaveDeposit(this)">Lưu</button>' +
                        '<button class="ta-pop-deposit-cancel" onclick="rcCancelDeposit(this)">Huỷ</button>' +
                    '</div>' +
                '</div>' +
            '</div>' +
        '</div>';
    }).join('');
};

window._rcShowPopup = function(orders, title, subtitle, roomList) {
    var body = document.getElementById('ta-rc-popup-body');
    var sub  = document.getElementById('ta-rc-popup-sub');
    var ttl  = document.getElementById('ta-rc-popup-title');
    if (!body) return;
    if (ttl) ttl.textContent = title    || 'Đơn đã chọn';
    if (sub) sub.textContent = subtitle || (orders.length + ' đơn');
    body.innerHTML = window._rcBuildPopupHtml(orders, roomList);
    var popup = document.getElementById('ta-rc-popup');
    if (popup) popup.style.display = '';
};

window.rcOpenSelectedPopup = function() {
    var orders = Object.values(window._rcSelectedOrders);
    window._rcShowPopup(orders, 'Đơn đã chọn', orders.length + ' đơn được chọn');
};

window.rcOpenSegmentPopup = function(segment) {
    var orders = [];
    document.querySelectorAll('#ta-room-grid .ta-rc-order-item[data-segment="' + segment + '"]').forEach(function(item) {
        var orderData = {};
        try { orderData = JSON.parse(item.dataset.order || '{}'); } catch(e) { return; }
        var card   = item.closest('.ta-room-card');
        var nameEl = card ? card.querySelector('.ta-rc-name') : null;
        orderData.room_name   = nameEl ? nameEl.textContent.trim() : '';
        orderData.room_branch = card ? (card.dataset.branch || '') : '';
        orders.push(orderData);
    });
    var titles    = { active: 'Đơn đang ở',                  today: 'Đơn hôm nay' };
    var subtitles = { active: orders.length + ' đơn đang ở',  today: orders.length + ' đơn hôm nay' };
    window._rcShowPopup(orders, titles[segment] || 'Đơn', subtitles[segment] || (orders.length + ' đơn'));
};

window.rcOpenNowPopup = function() {
    var orders = [];
    var roomList = [];
    var seenRooms = {};

    // Collect all rooms from the DOM first (preserves grid order, includes empty rooms)
    // Skip rooms with styles=2 (non-slot style rooms)
    document.querySelectorAll('#ta-room-grid .ta-room-card').forEach(function(card) {
        if (card.dataset.styles === '2') return;
        var nameEl = card.querySelector('.ta-rc-name');
        var name = nameEl ? nameEl.textContent.trim() : '';
        if (!name || seenRooms[name]) return;
        seenRooms[name] = true;
        roomList.push({ name: name, branch: card.dataset.branch || '' });
    });

    ['active', 'today'].forEach(function(seg) {
        document.querySelectorAll('#ta-room-grid .ta-rc-order-item[data-segment="' + seg + '"]').forEach(function(item) {
            var orderData = {};
            try { orderData = JSON.parse(item.dataset.order || '{}'); } catch(e) { return; }
            var card   = item.closest('.ta-room-card');
            if (card && card.dataset.styles === '2') return;
            var nameEl = card ? card.querySelector('.ta-rc-name') : null;
            orderData.room_name   = nameEl ? nameEl.textContent.trim() : '';
            orderData.room_branch = card ? (card.dataset.branch || '') : '';
            orders.push(orderData);
        });
    });
    window._rcShowPopup(orders, 'Lịch hôm nay', orders.length + ' đơn đang ở & hôm nay', roomList);
};

window.rcToggleDeposit = function(btn) {
    var card = btn.closest('.ta-room-card');
    if (!card) return;
    var wrap    = card.querySelector('.ta-pop-deposit');
    var editDiv = wrap.querySelector('.ta-pop-deposit-edit');
    var dispDiv = wrap.querySelector('.ta-pop-deposit-display');
    if (editDiv.style.display !== 'none') {
        editDiv.style.display = 'none';
        var hasNote = dispDiv.textContent.trim();
        wrap.style.display = hasNote ? '' : 'none';
        btn.textContent = hasNote ? 'Sửa ghi chú' : '+ Ghi chú';
    } else {
        wrap.querySelector('.ta-pop-deposit-input').value = dispDiv.textContent.trim();
        wrap.style.display = '';
        editDiv.style.display = '';
        btn.textContent = 'Đóng';
        wrap.querySelector('.ta-pop-deposit-input').focus();
    }
};

window.rcSaveDeposit = function(btn) {
    var wrap    = btn.closest('.ta-pop-deposit');
    var orderId = wrap.dataset.orderId;
    var note    = wrap.querySelector('.ta-pop-deposit-input').value.trim();
    btn.disabled = true; btn.textContent = 'Đang lưu...';
    fetch('/admin/api/orders/' + orderId + '/deposit-room', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ deposit_room: note }),
        credentials: 'same-origin',
    })
    .then(function(r){ return r.ok ? r.json() : null; })
    .then(function(d) {
        if (!d) { btn.disabled = false; btn.textContent = 'Lưu'; return; }
        if (window._rcSelectedOrders[orderId]) window._rcSelectedOrders[orderId].deposit_room = note;
        var card      = wrap.closest('.ta-room-card');
        var dispDiv   = wrap.querySelector('.ta-pop-deposit-display');
        var toggleBtn = card ? card.querySelector('.ta-pop-deposit-btn') : null;
        dispDiv.innerHTML = note.replace(/</g,'&lt;').replace(/>/g,'&gt;');
        wrap.style.display = note ? '' : 'none';
        if (toggleBtn) toggleBtn.textContent = note ? 'Sửa ghi chú' : '+ Ghi chú';
        wrap.querySelector('.ta-pop-deposit-edit').style.display = 'none';
        btn.disabled = false; btn.textContent = 'Lưu';
    })
    .catch(function(){ btn.disabled = false; btn.textContent = 'Lưu'; });
};

window.rcCancelDeposit = function(btn) {
    var wrap    = btn.closest('.ta-pop-deposit');
    var dispDiv = wrap.querySelector('.ta-pop-deposit-display');
    var card    = wrap.closest('.ta-room-card');
    var togBtn  = card ? card.querySelector('.ta-pop-deposit-btn') : null;
    wrap.querySelector('.ta-pop-deposit-edit').style.display = 'none';
    var hasNote = dispDiv.textContent.trim();
    wrap.style.display = hasNote ? '' : 'none';
    if (togBtn) togBtn.textContent = hasNote ? 'Sửa ghi chú' : '+ Ghi chú';
};

window.rcCloseSelectedPopup = function() {
    var popup = document.getElementById('ta-rc-popup');
    if (popup) popup.style.display = 'none';
};

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') window.rcCloseSelectedPopup();
});
</script>
@endscript

{{-- Script 2: polling --}}
@script
<script>
(function() {
    var statusColorMap = {
        pending: '#f59e0b', deposit: '#d97757', paid: '#10b981',
        shipping: '#3b82f6', completed: '#8b5cf6', cancelled: '#ef4444', failed: '#ef4444'
    };

    function buildOrdersHtml(orders) {
        if (!orders || orders.length === 0) {
            return '<div class="ta-rc-empty">Không có đơn</div><div class="ta-rc-no-match" style="display:none;"></div>';
        }
        var html = '';
        orders.forEach(function(o) {
            var col      = statusColorMap[o.status] || '#94a3b8';
            var seg      = o.segment || 'upcoming';
            var segLabel = seg === 'active' ? 'Đang ở' : (seg === 'today' ? 'Hôm nay' : (seg === 'overdue' ? 'Quá hạn' : (o.checkin || 'Sắp tới')));
            var timeHtml = o.checkin
                ? '<span class="ta-rc-time"><svg width="9" height="9" viewBox="0 0 24 24" fill="none" style="display:inline;vertical-align:middle;margin-right:2px;"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>' + o.checkin + (o.checkout ? ' → ' + o.checkout : '') + '</span>'
                : '';
            var amtHtml  = o.amount > 0 ? '<span class="ta-rc-amount">' + Number(o.amount).toLocaleString('vi-VN') + '₫</span>' : '<span></span>';

            var slotHtml = '';
            if (o.slot_count !== null && o.slot_count !== undefined) {
                slotHtml = '<div class="ta-rc-slot-row">' +
                    '<span class="ta-rc-slot-badge">' +
                        '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" style="display:inline;vertical-align:middle;margin-right:3px;"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>' +
                        o.slot_count + ' khung giờ' +
                    '</span>' +
                    (o.slot_labels ? '<span class="ta-rc-slot-labels">' + o.slot_labels + '</span>' : '') +
                '</div>';
            }

            var _oAttr = window._escAttr ? window._escAttr(JSON.stringify({
                order_id: o.order_id, order_code: o.order_code,
                buyer_name: o.buyer_name, buyer_phone: o.buyer_phone,
                checkin: o.checkin, checkout: o.checkout,
                status_label: o.status_label, status_color: col,
                amount: o.amount, segment: seg,
                slot_count: o.slot_count, slot_labels: o.slot_labels,
                slot_ranges: o.slot_ranges || [],
                created_at: o.created_at,
                deposit_room: o.deposit_room,
                note_for_admin: o.note_for_admin || ''
            })) : '';
            html +=
                '<div class="ta-rc-order-item' + (o.is_new ? ' is-new' : '') + ' seg-' + seg + '" data-segment="' + seg + '" data-order="' + _oAttr + '">' +
                  '<div class="ta-rc-order-top">' +
                    '<div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;">' +
                      '<label class="ta-rc-check-wrap"><input type="checkbox" class="ta-rc-checkbox" onchange="rcToggleOrder(this)"><span class="ta-rc-check-box"></span></label>' +
                      '<span class="ta-rc-code">#' + o.order_code + '</span>' +
                      (o.is_new ? '<span class="ta-rc-new-badge">M&#7899;i</span>' : '') +
                      '<span class="ta-seg-badge ' + seg + '">' + segLabel + '</span>' +
                    '</div>' +
                    '<span class="ta-rc-status-pill" style="background:' + col + '22;color:' + col + ';border-color:' + col + '44;">' + o.status_label + '</span>' +
                  '</div>' +
                  '<div class="ta-rc-order-bot">' +
                    '<span class="ta-rc-guest"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" style="display:inline;vertical-align:middle;margin-right:3px;"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>' + o.buyer_name + (o.buyer_phone ? ' · ' + o.buyer_phone : '') + '</span>' +
                    timeHtml +
                  '</div>' +
                  slotHtml +
                  '<div class="ta-rc-order-footer">' +
                    amtHtml +
                    '<div style="display:flex;align-items:center;gap:5px;">' +
                      '<span class="ta-rc-ago">' + o.created_at + '</span>' +
                      '<a href="/admin/orders/' + o.order_id + '/edit" class="ta-rc-btn-detail"><svg width="11" height="11" viewBox="0 0 24 24" fill="none"><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" stroke="currentColor" stroke-width="2"/><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" stroke="currentColor" stroke-width="2"/></svg>Chi tiết</a>' +
                    '</div>' +
                  '</div>' +
                '</div>';
        });
        html += '<div class="ta-rc-no-match" style="display:none;">Không có đơn ở chế độ xem này</div>';
        return html;
    }

    function renderRoomCards(data) {
        if (window._rcSelectedOrders && Object.keys(window._rcSelectedOrders).length > 0) {
            window._rcSelectedOrders = {};
            if (window.rcUpdateSelBtn) window.rcUpdateSelBtn();
            if (window.rcCloseSelectedPopup) window.rcCloseSelectedPopup();
        }
        var grid = document.getElementById('ta-room-grid');
        var tabs = document.getElementById('ta-rc-tabs');
        if (!grid || !tabs || !data) return;

        var ids  = { all: 'ta-rct-badge-all', active: 'ta-rct-badge-active', today: 'ta-rct-badge-today', upcoming: 'ta-rct-badge-upcoming', overdue: 'ta-rct-badge-overdue' };
        var vals = { all: data.total_orders, active: data.total_active, today: data.total_today, upcoming: data.total_upcoming, overdue: data.total_overdue };
        Object.keys(ids).forEach(function(k) {
            var el = document.getElementById(ids[k]);
            if (el) el.textContent = (vals[k] > 0) ? vals[k] : '';
        });
        // Đặt cọc badge — đếm từ DOM sau khi render xong
        var depositCount = document.querySelectorAll('#ta-room-grid .ta-rc-order-item[data-status="deposit"]').length;
        var elDepositBadge = document.getElementById('ta-rct-badge-deposit');
        if (elDepositBadge) elDepositBadge.textContent = depositCount > 0 ? depositCount : '';

        // Update combined now-button (Đang ở + Hôm nay)
        var btnNow   = document.getElementById('ta-rc-btn-now');
        var cntActive = document.getElementById('ta-rc-cnt-active');
        var cntToday  = document.getElementById('ta-rc-cnt-today');
        if (cntActive) cntActive.textContent  = data.total_active;
        if (cntToday)  cntToday.textContent   = data.total_today;
        if (btnNow)    btnNow.style.display   = (data.total_active + data.total_today) > 0 ? '' : 'none';

        var allBadge = tabs.querySelector('[data-branch="__all__"] .ta-rc-tab-badge');
        if (allBadge) allBadge.textContent = data.total_orders > 0 ? data.total_orders : '';
        var statRooms = document.getElementById('ta-rc-stat-rooms');
        if (statRooms) statRooms.textContent = data.total_rooms;

        var existingBtns = {};
        tabs.querySelectorAll('.ta-rc-tab:not([data-branch="__all__"])').forEach(function(b) {
            existingBtns[b.dataset.branch] = b;
        });
        var newBranches  = data.branches || [];
        var branchNames  = newBranches.map(function(b) { return b.name; });
        Object.keys(existingBtns).forEach(function(name) {
            if (!branchNames.includes(name)) existingBtns[name].remove();
        });
        newBranches.forEach(function(branch) {
            var btn = existingBtns[branch.name];
            if (!btn) {
                btn = document.createElement('button');
                btn.className = 'ta-rc-tab';
                btn.dataset.branch = branch.name;
                btn.setAttribute('onclick', 'rcSwitchTab(this)');
                tabs.appendChild(btn);
            }
            var badge = branch.new_count > 0
                ? '<span class="ta-rc-tab-badge new">' + branch.new_count + '</span>'
                : (branch.order_count > 0 ? '<span class="ta-rc-tab-badge">' + branch.order_count + '</span>' : '');
            btn.innerHTML = branch.name + badge;
            btn.dataset.branch = branch.name;
        });

        var rooms        = data.rooms || [];
        var existingCards = {};
        grid.querySelectorAll('.ta-room-card').forEach(function(c) { existingCards[c.dataset.product] = c; });
        var newProductIds = rooms.map(function(r) { return String(r.product_id); });
        Object.keys(existingCards).forEach(function(pid) {
            if (!newProductIds.includes(pid)) existingCards[pid].remove();
        });

        rooms.forEach(function(room, idx) {
            var pid   = String(room.product_id);
            var card  = existingCards[pid];
            var isNew = room.has_new;
            if (!card) {
                card = document.createElement('div');
                card.className = 'ta-room-card';
                card.dataset.product = pid;
                grid.appendChild(card);
                existingCards[pid] = card;
            }
            card.dataset.branch  = room.branch;
            card.dataset.styles  = String(room.styles || 1);
            card.dataset.time    = room.latest_time;
            card.classList.toggle('has-new',    isNew);
            card.classList.toggle('has-active', room.active_count > 0);

            card.innerHTML =
                '<div class="ta-rc-head">' +
                  '<div class="ta-rc-icon' + (room.active_count > 0 ? ' active' : '') + '">' +
                    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>' +
                  '</div>' +
                  '<div class="ta-rc-info">' +
                    '<div class="ta-rc-name">' + (isNew ? '<span class="ta-rc-new-dot"></span>' : '') + room.room_name + '</div>' +
                    '<div class="ta-rc-branch">' + room.branch + '</div>' +
                  '</div>' +
                  '<div class="ta-rc-count' + (room.count === 0 ? ' empty' : '') + '">' + (room.count > 0 ? room.count + ' đơn' : 'Trống') + '</div>' +
                '</div>' +
                '<div class="ta-rc-orders">' + buildOrdersHtml(room.orders) + '</div>';

            var children = Array.from(grid.children);
            if (children.indexOf(card) !== idx) grid.insertBefore(card, grid.children[idx] || null);
        });

        window.rcApplyFilters();
    }

    var rcPulse = document.getElementById('ta-rc-pulse');

    function getCurrentPeriod() {
        var active = document.querySelector('.ta-tab.active[data-period]');
        return active ? active.dataset.period : '30d';
    }

    function setKpiDelta(el, val, unit) {
        if (!el) return;
        var isUp = val >= 0;
        var w    = unit === 'pt' ? Math.min(Math.abs(val) * 5, 100) : Math.min(Math.abs(val) * 2, 100);
        el.className = 'ta-kpi-delta ' + (isUp ? 'up' : 'down');
        el.innerHTML = (isUp ? '↑' : '↓') + ' ' + Math.abs(val) + unit +
                       ' <span class="dbar" style="--w:' + w + '%"></span>';
    }

    function pollKpi() {
        var period = getCurrentPeriod();
        var url    = '/admin/api/kpi-stats?period=' + period;
        if (period === 'custom') {
            var cs = document.getElementById('ta-custom-start');
            var ce = document.getElementById('ta-custom-end');
            if (cs && cs.value) url += '&custom_start=' + cs.value;
            if (ce && ce.value) url += '&custom_end='   + ce.value;
        }
        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(d) {
                if (!d) return;
                var elTotal = document.getElementById('ta-kpi-total');
                var elPaid  = document.getElementById('ta-kpi-paid');
                var elRev   = document.getElementById('ta-kpi-revenue');
                var elPayos = document.getElementById('ta-kpi-revenue-payos');
                var elCod          = document.getElementById('ta-kpi-revenue-cod');
                var elDepositPayos = document.getElementById('ta-kpi-revenue-deposit-payos');
                var elDepositCod   = document.getElementById('ta-kpi-revenue-deposit-cod');
                var elRange        = document.getElementById('ta-period-range');
                if (elTotal)        elTotal.textContent        = Number(d.total).toLocaleString('vi-VN');
                if (elPaid)         elPaid.textContent         = Number(d.paidCount).toLocaleString('vi-VN');
                if (elRev)          elRev.innerHTML            = Number(d.revenue).toLocaleString('vi-VN') + '<span class="unit">đ</span>';
                if (elPayos)        elPayos.innerHTML          = Number(d.revenuePayos).toLocaleString('vi-VN') + '<span class="unit">đ</span>';
                if (elCod)          elCod.innerHTML            = Number(d.revenueCod).toLocaleString('vi-VN') + '<span class="unit">đ</span>';
                if (elDepositPayos) elDepositPayos.innerHTML   = Number(d.revenueDepositPayos || 0).toLocaleString('vi-VN') + '<span class="unit">đ</span>';
                if (elDepositCod)   elDepositCod.innerHTML     = Number(d.revenueDepositCod || 0).toLocaleString('vi-VN') + '<span class="unit">đ</span>';
                if (elRange && d.dateRange) elRange.textContent = d.dateRange;
                if (d.prevDateRange) {
                    document.querySelectorAll('.ta-kpi-hint-range').forEach(function(el) {
                        el.textContent = d.prevDateRange;
                    });
                }
                setKpiDelta(document.getElementById('ta-kpi-total-delta'),         d.totalDelta,        '%');
                setKpiDelta(document.getElementById('ta-kpi-paid-delta'),          d.paidDelta,         '%');
                setKpiDelta(document.getElementById('ta-kpi-revenue-delta'),       d.revenueDelta,      '%');
                setKpiDelta(document.getElementById('ta-kpi-revenue-payos-delta'), d.revenuePayosDelta, '%');
                setKpiDelta(document.getElementById('ta-kpi-revenue-cod-delta'),           d.revenueCodDelta,              '%');
                setKpiDelta(document.getElementById('ta-kpi-revenue-deposit-payos-delta'), d.revenueDepositPayosDelta || 0, '%');
                setKpiDelta(document.getElementById('ta-kpi-revenue-deposit-cod-delta'),   d.revenueDepositCodDelta || 0,   '%');
            })
            .catch(function() {});
    }

    function pollRoomCards() {
        if (rcPulse) rcPulse.style.display = '';
        fetch('/admin/api/room-cards', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(renderRoomCards)
            .catch(function() {})
            .finally(function() { if (rcPulse) rcPulse.style.display = 'none'; });
    }

    // ── Bell sound (Web Audio API) ────────────────────────────────────
    var _audioCtx = null;

    function _getAudioCtx() {
        if (!_audioCtx) {
            _audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
        return _audioCtx;
    }

    document.addEventListener('click', function() {
        var ctx = _getAudioCtx();
        if (ctx.state === 'suspended') ctx.resume();
    });

    function _ringBell(ctx) {
        [880, 1108, 1320].forEach(function(freq, i) {
            var osc  = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.type = 'sine';
            osc.frequency.value = freq;
            var t = ctx.currentTime + i * 0.18;
            gain.gain.setValueAtTime(0, t);
            gain.gain.linearRampToValueAtTime(0.35, t + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.001, t + 1.4);
            osc.start(t);
            osc.stop(t + 1.4);
        });
    }

    function playOrderBell() {
        try {
            var ctx = _getAudioCtx();
            if (ctx.state === 'suspended') {
                ctx.resume().then(function() { _ringBell(ctx); });
            } else {
                _ringBell(ctx);
            }
        } catch (e) {}
    }

    // ── Order signal polling ─────────────────────────────────────────
    var _lastCreatedTs = null;
    var _lastUpdatedTs = null;

    function pollNewOrderSignal() {
        fetch('/admin/api/orders/latest-ts', { credentials: 'same-origin' })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(d) {
                if (!d) return;
                var createdTs = d.created_ts || 0;
                var updatedTs = d.updated_ts || 0;

                if (_lastCreatedTs === null) {
                    _lastCreatedTs = createdTs;
                    _lastUpdatedTs = updatedTs;
                    return;
                }

                var isNewOrder     = createdTs > _lastCreatedTs;
                var isStatusChange = updatedTs > _lastUpdatedTs;

                if (isNewOrder) {
                    _lastCreatedTs = createdTs;
                    playOrderBell();
                }

                if (isNewOrder || isStatusChange) {
                    _lastUpdatedTs = updatedTs;
                    pollRoomCards();
                    pollKpi();
                    if (window.pollBottomCharts) window.pollBottomCharts();
                }
            })
            .catch(function() {});
    }

    pollNewOrderSignal();
    setInterval(pollNewOrderSignal, 10000);
    setInterval(pollRoomCards, 120000);
    setInterval(pollKpi, 90000);
    setInterval(function() { if (window.pollBottomCharts) window.pollBottomCharts(); }, 120000);

    pollKpi();

    // ── Branch Filter Panel ──────────────────────────────────────────
    var _branchOpen        = false;
    var _selectedBranchId  = '';   // '' = tất cả

    window.taBranchToggle = function() {
        _branchOpen = !_branchOpen;
        var panel = document.getElementById('ta-branch-panel');
        var btn   = document.getElementById('ta-branch-toggle');
        if (!panel) return;
        panel.style.display = _branchOpen ? '' : 'none';
        if (btn) btn.classList.toggle('active', _branchOpen);
        if (_branchOpen) fetchBranchStats();
    };

    window.taBranchSelect = function(el) {
        if (!el) return;
        var branchId = el.dataset.branchId || '';
        _selectedBranchId = branchId;
        window._taBranchId = branchId;

        // Cập nhật active state
        document.querySelectorAll('.ta-branch-item').forEach(function(item) {
            item.classList.toggle('active', (item.dataset.branchId || '') === branchId);
        });

        // Cập nhật label trên button
        var btn = document.getElementById('ta-branch-toggle');
        if (btn) {
            var nameEl = el.querySelector('.ta-branch-item-name');
            var label  = branchId ? (nameEl ? nameEl.textContent.trim() : 'Chi nhánh') : 'Chi nhánh';
            btn.childNodes.forEach(function(n) { if (n.nodeType === 3) n.textContent = ' ' + label + ' '; });
        }

        // Cập nhật hint footer
        var hint = document.getElementById('ta-branch-foot-hint');
        if (hint) {
            hint.textContent = branchId
                ? '⚡ Đang lọc: ' + (el.querySelector('.ta-branch-item-name') || {textContent:''}).textContent.trim()
                : 'Click vào chi nhánh để lọc tất cả số liệu ở trên';
            hint.style.color = branchId ? '#059669' : '';
        }

        // Đồng bộ tab chi nhánh ở Lịch phòng
        var branchName = branchId
            ? ((el.querySelector('.ta-branch-item-name') || {textContent:''}).textContent.trim())
            : '__all__';
        if (window._rcActiveBranch !== undefined) {
            window._rcActiveBranch = branchName;
            if (window.rcApplyFilters) window.rcApplyFilters();
        }

        // Reload KPI + các biểu đồ bên dưới với branch filter
        pollKpi();
        if (window.pollBottomCharts) window.pollBottomCharts();
    };

    // Override pollKpi để truyền branch_id
    var _origPollKpi = pollKpi;
    pollKpi = function() {
        var period = getCurrentPeriod();
        var url    = '/admin/api/kpi-stats?period=' + period;
        if (period === 'custom') {
            var cs = document.getElementById('ta-custom-start');
            var ce = document.getElementById('ta-custom-end');
            if (cs && cs.value) url += '&custom_start=' + cs.value;
            if (ce && ce.value) url += '&custom_end='   + ce.value;
        }
        if (_selectedBranchId) url += '&branch_id=' + _selectedBranchId;

        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(d) {
                if (!d) return;
                var elTotal        = document.getElementById('ta-kpi-total');
                var elPaid         = document.getElementById('ta-kpi-paid');
                var elRev          = document.getElementById('ta-kpi-revenue');
                var elPayos        = document.getElementById('ta-kpi-revenue-payos');
                var elCod          = document.getElementById('ta-kpi-revenue-cod');
                var elDepositPayos = document.getElementById('ta-kpi-revenue-deposit-payos');
                var elDepositCod   = document.getElementById('ta-kpi-revenue-deposit-cod');
                var elRange        = document.getElementById('ta-period-range');
                if (elTotal)        elTotal.textContent        = Number(d.total).toLocaleString('vi-VN');
                if (elPaid)         elPaid.textContent         = Number(d.paidCount).toLocaleString('vi-VN');
                if (elRev)          elRev.innerHTML            = Number(d.revenue).toLocaleString('vi-VN') + '<span class="unit">đ</span>';
                if (elPayos)        elPayos.innerHTML          = Number(d.revenuePayos).toLocaleString('vi-VN') + '<span class="unit">đ</span>';
                if (elCod)          elCod.innerHTML            = Number(d.revenueCod).toLocaleString('vi-VN') + '<span class="unit">đ</span>';
                if (elDepositPayos) elDepositPayos.innerHTML   = Number(d.revenueDepositPayos || 0).toLocaleString('vi-VN') + '<span class="unit">đ</span>';
                if (elDepositCod)   elDepositCod.innerHTML     = Number(d.revenueDepositCod || 0).toLocaleString('vi-VN') + '<span class="unit">đ</span>';
                if (elRange && d.dateRange) elRange.textContent = d.dateRange;
                if (d.prevDateRange) {
                    document.querySelectorAll('.ta-kpi-hint-range').forEach(function(el) {
                        el.textContent = d.prevDateRange;
                    });
                }
                setKpiDelta(document.getElementById('ta-kpi-total-delta'),                 d.totalDelta,              '%');
                setKpiDelta(document.getElementById('ta-kpi-paid-delta'),                  d.paidDelta,               '%');
                setKpiDelta(document.getElementById('ta-kpi-revenue-delta'),               d.revenueDelta,            '%');
                setKpiDelta(document.getElementById('ta-kpi-revenue-payos-delta'),         d.revenuePayosDelta,       '%');
                setKpiDelta(document.getElementById('ta-kpi-revenue-cod-delta'),           d.revenueCodDelta,         '%');
                setKpiDelta(document.getElementById('ta-kpi-revenue-deposit-payos-delta'), d.revenueDepositPayosDelta || 0, '%');
                setKpiDelta(document.getElementById('ta-kpi-revenue-deposit-cod-delta'),   d.revenueDepositCodDelta || 0,   '%');

                // Refresh branch stats nếu panel đang mở
                if (_branchOpen) fetchBranchStats();
            })
            .catch(function() {});
    };

    // Fetch stats cho từng chi nhánh trong panel
    function fetchBranchStats() {
        var period = getCurrentPeriod();
        var url    = '/admin/api/branch-revenue?period=' + period;
        if (period === 'custom') {
            var cs = document.getElementById('ta-custom-start');
            var ce = document.getElementById('ta-custom-end');
            if (cs && cs.value) url += '&custom_start=' + cs.value;
            if (ce && ce.value) url += '&custom_end='   + ce.value;
        }
        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(d) {
                if (!d) return;
                var rangeEl = document.getElementById('ta-branch-range');
                if (rangeEl && d.dateRange) rangeEl.textContent = d.dateRange;

                // Cập nhật "Tất cả"
                var allEl = document.getElementById('ta-bfi-all');
                if (allEl) {
                    var allCount   = (d.branches || []).reduce(function(s, b) { return s + b.count; }, 0);
                    allEl.querySelector('.ta-bfi-count').textContent = allCount + ' đơn';
                    allEl.querySelector('.ta-bfi-rev').textContent   = Number(d.total).toLocaleString('vi-VN') + 'đ';
                }

                // Cập nhật từng chi nhánh
                (d.branches || []).forEach(function(b) {
                    var items = document.querySelectorAll('.ta-branch-item[data-branch-id]');
                    items.forEach(function(item) {
                        var statsEl = item.querySelector('.ta-branch-item-stats');
                        if (!statsEl) return;
                        var nameEl = item.querySelector('.ta-branch-item-name');
                        if (nameEl && nameEl.textContent.trim() === b.name) {
                            statsEl.querySelector('.ta-bfi-count').textContent = b.count + ' đơn';
                            statsEl.querySelector('.ta-bfi-rev').textContent   = Number(b.revenue).toLocaleString('vi-VN') + 'đ';
                        }
                    });
                });
            })
            .catch(function() {});
    }

    // Refresh panel khi period thay đổi (Livewire re-render)
    document.addEventListener('livewire:updated', function() {
        if (_branchOpen) fetchBranchStats();
    });

    window.rcPollNow = function() { pollRoomCards(); pollKpi(); if (window.pollBottomCharts) window.pollBottomCharts(); };
})();
</script>
@endscript

{{-- ECharts (synchronous — must come before the @script block that uses it) --}}
<script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>

@script
<script>
(function () {
    var rrData = JSON.parse(document.getElementById('ta-rr-data')?.textContent || '{"rooms":[],"total":0,"available_years":[]}');
    var mrData = JSON.parse(document.getElementById('ta-mr-data')?.textContent || '{"months":[0,0,0,0,0,0,0,0,0,0,0,0],"available_years":[]}');

    var COLORS = ['#10b981','#3b82f6','#8b5cf6','#f59e0b','#ef4444','#06b6d4','#f97316','#84cc16','#ec4899','#6366f1'];

    function fmtM(v) { return Number(v).toLocaleString('vi-VN') + 'đ'; }

    /* ── Update donut center ── */
    function updateCenter(room) {
        var lbl = document.getElementById('ta-rr-center-label');
        var val = document.getElementById('ta-rr-center-val');
        if (!room) {
            if (lbl) lbl.textContent = '—';
            if (val) val.textContent = '—';
            return;
        }
        if (lbl) lbl.textContent = room.name;
        if (val) val.textContent = fmtM(room.revenue) + ' (' + room.pct + '%)';
    }

    /* ── Render room table rows ── */
    function renderRrTable(data) {
        var rooms = data.rooms || [];
        var tbody = document.getElementById('ta-rr-tbody');
        if (!tbody) return;

        if (!rooms.length) {
            tbody.innerHTML = '<div class="ta-rr-empty">Chưa có dữ liệu doanh thu năm ' + (data.year || '') + '</div>';
        } else {
            tbody.innerHTML = rooms.map(function (r, i) {
                return '<div class="ta-rr-tr" data-idx="' + i + '" data-name="' + r.name.replace(/[<>"]/g, '') + '">' +
                    '<div class="ta-rr-svc">' +
                        '<span class="ta-rr-dot" style="background:' + COLORS[i % COLORS.length] + ';"></span>' +
                        '<span class="ta-rr-name">' + r.name + '</span>' +
                        '<span class="ta-rr-pct">' + r.pct + '%</span>' +
                    '</div>' +
                    '<div class="ta-rr-num">' + r.order_count + '</div>' +
                    '<div class="ta-rr-num ta-rr-bold">' + fmtM(r.revenue) + '</div>' +
                '</div>';
            }).join('');
        }
        bindTableHover(rooms);
    }

    /* ── Update summary panel ── */
    function renderRrSummary(data) {
        var rooms = data.rooms || [];
        var sumEl = document.getElementById('ta-rr-sum-total');
        if (sumEl) sumEl.textContent = fmtM(data.total || 0);

        var bestRow  = document.getElementById('ta-rr-sum-best-row');
        var worstRow = document.getElementById('ta-rr-sum-worst-row');
        var bestEl   = document.getElementById('ta-rr-sum-best');
        var worstEl  = document.getElementById('ta-rr-sum-worst');

        if (bestRow)  bestRow.style.display  = rooms.length > 0 ? '' : 'none';
        if (worstRow) worstRow.style.display = rooms.length > 1 ? '' : 'none';
        if (bestEl  && rooms[0])              bestEl.textContent  = rooms[0].name;
        if (worstEl && rooms.length > 1)      worstEl.textContent = rooms[rooms.length - 1].name;

        if (rooms[0]) updateCenter(rooms[0]);
        else          updateCenter(null);
    }

    /* ── Custom year picker init ── */
    function initYearPicker(pickerId, onChange) {
        var picker = document.getElementById(pickerId);
        if (!picker) return;
        var btn  = picker.querySelector('.ta-yr-btn');
        var drop = picker.querySelector('.ta-yr-drop');

        if (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                document.querySelectorAll('.ta-yr-picker.open').forEach(function (p) {
                    if (p !== picker) p.classList.remove('open');
                });
                picker.classList.toggle('open');
            });
        }

        if (drop) {
            drop.addEventListener('click', function (e) {
                var opt = e.target.closest('.ta-yr-opt');
                if (!opt) return;
                var yr = parseInt(opt.dataset.year, 10);
                drop.querySelectorAll('.ta-yr-opt').forEach(function (o) { o.classList.remove('active'); });
                opt.classList.add('active');
                var valEl = picker.querySelector('.ta-yr-val');
                if (valEl) valEl.textContent = yr;
                picker.dataset.selected = yr;
                picker.classList.remove('open');
                if (onChange) onChange(yr);
            });
        }
    }

    /* ── Sync year picker options with available_years list ── */
    function updateYearSelectors(years, rrSelected, mrSelected) {
        function syncPicker(pickerId, selected) {
            var picker = document.getElementById(pickerId);
            if (!picker || !years || !years.length) return;
            var cur = selected != null ? selected : parseInt(picker.dataset.selected || years[0], 10);
            var drop = picker.querySelector('.ta-yr-drop');
            if (drop) {
                drop.innerHTML = years.map(function (y) {
                    return '<div class="ta-yr-opt' + (y === cur ? ' active' : '') + '" role="option" data-year="' + y + '">' + y + '</div>';
                }).join('');
            }
            var valEl = picker.querySelector('.ta-yr-val');
            if (valEl) valEl.textContent = cur;
            picker.dataset.selected = cur;
        }
        syncPicker('ta-rr-yr', rrSelected);
        syncPicker('ta-mr-yr', mrSelected);
    }

    /* ── Room Revenue Donut ── */
    function initRrDonut(data) {
        var el = document.getElementById('ta-rr-donut');
        if (!el || !window.echarts) return;

        var rooms = data.rooms || [];
        var chart = echarts.getInstanceByDom(el) || echarts.init(el, null, { renderer: 'svg' });

        chart.setOption({
            tooltip: { show: false },
            legend:  { show: false },
            series:  [{
                type:      'pie',
                radius:    ['64%', '90%'],
                center:    ['50%', '50%'],
                padAngle:  2,
                itemStyle: { borderRadius: 4, borderColor: '#fff', borderWidth: 2 },
                label:     { show: false },
                emphasis:  { scale: true, scaleSize: 5,
                             itemStyle: { shadowBlur: 10, shadowColor: 'rgba(0,0,0,.12)' } },
                data: rooms.length
                    ? rooms.map(function (r, i) {
                        return { value: r.revenue, name: r.name,
                                 itemStyle: { color: COLORS[i % COLORS.length] } };
                      })
                    : [{ value: 1, name: '—', itemStyle: { color: '#e5e7eb' } }],
            }],
        });

        chart.on('mouseover', function (p) {
            var r = rooms.find(function (x) { return x.name === p.name; });
            if (r) updateCenter(r);
        });
        chart.on('mouseout', function () {
            if (rooms[0]) updateCenter(rooms[0]);
            else          updateCenter(null);
        });

        if (rooms[0]) updateCenter(rooms[0]);
        else          updateCenter(null);

        window.addEventListener('resize', function () { chart.resize(); });
    }

    /* ── Table-row hover → highlight donut slice ── */
    function bindTableHover(rooms) {
        document.querySelectorAll('#ta-rr-tbody .ta-rr-tr').forEach(function (row) {
            row.addEventListener('mouseenter', function () {
                var idx   = parseInt(row.dataset.idx, 10);
                var el    = document.getElementById('ta-rr-donut');
                var chart = el && echarts.getInstanceByDom(el);
                if (chart) chart.dispatchAction({ type: 'highlight', seriesIndex: 0, dataIndex: idx });
                if (rooms[idx]) updateCenter(rooms[idx]);
            });
            row.addEventListener('mouseleave', function () {
                var idx   = parseInt(row.dataset.idx, 10);
                var el    = document.getElementById('ta-rr-donut');
                var chart = el && echarts.getInstanceByDom(el);
                if (chart) chart.dispatchAction({ type: 'downplay', seriesIndex: 0, dataIndex: idx });
                if (rooms[0]) updateCenter(rooms[0]);
            });
        });
    }

    /* ── Monthly Revenue Bar ── */
    function initMonthlyBar(data) {
        var el = document.getElementById('ta-monthly-chart');
        if (!el || !window.echarts) return;

        var months = data.months || Array(12).fill(0);
        var labels = ['T1','T2','T3','T4','T5','T6','T7','T8','T9','T10','T11','T12'];
        var maxVal = Math.max.apply(null, months);
        var chart  = echarts.getInstanceByDom(el) || echarts.init(el, null, { renderer: 'svg' });

        chart.setOption({
            grid: { top: 28, right: 12, bottom: 36, left: 54 },
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'shadow' },
                formatter: function (params) {
                    var p = params[0];
                    return '<b>' + p.name + '</b><br/>' + fmtM(p.value);
                },
                backgroundColor: '#1e293b',
                borderColor:     'transparent',
                textStyle:       { color: '#f1f5f9', fontSize: 12 },
                padding:         [6, 10],
            },
            xAxis: {
                type:      'category',
                data:      labels,
                axisLine:  { lineStyle: { color: '#e5e7eb' } },
                axisTick:  { show: false },
                axisLabel: { color: '#6b7280', fontSize: 11 },
            },
            yAxis: {
                type:      'value',
                axisLabel: {
                    color: '#6b7280', fontSize: 10,
                    formatter: function (v) {
                        if (v >= 1e6) return (v / 1e6).toFixed(0) + 'tr';
                        if (v >= 1e3) return (v / 1e3).toFixed(0) + 'k';
                        return v;
                    },
                },
                splitLine: { lineStyle: { color: '#f3f4f6' } },
                axisLine:  { show: false },
            },
            series: [{
                type: 'bar',
                data: months.map(function (v) {
                    return {
                        value:     v,
                        itemStyle: {
                            color:        { type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                                            colorStops: [{ offset: 0, color: '#34d399' },
                                                         { offset: 1, color: '#059669' }] },
                            borderRadius: [4, 4, 0, 0],
                        },
                    };
                }),
                barMaxWidth: 36,
                emphasis: {
                    itemStyle: {
                        color: { type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                                 colorStops: [{ offset: 0, color: '#6ee7b7' },
                                              { offset: 1, color: '#059669' }] },
                    },
                },
                label: {
                    show:      maxVal > 0,
                    position:  'top',
                    formatter: function (p) { return p.value > 0 ? Number(p.value).toLocaleString('vi-VN') : ''; },
                    fontSize:  9.5,
                    color:     '#6b7280',
                },
            }],
        });

        var mrTotalEl = document.getElementById('ta-mr-total');
        if (mrTotalEl) {
            var total = (data.months || []).reduce(function (a, b) { return a + b; }, 0);
            mrTotalEl.textContent = fmtM(total);
        }

        window.addEventListener('resize', function () { chart.resize(); });
    }

    /* ── Fetch & update room revenue for a given year ── */
    function fetchRrData(year) {
        var url = '/admin/api/room-revenue?year=' + year;
        if (window._taBranchId) url += '&branch_id=' + window._taBranchId;
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
                rrData = data;
                initRrDonut(data);
                renderRrTable(data);
                renderRrSummary(data);
                if (data.available_years) updateYearSelectors(data.available_years, parseInt(year, 10), null);
            })
            .catch(function () {});
    }

    /* ── Fetch & update monthly revenue for a given year ── */
    function fetchMrData(year) {
        var url = '/admin/api/monthly-revenue?year=' + year;
        if (window._taBranchId) url += '&branch_id=' + window._taBranchId;
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
                mrData = data;
                initMonthlyBar(data);
                if (data.available_years) updateYearSelectors(data.available_years, null, parseInt(year, 10));
            })
            .catch(function () {});
    }

    /* ── Full boot ── */
    function bootBottomCharts() {
        initRrDonut(rrData);
        renderRrTable(rrData);
        renderRrSummary(rrData);
        initMonthlyBar(mrData);

        var years = rrData.available_years || mrData.available_years;
        if (years) updateYearSelectors(years, rrData.year, mrData.year);

        initYearPicker('ta-rr-yr', function (yr) { fetchRrData(yr); });
        initYearPicker('ta-mr-yr', function (yr) { fetchMrData(yr); });
        initYearPicker('ta-br-yr', function (yr) { fetchBrData(yr); });
        initYearPicker('ta-cust-yr', function (yr) { fetchCustData(yr); });

        // Customer sort/min filter buttons
        document.querySelectorAll('#ta-cust-card [data-cust-sort]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                _custSort = btn.dataset.custSort;
                document.querySelectorAll('#ta-cust-card [data-cust-sort]').forEach(function(b){ b.classList.remove('active'); });
                btn.classList.add('active');
                var yr = parseInt(document.getElementById('ta-cust-yr')?.dataset.selected || new Date().getFullYear(), 10);
                fetchCustData(yr);
            });
        });

        fetchBrData(parseInt(document.getElementById('ta-br-yr')?.dataset.selected || new Date().getFullYear(), 10));
        fetchCustData(parseInt(document.getElementById('ta-cust-yr')?.dataset.selected || new Date().getFullYear(), 10));

        document.addEventListener('click', function () {
            document.querySelectorAll('.ta-yr-picker.open').forEach(function (p) { p.classList.remove('open'); });
        });
    }

    if (window.echarts) {
        bootBottomCharts();
    } else {
        document.querySelector('script[src*="echarts"]')
            ?.addEventListener('load', function () { bootBottomCharts(); });
    }

    /* ── Expose for cross-IIFE polling ── */
    window.pollBottomCharts = function () {
        var rrYear = parseInt(document.getElementById('ta-rr-yr')?.dataset.selected || new Date().getFullYear(), 10);
        var mrYear = parseInt(document.getElementById('ta-mr-yr')?.dataset.selected || new Date().getFullYear(), 10);
        var brYear   = parseInt(document.getElementById('ta-br-yr')?.dataset.selected   || new Date().getFullYear(), 10);
        var custYear = parseInt(document.getElementById('ta-cust-yr')?.dataset.selected || new Date().getFullYear(), 10);
        fetchRrData(rrYear);
        fetchMrData(mrYear);
        fetchBrData(brYear);
        fetchCustData(custYear);
    };

    /* ── Branch Grouped Bar Chart (style giống card 03) ── */
    var _brChart    = null;
    /* Gradient pairs per branch: indigo → violet → sky → fuchsia ... */
    var _brGradients = [
        ['#818cf8','#4f46e5'], // indigo
        ['#c084fc','#9333ea'], // violet
        ['#38bdf8','#0284c7'], // sky
        ['#f472b6','#db2777'], // pink
        ['#fb923c','#ea580c'], // orange
        ['#a3e635','#65a30d'], // lime
        ['#2dd4bf','#0f766e'], // teal
        ['#fbbf24','#d97706'], // amber
    ];

    function fetchBrData(year) {
        var url = '/admin/api/branch-monthly?year=' + year;
        if (window._taBranchId) url += '&branch_id=' + window._taBranchId;
        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(d) { if (d) renderBrChart(d); })
            .catch(function() {});
    }

    function renderBrChart(d) {
        var el = document.getElementById('ta-branch-race-chart');
        if (!el || !window.echarts) return;

        var branches = d.branches || [];
        if (!_brChart) _brChart = echarts.init(el, null, { renderer: 'svg' });

        if (!branches.length) { _brChart.clear(); return; }

        var labels  = ['T1','T2','T3','T4','T5','T6','T7','T8','T9','T10','T11','T12'];
        var maxVal  = 0;
        branches.forEach(function(b) { b.data.forEach(function(v) { if (v > maxVal) maxVal = v; }); });

        /* Gradient set at SERIES level so legend icon colour matches bars */
        var series = branches.map(function(b, i) {
            var g = _brGradients[i % _brGradients.length];
            return {
                name: b.name,
                type: 'bar',
                itemStyle: {
                    color: { type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                             colorStops: [{ offset: 0, color: g[0] }, { offset: 1, color: g[1] }] },
                    borderRadius: [4, 4, 0, 0]
                },
                emphasis: {
                    itemStyle: {
                        color: { type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                                 colorStops: [{ offset: 0, color: g[0] }, { offset: 1, color: g[1] + 'bb' }] }
                    }
                },
                label: {
                    show: maxVal > 0,
                    position: 'top',
                    formatter: function(p) {
                        return p.value > 0 ? (p.value / 1e6).toFixed(1) + 'tr' : '';
                    },
                    fontSize: 9,
                    color: '#6b7280'
                },
                barMaxWidth: 24,
                data: b.data
            };
        });

        _brChart.setOption({
            grid: { top: 28, right: 12, bottom: 56, left: 54 },
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'shadow' },
                formatter: function(params) {
                    var lines = params
                        .filter(function(p) { return p.value > 0; })
                        .map(function(p) {
                            var c = _brGradients[params.indexOf(p) % _brGradients.length];
                            return '<span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:' + (c ? c[0] : '#888') + ';margin-right:5px;"></span>'
                                 + p.seriesName + ': <b>' + fmtM(p.value) + '</b>';
                        }).join('<br/>');
                    return '<b>' + params[0].name + '</b><br/>' + (lines || '—');
                },
                backgroundColor: '#1e293b',
                borderColor:     'transparent',
                textStyle:       { color: '#f1f5f9', fontSize: 12 },
                padding:         [6, 10]
            },
            legend: {
                bottom: 0, type: 'scroll',
                textStyle: { fontSize: 11, color: '#6b7280' },
                icon: 'roundRect', itemWidth: 12, itemHeight: 8
            },
            xAxis: {
                type:      'category', data: labels,
                axisLine:  { lineStyle: { color: '#e5e7eb' } },
                axisTick:  { show: false },
                axisLabel: { color: '#6b7280', fontSize: 11 }
            },
            yAxis: {
                type: 'value',
                axisLabel: {
                    color: '#6b7280', fontSize: 10,
                    formatter: function(v) {
                        if (v >= 1e6) return (v/1e6).toFixed(0) + 'tr';
                        if (v >= 1e3) return (v/1e3).toFixed(0) + 'k';
                        return v;
                    }
                },
                splitLine: { lineStyle: { color: '#f3f4f6' } },
                axisLine:  { show: false }
            },
            series: series
        }, true);

        var brTotalEl = document.getElementById('ta-br-total');
        if (brTotalEl) {
            var grandTotal = branches.reduce(function(sum, b) {
                return sum + b.data.reduce(function(s, v) { return s + v; }, 0);
            }, 0);
            brTotalEl.textContent = fmtM(grandTotal);
        }

        window.addEventListener('resize', function() { _brChart && _brChart.resize(); });
    }

    /* ── Top Customers ── */
    var _custSort      = 'count';
    var _custMinOrders = 4;

    function fetchCustData(year) {
        var el = document.getElementById('ta-cust-body');
        if (el) el.innerHTML =
            '<div class="ta-cust-skel-row"><div class="ta-skel ta-skel-lg"></div><div class="ta-skel ta-skel-xs"></div><div class="ta-skel ta-skel-sm"></div><div class="ta-skel ta-skel-xs"></div></div>' +
            '<div class="ta-cust-skel-row"><div class="ta-skel ta-skel-lg"></div><div class="ta-skel ta-skel-xs"></div><div class="ta-skel ta-skel-sm"></div><div class="ta-skel ta-skel-xs"></div></div>' +
            '<div class="ta-cust-skel-row"><div class="ta-skel ta-skel-lg"></div><div class="ta-skel ta-skel-xs"></div><div class="ta-skel ta-skel-sm"></div><div class="ta-skel ta-skel-xs"></div></div>';

        var custUrl = '/admin/api/top-customers?year=' + year + '&sort=' + _custSort + '&min_orders=' + _custMinOrders;
        if (window._taBranchId) custUrl += '&branch_id=' + window._taBranchId;
        fetch(custUrl, { credentials: 'same-origin' })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(d) { if (d) renderCustList(d); else if (el) el.innerHTML = '<div class="ta-rr-empty">Lỗi tải dữ liệu</div>'; })
            .catch(function() { if (el) el.innerHTML = '<div class="ta-rr-empty">Lỗi tải dữ liệu</div>'; });
    }

    function renderCustList(d) {
        var el = document.getElementById('ta-cust-body');
        if (!el) return;

        var customers = d.customers || [];
        if (!customers.length) {
            el.innerHTML = '<div class="ta-rr-empty">Chưa có dữ liệu năm ' + (d.year || '') + '</div>';
            return;
        }

        var maxCount = Math.max.apply(null, customers.map(function(c){ return _custSort==='revenue' ? c.revenue : c.count; })) || 1;

        var TROPHY = [
            '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></svg>',
        ];

        var COUPON_ICO = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>';

        el.innerHTML = customers.map(function(c, i) {
            var barVal  = _custSort === 'revenue' ? c.revenue : c.count;
            var pct     = Math.round((barVal / maxCount) * 100);
            var rankEl  = i < 3
                ? '<span class="ta-cust-rank-ico">' + TROPHY[i] + '</span>'
                : '<span class="ta-cust-rank-num">' + (i + 1) + '</span>';
            var nameLabel = (c.name && c.name !== c.phone)
                ? '<span class="ta-cust-name">' + c.name + '</span>'
                : '';
            return '<div class="ta-rr-tr ta-cust-tr">' +
                '<div class="ta-cust-svc">' +
                    '<div class="ta-cust-svc-top">' +
                        rankEl +
                        '<span class="ta-rr-name">' + c.phone + '</span>' +
                        nameLabel +
                    '</div>' +
                    '<div class="ta-cust-prog"><div class="ta-cust-prog-bar" style="width:' + pct + '%"></div></div>' +
                '</div>' +
                '<div class="ta-rr-num">' + c.count + '</div>' +
                '<div class="ta-rr-num ta-rr-bold">' + fmtM(c.revenue) + '</div>' +
                '<div class="ta-rr-num">' +
                    '<a href="/admin/coupons/create" target="_blank" class="ta-cust-coupon-btn" title="Tạo mã khuyến mãi cho ' + c.phone + '">' + COUPON_ICO + '</a>' +
                '</div>' +
            '</div>';
        }).join('');
    }

})();
</script>
@endscript

{{-- FCM Push Notification (ES module — phải nằm ngoài @script Livewire) --}}
<script type="module">
import { initializeApp }  from 'https://www.gstatic.com/firebasejs/12.12.1/firebase-app.js';
import { getMessaging, getToken, onMessage } from 'https://www.gstatic.com/firebasejs/12.12.1/firebase-messaging.js';

const VAPID_KEY = '{{ config('services.firebase.vapid_key') }}';

if (!VAPID_KEY || VAPID_KEY === '') {
    console.warn('[FCM] VAPID key chưa được cấu hình trong .env (FIREBASE_VAPID_KEY).');
} else {
    const app = initializeApp({
        apiKey:            'AIzaSyDZQjQNuNmhiumNFM43GgbMUxIT5SXMwvU',
        authDomain:        'ittriet.firebaseapp.com',
        projectId:         'ittriet',
        storageBucket:     'ittriet.firebasestorage.app',
        messagingSenderId: '811008242226',
        appId:             '1:811008242226:web:e47169f406189fa585c22b',
    });

    const messaging = getMessaging(app);

    async function initFcm() {
        try {
            // Đăng ký Service Worker
            const reg = await navigator.serviceWorker.register('/firebase-messaging-sw.js');

            // Yêu cầu quyền notification
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') return;

            // Lấy FCM token
            const token = await getToken(messaging, { vapidKey: VAPID_KEY, serviceWorkerRegistration: reg });
            if (!token) return;

            // Gửi token lên server
            await fetch('/admin/api/fcm-token', {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ token }),
                credentials: 'same-origin',
            });

            // Foreground message (khi tab đang mở) → dùng Filament notification
            onMessage(messaging, (payload) => {
                const title = payload.notification?.title ?? 'Thông báo';
                const body  = payload.notification?.body  ?? '';
                // Hiện browser notification ngay cả khi foreground
                if (Notification.permission === 'granted') {
                    new Notification(title, { body, icon: '/favicon.ico' });
                }
            });

        } catch (err) {
            console.warn('[FCM] Khởi tạo thất bại:', err);
        }
    }

    if ('serviceWorker' in navigator && 'Notification' in window) {
        initFcm();
    }
}
</script>
