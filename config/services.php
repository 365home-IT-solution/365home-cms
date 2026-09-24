<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Zalo Official Account (OA) Configuration
    |--------------------------------------------------------------------------
    | 
    | Configuration cho Zalo ZNS (Zalo Notification Service)
    | 
    | - app_id: ID ứng dụng Zalo (lấy từ https://developers.zalo.me/)
    | - app_secret: Secret key (dùng làm secret_key trong API)
    | - oauth_url: URL để lấy/refresh access token
    | - api_url: URL gửi ZNS
    | - refresh_token: Token để refresh (hiệu lực 3 tháng)
    | - templates: Các template ID đã tạo trên Zalo OA
    |
    */

    'zalo' => [
        'app_id' => env('ZALO_APP_ID'),
        'app_secret' => env('ZALO_APP_SECRET'),
        'oauth_url' => env('ZALO_OAUTH_URL', 'https://oauth.zaloapp.com/v4/oa/access_token'),
        'api_url' => env('ZALO_API_URL', 'https://business.openapi.zalo.me/message/template'),
        'refresh_token' => env('ZALO_REFRESH_TOKEN'),
        'templates' => [
            'booking_success' => env('ZALO_TEMPLATE_BOOKING_SUCCESS'),
            'booking_reminder' => env('ZALO_TEMPLATE_BOOKING_REMINDER'),
            'booking_cancelled' => env('ZALO_TEMPLATE_BOOKING_CANCELLED'),
        ],
        'timeout' => 30,
        'retry' => [
            'enabled' => true,
            'max_attempts' => 3,
            'delay' => [5, 15, 30],
        ],
    ],

    'telegram' => [
        'bot_token'       => env('TELEGRAM_BOT_TOKEN'),
        'chat_id'         => env('TELEGRAM_CHAT_ID'),
        'lock_chat_id'    => env('TELEGRAM_LOCK_CHAT_ID'),    // Nhóm check-in/check-out riêng
        'deposit_chat_id' => env('TELEGRAM_DEPOSIT_CHAT_ID'), // Nhóm nhận thông báo cọc phòng (style=2)
    ],

    /*
    |--------------------------------------------------------------------------
    | TTLock Cloud API
    |--------------------------------------------------------------------------
    | clientId/clientSecret: lấy từ TTLock Open Platform (Management Center)
    | username/password: tài khoản TTLock App (KHÔNG phải developer account)
    | password phải là MD5(password_thực) lowercase 32 ký tự
    */
    'firebase' => [
        'vapid_key' => env('FIREBASE_VAPID_KEY'),
    ],

    'ttlock' => [
        'client_id'      => env('TTLOCK_CLIENT_ID'),
        'client_secret'  => env('TTLOCK_CLIENT_SECRET'),
        'username'       => env('TTLOCK_USERNAME'),
        'password'       => env('TTLOCK_PASSWORD'), // MD5 lowercase
        'api_base'       => env('TTLOCK_API_BASE', 'https://euapi.ttlock.com'),
        // Token dùng riêng cho app Flutter "Đọc thẻ TTLock" (Modules/TTLock) xác thực khi gọi
        // API lấy lockData/đăng ký thẻ — KHÔNG phải tài khoản đăng nhập panel, chỉ 1 chuỗi bí mật
        // cố định app nhúng sẵn lúc build (xem TtlockCardAppController + AuthorizeTtlockCardApp).
        'card_app_token' => env('TTLOCK_CARD_APP_TOKEN'),
    ],

    'ocr_space' => [
        'api_key' => env('OCR_SPACE_API_KEY'),
    ],

    'google_maps' => [
        'key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    // IndexNow (indexnow.org): instant new/updated-URL notification consumed by Bing, Yandex,
    // Seznam and Naver — NOT by Google (Google has no public equivalent for regular articles; it
    // only relies on sitemap re-crawl + manual "Request Indexing" in Search Console). Key file is
    // served at GET /{key}.txt (see Modules/BladeThemeV1/Routes/web.php) as required by the
    // protocol. Empty key disables the feature entirely (see App\Jobs\SubmitUrlToIndexNow).
    'indexnow' => [
        'key' => env('INDEXNOW_KEY'),
    ],

    'websocket' => [
        'url'          => env('WS_SERVER_URL', 'http://localhost:3001'),
        'public_url'   => env('WS_PUBLIC_URL', env('WS_SERVER_URL', 'http://localhost:3001')),
        'internal_key' => env('WS_INTERNAL_KEY', ''),
    ],

    // Binary ffmpeg dùng để chuyển mã (transcode) đoạn ghi hình camera H.265/HEVC sang H.264 khi
    // phát lại — trình duyệt desktop thường không giải mã được H.265 qua MSE. Mặc định 'ffmpeg' (dò
    // theo PATH hệ thống, đúng cho server thật sau khi `apt install ffmpeg`) — chỉ cần đổi
    // FFMPEG_BINARY trong .env nếu server cài ở đường dẫn khác PATH mặc định.
    'ffmpeg' => [
        'binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    ],
];
