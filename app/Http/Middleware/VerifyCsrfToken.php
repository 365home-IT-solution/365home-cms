<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'webhook/payos',
        // Gọi server-to-server từ Node (websocket/server.js) — không có phiên trình duyệt nào để
        // lấy CSRF token, tự bảo vệ bằng X-Internal-Key riêng (xem routes/web.php).
        'internal/frigate-session',
    ];
}
