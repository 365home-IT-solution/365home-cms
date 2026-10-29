<?php

return [
    // Công tắc tổng: false = mọi đối tác dùng Pay2S quay về PayOS ngay — dùng khi cần ngắt Pay2S khẩn cấp.
    'enabled' => (bool) env('PAY2S_ENABLED', true),
    // Để trống thì tự lấy theo APP_URL (…/api/webhook/pay2s).
    'webhook_url' => env('PAY2S_WEBHOOK_URL'),
    // API giao dịch Pay2S (my.pay2s.vn → Tích hợp Web/App → token): cho phép hệ thống TỰ HỎI Pay2S giao dịch tiền vào khi webhook chậm/lỡ
    // (Pay2S chỉ gửi lại webhook 5 lần cách nhau 60 giây) và để trang QR xác nhận đơn ngay. Không đặt token thì chỉ dựa vào webhook.
    'api_token' => env('PAY2S_API_TOKEN'),
    'api_url' => env('PAY2S_API_URL', 'https://api.pay2s.vn'),
    // Pay2S giới hạn 60 request / phút: mọi lần hỏi từ trang QR dùng chung một nhịp tối thiểu giữa 2 lần gọi.
    'pull_throttle_seconds' => (int) env('PAY2S_PULL_THROTTLE', 3),
];
