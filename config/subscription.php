<?php

// GÓI DỊCH VỤ — CHỈ ÁP DỤNG CHO MINIHOUSE: 1 gói duy nhất (giá theo tháng), mở toàn bộ chức năng quản trị; đối tác mua theo kỳ 1/3/6/9/12 tháng.
// Hết hạn → khoá (API 402, trang quản trị chuyển về trang Gói dịch vụ) cho tới khi thanh toán. Homestay KHÔNG dùng gói: đăng nhập là dùng.
// Super Admin / tài khoản không thuộc đối tác / đối tác hệ thống / đối tác chưa có đăng ký gói không bị giới hạn.
return [
    // Số tháng dùng thử mặc định khi gói không ghi số tháng dùng thử riêng.
    'default_trial_months' => 6,

    // Các kỳ (số tháng) được phép mua một lần.
    'period_options' => [1, 3, 6, 9, 12],

    // Báo trước khi hết hạn: các mốc (ngày còn lại) gửi thông báo.
    'reminder_days' => [30, 7, 3, 1],

    // Khi bật "tự động gia hạn": tạo sẵn link thanh toán và gửi cho đối tác trước hạn bấy nhiêu ngày.
    'auto_renew_days_before' => 7,

    // Chống spam: tối đa bấy nhiêu giao dịch MỚI / giờ / đối tác (bấm lại cùng gói + số kỳ thì dùng lại giao dịch đang chờ, không tính).
    'max_payments_per_hour' => 6,

    // Link thanh toán PayOS hết hạn sau bấy nhiêu giờ.
    'payment_link_hours' => 24,

    // Đường dẫn API LUÔN dùng được kể cả khi hết hạn (đăng nhập, hồ sơ, thông báo, xem & thanh toán gói...).
    'always_allowed_api' => [
        'login', 'logout', 'me', 'fcm-token', 'notifications', 'subscription', 'partners', 'minihouse/partners',
    ],
];
