<?php

namespace Modules\BladeThemeV1\Support;

use Illuminate\Support\Facades\Cache;
use Modules\Post\Entities\Post;
use Modules\Product\App\Models\Product;

/**
 * Dữ liệu khối "Bài viết mới + Khám phá chi nhánh/khu vực" cuối trang chủ — render thẳng HTML
 * (thẻ <a href> thật) để Googlebot đi được từ trang chủ vào trang con: các section phòng phía trên
 * dựng bằng JS (home-sections.js), crawler không thấy link nào trong HTML ban đầu.
 * Cache ngắn vì resolveLocationForProduct() tốn vài query mỗi phòng.
 */
class HomeInternalLinks
{
    private const CACHE_KEY = 'home-internal-links:v2';

    // Bài viết về địa phương KHÁC Cần Thơ (trang chủ nhắm Cần Thơ) — nhận theo từ khoá trong tiêu đề,
    // gom vào nhóm khu vực tương ứng thay vì nằm lẫn trong "Cẩm nang & tin tức".
    private const POST_REGION_KEYWORDS = [
        'Lâm Đồng' => '/đà lạt|lâm đồng|da lat|dalat/iu',
    ];
    private const CACHE_TTL_MINUTES = 30;

    public static function get(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(self::CACHE_TTL_MINUTES), function () {
            $posts = self::posts();
            $regional = [];
            $general = [];
            foreach ($posts as $post) {
                $region = collect(self::POST_REGION_KEYWORDS)->search(fn ($re) => preg_match($re, $post['title']) === 1);
                if ($region !== false) {
                    $regional[$region][] = $post;
                } else {
                    $general[] = $post;
                }
            }

            $data = self::branchesAndAreas();
            // Nhóm theo tỉnh, tỉnh nhiều phòng nhất đứng đầu (Cần Thơ) — trang chủ nhắm Cần Thơ, các
            // tỉnh khác hiện thành khối riêng có tiêu đề rõ ràng thay vì lẫn trong cùng 1 danh sách.
            $regions = [];
            foreach ($data['branches'] as $b) {
                $regions[$b['province']]['branches'][] = $b;
                $regions[$b['province']]['rooms'] = ($regions[$b['province']]['rooms'] ?? 0) + $b['rooms'];
            }
            foreach ($data['areas'] as $a) {
                $regions[$a['province']]['areas'][] = $a;
            }
            foreach ($regional as $province => $list) {
                $key = collect(array_keys($regions))->first(fn ($k) => str_contains($k, $province)) ?? $province;
                $regions[$key]['posts'] = array_slice($list, 0, 4);
            }
            uasort($regions, fn ($x, $y) => ($y['rooms'] ?? 0) <=> ($x['rooms'] ?? 0));

            return [
                'posts'    => array_slice($general, 0, 6),
                'regions'  => $regions,
                ...$data,
            ];
        });
    }

    private static function posts(): array
    {
        return Post::where('status', 'published')
            ->with('media')
            ->latest('published_at')
            ->latest('id')
            ->limit(12)
            ->get(['id', 'title', 'slug', 'summary', 'published_at', 'created_at'])
            ->map(function (Post $post) {
                $media = $post->getFirstMedia('Ảnh chính');

                return [
                    'title'   => $post->title,
                    'url'     => route('post.detail', ['slug' => $post->slug]),
                    'summary' => \Illuminate\Support\Str::limit(trim(strip_tags((string) $post->summary)), 110),
                    'image'   => $media ? ($media->hasGeneratedConversion('card') ? $media->getUrl('card') : $media->getUrl()) : null,
                    'date'    => ($post->published_at ?? $post->created_at)?->toDateString(),
                ];
            })
            ->all();
    }

    // Chi nhánh và trang khu vực (/{loại hình}/{tỉnh}) suy từ các phòng đang hoạt động — cùng cách
    // SitemapController dựng URL canonical, nên chỉ trỏ vào trang có thật.
    private static function branchesAndAreas(): array
    {
        $branches = [];
        $areas = [];

        Product::where(['is_activated' => true, 'type' => 'simple'])
            ->activeBranch()
            ->with(['categories:id,slug,parent_id', 'roomType:id,slug,name'])
            ->get(['id', 'slug', 'room_type_id'])
            ->each(function (Product $room) use (&$branches, &$areas) {
                $loc = BranchBookConfig::resolveLocationForProduct($room);
                if (! $loc) {
                    return;
                }

                $areaPath = '/' . $loc['type_url_slug'] . '/' . $loc['province_slug'];
                $areas[$areaPath] ??= [
                    'name'     => $loc['type_name'] . ' ' . $loc['province_name'],
                    'url'      => url($areaPath),
                    'province' => $loc['province_name'],
                ];

                $branchPath = $areaPath . '/' . $loc['branch_slug'];
                $branches[$branchPath] ??= [
                    'name'     => trim((string) preg_replace('/\s+/u', ' ', $loc['branch_name'])),
                    'url'      => url($branchPath),
                    'area'     => $loc['type_name'] . ' · ' . $loc['province_name'],
                    'province' => $loc['province_name'],
                    'rooms'    => 0,
                ];
                $branches[$branchPath]['rooms']++;
            });

        return [
            'branches' => array_values($branches),
            'areas'    => array_values($areas),
        ];
    }
}
