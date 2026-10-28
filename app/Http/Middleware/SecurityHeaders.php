<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Mặc định cho mọi trang; trang nào tự đặt chặt hơn thì giữ của trang đó (vd trang chuyển khoản: DENY, no-referrer).
        foreach (['X-Frame-Options' => 'SAMEORIGIN', 'Referrer-Policy' => 'strict-origin-when-cross-origin'] as $header => $value) {
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        // No includeSubDomains: this app doesn't control every subdomain under 365home.vn, and
        // forcing HTTPS on subdomains that don't have a valid cert would break them outright.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
