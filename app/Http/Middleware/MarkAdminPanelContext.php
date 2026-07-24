<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AdminPanelContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MarkAdminPanelContext
{
    public function handle(Request $request, Closure $next): Response
    {
        AdminPanelContext::markActive();

        return $next($request);
    }
}
