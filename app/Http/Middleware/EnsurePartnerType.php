<?php

namespace App\Http\Middleware;

use App\Models\Partner;
use Closure;
use Illuminate\Http\Request;

class EnsurePartnerType
{
    public function handle(Request $request, Closure $next, string $expectedType)
    {
        $partner = $request->route('partner');

        if ($partner instanceof Partner && ($partner->partner_type !== $expectedType || $partner->isSystemPartner())) {
            abort(404);
        }

        return $next($request);
    }
}
