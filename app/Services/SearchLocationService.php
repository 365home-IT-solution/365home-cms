<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Province;
use App\Models\ProvinceBranch;
use App\Models\Ward;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;

/**
 * Gợi ý khi khách GÕ vào ô "Địa điểm" của màn Tìm kiếm (mobile): trộn CHI NHÁNH + TỈNH/THÀNH + PHƯỜNG/XÃ + ĐỊA CHỈ.
 *
 *  - Tìm không phân biệt dấu và hoa thường ("thung" ra "Thung Lũng Mơ"; collation unicode_ci lo phần dấu).
 *    Riêng chữ đ/Đ không được collation coi như d nên chuẩn hoá đ → d ở CẢ cột lẫn từ khoá ("da lat" ra "Đà Lạt",
 *    "đa lat" cũng ra "Đà Lạt", kể cả từ khoá trộn d và đ).
 *  - Chỉ chi nhánh đang hoạt động và CÒN phòng đang bán; tỉnh / phường chỉ hiện khi có chi nhánh đang hoạt động.
 *  - Mỗi dòng có "params" — đúng tham số gửi tiếp vào GET /api/v1/search để ra danh sách:
 *      branch   → { province: <slug chi nhánh> }   (RoomSearchService nhận slug chi nhánh ở tham số province)
 *      province → { province_id }
 *      ward     → { ward_code }
 *      address  → { q: <địa chỉ> }
 */
class SearchLocationService
{
    public const MIN_LENGTH = 2;

    private const CAPS = ['branch' => 5, 'province' => 3, 'ward' => 4, 'address' => 5];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function suggest(string $keyword, int $limit = 10): Collection
    {
        $keyword = trim($keyword);

        if (mb_strlen($keyword) < self::MIN_LENGTH) {
            return collect();
        }

        return collect()
            ->merge($this->branches($keyword, self::CAPS['branch']))
            ->merge($this->provinces($keyword, self::CAPS['province']))
            ->merge($this->wards($keyword, self::CAPS['ward']))
            ->merge($this->addresses($keyword, self::CAPS['address']))
            ->take($limit)
            ->values();
    }

    /** Chữ đ/Đ → d/D (collation unicode_ci đã bỏ dấu các chữ khác nhưng không coi đ = d). */
    public static function strip(string $text): string
    {
        return str_replace(['đ', 'Đ'], ['d', 'D'], $text);
    }

    /** Biểu thức SQL: cột đã chuẩn hoá đ → d, để so với pattern() bằng LIKE. */
    public static function column(string $column): string
    {
        return "REPLACE(REPLACE({$column}, 'đ', 'd'), 'Đ', 'D')";
    }

    /** Mẫu LIKE đã thoát ký tự đặc biệt + chuẩn hoá đ → d. */
    public static function pattern(string $keyword): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], self::strip(trim($keyword))) . '%';
    }

    /** Điều kiện "cột khớp từ khoá" (không phân biệt dấu, đ = d) — dùng làm closure where của query builder. */
    public function matching(string $keyword, string $column): \Closure
    {
        return fn ($query) => $query->whereRaw(self::column($column) . ' like ?', [self::pattern($keyword)]);
    }

    /** Tên bắt đầu bằng từ khoá xếp trước. */
    private function startsWith(string $keyword, string $name): bool
    {
        return mb_stripos(self::strip($name), self::strip(trim($keyword))) === 0;
    }
    /** @return Collection<int, array<string, mixed>> */
    private function branches(string $keyword, int $cap): Collection
    {
        $rows = ProvinceBranch::query()
            ->where('status', true)
            ->whereHas('category', fn ($c) => $c->where('status', true)->whereNull('parent_id')->where('category_type', 'product')
                ->where($this->matching($keyword, 'name')))
            ->with(['category:id,name,slug,image', 'province:id,name,slug'])
            ->get()
            ->unique('categorie_id')
            ->sortBy(fn (ProvinceBranch $b) => [$this->startsWith($keyword, $b->category->name) ? 0 : 1, $b->category->name])
            ->values();

        $out = collect();

        foreach ($rows as $row) {
            $branch = $row->category;
            $count  = $this->roomCount($branch->id);

            if ($count === 0) {
                continue; // chi nhánh chưa có phòng đang bán thì không gợi ý
            }

            $coords = Product::query()->activeBranch()->whereNotNull('latitude')->whereNotNull('longitude')
                ->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $this->branchCategoryIds($branch->id)))
                ->first(['latitude', 'longitude']);

            $out->push([
                'type'          => 'branch',
                'id'            => $branch->id,
                'name'          => $branch->name,
                'query'         => $branch->name,
                'description'   => $count . ' phòng' . ($row->province?->name ? ' · ' . $row->province->name : ''),
                'slug'          => $branch->slug,
                'province_id'   => $row->province?->id,
                'province_name' => $row->province?->name,
                'room_count'    => $count,
                'image_url'     => $branch->image ? Storage::disk('public')->url($branch->image) : null,
                'latitude'      => $coords?->latitude ? (string) $coords->latitude : null,
                'longitude'     => $coords?->longitude ? (string) $coords->longitude : null,
                'params'        => ['province' => $branch->slug],
            ]);

            if ($out->count() >= $cap) {
                break;
            }
        }

        return $out;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function provinces(string $keyword, int $cap): Collection
    {
        return Province::query()
            ->where($this->matching($keyword, 'name'))
            ->whereHas('branches', fn ($b) => $b->where('status', true))
            ->withCount(['branches as branch_count' => fn ($b) => $b->where('status', true)])
            ->get()
            ->sortBy(fn (Province $p) => [$this->startsWith($keyword, $p->name) ? 0 : 1, $p->name])
            ->take($cap)
            ->map(fn (Province $p) => [
                'type'          => 'province',
                'id'            => $p->id,
                'name'          => $p->name,
                'query'         => $p->name,
                'description'   => $p->branch_count . ' chi nhánh',
                'slug'          => $p->slug,
                'province_id'   => $p->id,
                'province_name' => $p->name,
                'room_count'    => null,
                'image_url'     => $p->image ? Storage::disk('public')->url($p->image) : null,
                'latitude'      => $p->lat !== null ? (string) $p->lat : null,
                'longitude'     => $p->lng !== null ? (string) $p->lng : null,
                'params'        => ['province_id' => $p->id],
            ])
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function wards(string $keyword, int $cap): Collection
    {
        $active = ProvinceBranch::query()->where('status', true)->whereNotNull('ward_code');

        return Ward::query()
            ->with('province:code,id,name')
            ->whereIn('code', (clone $active)->select('ward_code'))
            ->where($this->matching($keyword, 'name'))
            ->limit($cap * 4)
            ->get()
            ->sortBy(fn (Ward $w) => [$this->startsWith($keyword, $w->name) ? 0 : 1, $w->name])
            ->take($cap)
            ->map(function (Ward $w) use ($active) {
                $branches = (clone $active)->where('ward_code', $w->code)->count();

                return [
                    'type'          => 'ward',
                    'id'            => $w->code,
                    'name'          => $w->name,
                    'query'         => $w->name,
                    'description'   => ($w->province?->name ? $w->province->name . ' · ' : '') . $branches . ' chi nhánh',
                    'slug'          => null,
                    'province_id'   => $w->province?->id,
                    'province_name' => $w->province?->name,
                    'room_count'    => null,
                    'image_url'     => null,
                    'latitude'      => null,
                    'longitude'     => null,
                    'ward_code'     => $w->code,
                    'params'        => ['ward_code' => $w->code],
                ];
            })
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function addresses(string $keyword, int $cap): Collection
    {
        return Product::query()
            ->where('is_activated', true)->where('is_in_stock', true)->activeBranch()
            ->whereNotNull('address')
            ->where($this->matching($keyword, 'address'))
            ->orderBy('name')
            ->limit($cap * 4)
            ->get(['name', 'address', 'latitude', 'longitude'])
            ->unique('address')
            ->take($cap)
            ->map(fn (Product $p) => [
                'type'          => 'address',
                'id'            => null,
                'name'          => $p->address,
                'query'         => $p->address,
                'description'   => 'Địa chỉ',
                'slug'          => null,
                'province_id'   => null,
                'province_name' => null,
                'room_count'    => null,
                'image_url'     => null,
                'latitude'      => $p->latitude ? (string) $p->latitude : null,
                'longitude'     => $p->longitude ? (string) $p->longitude : null,
                'params'        => ['q' => $p->address],
            ])
            ->values();
    }

    /** Chi nhánh + các category con (khu vực). */
    private function branchCategoryIds(int $branchId): array
    {
        return array_merge([$branchId], Category::where('parent_id', $branchId)->pluck('id')->all());
    }

    /** Số phòng đang bán (kích hoạt + còn bán) của chi nhánh. */
    private function roomCount(int $branchId): int
    {
        return Product::query()->activeBranch()
            ->where('is_activated', true)->where('is_in_stock', true)
            ->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $this->branchCategoryIds($branchId)))
            ->count();
    }
}
