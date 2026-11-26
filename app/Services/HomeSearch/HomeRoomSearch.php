<?php

declare(strict_types=1);

namespace App\Services\HomeSearch;

use App\Models\Customer;
use App\Services\RoomDateAvailability;
use App\Services\RoomSearchRoomsService;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;

/**
 * Kết quả tìm phòng của ô tìm kiếm trang chủ mobile (GET /api/v1/search/home-rooms) — CHỈ trả card
 * phòng (không lịch, không khung giờ, không bản đồ), phòng đang khuyến mãi lên đầu.
 *
 * Lọc dùng lại nguyên RoomSearchRoomsService (/api/search?ver=1.1.0: ngày còn trống, số khách,
 * loại hình, chi nhánh, toạ độ...), thêm:
 *  - onlyIds: giới hạn theo danh sách phòng khớp điểm đến tỉnh/thành CŨ (HomeSearchDestinations);
 *  - phòng theo ngày phải trống MỌI đêm từ nhận tới trả phòng (bản gốc chỉ xét 1 ngày "date");
 *  - sắp xếp trên TOÀN BỘ kết quả (khuyến mãi chỉ biết sau khi dựng card, nên không sort bằng SQL
 *    được) rồi mới cắt trang. Danh sách card đã sắp được cache ngắn để cuộn trang sau không tính lại.
 */
class HomeRoomSearch extends RoomSearchRoomsService
{
    public const SORTS = ['promo', 'price_asc', 'price_desc', 'rating', 'distance'];

    private const FETCH_PER_PAGE = 100;

    private const MAX_FETCH_PAGES = 5;

    private const MAX_NIGHTS = 30;

    /** @var array<int, int|string>|null */
    private ?array $onlyIds = null;

    /**
     * @param  array{checkin?:?string, checkout?:?string, guests?:?int, type?:?string, province?:?string,
     *               lat?:?float, lng?:?float, radius?:?float, sort?:?string, page?:int, per_page?:int}  $params
     * @param  array<int, int|string>|null  $onlyIds
     */
    public function run(array $params, ?array $onlyIds, ?Authenticatable $authUser): array
    {
        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, min(30, (int) ($params['per_page'] ?? 20)));
        $geo     = isset($params['lat'], $params['lng']);
        $sort    = in_array($params['sort'] ?? null, self::SORTS, true) ? $params['sort'] : ($geo ? 'distance' : 'promo');
        if ($sort === 'distance' && ! $geo) {
            $sort = 'promo';
        }

        $cacheKey = 'home-search:rooms:' . md5(json_encode([
            array_intersect_key($params, array_flip(['checkin', 'checkout', 'guests', 'type', 'province', 'lat', 'lng', 'radius'])),
            $onlyIds,
            $sort,
        ]));

        $cards = $onlyIds === []
            ? []
            : Cache::remember($cacheKey, now()->addMinutes(3), fn () => $this->collectCards($params, $onlyIds, $sort));

        $total  = count($cards);
        $slice  = array_slice($cards, ($page - 1) * $perPage, $perPage);
        $slice  = $this->withWishlist($slice, $authUser);

        return [
            'data' => $slice,
            'meta' => [
                'current_page' => $page,
                'last_page'    => max(1, (int) ceil($total / $perPage)),
                'per_page'     => $perPage,
                'total'        => $total,
                'promo_count'  => count(array_filter($cards, fn (array $c) => $c['has_promotion'])),
                'sort'         => $sort,
            ],
        ];
    }

    protected function baseQuery(bool $minihouse): Builder
    {
        $query = parent::baseQuery($minihouse);

        if ($this->onlyIds !== null) {
            $query->whereIn($query->qualifyColumn('id'), $this->onlyIds);
        }

        return $query;
    }

    private function collectCards(array $params, ?array $onlyIds, string $sort): array
    {
        $filters = array_filter([
            'date'     => $params['checkin'] ?? null,
            'guests'   => $params['guests'] ?? null,
            'type'     => $params['type'] ?? null,
            'province' => $params['province'] ?? null,
            'lat'      => $params['lat'] ?? null,
            'lng'      => $params['lng'] ?? null,
            'radius'   => isset($params['lat'], $params['lng']) ? ($params['radius'] ?? 10) : null,
            'per_page' => self::FETCH_PER_PAGE,
        ], fn ($v) => $v !== null && $v !== '');

        $this->onlyIds = $onlyIds;

        // parent::search() gọi paginate() không truyền số trang, nên Paginator đọc ?page= của CHÍNH
        // request này (trang hiển thị của client, vd 2) — ép trang theo vòng lặp, xong trả lại như cũ.
        $resolverProp     = new \ReflectionProperty(Paginator::class, 'currentPageResolver');
        $originalResolver = $resolverProp->getValue();
        $fetchPage        = 1;
        Paginator::currentPageResolver(function () use (&$fetchPage) {
            return $fetchPage;
        });

        try {
            $cards = [];
            for ($fetchPage = 1; $fetchPage <= self::MAX_FETCH_PAGES; $fetchPage++) {
                // Không truyền user: wishlist gắn sau (withWishlist), để cache dùng chung mọi khách.
                $result = $this->searchRooms($filters, null);
                array_push($cards, ...$result['data']);

                if ($fetchPage >= (int) ($result['meta']['last_page'] ?? 1)) {
                    break;
                }
            }
        } finally {
            $this->onlyIds = null;
            $resolverProp->setValue(null, $originalResolver);
        }

        $cards = $this->withoutBlockedNights($cards, $params['checkin'] ?? null, $params['checkout'] ?? null);

        foreach ($cards as $i => $card) {
            $cards[$i]['has_promotion'] = isset($card['price']['original_amount'], $card['price']['amount'])
                && (float) $card['price']['original_amount'] > (float) $card['price']['amount'];
            $cards[$i]['_order'] = $i;
        }

        usort($cards, $this->comparator($sort));

        return array_map(function (array $card) {
            unset($card['_order']);

            return $card;
        }, $cards);
    }

    // Phòng theo ngày phải trống từng đêm trong [nhận, trả) — đêm đầu đã lọc bằng "date" ở trên.
    private function withoutBlockedNights(array $cards, ?string $checkin, ?string $checkout): array
    {
        if (! $checkin || ! $checkout) {
            return $cards;
        }

        $start = Carbon::parse($checkin)->addDay();
        $end   = Carbon::parse($checkout);
        if ($end->lte($start)) {
            return $cards;
        }

        $availability = app(RoomDateAvailability::class);
        $blocked      = [];
        for ($day = $start->copy(), $n = 0; $day->lt($end) && $n < self::MAX_NIGHTS; $day->addDay(), $n++) {
            foreach ($availability->unavailableDayIds($day->format('Y-m-d')) as $id) {
                $blocked[(string) $id] = true;
            }
        }

        if ($blocked === []) {
            return $cards;
        }

        return array_values(array_filter(
            $cards,
            fn (array $card) => ($card['room_style'] ?? null) !== 'theo_ngay' || ! isset($blocked[(string) $card['id']])
        ));
    }

    private function comparator(string $sort): \Closure
    {
        $price = fn (array $c) => isset($c['price']['amount']) ? (float) $c['price']['amount'] : null;

        return match ($sort) {
            'price_asc' => fn ($a, $b) => [$price($a) === null, $price($a), $a['_order']] <=> [$price($b) === null, $price($b), $b['_order']],
            'price_desc' => fn ($a, $b) => [$price($a) === null, -($price($a) ?? 0), $a['_order']] <=> [$price($b) === null, -($price($b) ?? 0), $b['_order']],
            'rating' => fn ($a, $b) => [-($a['rating'] ?? 0), $a['_order']] <=> [-($b['rating'] ?? 0), $b['_order']],
            // Toạ độ: giữ thứ tự gần → xa của truy vấn, phòng khuyến mãi chỉ lên trước trong cùng khoảng cách.
            'distance' => fn ($a, $b) => [$a['distance'] ?? 0, ! $a['has_promotion'], $a['_order']] <=> [$b['distance'] ?? 0, ! $b['has_promotion'], $b['_order']],
            // Mặc định: đang khuyến mãi lên đầu (giảm sâu trước), còn lại giữ thứ tự gốc.
            default => fn ($a, $b) => [! $a['has_promotion'], -($a['price']['discount_percent'] ?? 0), $a['_order']]
                <=> [! $b['has_promotion'], -($b['price']['discount_percent'] ?? 0), $b['_order']],
        };
    }

    private function withWishlist(array $cards, ?Authenticatable $authUser): array
    {
        if (! $authUser instanceof Customer || $cards === []) {
            return $cards;
        }

        $ids = $authUser->wishlists()->whereIn('product_id', array_column($cards, 'id'))->pluck('product_id')
            ->map(fn ($id) => (string) $id)->flip();

        return array_map(function (array $card) use ($ids) {
            $card['wishlist_status'] = $ids->has((string) $card['id']);

            return $card;
        }, $cards);
    }
}
