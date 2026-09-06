<?php

namespace Modules\BladeThemeV1\Http\Controllers;

use Illuminate\Routing\Controller;
use Modules\BladeThemeV1\Support\BranchBookConfig;
use Modules\Menu\Entities\MenuItem;
use Modules\Page\Entities\Page;
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

        // 3. Phòng / sản phẩm (type = simple) — liệt kê thẳng URL canonical (có khu vực/chi nhánh
        // khi xác định được, xem BranchBookConfig::resolveLocationForProduct()) thay vì URL phẳng
        // /room/{slug} rồi để Google tự đi theo redirect — sitemap không nên chứa URL redirect.
        // Phòng chưa gắn chi nhánh nào (resolveLocationForProduct trả về null) thì /room/{slug}
        // cũng 404 luôn (renderProductDetail yêu cầu category active) — loại hẳn khỏi sitemap thay
        // vì trỏ vào URL chết.
        $rooms = Product::where([
            'is_activated' => true,
            'type'         => 'simple',
        ])
            ->activeBranch()
            ->with(['categories:id,slug,parent_id', 'roomType:id,slug'])
            ->select(['id', 'slug', 'updated_at', 'room_type_id'])
            ->latest('updated_at')
            ->get()
            ->map(function ($room) {
                $loc = BranchBookConfig::resolveLocationForProduct($room);
                $room->url = $loc
                    ? url('/' . $loc['type_url_slug'] . '/' . $loc['province_slug'] . '/' . $loc['branch_slug'] . '/' . $room->slug . '/')
                    : null;
                return $room;
            })
            ->filter(fn ($room) => $room->url !== null)
            ->values();

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
            '- [Bài viết](' . route('posts.page') . ')',
            '- [Tra cứu booking](' . url('/ticket-booking') . ')',
        ]);

        return response(implode("\n", $lines), 200)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    private function flattenMenuItems($items, &$collection, string $parentUrl = ''): void
    {
        foreach ($items as $item) {
            // Menu item trỏ tới 1 Page (qua page_id, hoặc qua linkable_type/linkable_id) đã bị xóa
            // khỏi DB — không có cascade delete khi xóa Page nên hàng menu_items để lại rác, cột
            // `url` vẫn giữ nguyên giá trị cũ khiến sitemap liệt kê URL đã chết mãi mãi. Bỏ qua
            // item này (vẫn duyệt tiếp children) nếu model được trỏ tới không còn tồn tại.
            $isDangling = ($item->page_id && !Page::whereKey($item->page_id)->exists())
                || ($item->linkable_type && $item->linkable_id && !$item->linkable);

            if (!$isDangling) {
                $rawUrl = ltrim($item->url ?? '', '/');

                if (!empty($rawUrl) && !str_starts_with($rawUrl, 'http')) {
                    $collection->push([
                        'url'     => url($rawUrl),
                        'lastmod' => $item->updated_at?->toAtomString() ?? now()->toAtomString(),
                    ]);
                }
            }

            if ($item->children && $item->children->isNotEmpty()) {
                $this->flattenMenuItems($item->children, $collection);
            }
        }
    }
}
