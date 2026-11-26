<?php

declare(strict_types=1);

namespace App\Services\HomeSearch;

use App\Models\ProvinceBranch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

/**
 * Ghép điểm đến theo địa giới CŨ (OldProvinceDirectory — vd "Sóc Trăng", "Đà Lạt") với phòng thật
 * của hệ thống. Phòng không lưu tỉnh cũ, nên 1 phòng khớp điểm đến khi CẢ 2 điều kiện đúng:
 *  1. Tên điểm đến xuất hiện (nguyên từ, có dấu, không phân biệt hoa thường) trong tên/địa chỉ phòng,
 *     tên chi nhánh, hoặc tên tỉnh của chi nhánh (bảng provinces của hệ thống).
 *  2. Tỉnh của chi nhánh (nếu có) là tỉnh cũ chứa điểm đến đó, hoặc tỉnh mới đã nhận sáp nhập tỉnh
 *     cũ đó (MERGED_INTO) — tránh "TP Tân An" (Long An cũ) khớp nhầm phường Tân An ở Cần Thơ.
 *
 * Khớp có dấu + nguyên từ (không dùng LIKE bỏ dấu như ô tìm kiếm cũ) để "Huế" không khớp đường
 * "Nguyễn Huệ"; chỉ chuẩn hoá vị trí dấu kiểu cũ/mới ("Hoà" = "Hòa", "Thuỷ" = "Thủy").
 */
final class HomeSearchDestinations
{
    private const INDEX_CACHE_KEY = 'home-search:room-index:v2';

    /** Tỉnh cũ → tỉnh mới nhận sáp nhập (Nghị quyết 202/2025/QH15, hiệu lực 01/07/2025). */
    private const MERGED_INTO = [
        'Hà Giang' => 'Tuyên Quang',
        'Yên Bái' => 'Lào Cai',
        'Bắc Kạn' => 'Thái Nguyên',
        'Vĩnh Phúc' => 'Phú Thọ',
        'Hòa Bình' => 'Phú Thọ',
        'Bắc Giang' => 'Bắc Ninh',
        'Thái Bình' => 'Hưng Yên',
        'Hải Dương' => 'Hải Phòng',
        'Hà Nam' => 'Ninh Bình',
        'Nam Định' => 'Ninh Bình',
        'Quảng Bình' => 'Quảng Trị',
        'Quảng Nam' => 'Đà Nẵng',
        'Kon Tum' => 'Quảng Ngãi',
        'Bình Định' => 'Gia Lai',
        'Ninh Thuận' => 'Khánh Hòa',
        'Đắk Nông' => 'Lâm Đồng',
        'Bình Thuận' => 'Lâm Đồng',
        'Phú Yên' => 'Đắk Lắk',
        'Bình Dương' => 'Hồ Chí Minh',
        'Bà Rịa - Vũng Tàu' => 'Hồ Chí Minh',
        'Bình Phước' => 'Đồng Nai',
        'Long An' => 'Tây Ninh',
        'Sóc Trăng' => 'Cần Thơ',
        'Hậu Giang' => 'Cần Thơ',
        'Bến Tre' => 'Vĩnh Long',
        'Trà Vinh' => 'Vĩnh Long',
        'Tiền Giang' => 'Đồng Tháp',
        'Bạc Liêu' => 'Cà Mau',
        'Kiên Giang' => 'An Giang',
        'Thừa Thiên Huế' => 'Huế',
    ];

    /** Tên gọi khác cần khớp thêm (tên cũ đầy đủ ít khi có trong địa chỉ). */
    private const ALIASES = [
        'Thừa Thiên Huế' => ['Huế'],
        'Bà Rịa - Vũng Tàu' => ['Vũng Tàu', 'Bà Rịa'],
    ];

    public function __construct(private readonly OldProvinceDirectory $directory)
    {
    }

    /**
     * Toàn bộ điểm đến kèm số phòng đang bán khớp với từng điểm đến.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $index = $this->roomIndex();

        return array_map(function (array $row) use ($index) {
            $row['room_count'] = count($this->matchIds($row, $index));

            return $row;
        }, $this->directory->all());
    }

    public function find(string $kind, int $code): ?array
    {
        foreach ($this->directory->all() as $row) {
            if ($row['kind'] === $kind && $row['code'] === $code) {
                return $row;
            }
        }

        return null;
    }

    /** @return array<int, int|string> id phòng khớp điểm đến. */
    public function roomIdsFor(array $destination): array
    {
        return $this->matchIds($destination, $this->roomIndex());
    }

    /** @param array<int|string, array{h:string, p:array<int,string>}> $index */
    private function matchIds(array $destination, array $index): array
    {
        $names = array_merge([(string) $destination['short_name']], self::ALIASES[$destination['short_name']] ?? []);
        $names = array_filter(array_map(fn ($n) => self::normalize($n), $names));
        if ($names === []) {
            return [];
        }

        $pattern = '/(?<![\p{L}\p{N}])(?:' . implode('|', array_map(fn ($n) => preg_quote($n, '/'), $names)) . ')(?![\p{L}\p{N}])/u';

        $oldProvince = (string) $destination['province_name'];
        $allowed     = [self::key($oldProvince), self::key(self::mergedInto($oldProvince) ?? $oldProvince)];

        $ids = [];
        foreach ($index as $id => $room) {
            if ($room['p'] !== [] && array_intersect($room['p'], $allowed) === []) {
                continue;
            }
            if (preg_match($pattern, $room['h'])) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private static function mergedInto(string $oldProvince): ?string
    {
        $key = self::key($oldProvince);
        foreach (self::MERGED_INTO as $old => $new) {
            if (self::key($old) === $key) {
                return $new;
            }
        }

        return null;
    }

    /** Khoá so sánh tên tỉnh: bỏ dấu, chữ thường, bỏ tiền tố, gộp khoảng trắng/gạch. */
    private static function key(string $name): string
    {
        $ascii = Str::lower(Str::ascii(OldProvinceDirectory::shortName($name)));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }

    /** Chữ thường + thống nhất vị trí dấu thanh kiểu cũ/mới (oà→òa, uỷ→ủy...), giữ nguyên dấu. */
    private static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        if (class_exists(\Normalizer::class)) {
            $text = (string) \Normalizer::normalize($text, \Normalizer::FORM_C);
        }

        return strtr($text, [
            'oà' => 'òa', 'oá' => 'óa', 'oả' => 'ỏa', 'oã' => 'õa', 'oạ' => 'ọa',
            'oè' => 'òe', 'oé' => 'óe', 'oẻ' => 'ỏe', 'oẽ' => 'õe', 'oẹ' => 'ọe',
            'uỳ' => 'ùy', 'uý' => 'úy', 'uỷ' => 'ủy', 'uỹ' => 'ũy', 'uỵ' => 'ụy',
        ]);
    }

    /**
     * id phòng → ['h' => chuỗi tra cứu (tên + địa chỉ phòng, tên chi nhánh cả con lẫn cha, tên tỉnh
     * của chi nhánh), 'p' => khoá tên các tỉnh của chi nhánh]. Cache ngắn: chỉ đổi khi sửa phòng/chi nhánh.
     *
     * @return array<int|string, array{h:string, p:array<int,string>}>
     */
    private function roomIndex(): array
    {
        $resolve = function (): array {
            $rooms = Product::query()
                ->where('is_activated', true)
                ->where('is_in_stock', true)
                ->activeBranch()
                ->with('categories:id,name,parent_id')
                ->get(['id', 'name', 'address']);

            $categoryIds = $rooms->flatMap(fn (Product $room) => $room->categories->pluck('id'))->unique();
            $parentIds   = $rooms->flatMap(fn (Product $room) => $room->categories->pluck('parent_id'))->filter()->unique();

            $parents = Category::query()->whereIn('id', $parentIds)->pluck('name', 'id');

            $provincesByCategory = ProvinceBranch::query()
                ->where('status', true)
                ->whereIn('categorie_id', $categoryIds->merge($parentIds)->unique())
                ->with('province:id,name')
                ->get()
                ->groupBy('categorie_id')
                ->map(fn ($rows) => $rows->pluck('province.name')->filter()->unique()->values()->all());

            $index = [];
            foreach ($rooms as $room) {
                $parts     = [$room->name, $room->address];
                $provinces = [];

                foreach ($room->categories as $category) {
                    $parts[] = $category->name;
                    array_push($provinces, ...($provincesByCategory[$category->id] ?? []));

                    if ($category->parent_id) {
                        $parts[] = $parents[$category->parent_id] ?? null;
                        array_push($provinces, ...($provincesByCategory[$category->parent_id] ?? []));
                    }
                }

                $provinces = array_values(array_unique($provinces));

                $index[$room->id] = [
                    'h' => self::normalize(implode(' | ', array_filter(array_merge($parts, $provinces)))),
                    'p' => array_values(array_unique(array_map(fn ($p) => self::key($p), $provinces))),
                ];
            }

            return $index;
        };

        try {
            return Cache::remember(self::INDEX_CACHE_KEY, now()->addMinutes(5), $resolve);
        } catch (\Throwable) {
            return $resolve();
        }
    }
}
