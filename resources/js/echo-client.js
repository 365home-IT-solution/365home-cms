import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Real-time cho trang đặt phòng CỦA KHÁCH HÀNG — nghe kênh PUBLIC "timeslot-holds.{roomId}"
// (không phải kênh private admin-timeslot-holds, khách hàng không đăng nhập được vào đó) để biết
// ngay khi 1 khung giờ vừa bị admin giữ/trả, không cần khách tự thao tác/F5 mới thấy cập nhật.
// Xem App\Events\TimeslotHeld/TimeslotReleased::broadcastOn() — cùng phát ra CẢ 2 kênh.
//
// QUAN TRỌNG: KHÔNG gọi Livewire.dispatch() để ép re-render component (như phía admin,
// echo-admin.js) — nếu có hàng nghìn khách đang mở trang cùng lúc, 1 admin giữ/trả CHỈ 1 ô sẽ
// khiến TẤT CẢ khách phải tính lại toàn bộ bảng (mọi phòng/mọi ngày/mọi khung giờ) — cực kỳ lãng
// phí cho 1 thay đổi chỉ ảnh hưởng đúng 1 ô. Thay vào đó, cập nhật TRỰC TIẾP đúng phần tử DOM
// tương ứng (khớp qua data-room-id + data-timeslot-id + data-iso-date, xem book/_slot-cell.blade.php
// + book/_desktop-table.blade.php + product-detail.blade.php) — dùng lại NGUYÊN class/icon đã có
// sẵn trong HTML server render, không đổi giao diện, chỉ thêm/gỡ đúng lúc cần.
if (import.meta.env.VITE_REVERB_APP_KEY) {
    const echoClient = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    const LOCK_ICON_HTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        + 'stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="9" rx="2"></rect>'
        + '<path d="M8 11V7a4 4 0 0 1 8 0v4"></path></svg>';

    // Class khác cũng khiến ô không bấm được (đã đặt/đang chờ/bị khoá ngày/qua giờ/qua ngày) — nếu
    // ô còn 1 trong các class này, bỏ giữ chỗ KHÔNG được mở lại click (vẫn không khả dụng vì lý do
    // khác), chỉ gỡ đúng phần "held" (nền cam + icon khoá).
    const OTHER_BLOCKING_CLASSES = ['booked', 'pending', 'blocked', 'past-time', 'past-date'];

    function findCells(roomId, timeslotId, date) {
        return document.querySelectorAll(
            `[data-room-id="${roomId}"][data-timeslot-id="${timeslotId}"][data-iso-date="${date}"]`
        );
    }

    function applyHeldStyle(cell, heldByName) {
        if (cell.classList.contains('held')) {
            return;
        }

        cell.classList.add('held');
        cell.style.pointerEvents = 'none';
        cell.style.opacity = '0.6';

        if (!cell.querySelector('.lock-icon')) {
            const icon = document.createElement('div');
            icon.className = 'lock-icon';
            icon.title = `Đang được ${heldByName || 'nhân viên'} xử lý cho 1 đơn khác`;
            icon.innerHTML = LOCK_ICON_HTML;
            cell.appendChild(icon);
        }
    }

    // Nhận TOÀN BỘ ô trùng khớp (book.blade.php render 2 DOM song sinh mobile/desktop cho CÙNG 1
    // khung giờ, xem book.blade.php::toggleSlot()) — xử lý cả nhóm 1 LẦN, không lặp riêng từng ô,
    // để tránh: nếu >1 ô song sinh cùng có class "active", click() từng ô sẽ bỏ-chọn-rồi-chọn-lại
    // (toggle) xen kẽ nhau, huỷ tác dụng lẫn nhau.
    function applyHeld(cells, heldByName) {
        const activeCell = Array.from(cells).find((c) => c.classList.contains('active'));

        // Khách ĐANG chọn đúng ô này (class "active", nền đen — xem toggleSlot() trong
        // book.blade.php/product-detail.blade.php) đúng lúc admin giữ chỗ — KHÔNG chỉ gỡ class
        // "active" bằng tay (Alpine có thể tự vẽ lại "active" bất cứ lúc nào vì mảng selectedSlots
        // thật chưa đổi) — giả lập ĐÚNG 1 cú click (dù có nhiều ô song sinh, chỉ click 1 lần) để
        // tận dụng lại logic bỏ chọn có sẵn của từng trang (tự xoá khỏi selectedSlots + tính lại
        // tổng tiền/giảm giá đúng, y hệt như khách tự bấm bỏ chọn, và tự đồng bộ cả ô song sinh) —
        // pointer-events:none chỉ chặn click THẬT của chuột/cảm ứng, không chặn .click() gọi bằng
        // JS nên vẫn chạy đúng dù đặt SAU dòng này.
        if (activeCell) {
            activeCell.click();

            window.dispatchEvent(new CustomEvent('notify', {
                detail: {
                    message: 'Khung giờ bạn vừa chọn đang được nhân viên xử lý cho 1 đơn khác — vui lòng chọn khung giờ khác.',
                    type: 'error',
                },
            }));
        }

        cells.forEach((cell) => applyHeldStyle(cell, heldByName));
    }

    function removeHeld(cell) {
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

    const subscribedRoomIds = new Set();

    function subscribeToRoom(roomId) {
        if (!roomId || subscribedRoomIds.has(roomId)) {
            return;
        }

        subscribedRoomIds.add(roomId);

        echoClient.channel('timeslot-holds.' + roomId)
            .listen('.held', (e) => {
                applyHeld(findCells(e.product_id, e.timeslot_id, e.date), e.user_name);
            })
            .listen('.released', (e) => {
                findCells(e.product_id, e.timeslot_id, e.date).forEach((cell) => removeHeld(cell));
            });
    }

    function subscribeFromElement(el) {
        if (!el) {
            return;
        }

        (el.dataset.productId || '').split(',').forEach(subscribeToRoom);
        (el.dataset.roomIds || '').split(',').forEach(subscribeToRoom);
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-product-id], [data-room-ids]').forEach((el) => {
            subscribeFromElement(el);

            // Đổi chi nhánh/danh mục trong Book.php cập nhật LẠI GIÁ TRỊ của đúng attribute này
            // trên cùng 1 phần tử gốc (Livewire morph attribute, không thay cả DOM node) — theo
            // dõi riêng phần tử đó để tự đăng ký thêm phòng mới xuất hiện, không cần tải lại trang.
            new MutationObserver(() => subscribeFromElement(el))
                .observe(el, { attributes: true, attributeFilter: ['data-room-ids', 'data-product-id'] });
        });
    });
}
