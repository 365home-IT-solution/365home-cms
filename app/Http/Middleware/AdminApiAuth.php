<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ($request->user() instanceof User)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        // Gói dịch vụ: hết hạn → 402, tính năng ngoài gói → 403 (xem App\Support\SubscriptionGate).
        if ($blocked = \App\Support\SubscriptionGate::checkRequest($request, $request->user())) {
            return $blocked;
        }

        return $next($request);
    }
}
