<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ClearBookingDetailState
{
    public function handle(Request $request, Closure $next)
    {
        if (
            $request->isMethod('GET') &&
            $request->route()?->getName() !== 'product.detail' &&
            session()->has('booking_detail_state')
        ) {
            session()->forget('booking_detail_state');
        }

        return $next($request);
    }
}
