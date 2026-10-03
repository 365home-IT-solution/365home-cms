<?php

// THÔNG TIN MẪU HỢP ĐỒNG HỢP TÁC KINH DOANH (Homestay) — theo mẫu "HỢP ĐỒNG 365 HOME".
// Bên A (nền tảng) và các mức mặc định của điều khoản. Đổi giá trị ở đây (hoặc .env) khi pháp nhân/mức thay đổi.
return [
    'place' => env('CONTRACT_PLACE', 'Cần Thơ'),

    'platform' => [
        'name'           => env('CONTRACT_PLATFORM_NAME', 'CÔNG TY TNHH TRUYỀN THÔNG VÀ DỊCH VỤ VẬN TẢI CẦN THƠ EXPRESS'),
        'representative' => env('CONTRACT_PLATFORM_REP', 'NGUYỄN AN KHOA'),
        'position'       => env('CONTRACT_PLATFORM_POSITION', 'Giám đốc'),
        'address'        => env('CONTRACT_PLATFORM_ADDRESS', 'Số 252 - 254 Đường Xuân Thuỷ, KDC Cái Sơn Hàng Bàng, Phường An Bình, Thành phố Cần Thơ.'),
        'phone'          => env('CONTRACT_PLATFORM_PHONE', '0708 071 188'),
        'tax_code'       => env('CONTRACT_PLATFORM_TAX', '1801709047'),
        'bank_account'   => env('CONTRACT_PLATFORM_ACCOUNT', '708071188'),
        'bank_name'      => env('CONTRACT_PLATFORM_BANK', 'Ngân hàng Thương mại Cổ phần Quân Đội (MB Bank) – Chi nhánh Cần Thơ'),
    ],

    // Điều 4.2 — khoản tiền đảm bảo thực hiện hợp đồng (đồng).
    'guarantee_vnd' => (int) env('CONTRACT_GUARANTEE_VND', 1000000),
    // Điều 5.2 — mức trừ vào doanh thu mỗi lần Bên B vi phạm phối hợp (đồng).
    'violation_fee_vnd' => (int) env('CONTRACT_VIOLATION_FEE_VND', 100000),
    // Điều 12 — phạt vi phạm: % giá trị phần nghĩa vụ bị vi phạm.
    'penalty_percent' => (int) env('CONTRACT_PENALTY_PERCENT', 8),
    // Điều 7 — thời hạn mặc định (tháng) khi chưa có ngày hết hạn.
    'default_term_months' => (int) env('CONTRACT_TERM_MONTHS', 12),
    // Nơi cấp CCCD mặc định.
    'id_issued_place' => env('CONTRACT_ID_PLACE', 'Cục Cảnh sát QLHC về TTXH'),
];
