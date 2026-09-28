<?php

return [
    // Giai đoạn chuyển tiếp: app mobile bản cũ vẫn gửi cccd_front + cccd_back (và
    // guests[i][front|back]) thay vì cccd_qr_image. Bật → server quét cả 2 ảnh, lưu ảnh chứa QR vào
    // cccd_qr_image. Tắt khi app mới đã phổ biến — không cần sửa code.
    'accept_legacy_front_back' => env('CCCD_ACCEPT_LEGACY_FRONT_BACK', true),

    // Thư mục lưu ảnh QR trên disk 'public'.
    'qr_image_directory' => 'cccd/qr',

    // Giới hạn số lượt quét QR (mỗi lượt chạy tiến trình Node/zbar tốn CPU).
    'scan_limit' => [
        'per_ip'         => (int) env('CCCD_SCAN_LIMIT_PER_IP', 30),
        'per_actor'      => (int) env('CCCD_SCAN_LIMIT_PER_ACTOR', 12),
        'decay_seconds'  => 600,
    ],
];
