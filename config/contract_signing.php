<?php

return [

    // Provider ký số đang dùng — đổi ở .env, KHÔNG sửa code gọi ký (ContractSignController,
    // PartnerForm). Giá trị hợp lệ: 'local' (test, không cần đăng ký gì) | 'vnpt_smartca' (thật,
    // cần tài khoản đối tác VNPT SmartCA — xem doitac-smartca.vnpt.vn).
    'default' => env('CONTRACT_SIGNING_PROVIDER', 'local'),

    'providers' => [

        'local' => [
            // LocalSelfSignedProvider — tự sinh khoá RSA lưu ở storage/app/contract-signing/,
            // dùng ngay được, không cần cấu hình gì thêm. CHỈ dùng để test kiến trúc ký/verify,
            // không có giá trị pháp lý.
        ],

        // Theo ĐÚNG tài liệu chính thức "DỊCH VỤ VNPT-SmartCA — Tài liệu mô tả và hướng dẫn tích
        // hợp" v1.1 (2024) — chuẩn CSC (Cloud Signature Consortium) quốc tế, dùng OAuth2 (Resource
        // Owner Password Credentials) để lấy access_token/refresh_token (refresh_token sống ~3
        // tháng), sau đó gọi csc/signature/signhash — VNPT gửi thông báo đẩy tới app SmartCA của
        // thuê bao, thuê bao chỉ cần bấm xác nhận, KHÔNG cần OTP/mật khẩu ở mỗi lần ký.
        'vnpt_smartca' => [
            // Domain GỐC (không có path /sca/sp769 hay /auth) — vd 'https://gwsca.vnpt.vn'
            // (production) hoặc 'https://rmgateway.vnptit.vn' (demo/UAT).
            'base_url'            => env('VNPT_SMARTCA_BASE_URL'),
            'client_id'           => env('VNPT_SMARTCA_CLIENT_ID'),
            'client_secret'       => env('VNPT_SMARTCA_CLIENT_SECRET'),
            'subscriber_user_id'  => env('VNPT_SMARTCA_SUBSCRIBER_USER_ID'), // CCCD chủ chứng thư
            'subscriber_password' => env('VNPT_SMARTCA_SUBSCRIBER_PASSWORD'), // chỉ cần đúng 1 lần đăng nhập đầu
        ],

    ],

];
