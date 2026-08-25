<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// SEO crawl found every page duplicated under both 365home.vn and www.365home.vn (52 duplicate
// titles, 52 duplicate content, 48 duplicate meta descriptions) — nothing redirected one to the
// other, so Google indexed both as separate pages. 365home.vn is the already-established canonical
// (GeneralSettings->canonical, robots.txt, and the generated sitemap.xml all already use it,
// confirmed directly against production) — this just enforces it with a 301 so the www copy stops
// being crawled as distinct content.
class RedirectToCanonicalDomain
{
    private const CANONICAL_HOST = '365home.vn';

    public function handle(Request $request, Closure $next)
    {
        if ($request->getHost() === 'www.' . self::CANONICAL_HOST) {
            return redirect()->to(
                'https://' . self::CANONICAL_HOST . $request->getRequestUri(),
                301
            );
        }

        return $next($request);
    }
}
