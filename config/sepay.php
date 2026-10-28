<?php

// SePay — theo dõi biến động số dư tài khoản ngân hàng của đối tác và gọi webhook về 365home khi có tiền vào.
// Xem App\Services\Payment\SepayWebhookService.
return [
    // Khoá API CHUNG (tuỳ chọn) — dùng khi 365home tự có 1 tài khoản SePay gắn nhiều tài khoản ngân hàng của đối tác: webhook
    // mang khoá này được tin, đối tác xác định theo số tài khoản nhận. Bỏ trống nếu mỗi đối tác tự có tài khoản SePay (khi đó
    // mỗi kênh có khoá riêng — partner_bank_channels.webhook_key).
    'webhook_key' => env('SEPAY_WEBHOOK_KEY'),

    // Tiền tố nội dung chuyển khoản: "365H" + mã đơn. Trên SePay đặt "Cấu hình mã thanh toán" cùng tiền tố này.
    'payment_prefix' => env('SEPAY_PAYMENT_PREFIX', '365H'),

    // Link trang chuyển khoản (/chuyen-khoan/...) có chữ ký, hết hạn sau số phút này.
    'page_ttl_minutes' => (int) env('SEPAY_PAGE_TTL_MINUTES', 120),

    // (Tuỳ chọn) Chỉ nhận webhook từ các IP/dải IP này, cách nhau dấu phẩy — lấy danh sách IP webhook từ tài liệu SePay. Bỏ trống =
    // không giới hạn IP (vẫn bắt buộc khoá API).
    'allowed_ips' => env('SEPAY_ALLOWED_IPS'),
];
