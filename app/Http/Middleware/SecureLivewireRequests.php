<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class SecureLivewireRequests
{
    /**
     * Handle an incoming request.
     * Protects the /livewire/update endpoint from abuse and unauthorized access.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Verify the request is actually a Livewire AJAX request
        if (! $request->hasHeader('X-Livewire')) {
            abort(403, 'Forbidden');
        }

        // 2. Apply per-IP rate limiting (60 requests / minute per IP)
        $key = 'livewire-update:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 60)) {
            $seconds = RateLimiter::availableIn($key);
            abort(429, "Too Many Requests. Retry after {$seconds} seconds.");
        }

        RateLimiter::hit($key, 60);

        // 3. Validate the request origin matches the app URL (basic SSRF/CSRF guard)
        $origin = $request->headers->get('Origin') ?? $request->headers->get('Referer', '');
        $appUrl = rtrim(config('app.url'), '/');

        if (! empty($origin) && ! str_starts_with(rtrim($origin, '/'), $appUrl)) {
            abort(403, 'Invalid origin');
        }

        return $next($request);
    }
}
