<?php

namespace Modules\BladeThemeV1\Http\Controllers;

use Illuminate\Routing\Controller;
use Modules\Menu\Entities\MenuItem;
use Modules\Post\Entities\Post;
use Modules\Product\App\Models\Product;

class SitemapController extends Controller
{
    // URLs bị loại khỏi sitemap (functional/private pages)
    private const EXCLUDED_PATHS = [
        'gio-hang',
        'thanh-toan',
        'thong-tin-dat-phong',
        '/s/',
        'kiem-tra-ten-mien',
        'trang-test',
        'admin',
        'api',
        'cancel',
        'success',
    ];

    public function index()
    {
        // 1. Pages từ menu (động, từ DB)
        $menuItems = MenuItem::whereNull('parent_id')
            ->with(['children.children'])
            ->get();

        $pageUrls = collect();
        $this->flattenMenuItems($menuItems, $pageUrls);

        // Lọc bỏ các URL không cần index
        $pageUrls = $pageUrls->filter(function ($item) {
            foreach (self::EXCLUDED_PATHS as $excluded) {
                if (str_contains($item['url'], $excluded)) {
                    return false;
                }
            }
            return true;
        })->values();

        // 2. Bài viết đã xuất bản
        $posts = Post::where('status', 'published')
            ->select(['slug', 'updated_at', 'published_at'])
            ->latest('updated_at')
            ->get();

        // 3. Phòng / sản phẩm (type = simple)
        $rooms = Product::where([
            'is_activated' => true,
            'type'         => 'simple',
        ])
            ->select(['slug', 'updated_at'])
            ->latest('updated_at')
            ->get();

        // 4. Mẫu giao diện / dịch vụ (type = service)
        $templates = Product::where([
            'is_activated' => true,
            'type'         => 'service',
        ])
            ->select(['slug', 'updated_at'])
            ->latest('updated_at')
            ->get();

        return response()
            ->view('bladethemev1::sitemap', compact('pageUrls', 'posts', 'rooms', 'templates'))
            ->header('Content-Type', 'application/xml; charset=utf-8');
    }

    public function robots()
    {
        $sitemap = url('/sitemap.xml');
        $content = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /api',
            'Disallow: /gio-hang',
            'Disallow: /thanh-toan',
            'Disallow: /thong-tin-dat-phong',
            'Disallow: /s/',
            'Disallow: /kiem-tra-ten-mien',
            'Disallow: /trang-test',
            'Disallow: /cancel',
            'Disallow: /success',
            '',
            "Sitemap: {$sitemap}",
        ]);

        return response($content, 200)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    // llms.txt (llmstxt.org) — plain-language site summary for AI crawlers (ChatGPT, Perplexity...),
    // separate from robots.txt/sitemap.xml which are built for traditional search engine crawlers.
    public function llmsTxt()
    {
        $gs   = app(\App\Settings\GeneralSettings::class);
        $name = $gs->brand_name ?: ($gs->og_title ?: config('app.name'));
        $desc = $gs->og_description ?: '';

        $lines = ["# {$name}", ''];

        if ($desc !== '') {
            $lines[] = "> {$desc}";
            $lines[] = '';
        }

        $lines = array_merge($lines, [
            '## Sitemap',
            '',
            '- [Sitemap](' . url('/sitemap.xml') . ')',
            '',
            '## Pages',
            '',
            '- [Tìm phòng](' . route('product.search') . ')',
            '- [Tin tức](' . url('/tin-tuc') . ')',
            '- [Tra cứu booking](' . url('/ticket-booking') . ')',
        ]);

        return response(implode("\n", $lines), 200)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    private function flattenMenuItems($items, &$collection, string $parentUrl = ''): void
    {
        foreach ($items as $item) {
            $rawUrl = ltrim($item->url ?? '', '/');

            if (empty($rawUrl)) {
                continue;
            }

            // Bỏ qua external links
            if (str_starts_with($rawUrl, 'http')) {
                continue;
            }

            $fullUrl = url($rawUrl);

            $collection->push([
                'url'     => $fullUrl,
                'lastmod' => $item->updated_at?->toAtomString() ?? now()->toAtomString(),
            ]);

            if ($item->children && $item->children->isNotEmpty()) {
                $this->flattenMenuItems($item->children, $collection);
            }
        }
    }
}
