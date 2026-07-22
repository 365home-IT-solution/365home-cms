import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Laravel Reverb (self-host, giao thức tương thích Pusher) — CHỈ nạp trong panel Filament (xem
// AdminPanelProvider::render hook), dùng cho kênh private "admin-timeslot-holds" (giữ chỗ
// real-time khung giờ đặt phòng, xem App\Services\TimeslotHoldService). Chỉ khởi tạo khi có
// VITE_REVERB_APP_KEY để tránh lỗi ở môi trường build chưa cấu hình biến này.
if (import.meta.env.VITE_REVERB_APP_KEY) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    // CỐ Ý KHÔNG dùng cú pháp khai báo #[On('echo-private:...')] của Livewire (phụ thuộc đúng thời
    // điểm window.Echo đã sẵn sàng LÚC Livewire boot — thứ tự script module (deferred) vs script
    // thường của Filament/Livewire không đảm bảo, từng khiến listener không bao giờ được gắn, real-
    // time hoàn toàn im lặng dù broadcast() phía server chạy đúng). Gắn thẳng bằng Echo API gốc rồi
    // TỰ báo cho Livewire qua Livewire.dispatch() — cách này không phụ thuộc thời điểm nạp script.
    window.Echo.private('admin-timeslot-holds')
        .listen('.held', () => window.Livewire?.dispatch('timeslotHoldsChanged'))
        .listen('.released', () => window.Livewire?.dispatch('timeslotHoldsChanged'));

    // Gia hạn TTL (5 phút) cho các khung giờ admin đang chọn dở — CHỈ ở đúng trang tạo/sửa đơn
    // (không polling ở mọi trang admin khác), miễn admin còn mở trang là hold không tự hết hạn
    // giữa chừng khi họ chưa lưu xong đơn (xem HasTimeslotGridSelection::renewTimeslotHolds()).
    //
    // QUAN TRỌNG: setInterval() bị trình duyệt TỰ ĐỘNG LÀM CHẬM/TẠM DỪNG khi tab chuyển sang nền
    // (không phải tab đang active — admin chuyển qua xem tab/cửa sổ khác) để tiết kiệm pin/CPU —
    // nên chỉ dựa vào setInterval là KHÔNG đủ tin cậy (hold vẫn có thể hết hạn dù admin chưa thật
    // sự rời đi, chỉ đang xem tab khác). Kết hợp thêm: gia hạn NGAY LẬP TỨC mỗi khi tab được quay
    // lại active (visibilitychange/focus) để bù đắp khoảng thời gian bị tạm dừng lúc ở nền — 2 cơ
    // chế cộng lại đảm bảo hold không hết hạn khi tab vẫn đang thật sự mở, dù có bị chuyển nền tạm
    // thời. Nếu admin ĐÓNG HẲN tab / không quay lại quá 5 phút, hold vẫn hết hạn như thiết kế
    // (đúng ý — không giữ chỗ vĩnh viễn cho người đã thật sự bỏ đi).
    if (/\/orders\/(create|\d+\/edit)/.test(window.location.pathname)) {
        const renew = () => window.Livewire?.dispatch('renew-timeslot-holds');

        setInterval(renew, 120000);

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                renew();
            }
        });

        window.addEventListener('focus', renew);
    }
}
