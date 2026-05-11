<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectUnauthorizedDashboard
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user
            && $request->routeIs('filament.admin.pages.dashboard')
            && ! $user->hasRole('super_admin')
            && ! $user->can('page_Dashboard')
        ) {
            return redirect()->route('filament.admin.pages.my-profile');
        }

        return $next($request);
    }
}
