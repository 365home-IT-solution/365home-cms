<?php

// Đối tác "xác nhận thủ công" (không qua cổng SePay / Pay2S): khách chuyển khoản vào tài khoản đối tác, đối tác tự xem app ngân hàng rồi bấm xác nhận.
// Không có cổng báo tiền về nên cần giữ đơn lâu hơn 15 phút của cổng tự động. Giá trị nhập ở CMS (Cấu hình web → Xác nhận thủ công) được ưu tiên.
return [
    // Giữ đơn / yêu cầu thanh toán tối thiểu (phút) kể từ lúc tạo.
    'hold_minutes' => (int) env('MANUAL_PAYMENT_HOLD_MINUTES', 60),
    // Khách bấm "Tôi đã chuyển khoản": giữ tới số phút này kể từ lúc báo.
    'claimed_hold_minutes' => (int) env('MANUAL_PAYMENT_CLAIMED_HOLD_MINUTES', 240),
];
