<?php

declare(strict_types=1);

namespace App\GraphQL;

use App\Services\RoomSearchService;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * Schema GraphQL cho pilot "tìm kiếm" — chỉ 1 field duy nhất (search), field/kiểu dữ liệu
 * giữ nguyên tên snake_case giống hệt JSON REST hiện tại (BuildsRoomCard::mapRoom() +
 * RoomSearchService), để JS phía client (roomCardHtml() trong home-sections.js) dùng lại
 * được y nguyên, không cần viết thêm lớp chuyển đổi field.
 */
class SearchSchema
{
    public static function build(): Schema
    {
        $badgeType = new ObjectType([
            'name'   => 'Badge',
            'fields' => [
                'label'      => Type::string(),
                'type'       => Type::string(),
                'bg_color'   => Type::string(),
                'text_color' => Type::string(),
            ],
        ]);

        $priceType = new ObjectType([
            'name'   => 'Price',
            'fields' => [
                'amount'     => Type::float(),
                'unit_label' => Type::string(),
            ],
        ]);

        $branchType = new ObjectType([
            'name'   => 'Branch',
            'fields' => [
                'id'            => Type::int(),
                'name'          => Type::string(),
                'slug'          => Type::string(),
                // FE dùng để dựng URL canonical /homestay/{province_slug}/{slug}/{room_slug} —
                // xem BuildsRoomCard::resolveBranch() (nguồn dữ liệu field này) và
                // window.roomCardHtml() trong public/js/home-sections.js.
                'province_slug' => Type::string(),
            ],
        ]);

        // Mirrors App\Support\MediaThumbnailUrls::build() — already present on every room card's
        // underlying array (BuildsRoomCard::mapRoom()), just not exposed on this GraphQL type until
        // now. See docs/image-thumbnails-fe-guide.md §2 for which preset to use where.
        $thumbnailType = new ObjectType([
            'name'   => 'Thumbnail',
            'fields' => [
                'thumb'  => Type::string(),
                'card'   => Type::string(),
                'wide'   => Type::string(),
                'full'   => Type::string(),
                'width'  => Type::int(),
                'height' => Type::int(),
            ],
        ]);

        $roomCardType = new ObjectType([
            'name'   => 'RoomCard',
            'fields' => [
                'id'               => Type::string(),
                'slug'             => Type::string(),
                'name'             => Type::string(),
                'thumbnail_url'    => Type::string(),
                'thumbnail'        => $thumbnailType,
                'room_style'       => Type::string(),
                'badge'            => $badgeType,
                'price'            => $priceType,
                'rating'           => Type::float(),
                'wishlist_status'  => Type::boolean(),
                'is_available'     => Type::boolean(),
                'latitude'         => Type::string(),
                'longitude'        => Type::string(),
                'address'          => Type::string(),
                'branch'           => $branchType,
                'distance'         => Type::float(),
            ],
        ]);

        $searchMetaType = new ObjectType([
            'name'   => 'SearchMeta',
            'fields' => [
                'current_page'  => Type::int(),
                'last_page'     => Type::int(),
                'per_page'      => Type::int(),
                'total'         => Type::int(),
                'province_name' => Type::string(),
                'type_name'     => Type::string(),
            ],
        ]);

        $searchResultType = new ObjectType([
            'name'   => 'SearchResult',
            'fields' => [
                'data' => Type::listOf($roomCardType),
                'meta' => $searchMetaType,
            ],
        ]);

        // Field tên trùng ngay với tham số query REST hiện tại (?category=, ?type=, ?buoi=...)
        // để RoomSearchService::search() dùng chung 1 mảng $filters cho cả REST lẫn GraphQL.
        $searchFiltersInputType = new InputObjectType([
            'name'   => 'SearchFiltersInput',
            'fields' => [
                'category'    => Type::string(),
                'type'        => Type::string(),
                'buoi'        => Type::string(),
                'overnight'   => Type::string(),
                'checkin'     => Type::string(),
                'checkout'    => Type::string(),
                'q'           => Type::string(),
                'lat'         => Type::float(),
                'lng'         => Type::float(),
                'radius'      => Type::float(),
                'time_type'   => Type::string(),
                'date'        => Type::string(),
                'time_from'   => Type::string(),
                'time_to'     => Type::string(),
                'from'        => Type::string(),
                'to'          => Type::string(),
                'month'       => Type::int(),
                'year'        => Type::int(),
                'adults'      => Type::int(),
                'ward_code'   => Type::int(),
                'province'    => Type::string(),
                'province_id' => Type::int(),
                'page'        => Type::int(),
                'per_page'    => Type::int(),
            ],
        ]);

        $queryType = new ObjectType([
            'name'   => 'Query',
            'fields' => [
                'search' => [
                    'type' => Type::nonNull($searchResultType),
                    'args' => [
                        'filters' => $searchFiltersInputType,
                    ],
                    'resolve' => function ($root, array $args) {
                        $filters = $args['filters'] ?? [];

                        return app(RoomSearchService::class)->search($filters, auth('sanctum')->user());
                    },
                ],
            ],
        ]);

        return new Schema([
            'query' => $queryType,
        ]);
    }
}
