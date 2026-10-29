<?php

return [
    // Công tắc tổng: false = mọi đối tác quay về PayOS ngay (kể cả đã khai báo SePay) — dùng khi cần ngắt SePay khẩn cấp.
    'enabled' => (bool) env('SEPAY_ENABLED', true),
    // Số tiền tối thiểu mỗi lần thu qua SePay (PayOS luôn 2.000đ).
    'min_amount' => (int) env('SEPAY_MIN_AMOUNT', 1000),
    'webhook_secret' => env('SEPAY_WEBHOOK_SECRET'),
    // Để trống thì tự lấy theo APP_URL (…/api/webhook/sepay).
    'webhook_url' => env('SEPAY_WEBHOOK_URL'),
    // Tiền tố mã thanh toán — phải trùng "Cấu trúc mã thanh toán" trên SePay. Mã hệ thống sinh là 8 chữ số (DH12345678).
    'code_prefix' => env('SEPAY_CODE_PREFIX', 'DH'),
    'timestamp_tolerance' => (int) env('SEPAY_TIMESTAMP_TOLERANCE', 300),
    // API SePay (Cấu hình Công ty → API Access → tạo API Token): cho phép hệ thống TỰ HỎI SePay giao dịch tiền vào khi webhook chậm/lỡ
    // và để trang QR xác nhận đơn ngay sau khi khách chuyển. Không đặt token thì chỉ dựa vào webhook.
    'api_token' => env('SEPAY_API_TOKEN'),
    'api_url' => env('SEPAY_API_URL', 'https://my.sepay.vn'),
    // Trang QR/app hỏi liên tục: mỗi yêu cầu thanh toán chỉ gọi API SePay tối đa 1 lần mỗi vài giây.
    'pull_throttle_seconds' => (int) env('SEPAY_PULL_THROTTLE', 3),
];
