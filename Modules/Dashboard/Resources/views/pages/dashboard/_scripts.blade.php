{{-- Flatpickr: date picker with Vietnamese locale --}}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/vn.js"></script>

{{-- Script 1: global filter functions — defined immediately so onclick works before DOMContentLoaded --}}
@script
<script>
window._rcActiveBranch = '__all__';
window._rcActiveTime   = 'all';

// Bootstrap dữ liệu phòng/chi nhánh cho lần vẽ đầu tiên của view "Lịch" (rcCalRenderBranches()/
// rcCalRenderGrid() bên dưới) — renderRoomCards() (poll định kỳ, xem pollRoomCards()) tự cập nhật
// lại 2 biến này mỗi khi có dữ liệu mới, không cần đợi tới lúc đó mới có dữ liệu ban đầu.
window.__rcRoomsData    = @json($roomCards['rooms'] ?? []);
window.__rcBranchesData = @json($roomCards['branches'] ?? []);
window._rcCalActiveBranch = null;

// Số ngày hiển thị ở view "Lịch" — 1 select DÙNG CHUNG cho mọi phòng đang xem (không phải riêng
// từng phòng, giữ nguyên cột "Thời gian" dùng chung cho cả carousel phòng), chỉ 3 mốc 5/10/15 —
// xem rcCalApplyDaysRange(). Mặc định 10 (khớp RoomCardsService::buildTimeslotGrid() mặc định),
// tối đa 15 (chặn cả 2 phía client lẫn server — routes/web.php admin.room-cards).
window._rcCalDays = 10;

// View đang hiển thị ('list' | 'detail' | 'calendar') — set trong rcSetView(), dùng để chặn
// rcApplyFilters() (chạy lại mỗi lần renderRoomCards() poll dữ liệu mới) hiện nhầm thanh phân
// trang của view "Danh sách" trong lúc view "Lịch" đang mở (đúng lỗi đã gặp).
window._rcCurrentView = 'calendar';

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

        // Đếm/tính theo view "Danh sách" (nguồn đếm DUY NHẤT — view "Chi tiết (cũ)" hiển thị
        // CÙNG bộ đơn, chỉ khác giao diện, nên KHÔNG đếm lại lần 2 kẻo ra gấp đôi số đơn).
        card.querySelectorAll('.ta-rc-orders .ta-rc-order-item').forEach(function(item) {
            var show = time === 'all'
                || (time === 'deposit' ? item.dataset.status === 'deposit' : item.dataset.segment === time);
            item.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });

        // View "Chi tiết (cũ)" — cùng bộ lọc, chỉ ẩn/hiện, không đếm lại.
        card.querySelectorAll('.ta-rc-orders-detail .ta-rc-order-item').forEach(function(item) {
            var show = time === 'all'
                || (time === 'deposit' ? item.dataset.status === 'deposit' : item.dataset.segment === time);
            item.style.display = show ? '' : 'none';
        });


        var allOrderCount = card.querySelectorAll('.ta-rc-orders .ta-rc-order-item').length;
        card.querySelectorAll('.ta-rc-empty').forEach(function(el) {
            el.style.display = allOrderCount === 0 ? '' : 'none';
        });
        card.querySelectorAll('.ta-rc-no-match').forEach(function(el) {
            el.style.display = (allOrderCount > 0 && visibleCount === 0) ? '' : 'none';
        });

        var countEl = card.querySelector('.ta-rc-count');
        if (countEl) {
            countEl.textContent = visibleCount > 0 ? visibleCount + ' đơn' : 'Trống';
            countEl.classList.toggle('empty', visibleCount === 0);
        }

        // CHỈ hiện thẻ phòng khi phòng đó CÓ đơn (allOrderCount > 0) — phòng "Trống" (chưa từng
        // có đơn nào) không còn hiện trong view "Danh sách" nữa. Dùng allOrderCount (TỔNG số đơn,
        // không phụ thuộc tab thời gian đang lọc) chứ không phải visibleCount — phòng có đơn
        // nhưng không khớp tab thời gian đang chọn vẫn hiện thẻ kèm ".ta-rc-no-match" như cũ, chỉ
        // phòng CHƯA TỪNG có đơn mới bị ẩn hẳn.
        card.style.display = (branchMatch && allOrderCount > 0) ? '' : 'none';
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

// Áp dụng ngay từ lần tải trang đầu tiên — thiếu bước này thì lọc chi nhánh/ẩn phòng "Trống" chỉ
// có tác dụng SAU khi bấm 1 tab (rcSwitchTab/rcSwitchTime) hoặc đợi poll 2 phút (pollRoomCards()
// → renderRoomCards()), ban đầu mọi thẻ phòng (kể cả phòng "Trống") hiện hết cùng lúc.
window.rcApplyFilters();

// Bấm vào thẻ phòng (vùng trống, không phải nút/link/checkbox bên trong) → mở trang sửa phòng
// (ProductResource edit, xem data-edit-url gắn từ $room['edit_url'] — Dashboard::getRoomCardsData()).
// Không dùng closest('a, button, input') vì nút ⋮ (rcOpenRoomMenu) đã tự stopPropagation() rồi,
// nhưng vẫn chặn ở đây phòng khi các link/checkbox khác (đơn, "Chi tiết (cũ)") không có.
window.rcCardClick = function(event, card) {
    if (event.target.closest('a, button, input, label')) return;
    var url = card.dataset.editUrl;
    if (url) window.location.href = url;
};

// Bấm vào 1 dòng đơn trong danh sách của thẻ phòng → mở thẳng trang chi tiết đơn đó, không cần
// bấm đúng icon mũi tên/nút "Chi tiết" nhỏ bên trong nữa. stopPropagation() để rcCardClick ở thẻ
// phòng cha (bên trên) không bắt lại sự kiện rồi điều hướng nhầm sang trang sửa phòng.
window.rcOrderItemClick = function(event, item) {
    if (event.target.closest('a, button, input, label')) return;
    event.stopPropagation();
    var orderData = {};
    try { orderData = JSON.parse(item.dataset.order || '{}'); } catch (e) { return; }
    if (!orderData.order_id) return;
    window.location.href = '/admin/orders/' + orderData.order_id + '/edit';
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

            // Line 3: deposit/admin note
            if (o.deposit_room) {
                row += '<div class="ta-pop-order-note">' + o.deposit_room.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</div>';
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
    document.querySelectorAll('#ta-room-grid .ta-rc-orders .ta-rc-order-item[data-segment="' + segment + '"]').forEach(function(item) {
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

    // KHÔNG còn dựng roomList từ TOÀN BỘ thẻ phòng trong DOM nữa (trước đây khiến popup hiện cả
    // phòng "Trống" không liên quan) — bỏ tham số roomList cho _rcShowPopup(), để hàm build popup
    // (_rcBuildPopupHtml()) tự suy danh sách phòng THẲNG từ chính mảng orders, đúng yêu cầu "có
    // đơn của phòng nào thì phòng đó hiển thị". Vẫn giữ lọc styles=2 (phòng "theo ngày" không có
    // khái niệm 'active'/'today' theo khung giờ như ở đây).
    ['active', 'today'].forEach(function(seg) {
        document.querySelectorAll('#ta-room-grid .ta-rc-orders .ta-rc-order-item[data-segment="' + seg + '"]').forEach(function(item) {
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
    window._rcShowPopup(orders, 'Lịch hôm nay', orders.length + ' đơn đang ở & hôm nay');
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

// ── Menu thao tác nhanh (⋮) trên thẻ phòng: chỉnh sửa phòng / giá & khung giờ / đặt phòng đều
// điều hướng sang trang tương ứng (đã tự chọn sẵn đúng phòng qua ?product_id=, xem
// CreateOrder::mount() và SettingBook::form()); dọn vệ sinh xử lý NGAY tại chỗ qua
// wire:click="confirmRoomCleaning(...)" gọi thẳng action Livewire của Dashboard — panel này tuy
// do JS dựng động (innerHTML) nhưng Livewire v3 chạy trên nền Alpine, tự quan sát DOM bằng
// MutationObserver nên vẫn nhận diện đúng directive wire:click gắn trong HTML chèn sau, không
// cần route/API riêng cho thao tác này.
window.rcOpenRoomMenu = function(event, productId) {
    event.stopPropagation();
    var btn = event.currentTarget;
    var data = {};
    try { data = JSON.parse(btn.dataset.roomMenu || '{}'); } catch (e) {}

    var esc = function(s) { return String(s == null ? '' : s).replace(/</g, '&lt;').replace(/>/g, '&gt;'); };

    var html = '';
    html += '<a href="' + data.edit_url + '" class="ta-rc-menu-item"> Chỉnh sửa phòng</a>';
    html += '<a href="' + data.timeslot_url + '" class="ta-rc-menu-item"> Giá &amp; khung giờ</a>';
    html += '<a href="' + data.book_url + '" class="ta-rc-menu-item"> Đặt phòng</a>';
    html += '<button type="button" class="ta-rc-menu-item" onclick="window.rcOpenLockGrid(\'' + productId + '\')"> Lịch Phòng</button>';
    html += '<button type="button" class="ta-rc-menu-item" onclick="window.rcOpenBlockModal(\'' + productId + '\')"> Khóa khung giờ</button>';
    html += '<div class="ta-rc-menu-sep"></div>';

    // Tạm thời ẩn khối "Dọn vệ sinh" khỏi popup thẻ phòng — bật lại bằng cách đổi `false` thành
    // `true` bên dưới, không cần khôi phục lại toàn bộ khối code.
    var showHousekeepingBlock = false;
    if (showHousekeepingBlock) {
        var cleanLabels = { available: 'Sẵn sàng', cleaning: 'Đang dọn', maintenance: 'Bảo trì' };
        var cleanLabel  = cleanLabels[data.cleaning] || data.cleaning || 'Sẵn sàng';
        var cleanPill   = data.cleaning === 'available' || !data.cleaning ? 'ok' : 'warn';

        html += '<div class="ta-rc-menu-status-block">';
        html += '  <div class="ta-rc-menu-status-label">Dọn vệ sinh</div>';
        html += '  <div class="ta-rc-menu-status-row"><span>Tình trạng</span><span class="ta-rc-menu-pill ' + cleanPill + '">' + esc(cleanLabel) + '</span></div>';
        if (data.cleaning === 'cleaning') {
            // product_id là ULID dạng chuỗi (không phải số) — PHẢI có dấu nháy đơn khi nhúng vào
            // biểu thức wire:click, nếu không Livewire/Alpine parse '01ksfmbs...' như 1 token số
            // không hợp lệ → "Uncaught SyntaxError: Invalid or unexpected token".
            html += '  <button type="button" class="ta-rc-menu-confirm-btn" wire:click="confirmRoomCleaning(\'' + productId + '\')" wire:loading.attr="disabled" wire:target="confirmRoomCleaning(\'' + productId + '\')" onclick="window.rcCloseRoomMenu()">Xác nhận đã dọn xong</button>';
        }
        html += '</div>';
    }

    // Hoàn tiền — bấm 1 trong 2 nút gọi thẳng confirmRoomRefund() trên Dashboard, tái dùng ĐÚNG
    // ExtraChargeService::markRefundAsDone() giống hệt nút hoàn tiền ở trang sửa đơn (EditOrder).
    // order_id là số nguyên tự tăng thường (không phải ULID như product_id) nên không cần nháy.
    if (data.refund) {
        html += '<div class="ta-rc-menu-sep"></div>';
        html += '<div class="ta-rc-menu-status-block">';
        html += '  <div class="ta-rc-menu-status-label">Hoàn tiền</div>';
        html += '  <div class="ta-rc-menu-status-row"><span>' + esc(data.refund.buyer_name) + ' — #' + esc(data.refund.order_code) + '</span><span class="ta-rc-menu-pill warn">' + Number(data.refund.amount || 0).toLocaleString('vi-VN') + 'đ</span></div>';
        html += '  <button type="button" class="ta-rc-menu-confirm-btn" wire:click="confirmRoomRefund(' + data.refund.order_id + ', \'cash\')" wire:loading.attr="disabled" wire:target="confirmRoomRefund(' + data.refund.order_id + ', \'cash\')" onclick="window.rcCloseRoomMenu()">Đã hoàn tiền mặt</button>';
        html += '  <button type="button" class="ta-rc-menu-confirm-btn secondary" wire:click="confirmRoomRefund(' + data.refund.order_id + ', \'bank_transfer\')" wire:loading.attr="disabled" wire:target="confirmRoomRefund(' + data.refund.order_id + ', \'bank_transfer\')" onclick="window.rcCloseRoomMenu()">Đã chuyển khoản</button>';
        html += '</div>';
    }

    var panel   = document.getElementById('ta-rc-menu-panel');
    var catcher = document.getElementById('ta-rc-menu-catcher');

    // Đưa panel/catcher ra làm con của GỐC component Livewire (thẻ có wire:id), KHÔNG phải
    // <body> — nếu để nguyên vị trí lồng sâu trong cây, 1 wrapper nào đó có CSS transform sẽ
    // "nhốt" position:fixed khiến panel chạy theo scroll; nhưng nếu đưa hẳn ra <body> thì lại
    // nằm NGOÀI cây component, Livewire không dò được component sở hữu nên wire:click bên trong
    // hoàn toàn không có tác dụng (đã từng xảy ra đúng bug này — clicking không phản hồi gì cả).
    // Gốc wire:id thường không tự transform (chỉ wrapper con bên trong mới có), nên vẫn giải
    // quyết được lỗi chạy theo scroll mà không rời khỏi cây Livewire.
    var lwRoot = btn.closest('[wire\\:id]') || document.body;
    if (panel.parentElement !== lwRoot) lwRoot.appendChild(panel);
    if (catcher.parentElement !== lwRoot) lwRoot.appendChild(catcher);

    panel.innerHTML = html;
    panel.style.display   = '';
    catcher.style.display = '';

    window._rcMenuAnchorBtn = btn;
    window._rcRepositionRoomMenu();
};

// Tính lại vị trí panel THEO NÚT ĐANG MỞ mỗi lần gọi — không giả định position:fixed chắc chắn
// bám đúng viewport (nếu có 1 ancestor nào đó "nhốt" containing block, fixed sẽ lệch dần theo
// scroll); gọi lại hàm này liên tục lúc cuộn trang thì panel vẫn luôn bám đúng theo nút bấm bất
// kể nguyên nhân containing-block là gì.
window._rcRepositionRoomMenu = function() {
    var btn = window._rcMenuAnchorBtn;
    var panel = document.getElementById('ta-rc-menu-panel');
    if (!btn || !panel || panel.style.display === 'none') return;

    var rect       = btn.getBoundingClientRect();
    var panelWidth = 260;
    var left       = Math.max(8, Math.min(rect.right - panelWidth, window.innerWidth - panelWidth - 8));
    panel.style.left = left + 'px';
    panel.style.top  = (rect.bottom + 6) + 'px';
};

window.rcCloseRoomMenu = function() {
    document.getElementById('ta-rc-menu-panel').style.display   = 'none';
    document.getElementById('ta-rc-menu-catcher').style.display = 'none';
    window._rcMenuAnchorBtn = null;
};

window.addEventListener('scroll', function() { window._rcRepositionRoomMenu(); }, true);
window.addEventListener('resize', function() { window._rcRepositionRoomMenu(); });

// "Chặn khung giờ dài hạn" trong menu ⋮ — mở modal tô đen/khóa khung giờ đã có sẵn (dùng chung
// đúng component book::block-timeslot-modal, đã nhúng 1 lần ở dashboard.blade.php,
// KHÔNG viết modal riêng), chỉ khác là mở SẴN cho đúng phòng này (xem
// BlockTimeslotModal::openForProduct() — lắng nghe qua #[On('open-block-timeslot-modal-for-room')]).
// Dispatch bằng window.dispatchEvent thường (không phải wire:click) vì đây là 1 Livewire
// component KHÁC với component chứa nút bấm (Dashboard) — event window là cách 2 component
// Livewire độc lập giao tiếp với nhau ở đây.
window.rcOpenBlockModal = function(productId) {
    window.rcCloseRoomMenu();
    window.dispatchEvent(new CustomEvent('open-block-timeslot-modal-for-room', {
        detail: { productId: productId },
    }));
};

// "Khóa tạm thời (realtime)" trong menu ⋮ — mở popup lưới NGÀY × KHUNG GIỜ (component
// room-lock-grid, nhúng 1 lần ở dashboard.blade.php) để bấm giữ chỗ real-time (TimeslotHold, tự
// hết hạn), KHÔNG tạo đơn — cùng cơ chế dispatch window event như rcOpenBlockModal() ở trên.
window.rcOpenLockGrid = function(productId) {
    window.rcCloseRoomMenu();
    window.dispatchEvent(new CustomEvent('open-room-lock-grid', {
        detail: { productId: productId },
    }));
};

window.rcCloseSelectedPopup = function() {
    var popup = document.getElementById('ta-rc-popup');
    if (popup) popup.style.display = 'none';
};

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') window.rcCloseSelectedPopup();
});

// ── Chuyển đổi kiểu xem thẻ phòng: "list" (danh sách gọn) / "detail" (chi tiết cũ, để so sánh) /
// "calendar" (Lịch — carousel chi nhánh + lưới nhiều phòng, xem rcCalRenderGrid() bên dưới) ──
window.rcSetView = function(mode) {
    window._rcCurrentView = mode;

    var grid = document.getElementById('ta-room-grid');
    if (grid) {
        grid.classList.toggle('rc-view-detail', mode === 'detail');
    }
    document.querySelectorAll('#ta-rc-view-toggle .ta-rc-view-toggle-btn').forEach(function(b) {
        b.classList.toggle('active', b.dataset.view === mode);
    });

    // View "Lịch" thay hẳn khối thẻ phòng (list/detail đều nằm bên trong từng .ta-room-card)
    // bằng carousel chi nhánh + lưới nhiều phòng cạnh nhau (#ta-rc-cal-view) — 2 khối loại trừ
    // nhau, ẩn/hiện luôn cả tab thời gian/chi nhánh cũ (không áp dụng cho view này, đã có
    // carousel chi nhánh riêng — xem rcCalRenderBranches()).
    var isCalendar = mode === 'calendar';
    var calView    = document.getElementById('ta-rc-cal-view');
    var timeTabs   = document.getElementById('ta-rc-time-tabs');
    var branchTabs = document.getElementById('ta-rc-tabs');
    if (grid)       grid.style.display       = isCalendar ? 'none' : '';
    if (timeTabs)   timeTabs.style.display   = isCalendar ? 'none' : '';
    if (branchTabs) branchTabs.style.display = isCalendar ? 'none' : '';
    if (calView)    calView.style.display    = isCalendar ? '' : 'none';

    if (isCalendar) {
        window.rcCalRenderBranches();
        window.rcCalRenderGrid();
    } else {
        // Grid vừa được hiện lại (display:'') — rcApplyFilters() tự tính lại lọc chi nhánh/thời
        // gian/ẩn phòng "Trống" đúng theo tab hiện tại (không đụng gì tới nếu người dùng chưa rời
        // khỏi view "Lịch" trước đó).
        window.rcApplyFilters();
    }

};

// ── View "Lịch" — carousel chi nhánh + lưới ngày×khung giờ nhiều phòng cạnh nhau, cùng ngôn ngữ
// thị giác với lịch đặt phòng phía khách (home-booking-board.blade.php +
// book/_desktop-grid.blade.php) nhưng CHỈ XEM: ô đã đặt bấm mở thẳng đơn, ô trống không bấm được
// (không có luồng chọn/đặt như bên khách). Dữ liệu lấy từ window.__rcRoomsData — CÙNG field
// 'timeslot_grid' mỗi phòng đã dùng cho view "Dải giờ" (RoomCardsService::buildTimeslotGrid()),
// không gọi API riêng.
window.rcCalRenderBranches = function() {
    var track = document.getElementById('ta-rc-cal-branch-track');
    if (!track) return;

    // order_count mỗi chi nhánh — ĐÃ có sẵn từ RoomCardsService (branchMap, tổng số đơn của mọi
    // phòng thuộc chi nhánh đó), dùng lại thẳng thay vì suy "phòng đang trống" như trước.
    var orderCountByBranch = {};
    (window.__rcBranchesData || []).forEach(function(b) { orderCountByBranch[b.name] = b.order_count || 0; });

    var branches = (window.__rcBranchesData || []).map(function(b) { return b.name; });
    // Phòng chưa được RoomCardsService gán vào branches[] (vd branch mới, chưa có đơn nào nên
    // không lọt vào branchMap) vẫn phải xuất hiện — suy thêm trực tiếp từ danh sách phòng để
    // không bị "biến mất" khỏi carousel, order_count mặc định 0.
    (window.__rcRoomsData || []).forEach(function(r) {
        if (branches.indexOf(r.branch) === -1) {
            branches.push(r.branch);
            if (orderCountByBranch[r.branch] === undefined) orderCountByBranch[r.branch] = 0;
        }
    });

    // CHỈ giữ chi nhánh có ÍT NHẤT 1 phòng "khung giờ" (styles=1, có timeslot_grid hợp lệ) — ĐÚNG
    // điều kiện lọc phòng của rcCalRenderGrid(), để carousel chi nhánh không bao giờ dẫn tới trạng
    // thái trống "Chi nhánh này chưa có phòng theo khung giờ để hiển thị" (chi nhánh chỉ có phòng
    // "theo ngày" styles=2, hoặc chưa có phòng nào, vẫn xem được bình thường qua view "Danh sách").
    var slotStyleBranches = {};
    (window.__rcRoomsData || []).forEach(function(r) {
        if ((r.styles || 1) === 1 && r.timeslot_grid && r.timeslot_grid.rows && r.timeslot_grid.rows.length
            && r.timeslot_grid.dates && r.timeslot_grid.dates.length) {
            slotStyleBranches[r.branch] = true;
        }
    });
    branches = branches.filter(function(name) { return slotStyleBranches[name]; });

    // Chi nhánh CÓ đơn ưu tiên hiện trước — branches từ server (RoomCardsService) đã tự sắp theo
    // active_count/new_count/order_count giảm dần rồi, ở đây chỉ cần đảm bảo chắc chắn nhóm "có
    // đơn" (order_count > 0) luôn đứng trước nhóm "chưa có đơn nào" (vd các branch suy thêm ở
    // trên) — Array.prototype.sort ổn định (stable) nên KHÔNG xáo trộn thứ tự đã sắp sẵn bên
    // trong từng nhóm.
    branches.sort(function(a, b) {
        var hasA = (orderCountByBranch[a] || 0) > 0;
        var hasB = (orderCountByBranch[b] || 0) > 0;
        if (hasA === hasB) return 0;
        return hasA ? -1 : 1;
    });

    if (!window._rcCalActiveBranch || branches.indexOf(window._rcCalActiveBranch) === -1) {
        window._rcCalActiveBranch = branches[0] || null;
    }

    if (!branches.length) {
        track.innerHTML = '';
        return;
    }

    // Nút gọn: tên chi nhánh + badge số đơn tròn nằm cùng 1 hàng, cuộn ngang bằng
    // rcCalScrollBranches() (Trước/Sau) thay vì tự xuống hàng.
    track.innerHTML = branches.map(function(name) {
        var orderCount = orderCountByBranch[name] || 0;
        var active     = name === window._rcCalActiveBranch ? ' active' : '';
        var hasOrders  = orderCount > 0 ? ' has-orders' : '';
        var badgeHtml  = orderCount > 0 ? '<span class="ta-rc-cal-branch-badge">' + orderCount + '</span>' : '';
        return '<button type="button" class="ta-rc-cal-branch-card' + active + hasOrders + '" data-branch="' + window._escAttr(name) + '" onclick="rcCalSelectBranch(this.dataset.branch)">'
            + window._escAttr(name)
            + badgeHtml
            + '</button>';
    }).join('');
};

window.rcCalSelectBranch = function(name) {
    window._rcCalActiveBranch = name;
    window.rcCalRenderBranches();
    window.rcCalRenderGrid();
};

window.rcCalScrollBranches = function(dir) {
    var track = document.getElementById('ta-rc-cal-branch-track');
    if (!track) return;
    track.scrollBy({ left: dir * track.clientWidth * 0.8, behavior: 'smooth' });
};

// Select "Số ngày hiển thị" cạnh carousel chi nhánh — ÁP DỤNG CHUNG cho mọi phòng đang xem (giữ
// nguyên cột "Thời gian" dùng chung cho cả carousel phòng, không tách riêng từng phòng), chỉ 3
// mốc 5/10/15, chọn là áp dụng ngay (onchange, không cần nút "Xem"). Gọi lại đúng route poll sẵn có
// (/admin/api/room-cards?days=N — routes/web.php) rồi renderRoomCards() tự cập nhật
// window.__rcRoomsData + vẽ lại view "Lịch" nếu đang mở (xem renderRoomCards()).
window.rcCalApplyDaysRange = function() {
    var select = document.getElementById('ta-rc-cal-days-input');
    if (!select) return;

    var v = parseInt(select.value, 10);
    if (isNaN(v) || v < 1) v = 10;
    if (v > 15) v = 15; // chặn tối đa 15 ngày — server (routes/web.php) cũng tự clamp lại phòng khi bị sửa tay ngoài ý muốn.
    window._rcCalDays = v;

    // rcPollNow() (đã có sẵn — _kpi/_scripts.blade.php) gọi lại pollRoomCards(), giờ tự đính kèm
    // ?days= window._rcCalDays — tái dùng nguyên cơ chế poll/refresh có sẵn thay vì viết fetch
    // riêng, cũng đồng thời khiến poll định kỳ tiếp theo giữ đúng số ngày admin vừa chọn.
    if (window.rcPollNow) window.rcPollNow();
};

// Carousel PHÒNG (khác carousel chi nhánh ở trên) — track #ta-rc-cal-rooms-track dựng SẴN TOÀN
// BỘ phòng 1 LẦN (không tính toán/vẽ lại theo "trang" nữa — trước đây re-render lại toàn bộ
// lưới mỗi lần chuyển trang, nặng và giật với nhiều ngày/nhiều ô), Trước/Sau chỉ scrollBy() mượt
// — CÙNG kỹ thuật với carousel chi nhánh (rcCalScrollBranches()) và tự động "cửa sổ trượt" nhờ
// trình duyệt tự kẹp vị trí cuộn tối đa (kéo tới cuối luôn thấy đủ phòng cuối cùng, không có
// "trang" lẻ loi thiếu phòng — cùng hiệu ứng đã yêu cầu nhưng không cần tính toán start/maxStart
// bằng tay nữa).
window.rcCalScrollRooms = function(dir) {
    var track = document.getElementById('ta-rc-cal-rooms-track');
    if (!track) return;
    track.scrollBy({ left: dir * track.clientWidth * 0.9, behavior: 'smooth' });
};

// Chỉ phòng theo khung giờ (styles=1) — phòng đặt theo ngày (styles=2) không có lưới khung giờ để
// so sánh cạnh nhau kiểu này, vẫn xem được qua view Danh sách/Chi tiết như cũ.
window.rcCalRenderGrid = function() {
    var wrap = document.getElementById('ta-rc-cal-grid-wrap');
    if (!wrap) return;

    var branch = window._rcCalActiveBranch;
    var rooms  = (window.__rcRoomsData || []).filter(function(r) {
        return r.branch === branch && (r.styles || 1) === 1 && r.timeslot_grid
            && r.timeslot_grid.rows && r.timeslot_grid.rows.length
            && r.timeslot_grid.dates && r.timeslot_grid.dates.length;
    });

    if (!rooms.length) {
        wrap.innerHTML = '<div class="ta-rc-cal-empty">Chi nhánh này chưa có phòng theo khung giờ để hiển thị.</div>';
        window.rcCalUpdateRoomsPageInfo(0);
        return;
    }

    window.rcCalUpdateRoomsPageInfo(rooms.length);

    // Ngày (hàng) giống nhau cho MỌI phòng trong cùng 1 lần gọi RoomCardsService::getData() (cùng
    // $today/$days=7 truyền vào buildTimeslotGrid() cho từng phòng) — lấy từ phòng đầu tiên.
    var dates = rooms[0].timeslot_grid.dates;

    var svgSun  = '<svg width="11" height="11" viewBox="0 0 16 16" fill="currentColor"><path d="M8 1a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 8 1ZM10.5 8a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0ZM12.95 4.11a.75.75 0 1 0-1.06-1.06l-1.062 1.06a.75.75 0 0 0 1.061 1.062l1.06-1.061ZM15 8a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 15 8ZM11.89 12.95a.75.75 0 0 0 1.06-1.06l-1.06-1.062a.75.75 0 0 0-1.062 1.061l1.061 1.06ZM8 12a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 8 12ZM5.172 11.89a.75.75 0 0 0-1.061-1.062L3.05 11.89a.75.75 0 1 0 1.06 1.06l1.06-1.06ZM4 8a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 4 8ZM4.11 5.172A.75.75 0 0 0 5.173 4.11L4.11 3.05a.75.75 0 1 0-1.06 1.06l1.06 1.06Z"/></svg>';
    var svgMoon = '<svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path fill-rule="evenodd" d="M9.528 1.718a.75.75 0 0 1 .162.819A8.97 8.97 0 0 0 9 6a9 9 0 0 0 9 9 8.97 8.97 0 0 0 3.463-.69.75.75 0 0 1 .981.98 10.503 10.503 0 0 1-9.694 6.46c-5.799 0-10.5-4.7-10.5-10.5 0-4.368 2.667-8.112 6.46-9.694a.75.75 0 0 1 .818.162Z" clip-rule="evenodd"/></svg>';

    // Cột "Thời gian" đứng cố định bên trái, KHÔNG trượt theo carousel phòng — spacer
    // (.ta-rc-cal-dates-spacer) bù đúng chiều cao 2 hàng tiêu đề (tên phòng + khung giờ) bên phía
    // carousel để hàng ngày khớp hàng khung giờ, cùng kỹ thuật spacer đã dùng ở
    // book/_desktop-grid.blade.php (book-dt-room-name ẩn) — ở đây dùng chiều cao cố định bằng CSS
    // thay vì đo động vì số hàng tiêu đề luôn cố định (2 hàng).
    var datesColHtml = '<div class="ta-rc-cal-dates-col">'
        + '<div class="ta-rc-cal-dates-spacer">Thời gian</div>'
        + dates.map(function(date) {
            return '<div class="ta-rc-cal-date-row' + (date.is_today ? ' is-today' : '') + '">'
                + '<span class="ta-rc-cal-dow">' + window._escAttr(date.dow) + '</span>'
                + '<span class="ta-rc-cal-dnum">' + window._escAttr(date.label) + '</span>'
                + '</div>';
        }).join('')
        + '</div>';

    // Carousel phòng: mỗi phòng 1 khối (tên phòng + header khung giờ + các hàng ô, cùng thứ tự
    // ngày với cột "Thời gian" cố định bên trái) — track cuộn ngang theo trang (rcCalScrollRooms()),
    // KHÔNG dùng Swiper.js (tránh thêm thư viện ngoài vào Filament admin), tự dựng bằng
    // scroll-snap thuần, cùng kỹ thuật với carousel chi nhánh ở trên.
    var roomsHtml = rooms.map(function(r) {
        var pid = window._escAttr(String(r.product_id));
        // Bản đồ lựa chọn hiện có của ĐÚNG phòng này — 'held_mine' đọc từ server (đơn cử vừa F5
        // lại trang giữa lúc đang chọn dở) TỰ ĐỘNG nạp lại vào đây để nút Khoá/Đặt phòng bật sẵn
        // đúng trạng thái, không cần chọn lại từ đầu. Xem rcCalToggleSlot()/rcCalGetSelMap().
        var selMap     = window.rcCalGetSelMap(r.product_id);
        var unblockMap = window.rcCalGetUnblockMap(r.product_id);

        var slotHeadHtml = r.timeslot_grid.rows.map(function(row) {
            return '<div class="ta-rc-cal-slot-head">' + window._escAttr(row.label) + '<br>' + (row.over_night ? svgMoon : svgSun) + '</div>';
        }).join('');

        var rowsHtml = dates.map(function(date) {
            var cellsHtml = r.timeslot_grid.rows.map(function(row) {
                var cell = r.timeslot_grid.cells[row.id + '|' + date.iso] || { kind: 'free' };
                var key  = row.id + '|' + date.iso;

                // Đã đặt (có đơn thật) — không đổi so với trước, chỉ đổi điều kiện từ "if (cell)"
                // sang so 'kind' vì giờ ô nào cũng LUÔN có object (không còn null cho ô trống —
                // RoomCardsService::buildTimeslotGrid() đã phân biệt rõ free/blocked/held/booked).
                if (cell.kind === 'booked') {
                    var noteSuffix = cell.has_note ? ' · Có ghi chú' : '';
                    // Quá giờ (RoomCardsService::buildTimeslotGrid() 'is_overdue' — checkout_date
                    // của order_item đã trôi qua) — ghi rõ vào title để biết vì sao ô đổi màu đỏ.
                    var overdueSuffix = cell.is_overdue ? ' · Đã hết giờ' : '';
                    var title = window._escAttr(cell.status_label + ' — ' + (cell.buyer_name || 'Khách') + noteSuffix + overdueSuffix);
                    var statusCls = cell.status === 'pending' ? ' is-pending' : '';
                    var overdueCls = cell.is_overdue ? ' is-overdue' : '';
                    // Icon chuông (has_note) và chữ "Đã đặt"/"Hết giờ" cùng tranh 1 chỗ giữa ô rất
                    // nhỏ (34px cao) — icon ưu tiên hơn khi có ghi chú (dấu hiệu cần xử lý), chữ chỉ
                    // hiện khi KHÔNG có ghi chú để tránh chồng chéo. "Hết giờ" ưu tiên hơn "Đã đặt"
                    // khi khách đã quá giờ checkout (is_overdue) — báo hiệu cần xử lý ngay.
                    var noteBadge = cell.has_note
                        ? '<span class="ta-rc-cal-cell-note-badge" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0M3.124 7.5A8.969 8.969 0 0 1 5.292 3m13.416 0a8.969 8.969 0 0 1 2.168 4.5" /></svg></span>'
                        : '<span class="ta-rc-cal-cell-label">' + (cell.is_overdue ? 'Hết giờ' : 'Đã đặt') + '</span>';
                    return '<button type="button" class="ta-rc-cal-cell is-booked' + statusCls + overdueCls + '" title="' + title + '" onclick="rcCalOpenOrderPopup(' + cell.order_id + ')">' + noteBadge + '</button>';
                }

                // Đã khoá dài hạn (BlockTimeslotModal/rcCalConfirmBlockSlots() — settings['blocked_dates'])
                // — bấm để ĐÁNH DẤU CHỌN (chấm ✓ nổi góc), mở khoá HÀNG LOẠT qua nút "Mở khoá (N)"
                // thay vì gọi server ngay cho từng ô 1 — xem rcCalToggleBlockedSlot()/
                // rcCalConfirmUnblockSlots().
                if (cell.kind === 'blocked') {
                    var isMarked = !!unblockMap[key];
                    return '<button type="button" class="ta-rc-cal-cell is-blocked' + (isMarked ? ' is-unblock-selected' : '') + '" title="Đã khoá — bấm để chọn mở khoá hàng loạt" onclick="rcCalToggleBlockedSlot(\'' + pid + '\', ' + row.id + ', \'' + date.iso + '\')">'
                        + (isMarked
                            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>'
                            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>')
                        + '</button>';
                }

                // Admin KHÁC đang chọn dở (TimeslotHold, real-time — xem TimeslotHoldService) —
                // không bấm chọn được, chỉ xem tên người đang giữ qua title.
                if (cell.kind === 'held_other') {
                    return '<span class="ta-rc-cal-cell is-held" title="' + window._escAttr('Đang được ' + (cell.held_by || 'admin khác') + ' chọn') + '">Chờ...</span>';
                }

                // free hoặc held_mine (CHÍNH admin này đang giữ — vd vừa F5 lại trang giữa lúc
                // đang chọn dở) — cả 2 đều bấm chọn/bỏ chọn được, khác nhau ở chỗ đã tô sẵn
                // is-selected hay chưa. rcCalToggleSlot() tự POST giữ/nhả chỗ real-time NGAY khi
                // bấm — đây chính là phần khiến lịch phía khách (book.blade.php) bị ảnh hưởng
                // tức thì theo đúng yêu cầu.
                if (cell.kind === 'held_mine') {
                    selMap[key] = true;
                }
                var isSelected  = !!selMap[key];
                // Khung giờ (row) ĐANG có khuyến mãi hiệu lực (RoomCardsService::buildTimeslotGrid()
                // — has_discount) — tô viền cầu vồng các ô TRỐNG thuộc khung giờ này, báo hiệu trực
                // quan "đặt khung này đang được giảm giá" mà không cần mở popup "Giá phòng" mới
                // biết. CHỈ khi CHƯA chọn — ĐÚNG điều kiện '$hasPromo && !$isSelected' của
                // .tsgrid-cell.has-promo bên OrderForm (timeslot-grid-table.blade.php): 1 khi ô đã
                // được chọn (background primary đặc) thì tắt hẳn hiệu ứng, không để 2 hiệu ứng
                // tranh nhau hiển thị.
                var isDiscount  = !!row.has_discount && !isSelected;
                // Chữ "Trống" BỌC trong <span> riêng (không để text thô nằm trực tiếp trong
                // <button>) — ô .is-discounted có ::after phủ trắng ĐẶC ở z-index cao hơn text thô
                // (paint sau cùng trong stacking context) sẽ che mất chữ nếu không có .ta-rc-cal-
                // cell-label tự nâng z-index riêng, giống hệt cách .tsgrid-cell > * làm ở OrderForm.
                return '<button type="button" class="ta-rc-cal-cell is-free' + (isSelected ? ' is-selected' : '') + (isDiscount ? ' is-discounted' : '') + '" onclick="rcCalToggleSlot(this, \'' + pid + '\', ' + row.id + ', \'' + date.iso + '\')"><span class="ta-rc-cal-cell-label">' + (isSelected ? 'Đã chọn' : 'Trống') + '</span></button>';
            }).join('');
            return '<div class="ta-rc-cal-slots-row">' + cellsHtml + '</div>';
        }).join('');

        // Hàng thao tác nhanh dưới tên phòng — tái dùng THẲNG url đã có sẵn cho "Xem phòng"
        // (edit_url từ Dashboard::getRoomCardsData(), mở tab mới — điều hướng thẳng, không popup)
        // trừ "Giá phòng"/"Thống kê" (rcCalOpenPricePopup()/rcCalOpenViewPopup(), popup riêng —
        // "Thống kê" dùng ĐÚNG popup thống kê doanh thu trước đây gắn ở nút "Xem phòng"). Hàng 1 (3
        // cột): Xem phòng/Giá phòng/Thống kê. Hàng 2 (2 cột): Khoá phòng/Đặt phòng — MẶC ĐỊNH khoá
        // (disabled) — chỉ bật khi có ít nhất 1 ô TRỐNG đang được chọn (selMap không rỗng). Nút
        // "Khoá phòng" TỰ ĐỔI thành "Mở khoá (N)" ngay khi có ≥1 ô ĐÃ KHOÁ đang được chọn
        // (unblockMap không rỗng — chọn nhiều ô để mở khoá HÀNG LOẠT nhanh hơn hẳn bấm từng ô).
        var hasSelection    = Object.keys(selMap).length > 0;
        var unblockKeys     = Object.keys(unblockMap);
        var hasUnblockSel   = unblockKeys.length > 0;
        var bookUrlAttr     = window._escAttr(r.book_url);
        var lockBtnHtml = hasUnblockSel
            ? '<button type="button" class="ta-rc-cal-room-action-btn is-unlock" id="ta-rc-cal-lock-btn-' + pid + '" title="Mở khoá các khung giờ đang chọn" onclick="rcCalConfirmUnblockSlots(\'' + pid + '\')">Mở khoá (' + unblockKeys.length + ')</button>'
            : '<button type="button" class="ta-rc-cal-room-action-btn" id="ta-rc-cal-lock-btn-' + pid + '" title="Khoá các khung giờ đang chọn"' + (hasSelection ? '' : ' disabled') + ' onclick="rcCalConfirmBlockSlots(\'' + pid + '\')">Khoá phòng</button>';
        var editUrlAttr = window._escAttr(r.edit_url);
        var actionsHtml = '<div class="ta-rc-cal-room-actions">'
            + '<div class="ta-rc-cal-room-actions-row cols-3">'
                + '<a href="' + r.edit_url + '" class="ta-rc-cal-room-action-btn" target="_blank" rel="noopener" title="Xem phòng">Xem phòng</a>'
                + '<button type="button" class="ta-rc-cal-room-action-btn" title="Giá phòng" onclick="rcCalOpenPricePopup(\'' + pid + '\', \'' + window._escAttr(r.room_name) + '\')">Giá phòng</button>'
                + '<button type="button" class="ta-rc-cal-room-action-btn" title="Thống kê" onclick="rcCalOpenViewPopup(\'' + pid + '\', \'' + window._escAttr(r.room_name) + '\', \'' + editUrlAttr + '\')">Thống kê</button>'
            + '</div>'
            + '<div class="ta-rc-cal-room-actions-row cols-2">'
                + lockBtnHtml
                + '<button type="button" class="ta-rc-cal-room-action-btn is-primary" id="ta-rc-cal-book-btn-' + pid + '" title="Đặt phòng với các khung giờ đang chọn"' + (hasSelection ? '' : ' disabled') + ' onclick="rcCalGoBook(\'' + pid + '\', \'' + bookUrlAttr + '\')">Đặt phòng</button>'
            + '</div>'
            + '</div>';

        return '<div class="ta-rc-cal-room-block">'
            + '<h4 class="ta-rc-cal-room-name">' + window._escAttr(r.room_name) + '</h4>'
            + actionsHtml
            + '<div class="ta-rc-cal-slot-head-row">' + slotHeadHtml + '</div>'
            + rowsHtml
            + '</div>';
    }).join('');

    wrap.innerHTML = datesColHtml
        + '<div class="ta-rc-cal-rooms-track" id="ta-rc-cal-rooms-track">' + roomsHtml + '</div>';
};

// Cập nhật chữ "N phòng" giữa nút Trước/Sau (id cố định trong Blade, không bị JS dựng lại như
// phần lưới) — không còn khái niệm "trang" nữa (rcCalScrollRooms() giờ chỉ scrollBy() mượt như
// carousel chi nhánh), nút Trước/Sau LUÔN bật (trình duyệt tự kẹp vị trí cuộn ở 2 đầu, giống hệt
// carousel chi nhánh không disable Trước/Sau bao giờ).
window.rcCalUpdateRoomsPageInfo = function(totalRooms) {
    var info = document.getElementById('ta-rc-cal-rooms-page-info');
    if (info) info.textContent = (totalRooms || 0) + ' phòng';
};

// Chọn khung giờ TRỰC TIẾP trên lưới (không qua modal) — mỗi phòng giữ 2 bản đồ lựa chọn riêng:
// _rcCalSelections (ô TRỐNG đang chọn để Khoá/Đặt phòng) và _rcCalUnblockSelections (ô ĐÃ KHOÁ
// đang chọn để mở khoá hàng loạt — nút "Khoá phòng" tự đổi thành "Mở khoá (N)" khi có ít nhất 1 ô
// loại này được chọn). Cả 2 tồn tại XUYÊN SUỐT nhiều lần rcCalRenderGrid() (đổi chi nhánh/carousel
// phòng/poll dữ liệu mới) cho tới khi admin xác nhận hành động hoặc tự bỏ chọn.
window._rcCalSelections         = window._rcCalSelections || {};
window._rcCalUnblockSelections  = window._rcCalUnblockSelections || {};

window.rcCalGetSelMap = function(productId) {
    var key = String(productId);
    if (!window._rcCalSelections[key]) window._rcCalSelections[key] = {};
    return window._rcCalSelections[key];
};

window.rcCalGetUnblockMap = function(productId) {
    var key = String(productId);
    if (!window._rcCalUnblockSelections[key]) window._rcCalUnblockSelections[key] = {};
    return window._rcCalUnblockSelections[key];
};

// Bấm 1 ô còn trống (hoặc đang do CHÍNH mình giữ) — giữ/nhả chỗ real-time NGAY LẬP TỨC qua
// TimeslotHoldService (routes/web.php rooms/{id}/timeslot-hold), CÙNG cơ chế
// app/Livewire/RoomLockGrid.php::selectTimeslot() đã dùng — lịch phía khách (book.blade.php,
// đọc TimeslotHold qua book/_slot-cell.blade.php) do đó bị ảnh hưởng NGAY khi bấm, không cần đợi
// "Khoá phòng"/"Đặt phòng". Vẽ lại lưới ngay (lạc quan — optimistic), revert + vẽ lại lần nữa nếu
// server báo có admin khác đang giữ (đua tay race condition).
window.rcCalToggleSlot = function(btn, productId, roomTimeSlotId, date) {
    var selMap = window.rcCalGetSelMap(productId);
    var key    = roomTimeSlotId + '|' + date;
    var csrf   = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    if (selMap[key]) {
        delete selMap[key];
        if (window.rcCalRenderGrid) window.rcCalRenderGrid();
        fetch('/admin/api/rooms/' + productId + '/timeslot-hold', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ room_time_slot_id: roomTimeSlotId, date: date, action: 'release' }),
            credentials: 'same-origin',
        }).catch(function() {});
        return;
    }

    selMap[key] = true;
    if (window.rcCalRenderGrid) window.rcCalRenderGrid();

    fetch('/admin/api/rooms/' + productId + '/timeslot-hold', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ room_time_slot_id: roomTimeSlotId, date: date, action: 'hold' }),
        credentials: 'same-origin',
    })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d && d.ok) return;

            // Admin khác vừa giữ mất đúng lúc mình bấm — huỷ chọn, cập nhật NGAY thành
            // 'held_other' (kèm tên người đang giữ) thay vì chờ tới lần poll sau mới thấy đúng.
            delete selMap[key];
            (window.__rcRoomsData || []).forEach(function(room) {
                if (String(room.product_id) !== String(productId)) return;
                var cells = room.timeslot_grid && room.timeslot_grid.cells;
                if (cells) cells[key] = { kind: 'held_other', held_by: (d && d.held_by) || 'admin khác' };
            });
            if (window.rcCalRenderGrid) window.rcCalRenderGrid();
        })
        .catch(function() {
            delete selMap[key];
            if (window.rcCalRenderGrid) window.rcCalRenderGrid();
        });
};

// Bấm 1 ô ĐÃ KHOÁ — CHỈ đánh dấu chọn (để mở khoá hàng loạt qua nút "Mở khoá (N)", xem
// rcCalConfirmUnblockSlots()), KHÔNG gọi server ngay như trước (mở khoá từng ô 1 chậm hơn hẳn khi
// cần mở nhiều ô cùng lúc).
window.rcCalToggleBlockedSlot = function(productId, roomTimeSlotId, date) {
    var unblockMap = window.rcCalGetUnblockMap(productId);
    var key = roomTimeSlotId + '|' + date;
    if (unblockMap[key]) {
        delete unblockMap[key];
    } else {
        unblockMap[key] = true;
    }
    if (window.rcCalRenderGrid) window.rcCalRenderGrid();
};

// "Khoá phòng" — khoá DÀI HẠN toàn bộ ô TRỐNG đang được chọn, ĐÚNG cách lưu của
// BlockTimeslotModal::saveBlock() (routes/web.php rooms/{id}/block-slots), KHÔNG mở modal tô
// đen/khoá lịch nữa theo đúng yêu cầu. Xong thì tự chuyển các ô đó sang trạng thái 'blocked'
// ngay trong window.__rcRoomsData và vẽ lại lưới — không cần đợi poll 2 phút mới thấy.
window.rcCalConfirmBlockSlots = function(productId) {
    var selMap = window.rcCalGetSelMap(productId);
    var keys   = Object.keys(selMap);
    if (!keys.length) return;

    var slots = keys.map(function(k) {
        var parts = k.split('|');
        return { room_time_slot_id: parseInt(parts[0], 10), date: parts[1] };
    });

    var btn = document.getElementById('ta-rc-cal-lock-btn-' + productId);
    if (btn) { btn.disabled = true; btn.textContent = 'Đang khoá...'; }

    fetch('/admin/api/rooms/' + productId + '/block-slots', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ slots: slots }),
        credentials: 'same-origin',
    })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d || !d.ok) {
                if (btn) { btn.disabled = false; btn.textContent = 'Khoá phòng'; }
                return;
            }

            (window.__rcRoomsData || []).forEach(function(room) {
                if (String(room.product_id) !== String(productId)) return;
                var cells = room.timeslot_grid && room.timeslot_grid.cells;
                if (!cells) return;
                slots.forEach(function(s) {
                    cells[s.room_time_slot_id + '|' + s.date] = { kind: 'blocked' };
                });
            });

            window._rcCalSelections[String(productId)] = {};
            if (window.rcCalRenderGrid) window.rcCalRenderGrid();
        })
        .catch(function() {
            if (btn) { btn.disabled = false; btn.textContent = 'Khoá phòng'; }
        });
};

// "Mở khoá (N)" — nút "Khoá phòng" tự đổi tên/hành vi thành nút này ngay khi có ≥1 ô ĐÃ KHOÁ đang
// được chọn (xem actionsHtml trong rcCalRenderGrid()) — mở khoá HÀNG LOẠT cùng lúc (kể cả chỉ
// chọn đúng 1 ô) thay vì gọi server riêng cho từng ô như trước, nhanh hơn hẳn khi cần mở nhiều ô.
window.rcCalConfirmUnblockSlots = function(productId) {
    var unblockMap = window.rcCalGetUnblockMap(productId);
    var keys = Object.keys(unblockMap);
    if (!keys.length) return;

    var slots = keys.map(function(k) {
        var parts = k.split('|');
        return { room_time_slot_id: parseInt(parts[0], 10), date: parts[1] };
    });

    var btn = document.getElementById('ta-rc-cal-lock-btn-' + productId);
    if (btn) { btn.disabled = true; btn.textContent = 'Đang mở khoá...'; }

    fetch('/admin/api/rooms/' + productId + '/unblock-slots', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ slots: slots }),
        credentials: 'same-origin',
    })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d || !d.ok) {
                if (btn) { btn.disabled = false; btn.textContent = 'Mở khoá (' + keys.length + ')'; }
                return;
            }

            (window.__rcRoomsData || []).forEach(function(room) {
                if (String(room.product_id) !== String(productId)) return;
                var cells = room.timeslot_grid && room.timeslot_grid.cells;
                if (!cells) return;
                slots.forEach(function(s) {
                    cells[s.room_time_slot_id + '|' + s.date] = { kind: 'free' };
                });
            });

            window._rcCalUnblockSelections[String(productId)] = {};
            if (window.rcCalRenderGrid) window.rcCalRenderGrid();
        })
        .catch(function() {
            if (btn) { btn.disabled = false; btn.textContent = 'Mở khoá (' + keys.length + ')'; }
        });
};

// "Đặt phòng" — hold đã được tạo NGAY lúc bấm chọn từng ô (rcCalToggleSlot()), nên chỉ cần mở
// tab mới: CreateOrder::mount() tự đọc lại TimeslotHold còn hiệu lực của ĐÚNG admin đang đăng
// nhập cho phòng này để tự động chọn sẵn các khung giờ đó (không cần truyền gì qua query string
// — xem Modules/Payment/App/Filament/Resources/OrderResource/Pages/CreateOrder.php::mount()).
window.rcCalGoBook = function(productId, bookUrl) {
    window.open(bookUrl, '_blank');
};

// Popup thông tin nhanh — bấm 1 ô đã đặt (button.ta-rc-cal-cell.is-booked, xem rcCalRenderGrid()
// ở trên) mở popup này thay vì điều hướng thẳng sang orderform như trước. Gọi
// GET /admin/api/orders/{id}/quick-info (routes/web.php) — 'amount_html' đã là component
// 'payment::components.total-amount-card' render SẴN từ server (ĐÚNG khối "Tổng thanh toán" của
// orderform, tự chèn thẳng vào đây), không tự dựng lại bằng tay ở JS nữa.
window.rcCalOpenOrderPopup = function(orderId) {
    var overlay    = document.getElementById('ta-rc-order-popup');
    var body       = document.getElementById('ta-rc-order-popup-body');
    var headBadges0 = document.getElementById('ta-rc-order-popup-head-badges');
    if (!overlay || !body || !orderId) return;

    body.innerHTML = '<div class="ta-rc-order-popup-loading">Đang tải...</div>';
    if (headBadges0) headBadges0.innerHTML = '';
    overlay.style.display = 'flex';

    fetch('/admin/api/orders/' + orderId + '/quick-info', { credentials: 'same-origin' })
        .then(function(r) { return r.ok ? r.json() : Promise.reject(); })
        .then(function(o) {
            // Cùng bảng màu trạng thái với RoomCardsService::getData()/statusColorMap trong file
            // này (buildOrdersHtml) — lặp lại cục bộ vì đây là closure route riêng trong
            // routes/web.php, không dùng chung được service PHP.
            var statusColorMap = {
                pending: '#f59e0b', deposit: '#d97757', paid: '#10b981',
                shipping: '#3b82f6', completed: '#8b5cf6', cancelled: '#ef4444', failed: '#ef4444'
            };
            var col = statusColorMap[o.status] || '#94a3b8';

            var noteVal = o.description || '';

            // Mô tả trạng thái: badge trạng thái đơn + badge riêng "Có ghi chú" (chỉ hiện khi đơn
            // đang có ghi chú) — cùng chỗ với badge chuông trên ô khung giờ (rcCalRenderGrid()),
            // đặt ở CUỐI HÀNG TIÊU ĐỀ popup (#ta-rc-order-popup-head-badges, không phải trong
            // thân popup) — tự ẩn/hiện lại ngay khi lưu ghi chú (rcCalSaveOrderNote()), không cần
            // mở lại popup.
            var noteFlagSvg = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0M3.124 7.5A8.969 8.969 0 0 1 5.292 3m13.416 0a8.969 8.969 0 0 1 2.168 4.5" /></svg>';
            var headBadges = document.getElementById('ta-rc-order-popup-head-badges');
            if (headBadges) {
                headBadges.innerHTML = '<span class="ta-rc-order-popup-status" style="background:' + col + '1a;color:' + col + ';">'
                    + window._escAttr(o.status_label) + ' · #' + window._escAttr(o.order_code) + '</span>'
                    + '<span class="ta-rc-order-popup-note-flag" id="ta-rc-order-popup-note-flag" style="display:' + (o.description ? '' : 'none') + ';">'
                    +     noteFlagSvg + ' Có ghi chú'
                    + '</span>';
            }

            // Cột trái: mã cổng (nếu có — CHỈ hiện khi server đã xác định đơn thuộc chi nhánh có
            // TTLock hoặc phòng dùng khóa thủ công, xem routes/web.php orders/{id}/quick-info,
            // cùng luật OrderForm::hasAccessCodeSection()) NẰM ĐẦU, rồi tới thông tin khách + ghi
            // chú (sửa được tại chỗ — rcCalSaveOrderNote()) + nút điều hướng. Cột phải:
            // 'amount_html' (component total-amount-card render sẵn).
            var left = o.access_code ? window._rcBuildAccessCodeHtml(o.access_code) : '';

            left += '<div class="ta-rc-order-popup-field"><span class="ta-rc-order-popup-field-label">Khách hàng</span>'
                + '<span class="ta-rc-order-popup-field-value">' + window._escAttr(o.buyer_name || 'Khách') + '</span></div>';
            left += '<div class="ta-rc-order-popup-field"><span class="ta-rc-order-popup-field-label">Số điện thoại</span>'
                + '<span class="ta-rc-order-popup-field-value">' + (o.buyer_phone
                    ? '<a href="tel:' + window._escAttr(o.buyer_phone) + '" style="color:inherit;text-decoration:none;">' + window._escAttr(o.buyer_phone) + '</a>'
                    : '—') + '</span></div>';

            left += '<div class="ta-rc-order-popup-note-block" data-order-id="' + o.order_id + '" data-note="' + window._escAttr(noteVal) + '">'
                + '<div class="ta-rc-order-popup-note-head">'
                +     '<span class="ta-rc-order-popup-field-label">Ghi chú</span>'
                +     '<button type="button" class="ta-rc-order-popup-note-edit-btn" onclick="rcCalToggleNoteEdit(this)">Sửa</button>'
                + '</div>'
                + '<div class="ta-rc-order-popup-note-display">' + (noteVal ? window._escAttr(noteVal) : '—') + '</div>'
                + '<div class="ta-rc-order-popup-note-edit" style="display:none;">'
                +     '<textarea class="ta-rc-order-popup-note-input" rows="3" maxlength="500">' + window._escAttr(noteVal) + '</textarea>'
                +     '<div class="ta-rc-order-popup-note-actions">'
                +         '<button type="button" class="ta-rc-order-popup-note-cancel-btn" onclick="rcCalCancelNoteEdit(this)">Huỷ</button>'
                +         '<button type="button" class="ta-rc-order-popup-note-save-btn" onclick="rcCalSaveOrderNote(this)">Lưu</button>'
                +     '</div>'
                + '</div>'
                + '</div>';

            left += '<a href="' + o.edit_url + '" class="ta-rc-order-popup-goto-btn">Xem chi tiết đơn'
                + '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></a>';

            var right = o.amount_html || '';

            body.innerHTML = '<div class="ta-rc-order-popup-columns">'
                + '<div class="ta-rc-order-popup-col-left">' + left + '</div>'
                + '<div class="ta-rc-order-popup-col-right">' + right + '</div>'
                + '</div>';
        })
        .catch(function() {
            body.innerHTML = '<div class="ta-rc-order-popup-error">Không tải được thông tin đơn.</div>';
        });
};

// Khung "Mã cổng" GỌN riêng cho popup này (KHÔNG tái dùng access-code-info.blade.php/
// manual-lock-info.blade.php nguyên bản — 2 component đó thiết kế cho khung RỘNG của orderform,
// chữ mã cỡ 3xl/4xl + lưới hiệu lực 2 cột + padding lớn, nhồi vào cột trái hẹp ~230px của popup
// sẽ vỡ layout) — chỉ đọc dữ liệu thô 'access_code' từ quick-info (routes/web.php).
window._rcBuildAccessCodeHtml = function(ac) {
    var colorMap = { success: '#10b981', danger: '#ef4444', warning: '#f59e0b', gray: '#94a3b8' };
    var col = colorMap[ac.status_color] || '#94a3b8';
    var svgCopy = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>';

    // suffix: ký tự dính liền ngay sau mã (vd '#' cho mã TTLock) — CÙNG màu với mã, và tính LUÔN
    // vào chuỗi khi bấm Sao chép (không tách riêng như trước).
    function codeRow(label, code, suffix) {
        if (!code) return '';
        var full = code + (suffix || '');
        return '<div class="ta-rc-order-popup-access-code-row">'
            + (label ? '<span class="ta-rc-order-popup-access-code-tag">' + window._escAttr(label) + '</span>' : '')
            + '<span class="ta-rc-order-popup-access-code">' + window._escAttr(full) + '</span>'
            + '<button type="button" class="ta-rc-order-popup-access-copy-btn" title="Sao chép" onclick="rcCalCopyAccessCode(this, \'' + window._escAttr(full) + '\')">' + svgCopy + '</button>'
            + '</div>';
    }

    var codesHtml = ac.type === 'manual'
        ? codeRow('Cổng', ac.gate_password) + codeRow('Phòng', ac.room_password)
        : (ac.code ? codeRow(null, ac.code, '#') : '<div class="ta-rc-order-popup-access-empty">Chưa có mã cổng</div>');

    // Vị trí cổng (nếu có) — đã bỏ dòng "Hiệu lực" theo yêu cầu.
    var metaHtml = ac.gate_location
        ? '<div class="ta-rc-order-popup-access-meta">Vị trí: ' + window._escAttr(ac.gate_location) + '</div>'
        : '';

    // Trạng thái: chỉ 1 chấm tròn màu, không còn chữ ("Hoạt động"/...) — hover (title) vẫn đọc
    // được nhãn đầy đủ cho ai cần.
    var statusDot = ac.status_label
        ? '<span class="ta-rc-order-popup-access-dot" style="background:' + col + ';" title="' + window._escAttr(ac.status_label) + '"></span>'
        : '';

    return '<div class="ta-rc-order-popup-access">'
        + '<div class="ta-rc-order-popup-access-top">'
        +     '<span class="ta-rc-order-popup-field-label">' + (ac.type === 'manual' ? 'Mã cổng (thủ công)' : 'Mã cổng') + '</span>'
        +     statusDot
        + '</div>'
        + codesHtml
        + metaHtml
        + '</div>';
};

window.rcCalCopyAccessCode = function(btn, code) {
    navigator.clipboard.writeText(code).then(function () {
        var original = btn.innerHTML;
        btn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>';
        setTimeout(function () { btn.innerHTML = original; }, 1200);
    });
};

window.rcCalCloseOrderPopup = function() {
    var overlay = document.getElementById('ta-rc-order-popup');
    if (overlay) overlay.style.display = 'none';
};

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { window.rcCalCloseOrderPopup(); window.rcCalClosePricePopup(); window.rcCalCloseViewPopup(); }
});

// Popup "Xem phòng" — nút cùng tên trong hàng thao tác nhanh dưới tên phòng (rcCalRenderGrid()) —
// gọi GET /admin/api/rooms/{id}/stats-popup?year=N (routes/web.php): tổng doanh thu + số đơn
// thành công (ALL-TIME), doanh thu 12 tháng (T1-T12) của NĂM đang lọc (ta-yr-picker, cùng kiểu
// panel "04/Theo tháng" ở Tổng quan — _bottom-grid.blade.php), và tần suất đặt theo khung giờ vẽ
// bằng Nightingale chart (CHỈ phòng styles=1 "khung giờ" — ẩn hẳn với phòng "theo ngày", không có
// khái niệm khung giờ cố định). "Xem chi tiết phòng" cuối popup dùng THẲNG editUrl truyền vào (đã
// có sẵn trong window.__rcRoomsData), không gọi API riêng.
window._rcViewPopupCtx = null;
window.rcCalOpenViewPopup = function(productId, roomName, editUrl) {
    var overlay = document.getElementById('ta-rc-view-popup');
    var body    = document.getElementById('ta-rc-view-popup-body');
    var title   = document.getElementById('ta-rc-view-popup-title');
    if (!overlay || !body || !productId) return;

    window._rcViewPopupCtx = { productId: productId, roomName: roomName, editUrl: editUrl };
    if (title) title.textContent = 'Thống kê — ' + roomName;
    overlay.style.display = 'flex';
    window.rcCalLoadViewPopup(new Date().getFullYear());
};

// Tải/vẽ lại TOÀN BỘ nội dung popup theo năm truyền vào — gọi lại khi mở popup lần đầu HOẶC khi
// admin đổi năm ở ta-yr-picker (built inline bên dưới, không tái dùng initYearPicker() vì hàm đó
// nằm trong 1 khối "Script" (Blade directive) / IIFE khác — _scripts.blade.php Script 3 — không
// truy cập được từ đây. LƯU Ý: không được viết liền ký tự @ + "script" trong comment JS (//) ở
// file .blade.php này — Blade compile RAW TEXT trước khi thành JS, hiểu nhầm thành 1 directive
// Livewire thật, gây lỗi "Multiple root elements detected" runtime rất khó dò.
window.rcCalLoadViewPopup = function(year) {
    var ctx  = window._rcViewPopupCtx;
    var body = document.getElementById('ta-rc-view-popup-body');
    if (!ctx || !body) return;

    body.innerHTML = '<div class="ta-rc-order-popup-loading">Đang tải...</div>';

    fetch('/admin/api/rooms/' + ctx.productId + '/stats-popup?year=' + year, { credentials: 'same-origin' })
        .then(function(r) { return r.ok ? r.json() : Promise.reject(); })
        .then(function(d) {
            var fmt = function(n) { return Number(n || 0).toLocaleString('vi-VN') + 'đ'; };

            var yearsHtml = (d.available_years || []).map(function(y) {
                return '<div class="ta-yr-opt' + (y === d.year ? ' active' : '') + '" role="option" data-year="' + y + '">' + y + '</div>';
            }).join('');
            var yearPickerHtml = '<div class="ta-yr-picker" id="ta-rc-view-yr" data-selected="' + d.year + '">'
                + '<button class="ta-yr-btn" type="button">'
                    + '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" class="ta-yr-ico"><rect x="3" y="4" width="18" height="18" rx="2.5" stroke="currentColor" stroke-width="2"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
                    + '<span class="ta-yr-val">' + d.year + '</span>'
                    + '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" class="ta-yr-chev"><path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                + '</button>'
                + '<div class="ta-yr-drop" role="listbox">' + yearsHtml + '</div>'
            + '</div>';

            var statsHtml = '<div class="ta-rc-view-stats">'
                + '<div class="ta-rc-view-stat-card">'
                    + '<span class="ta-rc-view-stat-label">Tổng doanh thu (mọi thời điểm)</span>'
                    + '<span class="ta-rc-view-stat-value">' + fmt(d.total_revenue) + '</span>'
                + '</div>'
                + '<div class="ta-rc-view-stat-card">'
                    + '<span class="ta-rc-view-stat-label">Đơn thành công (mọi thời điểm)</span>'
                    + '<span class="ta-rc-view-stat-value">' + (d.total_orders || 0) + ' đơn</span>'
                + '</div>'
            + '</div>';

            var monthlyChartHtml = '<div class="ta-rc-view-chart-head">'
                + '<div class="ta-rc-view-chart-title">Doanh thu theo tháng</div>'
                + yearPickerHtml
            + '</div>'
            + '<div class="ta-rc-view-chart-el" id="ta-rc-view-monthly-chart-el"></div>';

            var footerHtml = '<a href="' + ctx.editUrl + '" target="_blank" rel="noopener" class="ta-rc-order-popup-goto-btn">Xem chi tiết phòng</a>';

            body.innerHTML = statsHtml + monthlyChartHtml + footerHtml;

            window.rcCalInitViewMonthlyChart(d.monthly || []);

            // Toggle mở/đóng + chọn năm — inline thay vì tái dùng initYearPicker() (Script 3, IIFE
            // riêng không truy cập được). Đóng khi click ra ngoài đã có sẵn listener toàn cục ở
            // Script 3 (document.addEventListener('click', ...) đóng MỌI .ta-yr-picker.open), chỉ
            // cần tự lo phần mở + chọn năm ở đây.
            var picker = document.getElementById('ta-rc-view-yr');
            if (picker) {
                var btn  = picker.querySelector('.ta-yr-btn');
                var drop = picker.querySelector('.ta-yr-drop');
                if (btn) {
                    btn.addEventListener('click', function(e) {
                        e.stopPropagation();
                        document.querySelectorAll('.ta-yr-picker.open').forEach(function(p) {
                            if (p !== picker) p.classList.remove('open');
                        });
                        picker.classList.toggle('open');
                    });
                }
                if (drop) {
                    drop.addEventListener('click', function(e) {
                        var opt = e.target.closest('.ta-yr-opt');
                        if (!opt) return;
                        picker.classList.remove('open');
                        window.rcCalLoadViewPopup(parseInt(opt.dataset.year, 10));
                    });
                }
            }
        })
        .catch(function() {
            body.innerHTML = '<div class="ta-rc-order-popup-loading">Không tải được dữ liệu.</div>';
        });
};

// Biểu đồ cột "Doanh thu theo tháng" (T1-T12) — cùng cấu hình trực quan với initMonthlyBar()
// (panel "04/Theo tháng" ở Tổng quan) nhưng đổi màu theo --ta-cal-primary cho khớp bảng màu view
// "Lịch", và tự dispose/init mới mỗi lần vì #...-el bị innerHTML thay mới hoàn toàn mỗi lần mở/đổi
// năm (khác initMonthlyBar() gốc — container đó KHÔNG bị innerHTML thay nên mới getInstanceByDom()
// lại được).
window._rcViewMonthlyChart = null;
window.rcCalInitViewMonthlyChart = function(months) {
    var el = document.getElementById('ta-rc-view-monthly-chart-el');
    if (!el || !window.echarts) return;

    if (window._rcViewMonthlyChart) {
        try { window._rcViewMonthlyChart.dispose(); } catch (e) {}
    }

    var labels = ['T1','T2','T3','T4','T5','T6','T7','T8','T9','T10','T11','T12'];
    var maxVal = Math.max.apply(null, months);
    var chart  = echarts.init(el, null, { renderer: 'svg' });
    window._rcViewMonthlyChart = chart;

    chart.setOption({
        grid: { top: 28, right: 12, bottom: 24, left: 54 },
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            formatter: function(params) {
                var p = params[0];
                return '<b>' + p.name + '</b><br/>' + Number(p.value).toLocaleString('vi-VN') + 'đ';
            },
            backgroundColor: '#1e293b',
            borderColor: 'transparent',
            textStyle: { color: '#f1f5f9', fontSize: 12 },
            padding: [6, 10],
        },
        xAxis: {
            type: 'category',
            data: labels,
            axisLine: { lineStyle: { color: '#e5e7eb' } },
            axisTick: { show: false },
            axisLabel: { color: '#6b7280', fontSize: 11 },
        },
        yAxis: {
            type: 'value',
            axisLabel: {
                color: '#6b7280', fontSize: 10,
                formatter: function(v) {
                    if (v >= 1e6) return (v / 1e6).toFixed(0) + 'tr';
                    if (v >= 1e3) return (v / 1e3).toFixed(0) + 'k';
                    return v;
                },
            },
            splitLine: { lineStyle: { color: '#f3f4f6' } },
            axisLine: { show: false },
        },
        series: [{
            type: 'bar',
            data: months.map(function(v) {
                return {
                    value: v,
                    itemStyle: {
                        color: { type: 'linear', x: 0, y: 0, x2: 0, y2: 1,
                                 colorStops: [{ offset: 0, color: '#4a8a92' }, { offset: 1, color: '#2B5257' }] },
                        borderRadius: [4, 4, 0, 0],
                    },
                };
            }),
            barMaxWidth: 32,
            label: {
                show: maxVal > 0,
                position: 'top',
                formatter: function(p) { return p.value > 0 ? Number(p.value).toLocaleString('vi-VN') : ''; },
                fontSize: 9.5,
                color: '#6b7280',
            },
        }],
    });

    window.rcCalBindViewChartResize();
};

window.rcCalBindViewChartResize = function() {
    if (window._rcViewChartResizeHandler) return;
    window._rcViewChartResizeHandler = function() {
        if (window._rcViewMonthlyChart) window._rcViewMonthlyChart.resize();
    };
    window.addEventListener('resize', window._rcViewChartResizeHandler);
};

window.rcCalCloseViewPopup = function() {
    var overlay = document.getElementById('ta-rc-view-popup');
    if (overlay) overlay.style.display = 'none';
    if (window._rcViewMonthlyChart) {
        try { window._rcViewMonthlyChart.dispose(); } catch (e) {}
        window._rcViewMonthlyChart = null;
    }
    window._rcViewPopupCtx = null;
};

// Popup "Giá phòng" — nút cùng tên trong hàng thao tác nhanh dưới tên phòng (rcCalRenderGrid()) —
// gọi GET /admin/api/rooms/{id}/pricing-info (routes/web.php), CHỈ XEM (sửa vẫn qua SettingBook,
// link cuối popup — 'timeslot_url' server trả kèm).
window.rcCalOpenPricePopup = function(productId, roomName) {
    var overlay = document.getElementById('ta-rc-price-popup');
    var body    = document.getElementById('ta-rc-price-popup-body');
    var title   = document.getElementById('ta-rc-price-popup-title');
    if (!overlay || !body || !productId) return;

    if (title) title.textContent = 'Giá phòng — ' + roomName;
    body.innerHTML = '<div class="ta-rc-order-popup-loading">Đang tải...</div>';
    overlay.style.display = 'flex';

    fetch('/admin/api/rooms/' + productId + '/pricing-info', { credentials: 'same-origin' })
        .then(function(r) { return r.ok ? r.json() : Promise.reject(); })
        .then(function(d) {
            var fmt = function(n) { return Number(n || 0).toLocaleString('vi-VN') + 'đ'; };

            // Cột trái: 4 điều kiện giá (nhãn KHÔNG xuống dòng — .ta-rc-price-field-label riêng,
            // khác .ta-rc-order-popup-field-label của popup đơn hàng vì nhãn ở đây dài hơn hẳn:
            // "Giảm giá full phòng"/"Giảm theo khung"...). Cột phải: khung giờ/giá/khuyến mãi.
            function field(label, value) {
                return '<div class="ta-rc-price-field">'
                    + '<span class="ta-rc-price-field-label">' + window._escAttr(label) + '</span>'
                    + '<span class="ta-rc-price-field-value">' + value + '</span>'
                    + '</div>';
            }

            // 3/4 field sửa được TẠI CHỖ (input, lưu bằng rcCalSavePricing() — POST cùng URL GET
            // này) — "vượt ngưỡng" trước đây mơ hồ (vượt bao nhiêu?), giờ ghi rõ áp dụng từ khách
            // thứ mấy, suy trực tiếp từ ô "Khách miễn phí" (input#ta-rc-price-maxfree, cập nhật
            // khi gõ — xem listener gắn ở cuối hàm).
            var left = field('Giảm giá full phòng',
                '<input type="text" class="ta-rc-price-input" id="ta-rc-price-fbd" value="' + window._escAttr(d.full_booking_discount || '') + '" placeholder="VD: 10% hoặc 50000">');
            left += field('Khách miễn phí',
                '<div class="ta-rc-price-input-row"><input type="number" class="ta-rc-price-input" id="ta-rc-price-maxfree" value="' + d.max_free_guests + '" min="0"><span class="ta-rc-price-input-suffix">khách</span></div>');
            left += field('Phụ thu/khách',
                '<div class="ta-rc-price-input-row"><input type="number" class="ta-rc-price-input" id="ta-rc-price-extrafee" value="' + d.extra_guest_fee + '" min="0" step="1000"><span class="ta-rc-price-input-suffix">đ, tính từ khách thứ <span id="ta-rc-price-maxfree-echo">' + (d.max_free_guests + 1) + '</span></span></div>');

            // "Giảm theo số khung" — SỬA được: mỗi mức 1 hàng (số khung + % + nút xoá), "+ Thêm
            // mức" (rcCalAddBulkRow()) thêm hàng trống. Gửi lên khi Lưu — xem rcCalSavePricing().
            function bulkRowHtml(slots, discount) {
                return '<div class="ta-rc-price-bulk-row">'
                    + '<input type="number" class="ta-rc-price-bulk-slots" min="2" placeholder="Số khung"' + (slots != null ? ' value="' + slots + '"' : '') + '>'
                    + '<span class="ta-rc-price-bulk-arrow">khung →</span>'
                    + '<input type="number" class="ta-rc-price-bulk-discount" min="0" max="100" placeholder="%"' + (discount != null ? ' value="' + discount + '"' : '') + '>'
                    + '<span class="ta-rc-price-bulk-pct">%</span>'
                    + '<button type="button" class="ta-rc-price-bulk-remove-btn" onclick="this.closest(\'.ta-rc-price-bulk-row\').remove()">×</button>'
                    + '</div>';
            }
            var bulkRowsHtml = (d.bulk_discount_rules && d.bulk_discount_rules.length)
                ? d.bulk_discount_rules.map(function(r) { return bulkRowHtml(r.slots, r.discount); }).join('')
                : '';
            var bulkHtml = '<div class="ta-rc-price-bulk-editor" id="ta-rc-price-bulk-editor">' + bulkRowsHtml + '</div>'
                + '<button type="button" class="ta-rc-price-bulk-add-btn" onclick="rcCalAddBulkRow()">+ Thêm mức</button>';
            left += field('Giảm theo khung', bulkHtml);

            var right = '<div class="ta-rc-price-slots-head">Khung giờ, giá &amp; khuyến mãi</div>';

            // Danh sách khuyến mãi CÓ SẴN (available_promotions — đúng phạm vi
            // allowedPromotionOptions() của SettingBook) để gắn thêm vào từng khung giờ — dùng
            // chung 1 danh sách <option> cho MỌI khung (khuyến mãi đã gắn rồi vẫn chọn được,
            // rcCalAddPromoTag() tự bỏ qua nếu trùng).
            var promoOptionsHtml = '<option value="">+ Gắn khuyến mãi...</option>'
                + (d.available_promotions || []).map(function(p) {
                    return '<option value="' + p.id + '">' + window._escAttr(p.name) + '</option>';
                }).join('');

            if (!d.slots || !d.slots.length) {
                right += '<div class="ta-rc-order-popup-access-empty">Phòng chưa khai báo khung giờ nào.</div>';
            } else {
                right += '<div class="ta-rc-price-slots">' + d.slots.map(function(slot) {
                    // Khuyến mãi đã gắn hiện dạng tag, bấm × để gỡ (chỉ xoá khỏi giao diện, GỬI
                    // LÊN khi bấm "Lưu thay đổi" mới thật sự sync — xem rcCalSavePricing()).
                    var tagsHtml = (slot.promotions && slot.promotions.length)
                        ? slot.promotions.map(function(p) {
                            var valTxt = (p.type.indexOf('percentage') !== -1) ? (p.value + '%') : fmt(p.value);
                            var title = p.type_label + ' ' + valTxt
                                + (p.start_at || p.end_at ? ' (' + (p.start_at || '…') + ' – ' + (p.end_at || '…') + ')' : '')
                                + (p.is_active ? '' : ' · Đã tắt');
                            return '<span class="ta-rc-price-promo-tag' + (p.is_active ? '' : ' is-inactive') + '" data-promo-id="' + p.id + '" title="' + window._escAttr(title) + '">'
                                + window._escAttr(p.name)
                                + '<button type="button" onclick="this.closest(\'.ta-rc-price-promo-tag\').remove()">×</button>'
                                + '</span>';
                        }).join('')
                        : '';
                    return '<div class="ta-rc-price-slot-row" data-slot-id="' + slot.id + '">'
                        + '<div class="ta-rc-price-slot-top">'
                        +     '<span class="ta-rc-price-slot-label">' + window._escAttr(slot.label) + (slot.over_night ? ' 🌙' : '') + '</span>'
                        +     '<div class="ta-rc-price-input-row"><input type="number" class="ta-rc-price-input ta-rc-price-slot-input" value="' + slot.price + '" min="0" step="1000"><span class="ta-rc-price-input-suffix">đ</span></div>'
                        + '</div>'
                        + '<div class="ta-rc-price-promo-tags">' + tagsHtml + '</div>'
                        + '<select class="ta-rc-price-promo-select" onchange="rcCalAddPromoTag(this)">' + promoOptionsHtml + '</select>'
                        + '</div>';
                }).join('') + '</div>';
            }

            var html = '<div class="ta-rc-price-columns">'
                + '<div class="ta-rc-price-col-left">' + left + '</div>'
                + '<div class="ta-rc-price-col-right">' + right + '</div>'
                + '</div>'
                + '<div class="ta-rc-price-footer">'
                +     '<a href="' + d.timeslot_url + '" class="ta-rc-price-settingbook-link" target="_blank" rel="noopener">Mở Hệ thống giá (giảm theo khung, khuyến mãi...)</a>'
                +     '<div class="ta-rc-price-save-row">'
                +         '<span class="ta-rc-price-save-status" id="ta-rc-price-save-status"></span>'
                +         '<button type="button" class="ta-rc-order-popup-goto-btn" id="ta-rc-price-save-btn" onclick="rcCalSavePricing(\'' + window._escAttr(String(productId)) + '\')">Lưu thay đổi</button>'
                +     '</div>'
                + '</div>';

            body.innerHTML = html;

            // Ô "Phụ thu/khách" hiện kèm "tính từ khách thứ N" — N phải đổi NGAY khi admin sửa ô
            // "Khách miễn phí" (chưa lưu), không đợi tải lại popup mới khớp.
            var maxFreeInput = document.getElementById('ta-rc-price-maxfree');
            var echoEl       = document.getElementById('ta-rc-price-maxfree-echo');
            if (maxFreeInput && echoEl) {
                maxFreeInput.addEventListener('input', function() {
                    var v = parseInt(maxFreeInput.value, 10);
                    echoEl.textContent = (isNaN(v) ? 0 : v) + 1;
                });
            }
        })
        .catch(function() {
            body.innerHTML = '<div class="ta-rc-order-popup-error">Không tải được thông tin giá phòng.</div>';
        });
};

// "+ Thêm mức" trong "Giảm theo khung" — thêm 1 hàng trống (số khung + % + nút xoá riêng từng
// hàng, xem inline onclick trong bulkRowHtml() ở rcCalOpenPricePopup()).
window.rcCalAddBulkRow = function() {
    var editor = document.getElementById('ta-rc-price-bulk-editor');
    if (!editor) return;
    var div = document.createElement('div');
    div.className = 'ta-rc-price-bulk-row';
    div.innerHTML = '<input type="number" class="ta-rc-price-bulk-slots" min="2" placeholder="Số khung">'
        + '<span class="ta-rc-price-bulk-arrow">khung →</span>'
        + '<input type="number" class="ta-rc-price-bulk-discount" min="0" max="100" placeholder="%">'
        + '<span class="ta-rc-price-bulk-pct">%</span>'
        + '<button type="button" class="ta-rc-price-bulk-remove-btn" onclick="this.closest(\'.ta-rc-price-bulk-row\').remove()">×</button>';
    editor.appendChild(div);
    div.querySelector('.ta-rc-price-bulk-slots').focus();
};

// Chọn khuyến mãi trong <select> của 1 khung giờ → thêm thành tag gắn kèm (bấm × trên tag để gỡ,
// xem inline onclick trong rcCalOpenPricePopup()) — CHƯA gọi server, chỉ thật sự sync khi bấm
// "Lưu thay đổi" (rcCalSavePricing() đọc lại toàn bộ tag hiện có của từng khung giờ).
window.rcCalAddPromoTag = function(selectEl) {
    var id = selectEl.value;
    if (!id) return;
    var name = selectEl.options[selectEl.selectedIndex].text;
    var tagsWrap = selectEl.closest('.ta-rc-price-slot-row').querySelector('.ta-rc-price-promo-tags');
    selectEl.value = '';
    if (!tagsWrap || tagsWrap.querySelector('[data-promo-id="' + id + '"]')) return;

    var span = document.createElement('span');
    span.className = 'ta-rc-price-promo-tag';
    span.dataset.promoId = id;
    span.innerHTML = window._escAttr(name) + '<button type="button" onclick="this.closest(\'.ta-rc-price-promo-tag\').remove()">×</button>';
    tagsWrap.appendChild(span);
};

// Lưu full_booking_discount/max_free_guests/extra_guest_fee/giá + "Giảm theo khung"
// (bulk_discount_rules) + khuyến mãi đang gắn từng khung giờ (đọc lại NGAY từ DOM — tag đã gỡ
// bằng nút × không còn trong DOM nên tự động không gửi lên nữa) — POST cùng URL với GET
// (routes/web.php).
window.rcCalSavePricing = function(productId) {
    var btn    = document.getElementById('ta-rc-price-save-btn');
    var status = document.getElementById('ta-rc-price-save-status');
    if (!btn) return;

    var fbdInput = document.getElementById('ta-rc-price-fbd');
    var maxFree  = parseInt(document.getElementById('ta-rc-price-maxfree').value, 10) || 0;
    var extraFee = parseInt(document.getElementById('ta-rc-price-extrafee').value, 10) || 0;

    var bulkRules = Array.prototype.map.call(document.querySelectorAll('.ta-rc-price-bulk-row'), function(row) {
        return {
            slots: parseInt(row.querySelector('.ta-rc-price-bulk-slots').value, 10),
            discount: parseFloat(row.querySelector('.ta-rc-price-bulk-discount').value),
        };
    }).filter(function(r) { return r.slots >= 2 && !isNaN(r.discount) && r.discount >= 0; });

    var slots = Array.prototype.map.call(document.querySelectorAll('.ta-rc-price-slot-row'), function(row) {
        return {
            id: parseInt(row.dataset.slotId, 10),
            price: parseInt(row.querySelector('.ta-rc-price-slot-input').value, 10) || 0,
            promotion_ids: Array.prototype.map.call(row.querySelectorAll('.ta-rc-price-promo-tag'), function(tag) {
                return parseInt(tag.dataset.promoId, 10);
            }),
        };
    });

    btn.disabled = true;
    btn.textContent = 'Đang lưu...';
    if (status) status.textContent = '';

    fetch('/admin/api/rooms/' + productId + '/pricing-info', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({
            full_booking_discount: fbdInput.value.trim() || null,
            max_free_guests: maxFree,
            extra_guest_fee: extraFee,
            bulk_discount_rules: bulkRules,
            slots: slots,
        }),
        credentials: 'same-origin',
    })
        .then(function(r) { return r.ok ? r.json() : null; })
        .then(function(d) {
            btn.disabled = false;
            btn.textContent = 'Lưu thay đổi';
            if (status) {
                status.textContent = (d && d.ok) ? 'Đã lưu ✓' : 'Lưu thất bại, thử lại';
                status.classList.toggle('is-error', !(d && d.ok));
                setTimeout(function() { status.textContent = ''; }, 2500);
            }

            // Cập nhật NGAY hiệu ứng viền cầu vồng (.is-discounted) trên view "Lịch" theo
            // has_discount server vừa tính lại (routes/web.php admin.rooms.pricing-info.update)
            // — sửa thẳng window.__rcRoomsData rồi vẽ lại lưới, không cần đợi poll định kỳ hay F5
            // lại trang mới thấy khuyến mãi vừa gắn/gỡ.
            if (d && d.ok && Array.isArray(d.slots)) {
                var room = (window.__rcRoomsData || []).find(function(r) { return String(r.product_id) === String(productId); });
                if (room && room.timeslot_grid && room.timeslot_grid.rows) {
                    d.slots.forEach(function(s) {
                        var row = room.timeslot_grid.rows.find(function(rw) { return String(rw.id) === String(s.id); });
                        if (row) row.has_discount = s.has_discount;
                    });
                    if (window._rcCurrentView === 'calendar' && window.rcCalRenderGrid) {
                        window.rcCalRenderGrid();
                    }
                }
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = 'Lưu thay đổi';
            if (status) { status.textContent = 'Lưu thất bại, thử lại'; status.classList.add('is-error'); }
        });
};

window.rcCalClosePricePopup = function() {
    var overlay = document.getElementById('ta-rc-price-popup');
    if (overlay) overlay.style.display = 'none';
};

window.rcCalToggleNoteEdit = function(btn) {
    var block = btn.closest('.ta-rc-order-popup-note-block');
    block.querySelector('.ta-rc-order-popup-note-display').style.display = 'none';
    block.querySelector('.ta-rc-order-popup-note-edit').style.display = '';
    var input = block.querySelector('.ta-rc-order-popup-note-input');
    input.focus();
    input.selectionStart = input.selectionEnd = input.value.length;
};

window.rcCalCancelNoteEdit = function(btn) {
    var block = btn.closest('.ta-rc-order-popup-note-block');
    block.querySelector('.ta-rc-order-popup-note-input').value = block.dataset.note || '';
    block.querySelector('.ta-rc-order-popup-note-edit').style.display = 'none';
    block.querySelector('.ta-rc-order-popup-note-display').style.display = '';
};

// Lưu ghi chú (Order.description) ngay trong popup, không cần mở orderform — sau khi lưu, tự cập
// nhật cờ 'has_note' cho MỌI ô khung giờ của đúng đơn này trong window.__rcRoomsData (1 đơn có
// thể chiếm nhiều ô — nhiều khung giờ/ngày) rồi vẽ lại lưới ngay để badge chuông hiện/ẩn tức thì,
// không cần đợi poll lại (pollRoomCards(), 2 phút/lần).
window.rcCalSaveOrderNote = function(btn) {
    var block   = btn.closest('.ta-rc-order-popup-note-block');
    var orderId = block.dataset.orderId;
    var input   = block.querySelector('.ta-rc-order-popup-note-input');
    var note    = input.value.trim();

    btn.disabled = true;
    btn.textContent = 'Đang lưu...';

    fetch('/admin/api/orders/' + orderId + '/description', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ description: note }),
        credentials: 'same-origin',
    })
        .then(function(r) { return r.ok ? r.json() : null; })
        .then(function(d) {
            btn.disabled = false;
            btn.textContent = 'Lưu';
            if (!d || !d.ok) return;

            block.dataset.note = d.description || '';
            var disp = block.querySelector('.ta-rc-order-popup-note-display');
            disp.textContent = d.description || '—';
            block.querySelector('.ta-rc-order-popup-note-edit').style.display = 'none';
            disp.style.display = '';

            // Mô tả trạng thái (badge "Có ghi chú" cạnh trạng thái đơn ở đầu popup) tự cập nhật
            // ngay theo cờ has_note vừa lưu, không cần đóng/mở lại popup mới thấy.
            var noteFlag = document.getElementById('ta-rc-order-popup-note-flag');
            if (noteFlag) noteFlag.style.display = d.has_note ? '' : 'none';

            (window.__rcRoomsData || []).forEach(function(room) {
                var cells = room.timeslot_grid && room.timeslot_grid.cells;
                if (!cells) return;
                Object.keys(cells).forEach(function(key) {
                    var cell = cells[key];
                    if (cell && String(cell.order_id) === String(orderId)) {
                        cell.has_note = d.has_note;
                    }
                });
            });
            if (window.rcCalRenderGrid) window.rcCalRenderGrid();
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = 'Lưu';
        });
};

// Luôn vào view "Lịch" mỗi khi tải lại trang bảng điều khiển — KHÔNG nhớ lựa chọn cũ qua
// localStorage nữa (trước đây có nhớ, nhưng lựa chọn 'list' lưu từ trước khi có yêu cầu này khiến
// trang vẫn hiện "Danh sách" dù đã đổi mặc định). Trong phiên làm việc admin vẫn đổi view thoải
// mái qua rcSetView(), chỉ là không giữ lại giữa các lần tải trang.
(function() {
    window.rcSetView('calendar');
})();

// ── Tooltip dùng chung cho mọi đơn (view Danh sách + Dải giờ) ──────────────
// 1 phần tử duy nhất, định vị bằng getBoundingClientRect() nên không bị overflow:hidden của thẻ
// phòng cắt mất, và không có chuyện chỉ hover được đúng 1 đơn khi thẻ có nhiều đơn.
(function() {
    var svgClock = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    var svgCoin  = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/><path d="M12 7v10M9 9.5c0-1.5 1.3-2.5 3-2.5s3 1 3 2.5-1.3 2-3 2.5-3 1-3 2.5 1.3 2.5 3 2.5 3-1 3-2.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
    var svgNote  = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="1.6"/><path d="M14 2v6h6" stroke="currentColor" stroke-width="1.6"/></svg>';

    function buildTooltipHtml(o) {
        var timeText = o.slot_labels
            ? (o.checkin ? o.checkin + ', ' : '') + o.slot_labels
            : (o.checkin ? o.checkin + (o.checkout ? ' → ' + o.checkout : '') : '');

        var html = '<div class="rc-tooltip-row">' +
            '<span class="rc-tooltip-name">' + (o.buyer_name || 'Khách') + '</span>' +
            (o.buyer_phone ? '<span class="rc-tooltip-phone">· ' + o.buyer_phone + '</span>' : '') +
            (o.status_label ? '<span class="rc-tooltip-status" style="background:' + (o.status_color || '#94a3b8') + ';color:#fff;">' + o.status_label + '</span>' : '') +
        '</div>';
        html += '<div class="rc-tooltip-divider"></div>';
        if (timeText) html += '<div class="rc-tooltip-line">' + svgClock + '<span>' + timeText + '</span></div>';
        if (o.amount > 0) html += '<div class="rc-tooltip-line">' + svgCoin + '<span class="rc-tooltip-amount">' + Number(o.amount).toLocaleString('vi-VN') + '₫</span></div>';
        if (o.deposit_room) html += '<div class="rc-tooltip-line">' + svgNote + '<span>' + o.deposit_room + '</span></div>';
        html += '<div class="rc-tooltip-muted">Tạo lúc ' + (o.created_at_fmt || o.created_at || '') + '</div>';
        return html;
    }

    function positionTooltip(el, target) {
        var rect = target.getBoundingClientRect();
        // đo trước để biết chiều cao thật (đang display nhưng opacity 0)
        var tw = el.offsetWidth, th = el.offsetHeight;
        var top = rect.top - th - 8;
        if (top < 8) top = rect.bottom + 8; // không đủ chỗ phía trên → đặt phía dưới
        var left = rect.left + (rect.width - tw) / 2;
        left = Math.max(8, Math.min(left, window.innerWidth - tw - 8));
        el.style.top  = top + 'px';
        el.style.left = left + 'px';
    }

    function showTooltip(target) {
        var el = document.getElementById('rc-tooltip');
        if (!el) return;
        var data = {};
        try { data = JSON.parse(target.dataset.order || '{}'); } catch (e) { return; }
        el.innerHTML = buildTooltipHtml(data);
        el.style.display = 'block';
        positionTooltip(el, target);
        requestAnimationFrame(function() { el.classList.add('show'); });
    }

    function hideTooltip() {
        var el = document.getElementById('rc-tooltip');
        if (!el) return;
        el.classList.remove('show');
    }

    document.addEventListener('mouseover', function(e) {
        var target = e.target.closest('.ta-rc-order-item');
        if (!target || !target.closest('#ta-room-grid')) return;
        showTooltip(target);
    });
    document.addEventListener('mouseout', function(e) {
        var target = e.target.closest('.ta-rc-order-item');
        if (!target) return;
        if (target.contains(e.relatedTarget)) return; // vẫn còn trong cùng 1 đơn (di chuột nội bộ)
        hideTooltip();
    });
    document.addEventListener('focusin', function(e) {
        var target = e.target.closest('.ta-rc-order-item');
        if (target && target.closest('#ta-room-grid')) showTooltip(target);
    });
    document.addEventListener('focusout', function(e) {
        var target = e.target.closest('.ta-rc-order-item');
        if (target) hideTooltip();
    });
    // Cuộn trang / đổi tab lọc → toạ độ cũ không còn đúng, ẩn luôn cho chắc.
    window.addEventListener('scroll', hideTooltip, true);
})();
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
            var col = statusColorMap[o.status] || o.status_color || '#94a3b8';
            var seg = o.segment || 'upcoming';

            // Đơn theo khung giờ (slot) — ưu tiên hiện đúng khung giờ thật (vd "15:10 - 18:00")
            // kèm ngày, thay vì nhãn checkin/checkout (rỗng giờ với kiểu slot, dễ gây hiểu lầm).
            var timeHtml = o.slot_labels
                ? '<span class="ta-rc-time-compact">' + (o.checkin ? o.checkin + ', ' : '') + o.slot_labels + '</span>'
                : (o.checkin
                    ? '<span class="ta-rc-time-compact">' + o.checkin + (o.checkout ? ' → ' + o.checkout : '') + '</span>'
                    : '');
            var amtHtml = o.amount > 0
                ? '<span class="ta-rc-amount-compact">' + Number(o.amount).toLocaleString('vi-VN') + '₫</span>'
                : '';

            var _oAttr = window._escAttr ? window._escAttr(JSON.stringify({
                order_id: o.order_id, order_code: o.order_code,
                buyer_name: o.buyer_name, buyer_phone: o.buyer_phone,
                checkin: o.checkin, checkout: o.checkout,
                status_label: o.status_label, status_color: col,
                amount: o.amount, segment: seg,
                slot_count: o.slot_count, slot_labels: o.slot_labels,
                slot_ranges: o.slot_ranges || [],
                created_at: o.created_at, created_at_fmt: o.created_at_fmt,
                is_new: o.is_new,
                deposit_room: o.deposit_room
            })) : '';
            html +=
                '<div class="ta-rc-order-item' + (o.is_new ? ' is-new' : '') + ' seg-' + seg + '" data-segment="' + seg + '" data-status="' + o.status + '" data-order="' + _oAttr + '" onclick="rcOrderItemClick(event, this)">' +
                  '<div class="ta-rc-line1">' +
                    '<span class="ta-rc-status-compact" style="background:' + col + '1a;color:' + col + ';">' + o.status_label + '</span>' +
                    '<span class="ta-rc-guest-compact">' + o.buyer_name + '</span>' +
                  '</div>' +
                  '<div class="ta-rc-line2">' +
                    timeHtml +
                    '<span class="ta-rc-spacer"></span>' +
                    amtHtml +
                    '<a href="/admin/orders/' + o.order_id + '/edit" class="ta-rc-detail-compact" title="Xem chi tiết đơn"><svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M9 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></a>' +
                  '</div>' +
                '</div>';
        });
        html += '<div class="ta-rc-no-match" style="display:none;">Không có đơn ở chế độ xem này</div>';
        return html;
    }

    // View "Chi tiết (cũ)" — giữ nguyên giao diện đầy đủ trước khi tối giản, để so sánh.
    function buildOrdersHtmlDetail(orders) {
        if (!orders || orders.length === 0) {
            return '<div class="ta-rc-empty">Không có đơn</div><div class="ta-rc-no-match" style="display:none;"></div>';
        }
        var html = '';
        orders.forEach(function(o) {
            var col      = statusColorMap[o.status] || o.status_color || '#94a3b8';
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
                created_at: o.created_at, created_at_fmt: o.created_at_fmt,
                is_new: o.is_new,
                deposit_room: o.deposit_room
            })) : '';
            html +=
                '<div class="ta-rc-order-item' + (o.is_new ? ' is-new' : '') + ' seg-' + seg + '" data-segment="' + seg + '" data-status="' + o.status + '" data-order="' + _oAttr + '" onclick="rcOrderItemClick(event, this)">' +
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

    // ── Icon nhỏ (dọn vệ sinh / hoàn tiền) + nút menu ⋮ trên thẻ phòng — PHẢI khớp 100% với bản
    // Blade (_room-cards.blade.php) vì renderRoomCards() (JS) đè lại card.innerHTML mỗi khi tự
    // làm mới định kỳ (pollRoomCards(), 2 phút/lần + mỗi khi có đơn mới), nếu thiếu nút này ở
    // đây thì sau khoảng thời gian đó menu ⋮ sẽ "tự mất" dù lúc F5 trang vẫn còn (đã từng xảy ra
    // đúng bug này — xem lại lịch sử: thiếu đồng bộ giữa bản Blade và bản JS render lại). ────────
    function rcEscHtml(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function rcEscAttr(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
    }

    function rcBuildNameFlagsHtml(room) {
        var html = '<span class="ta-rc-name-text">' + rcEscHtml(room.room_name) + '</span>';
        if (room.pending_refund) {
            var r = room.pending_refund;
            var refundTitle = 'Chờ hoàn tiền: ' + Number(r.amount || 0).toLocaleString('vi-VN') + 'đ — ' + r.buyer_name + ' (#' + r.order_code + ')';
            html += '<span class="ta-rc-flag refund" title="' + rcEscAttr(refundTitle) + '">' +
                '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10h11a4 4 0 010 8h-2M3 10l4-4M3 10l4 4"/></svg>' +
                '</span>';
        }
        return html;
    }

    function rcBuildMenuBtnHtml(room) {
        var menuData = {
            edit_url: room.edit_url,
            timeslot_url: room.timeslot_url,
            book_url: room.book_url,
            room_name: room.room_name,
            cleaning: room.housekeeping_status || 'available',
            refund: room.pending_refund || null,
        };
        var pid = String(room.product_id).replace(/'/g, '');
        return '<button type="button" class="ta-rc-menu-btn" title="Thao tác nhanh" ' +
            'onclick="rcOpenRoomMenu(event, \'' + pid + '\')" ' +
            'data-room-menu="' + rcEscAttr(JSON.stringify(menuData)) + '">' +
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="5" r="1.6" fill="currentColor"/><circle cx="12" cy="12" r="1.6" fill="currentColor"/><circle cx="12" cy="19" r="1.6" fill="currentColor"/></svg>' +
            '</button>';
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

        // Cập nhật bootstrap của view "Lịch" (rcCalRenderBranches()/rcCalRenderGrid()) — nếu view
        // đó đang mở thì vẽ lại ngay để khớp dữ liệu vừa poll (đơn mới/huỷ đơn...), không đợi
        // người dùng tự bấm lại tab "Lịch".
        window.__rcRoomsData    = data.rooms || [];
        window.__rcBranchesData = data.branches || [];
        var calView = document.getElementById('ta-rc-cal-view');
        if (calView && calView.style.display !== 'none') {
            window.rcCalRenderBranches();
            window.rcCalRenderGrid();
        }

        var ids  = { all: 'ta-rct-badge-all', active: 'ta-rct-badge-active', today: 'ta-rct-badge-today', upcoming: 'ta-rct-badge-upcoming', overdue: 'ta-rct-badge-overdue' };
        var vals = { all: data.total_orders, active: data.total_active, today: data.total_today, upcoming: data.total_upcoming, overdue: data.total_overdue };
        Object.keys(ids).forEach(function(k) {
            var el = document.getElementById(ids[k]);
            if (el) el.textContent = (vals[k] > 0) ? vals[k] : '';
        });
        // Đặt cọc badge — đếm từ DOM sau khi render xong
        var depositCount = document.querySelectorAll('#ta-room-grid .ta-rc-orders .ta-rc-order-item[data-status="deposit"]').length;
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
                card.addEventListener('click', function(e) { window.rcCardClick(e, card); });
                grid.appendChild(card);
                existingCards[pid] = card;
            }
            card.dataset.branch   = room.branch;
            card.dataset.styles   = String(room.styles || 1);
            card.dataset.time     = room.latest_time;
            card.dataset.editUrl  = room.edit_url || '';
            card.classList.toggle('has-new',    isNew);
            card.classList.toggle('has-active', room.active_count > 0);

            card.innerHTML =
                '<div class="ta-rc-head">' +
                  '<div class="ta-rc-info">' +
                    '<div class="ta-rc-name">' + rcBuildNameFlagsHtml(room) + '</div>' +
                  '</div>' +
                  rcBuildMenuBtnHtml(room) +
                  '<div class="ta-rc-count' + (room.count === 0 ? ' empty' : '') + '">' + (room.count > 0 ? room.count + ' đơn' : 'Trống') + '</div>' +
                '</div>' +
                '<div class="ta-rc-orders">' + buildOrdersHtml(room.orders) + '</div>' +
                '<div class="ta-rc-orders-detail">' + buildOrdersHtmlDetail(room.orders) + '</div>';

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
                var elRevOriginal = document.getElementById('ta-kpi-revenue-original');
                var elRevOriginalHint = document.getElementById('ta-kpi-revenue-original-hint');
                var elPayos = document.getElementById('ta-kpi-revenue-payos');
                var elCod          = document.getElementById('ta-kpi-revenue-cod');
                var elDepositPayos = document.getElementById('ta-kpi-revenue-deposit-payos');
                var elDepositCod   = document.getElementById('ta-kpi-revenue-deposit-cod');
                var elRange        = document.getElementById('ta-period-range');
                if (elTotal)        elTotal.textContent        = Number(d.total).toLocaleString('vi-VN');
                if (elPaid)         elPaid.textContent         = Number(d.paidCount).toLocaleString('vi-VN');
                if (elRev)          elRev.innerHTML            = Number(d.revenue).toLocaleString('vi-VN') + '<span class="unit">đ</span>';
                if (elRevOriginal)  elRevOriginal.innerHTML    = Number(d.revenueOriginal || 0).toLocaleString('vi-VN') + '<span class="unit">đ</span>';
                if (elRevOriginalHint) {
                    var extraCharge = Number(d.revenueExtraCharge || 0);
                    elRevOriginalHint.innerHTML = extraCharge !== 0
                        ? 'Chênh lệch (phụ phí phát sinh): <strong>' + (extraCharge > 0 ? '+' : '') + extraCharge.toLocaleString('vi-VN') + 'đ</strong>'
                        : 'Đơn đã thanh toán · chưa có phụ phí phát sinh';
                }
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
                setKpiDelta(document.getElementById('ta-kpi-revenue-original-delta'), d.revenueOriginalDelta || 0, '%');
                setKpiDelta(document.getElementById('ta-kpi-revenue-payos-delta'), d.revenuePayosDelta, '%');
                setKpiDelta(document.getElementById('ta-kpi-revenue-cod-delta'),           d.revenueCodDelta,              '%');
                setKpiDelta(document.getElementById('ta-kpi-revenue-deposit-payos-delta'), d.revenueDepositPayosDelta || 0, '%');
                setKpiDelta(document.getElementById('ta-kpi-revenue-deposit-cod-delta'),   d.revenueDepositCodDelta || 0,   '%');
            })
            .catch(function() {});
    }

    function pollRoomCards() {
        if (rcPulse) rcPulse.style.display = '';
        // 'days' — số ngày hiển thị ở view "Lịch" (select 5/10/15 cạnh carousel chi nhánh, xem
        // rcCalApplyDaysRange()), mặc định 10. Gửi kèm ở MỌI lần poll (kể cả poll định kỳ tự
        // động, không riêng lúc admin vừa đổi số) để giữ đúng số ngày admin đang xem, không bị
        // tự rơi về 10 sau 2 phút.
        fetch('/admin/api/room-cards?days=' + (window._rcCalDays || 10), { credentials: 'same-origin' })
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
                var elRevOriginal  = document.getElementById('ta-kpi-revenue-original');
                var elRevOriginalHint = document.getElementById('ta-kpi-revenue-original-hint');
                var elPayos        = document.getElementById('ta-kpi-revenue-payos');
                var elCod          = document.getElementById('ta-kpi-revenue-cod');
                var elDepositPayos = document.getElementById('ta-kpi-revenue-deposit-payos');
                var elDepositCod   = document.getElementById('ta-kpi-revenue-deposit-cod');
                var elRange        = document.getElementById('ta-period-range');
                if (elTotal)        elTotal.textContent        = Number(d.total).toLocaleString('vi-VN');
                if (elPaid)         elPaid.textContent         = Number(d.paidCount).toLocaleString('vi-VN');
                if (elRev)          elRev.innerHTML            = Number(d.revenue).toLocaleString('vi-VN') + '<span class="unit">đ</span>';
                if (elRevOriginal)  elRevOriginal.innerHTML    = Number(d.revenueOriginal || 0).toLocaleString('vi-VN') + '<span class="unit">đ</span>';
                if (elRevOriginalHint) {
                    var extraCharge2 = Number(d.revenueExtraCharge || 0);
                    elRevOriginalHint.innerHTML = extraCharge2 !== 0
                        ? 'Chênh lệch (phụ phí phát sinh): <strong>' + (extraCharge2 > 0 ? '+' : '') + extraCharge2.toLocaleString('vi-VN') + 'đ</strong>'
                        : 'Đơn đã thanh toán · chưa có phụ phí phát sinh';
                }
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
                setKpiDelta(document.getElementById('ta-kpi-revenue-original-delta'),      d.revenueOriginalDelta || 0, '%');
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
