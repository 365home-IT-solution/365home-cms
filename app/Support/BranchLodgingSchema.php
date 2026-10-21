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

    // @id của 2 thực thể gốc khai báo ở trang chủ (components/seo.blade.php) — chi nhánh nối vào qua
    // parentOrganization: chi nhánh Cần Thơ -> "365 Home Cần Thơ", tỉnh khác -> Organization "365 Home".
    public const ORGANIZATION_ID = 'https://365home.vn/#organization';
    public const CAN_THO_ENTITY_ID = 'https://365home.vn/#lodging-can-tho';
    private const DEFAULT_CHECKOUT = '12:00';

    // Các node của mọi chi nhánh đang hoạt động đã có toạ độ — cho trang chủ.
    public static function forHomepage(): array
    {
        return Cache::remember('branch-lodging-schema:v5:home', now()->addMinutes(self::CACHE_TTL_MINUTES), function () {
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

    // Node của 1 chi nhánh — cho trang chi nhánh và containedInPlace của trang phòng (cùng @id
    // "{url chi nhánh}#lodging"). Chi nhánh chưa nhập toạ độ vẫn có node, chỉ thiếu "geo" — trước đây
    // trả null khiến trang chi nhánh/phòng đó mất hẳn LodgingBusiness.
    public static function forBranch(Category $branch, ?string $url = null): ?array
    {
        return Cache::remember(
            'branch-lodging-schema:v5:branch:'.$branch->id,
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
        $roomPlace = self::roomPlace($branch);
        // Tên chi nhánh thường chính là địa chỉ ("254 Xuân Thủy...") — tên thương hiệu ("Enjoy Villa")
        // thì lấy địa chỉ nhập ở các phòng của chi nhánh (form chi nhánh không có ô địa chỉ).
        $streetAddress = preg_match('/^\s*\d|(hẻm|hẽm|đường|đ\.|kiệt|ngõ)/iu', $branchName)
            ? $branchName
            : $roomPlace['address'];
        $latitude = $branch->latitude ?? $roomPlace['latitude'];
        $longitude = $branch->longitude ?? $roomPlace['longitude'];

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
            // Tên chi nhánh thường chính là địa chỉ ("254 Xuân Thủy, An Bình...", "Hẻm 515 Đ. 30 Tháng 4") —
            // chỉ dùng làm streetAddress khi tên trông như địa chỉ (có số nhà / hẻm / đường). Tên thương
            // hiệu như "Enjoy Villa" thì bỏ trống thay vì khai sai (bảng chi nhánh chưa có cột địa chỉ riêng).
            'address'      => array_filter([
                '@type'           => 'PostalAddress',
                'streetAddress'   => $streetAddress,
                'addressLocality' => $provinceBranch?->ward?->name,
                'addressRegion'   => $provinceBranch?->province?->name,
                'addressCountry'  => 'VN',
            ]),
            'geo'          => $latitude !== null && $longitude !== null ? [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float) $latitude,
                'longitude' => (float) $longitude,
            ] : null,
            'parentOrganization' => [
                '@id' => str_contains((string) $provinceBranch?->province?->name, 'Cần Thơ')
                    ? self::CAN_THO_ENTITY_ID
                    : self::ORGANIZATION_ID,
            ],
        ];

        $schema = array_filter($schema, fn ($v) => $v !== null);

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

    // Địa chỉ + toạ độ ĐẠI DIỆN của chi nhánh lấy từ các phòng đang hoạt động (ô "Địa chỉ"/vĩ độ/kinh
    // độ của form phòng): giá trị xuất hiện NHIỀU NHẤT, không phải phòng đầu tiên — 1 phòng nhập khác
    // (hoặc nhập nhầm) không kéo cả chi nhánh theo. Từng trang phòng vẫn ghi địa chỉ riêng của chính nó
    // trong node HotelRoom (components/seo.blade.php), nên phòng có địa chỉ khác vẫn hiện đúng.
    private static function roomPlace(Category $branch): array
    {
        $categoryIds = Category::where('parent_id', $branch->id)->pluck('id')->push($branch->id);
        $rooms = Product::where('is_activated', true)
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds))
            ->get(['id', 'address', 'latitude', 'longitude']);

        $mode = function ($values) {
            $values = $values->filter(fn ($v) => $v !== null && $v !== '')->values();

            return $values->isEmpty() ? null : $values->countBy()->sortDesc()->keys()->first();
        };
        $address = $mode($rooms->map(fn ($r) => trim((string) preg_replace('/\s+/u', ' ', (string) $r->address))));
        $coords = $mode($rooms->map(fn ($r) => $r->latitude !== null && $r->longitude !== null && (float) $r->latitude != 0.0
            ? $r->latitude . ',' . $r->longitude : null));
        [$lat, $lng] = $coords ? explode(',', $coords) : [null, null];

        return ['address' => $address, 'latitude' => $lat, 'longitude' => $lng];
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
