<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ProvinceBranch;
use App\Models\RoomRating;
use Illuminate\Support\Facades\Cache;
use Modules\BladeThemeV1\Support\BranchBookConfig;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

// Schema.org LodgingBusiness cho TỪNG chi nhánh (category_type = product) — gắn ở trang chủ (mọi
// chi nhánh đã có toạ độ) và trang chi nhánh (components/seo.blade.php). Chỉ dùng dữ liệu thật:
// "geo" lấy từ categories.latitude/longitude (không có thì KHÔNG sinh node, không áng chừng),
// "aggregateRating" chỉ gắn khi chi nhánh có ít nhất 1 đánh giá thật (RoomRating).
class BranchLodgingSchema
{
    private const CACHE_TTL_MINUTES = 60;

    // Hotline đặt phòng chung của hệ thống — khớp telephone của node LodgingBusiness trang chủ.
    private const TELEPHONE = '+84939174365';

    // Giờ nhận/trả phòng mặc định của hình thức đặt theo ngày, dùng khi chi nhánh chưa cấu hình riêng.
    private const DEFAULT_CHECKIN = '14:00';
    private const DEFAULT_CHECKOUT = '12:00';

    // Các node của mọi chi nhánh đang hoạt động đã có toạ độ — cho trang chủ.
    public static function forHomepage(): array
    {
        return Cache::remember('branch-lodging-schema:home', now()->addMinutes(self::CACHE_TTL_MINUTES), function () {
            return ProvinceBranch::where('status', true)
                ->with('category.partner')
                ->get()
                ->pluck('category')
                ->filter(fn ($branch) => $branch && $branch->status && $branch->latitude !== null && $branch->longitude !== null)
                ->sortBy('sort_order')
                ->map(fn (Category $branch) => self::build($branch, null))
                ->filter()
                ->values()
                ->all();
        });
    }

    // Node của 1 chi nhánh — cho trang chi nhánh. null nếu chi nhánh chưa có toạ độ.
    public static function forBranch(Category $branch, ?string $url = null): ?array
    {
        if ($branch->latitude === null || $branch->longitude === null) {
            return null;
        }

        return Cache::remember(
            'branch-lodging-schema:branch:'.$branch->id,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => self::build($branch, $url)
        );
    }

    private static function build(Category $branch, ?string $url): ?array
    {
        if ($url === null) {
            $loc = BranchBookConfig::resolveTypeAndLocationForBranch($branch);
            $url = $loc
                ? url('/'.$loc['type_url_slug'].'/'.$loc['province_slug'].'/'.$branch->slug)
                : url('/chi-nhanh/'.$branch->slug);
        }

        $provinceBranch = ProvinceBranch::where('categorie_id', $branch->id)->with(['province', 'ward'])->first();
        $partnerName = trim((string) ($branch->partner?->name ?? ''));
        $branchName = trim((string) preg_replace('/\s+/u', ' ', (string) $branch->name));

        $schema = [
            '@context'     => 'https://schema.org',
            '@type'        => 'LodgingBusiness',
            '@id'          => $url.'#lodging',
            'name'         => $partnerName !== '' ? $partnerName.' - '.$branchName : $branchName,
            'url'          => $url,
            'telephone'    => self::TELEPHONE,
            'priceRange'   => '$$',
            'checkinTime'  => self::time($branch->checkin_time) ?? self::DEFAULT_CHECKIN,
            'checkoutTime' => self::time($branch->checkout_time) ?? self::DEFAULT_CHECKOUT,
            // Tên chi nhánh trong hệ thống chính là địa chỉ đường/số nhà của nó.
            'address'      => array_filter([
                '@type'           => 'PostalAddress',
                'streetAddress'   => $branchName,
                'addressLocality' => $provinceBranch?->ward?->name,
                'addressRegion'   => $provinceBranch?->province?->name,
                'addressCountry'  => 'VN',
            ]),
            'geo'          => [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float) $branch->latitude,
                'longitude' => (float) $branch->longitude,
            ],
        ];

        $image = $branch->thumbnail['wide'] ?? null;
        if ($image) {
            $schema['image'] = $image;
        }

        $rating = self::rating($branch);
        if ($rating) {
            $schema['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) $rating['average'],
                'ratingCount' => $rating['count'],
                'bestRating'  => '5',
                'worstRating' => '1',
            ];
        }

        return $schema;
    }

    // Trung bình + số lượng đánh giá thật của mọi phòng thuộc chi nhánh (kể cả danh mục con).
    private static function rating(Category $branch): ?array
    {
        $categoryIds = Category::where('parent_id', $branch->id)->pluck('id')->push($branch->id);
        $roomIds = Product::whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds))->pluck('id');
        if ($roomIds->isEmpty()) {
            return null;
        }

        $stats = RoomRating::whereIn('room_id', $roomIds)
            ->selectRaw('COUNT(*) as rating_count, AVG(star) as rating_average')
            ->first();
        $count = (int) ($stats->rating_count ?? 0);

        return $count > 0 ? ['count' => $count, 'average' => round((float) $stats->rating_average, 1)] : null;
    }

    private static function time(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^(\d{2}:\d{2})/', $value, $m) ? $m[1] : null;
    }
}
