// Realtime "khung giờ vừa đổi giá/khuyến mãi/mã giảm giá" cho khách hàng — nghe Node WS service
// (websocket/server.js, KHÁC với Reverb dùng cho hold-chỗ ADMIN ở echo-client.js) kênh
// "room:{roomId}:{date}", event "slot.updated" (bắn bởi SlotRealtimeService::broadcastBlockedRange
// khi admin lưu ở SettingBook — xem Modules/Book/App/Filament/Resources/BookResource/Pages/
// SettingBook.php::broadcastDatesChanged()).
//
// KHÁC với echo-client.js (vá trực tiếp DOM cho hold — tần suất rất cao, hàng nghìn khách cùng
// lúc): ở đây admin đổi giá là thao tác HIẾM (vài lần/ngày), nên chấp nhận ép Livewire re-render
// component thật (Livewire.dispatch('roomAvailabilityChanged') — xem onRoomAvailabilityChanged()
// trong ProductDetail.php/Book.php) để lấy đúng giá/khuyến mãi mới từ DB, thay vì tự vá DOM (sẽ
// phải chép lại logic format giá/km sang JS, dễ lệch với PHP).
//
// CÙNG file này còn nghe thêm event "slot.hold.updated" (bắn bởi TimeSlotHoldController — KHÁCH
// tự chọn khung giờ, khác hẳn TimeslotHoldService/echo-client.js dành cho ADMIN) — event này tần
// suất CAO (mỗi lần khách bấm chọn/bỏ chọn 1 ô), nên xử lý bằng vá DOM trực tiếp (applySlotHoldUpdate())
// giống kỹ thuật echo-client.js, KHÔNG dùng scheduleDispatch()/Livewire re-render như slot.updated.
(function () {
    const WS_URL = window.__WS_PUBLIC_URL;

    if (!WS_URL) {
        return;
    }

    const LOCK_ICON_HTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        + 'stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="9" rx="2"></rect>'
        + '<path d="M8 11V7a4 4 0 0 1 8 0v4"></path></svg>';

    const OTHER_BLOCKING_CLASSES = ['booked', 'pending', 'blocked', 'past-time', 'past-date'];

    function heldCellsFor(roomId, date) {
        return document.querySelectorAll(`[data-room-id="${roomId}"][data-iso-date="${date}"]`);
    }

    function applyCustomerHeldStyle(cell) {
        if (cell.classList.contains('held')) {
            return;
        }
        cell.classList.add('held');
        cell.style.pointerEvents = 'none';
        cell.style.opacity = '0.6';
        if (!cell.querySelector('.lock-icon')) {
            const icon = document.createElement('div');
            icon.className = 'lock-icon';
            icon.title = 'Khung giờ đang được khách khác chọn';
            icon.innerHTML = LOCK_ICON_HTML;
            cell.appendChild(icon);
        }
    }

    function removeCustomerHeldStyle(cell) {
        if (!cell.classList.contains('held')) {
            return;
        }
        cell.classList.remove('held');
        cell.querySelector('.lock-icon')?.remove();
        const stillBlocked = OTHER_BLOCKING_CLASSES.some((c) => cell.classList.contains(c));
        if (!stillBlocked) {
            cell.style.pointerEvents = '';
            cell.style.opacity = '';
        }
    }

    // Payload là SNAPSHOT toàn bộ hold còn hiệu lực của (room, date) đó tại thời điểm bắn (không
    // phải 1 sự kiện add/remove đơn lẻ — xem TimeSlotHoldController::broadcastForDate()), nên phải
    // DIFF với DOM hiện tại: bật "held" cho timeslot có trong danh sách, tắt cho timeslot KHÔNG còn
    // trong danh sách (nhưng đang hiện "held" trên màn hình).
    function applySlotHoldUpdate(payload) {
        if (!payload || !payload.room_id || !payload.date) {
            return;
        }

        const heldTimeslotIds = new Set((payload.holds || []).map((h) => String(h.timeslot_id)));
        const cells = heldCellsFor(payload.room_id, payload.date);

        cells.forEach((cell) => {
            const timeslotId = cell.dataset.timeslotId;
            const shouldBeHeld = heldTimeslotIds.has(String(timeslotId));

            // Ô đang là lựa chọn CỦA CHÍNH khách này (class "active", nền đen — xem toggleSlot()
            // trong book.blade.php/product-detail.blade.php) — bỏ qua, không tự vá thành "held".
            // Payload này không phân biệt "giữ bởi ai" (xem lý do bảo mật ở
            // TimeSlotHoldController::broadcastForDate()), nên hold của CHÍNH khách vừa tạo cũng
            // vọng ngược lại qua kênh này — nếu không loại trừ .active, khách sẽ thấy NGAY ô mình
            // vừa chọn tự đổi màu cam như bị người khác giành mất.
            if (cell.classList.contains('active')) {
                return;
            }

            if (shouldBeHeld) {
                applyCustomerHeldStyle(cell);
            } else {
                removeCustomerHeldStyle(cell);
            }
        });
    }

    // Socket.IO client hiểu phần path của URI đầu tiên là NAMESPACE, không phải tiền tố đường dẫn
    // proxy (VD WS_PUBLIC_URL="https://domain.com/ws" — nếu gọi io(WS_URL) thẳng, nó sẽ kết nối
    // namespace "/ws" nhưng vẫn bắn request tới https://domain.com/socket.io/... ở GỐC domain,
    // không qua path "/ws" mà nginx đang proxy tới Node service → không bao giờ tới nơi). Phải tách
    // riêng origin + truyền path tường minh để khớp đúng route nginx đang proxy.
    let wsOrigin = WS_URL;
    let wsPath = '/socket.io/';
    try {
        const parsed = new URL(WS_URL);
        wsOrigin = parsed.origin;
        wsPath = (parsed.pathname.replace(/\/$/, '') || '') + '/socket.io/';
    } catch (e) {}

    let socket = null;
    let dispatchTimer = null;
    let scanTimer = null;
    const subscribedRoomDates = new Set();

    function scheduleDispatch() {
        // Gộp nhiều event dồn dập (VD sửa giá cả tháng → nhiều ngày cùng lúc bắn nhiều event) thành
        // 1 lần Livewire.dispatch duy nhất, tránh re-render lặp lại liên tiếp.
        clearTimeout(dispatchTimer);
        dispatchTimer = setTimeout(() => {
            if (window.Livewire) {
                window.Livewire.dispatch('roomAvailabilityChanged');
            }
        }, 400);
    }

    function initSocket() {
        if (socket) {
            return socket;
        }

        socket = window.io(wsOrigin, { path: wsPath, transports: ['websocket', 'polling'] });
        socket.on('slot.updated', scheduleDispatch);
        socket.on('daily.booked', scheduleDispatch);
        socket.on('daily.blocked', scheduleDispatch);
        socket.on('slot.hold.updated', applySlotHoldUpdate);

        return socket;
    }

    function loadSocketIoScript(cb) {
        if (window.io) {
            cb();
            return;
        }

        const s = document.createElement('script');
        s.src = WS_URL + '/socket.io/socket.io.js';
        s.onload = cb;
        s.onerror = () => {};
        document.head.appendChild(s);
    }

    function subscribeRoomDate(roomId, date) {
        const key = roomId + ':' + date;

        if (!roomId || !date || subscribedRoomDates.has(key)) {
            return;
        }

        subscribedRoomDates.add(key);
        initSocket().emit('subscribe:room', { room_id: roomId, date });
    }

    // Quét mọi ô khung giờ đang render trên trang (data-room-id + data-iso-date — cùng attribute
    // echo-client.js đã dùng cho hold) để biết cần subscribe đúng những (phòng, ngày) nào đang
    // hiển thị cho khách xem.
    function scanVisibleCells() {
        document.querySelectorAll('[data-room-id][data-iso-date]').forEach((cell) => {
            subscribeRoomDate(cell.dataset.roomId, cell.dataset.isoDate);
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        loadSocketIoScript(() => {
            scanVisibleCells();

            // Đổi tab chi nhánh/danh mục, bấm "xem thêm ngày"... render thêm ô mới sau khi trang đã
            // tải xong — quét lại toàn trang mỗi khi DOM đổi, debounce nhẹ để không quét liên tục
            // khi Livewire morph nhiều node cùng lúc.
            new MutationObserver(() => {
                clearTimeout(scanTimer);
                scanTimer = setTimeout(scanVisibleCells, 200);
            }).observe(document.body, { childList: true, subtree: true });
        });
    });
})();
