<?php

return [
    'app_id'        => env('ZALO_APP_ID'),
    'app_secret'    => env('ZALO_APP_SECRET'),
    'access_token'  => env('ZALO_ACCESS_TOKEN'),
    'refresh_token' => env('ZALO_REFRESH_TOKEN'),

    // Template ZNS dùng cho OTP (tạo trong Zalo Business Manager)
    'otp_template_id' => env('ZALO_OTP_TEMPLATE_ID'),

    'zns_url'   => 'https://business.openapi.zalo.me/message/template',
    'oauth_url' => 'https://oauth.zaloapp.com/v4/oa/access_token',

    // OTP hết hạn sau 5 phút
    'otp_ttl' => 300,

    // Tối đa 5 lần nhập sai
    'otp_max_attempts' => 5,

    // Cooldown giữa 2 lần gửi OTP (giây)
    'otp_cooldown' => 60,

    // Tối đa số lần gửi OTP trong 1 ngày / số điện thoại
    'otp_daily_limit' => 5,
];
