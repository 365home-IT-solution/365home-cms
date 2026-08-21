// Realtime "khung giờ vừa đổi giá/khuyến mãi/mã giảm giá HOẶC vừa có đơn mới" cho khách hàng —
// nghe Node WS service (websocket/server.js, KHÁC với Reverb dùng cho hold-chỗ/khoá phòng ở
// echo-client.js) kênh "room:{roomId}:{date}", event "slot.updated" (bắn khi admin lưu ở
// SettingBook — xem Modules/Book/App/Filament/Resources/BookResource/Pages/
// SettingBook.php::broadcastDatesChanged() — hoặc khi có đơn mới/đổi trạng thái) và "daily.booked".
//
// KHÁC với echo-client.js (vá trực tiếp DOM cho hold/khoá phòng — tần suất cao, hàng nghìn khách
// cùng lúc): ở đây admin đổi giá là thao tác HIẾM (vài lần/ngày), nên chấp nhận ép Livewire
// re-render component thật (Livewire.dispatch('roomAvailabilityChanged') — xem
// onRoomAvailabilityChanged() trong ProductDetail.php/Book.php) để lấy đúng giá/khuyến mãi mới từ
// DB, thay vì tự vá DOM (sẽ phải chép lại logic format giá/km sang JS, dễ lệch với PHP). Riêng
// khoá/mở khoá (status='blocked'/'available' kèm field `source`='admin-block') đã tách hẳn sang
// Reverb (App\Events\RoomSlotsBlocked/RoomDailyBlockedRangesChanged) — KHÔNG còn ép reload ở đây
// nữa, xem nhánh `if (e.source === 'admin-block')` bên dưới.
(function () {
    const WS_URL = window.__WS_PUBLIC_URL;

    if (!WS_URL) {
        return;
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
        // Khoá/mở khoá (source === 'admin-block') đã được Reverb vá TRỰC TIẾP đúng ô bị ảnh hưởng
        // (xem resources/js/echo-client.js + App\Events\RoomSlotsBlocked) — KHÔNG ép reload lần
        // nữa ở đây, chỉ còn giữ full reload cho thay đổi giá/khuyến mãi thật (SettingBook) hoặc
        // đặt phòng thật (không có field `source`). `daily.blocked` (style 2) giờ CHỈ còn ý nghĩa
        // khoá/mở khoá — đã bỏ hẳn khỏi đây, xử lý toàn bộ qua Reverb
        // (App\Events\RoomDailyBlockedRangesChanged).
        socket.on('slot.updated', (e) => {
            if (e && e.source === 'admin-block') {
                return;
            }
            scheduleDispatch();
        });
        socket.on('daily.booked', scheduleDispatch);

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
