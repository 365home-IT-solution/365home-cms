<?php

return [

    // Provider ký số mặc định (dự phòng) — nên nhập ở web: Cấu hình web > Chữ ký số (ưu tiên hơn .env), KHÔNG sửa code gọi ký (ContractSignController,
    // PartnerForm). Giá trị hợp lệ: 'local' (test, không cần đăng ký gì) | 'vnpt_smartca' (thật,
    // cần tài khoản đối tác VNPT SmartCA — xem doitac-smartca.vnpt.vn).
    'default' => env('CONTRACT_SIGNING_PROVIDER', 'local'),

    // Cho phép ký bằng nhà cung cấp "local" (khoá tự sinh, KHÔNG có giá trị pháp lý). null = chỉ cho phép khi APP_ENV không phải production.
    'allow_local' => env('CONTRACT_SIGNING_ALLOW_LOCAL'),

    // Cách ký hợp đồng Homestay: CHỈ tải file về ký (USB token / CKS / SmartCA / MISA...) rồi tải lên — cho cả nền tảng và đối tác.
    // Hai cách cũ chỉ bật lại khi cần (mặc định tắt): xác nhận OTP của đối tác và nền tảng ký tự động qua nhà cung cấp từ xa.
    'allow_otp_confirm' => (bool) env('CONTRACT_ALLOW_OTP', false),
    'allow_platform_auto_sign' => (bool) env('CONTRACT_ALLOW_AUTO_SIGN', false),

    // Đối tác ký bằng chữ ký số của chính họ (CKS USB / từ xa) thay cho OTP email — xem App\Services\PartnerContractCksService.
    'partner_cks' => [
        // Bắt buộc chứng thư khớp hồ sơ đối tác (MST hoặc CCCD người đại diện). Chỉ tắt khi thử nghiệm.
        'require_identity_match' => env('CONTRACT_PARTNER_CKS_REQUIRE_MATCH', true),
        // Cho phép chứng thư tự ký (không có giá trị pháp lý) — chỉ để thử nghiệm.
        'allow_self_signed' => env('CONTRACT_PARTNER_CKS_ALLOW_SELF_SIGNED', false),
    ],

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

        // Chữ ký số từ xa theo chuẩn CSC (Cloud Signature Consortium API v1) — xem CscRemoteSigningProvider. Nhập ở web (Cấu hình web > Chữ ký số)
        // hoặc .env; các nhà cung cấp chỉ cấp tài liệu/tài khoản tích hợp cho đối tác đã ký hợp đồng.
        'misa_esign' => [
            'base_url'      => env('MISA_ESIGN_BASE_URL'),
            'client_id'     => env('MISA_ESIGN_CLIENT_ID'),
            'client_secret' => env('MISA_ESIGN_CLIENT_SECRET'),
            'username'      => env('MISA_ESIGN_USERNAME'),       // tài khoản thuê bao (khi grant_type = password)
            'password'      => env('MISA_ESIGN_PASSWORD'),
            'credential_id' => env('MISA_ESIGN_CREDENTIAL_ID'),  // trống = lấy chứng thư đầu tiên của thuê bao
            'pin'           => env('MISA_ESIGN_PIN'),
            'grant_type'    => env('MISA_ESIGN_GRANT_TYPE', 'client_credentials'),
        ],

        'csc_custom' => [
            'base_url'      => env('CSC_BASE_URL'),
            'client_id'     => env('CSC_CLIENT_ID'),
            'client_secret' => env('CSC_CLIENT_SECRET'),
            'username'      => env('CSC_USERNAME'),
            'password'      => env('CSC_PASSWORD'),
            'credential_id' => env('CSC_CREDENTIAL_ID'),
            'pin'           => env('CSC_PIN'),
            'grant_type'    => env('CSC_GRANT_TYPE', 'client_credentials'),
        ],

    ],

];
