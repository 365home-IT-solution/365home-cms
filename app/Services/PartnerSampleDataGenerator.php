<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\PriceTemplate;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\TimeSlot;
use RuntimeException;

/**
 * Sinh DỮ LIỆU MẪU ngẫu nhiên cho 1 đối tác + 1 chi nhánh (trang quản trị "Dữ liệu mẫu", chỉ
 * super_admin): khung giờ riêng, bảng giá mẫu, tiện ích riêng, dịch vụ đi kèm của chi nhánh, phòng
 * theo khung giờ đã áp bảng giá. Mỗi loại lấy ngẫu nhiên từ một bộ có sẵn và BỎ QUA những gì đối tác
 * đã có (cùng giờ, cùng tên) — chạy lại nhiều lần không sinh trùng; hết bộ thì dừng ở số đã tạo được.
 */
class PartnerSampleDataGenerator
{
    private const TEMPLATE_NAMES = [
        'Giá ngày thường', 'Giá cuối tuần', 'Giá ngày lễ', 'Giá thấp điểm', 'Giá cao điểm',
        'Giá sinh viên', 'Giá khai trương', 'Giá mùa hè', 'Giá Tết', 'Giá đêm muộn',
    ];

    private const AMENITIES = [
        'Wifi tốc độ cao', 'Máy lạnh', 'Smart TV', 'Netflix', 'Máy chiếu', 'Bồn tắm', 'Bếp mini',
        'Tủ lạnh', 'Ban công', 'Gương toàn thân', 'Máy sấy tóc', 'Bàn làm việc', 'Loa Bluetooth',
        'Board game', 'Ấm siêu tốc', 'Két sắt', 'Rèm cản sáng', 'Máy lọc không khí',
    ];

    // [tên, giá thấp nhất, giá cao nhất] — giá random trong khoảng, làm tròn 5.000đ.
    private const SERVICES = [
        ['Thuê xe máy', 80000, 150000], ['Giặt ủi', 30000, 80000], ['Bữa sáng', 40000, 90000],
        ['Đưa đón sân bay', 150000, 300000], ['Trang trí sinh nhật', 200000, 500000],
        ['Thêm chăn gối', 20000, 50000], ['Nước suối', 10000, 20000], ['Mì ly', 15000, 30000],
        ['Trái cây', 50000, 120000], ['Hoa tươi', 100000, 250000], ['Bánh kem', 150000, 350000],
        ['Gửi hành lý', 20000, 50000],
    ];

    private const ROOM_PREFIXES = ['Phòng', 'Studio', 'Deluxe', 'Cozy', 'Mini'];

    public function __construct(
        private PriceTemplateService $templates,
        private BranchServiceSync $branchServices,
    ) {
    }

    /**
     * @param array{time_slots?:int, templates?:int, tags?:int, services?:int, rooms?:int} $counts
     * @return array<string, int> số bản ghi thực sự tạo theo từng loại
     */
    public function generate(string $partnerId, int $branchId, array $counts): array
    {
        $branch = Category::withoutGlobalScopes()
            ->whereKey($branchId)
            ->whereNull('parent_id')
            ->where('category_type', 'product')
            ->first();

        if (! $branch || $branch->partner_id !== $partnerId) {
            throw new RuntimeException('Chi nhánh không thuộc đối tác đã chọn.');
        }

        return DB::transaction(function () use ($partnerId, $branch, $counts) {
            $result = [
                'time_slots' => $this->makeTimeSlots($partnerId, (int) ($counts['time_slots'] ?? 0)),
                'templates'  => 0,
                'tags'       => $this->makeTags($partnerId, (int) ($counts['tags'] ?? 0)),
                'services'   => $this->makeServices($partnerId, $branch, (int) ($counts['services'] ?? 0)),
                'rooms'      => 0,
            ];

            $result['templates'] = $this->makeTemplates($partnerId, (int) ($counts['templates'] ?? 0));
            $result['rooms']     = $this->makeRooms($partnerId, $branch, (int) ($counts['rooms'] ?? 0));

            return $result;
        });
    }

    /** Khung giờ riêng: bắt đầu 06:00–21:50 (bước 10 phút), dài 2–3 tiếng; thêm 1 khung qua đêm. */
    private function makeTimeSlots(string $partnerId, int $count): int
    {
        $taken = TimeSlot::usableBy($partnerId)
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'date'))
            ->get()
            ->map(fn (TimeSlot $t) => substr((string) $t->start_time, 0, 5) . '-' . substr((string) $t->end_time, 0, 5))
            ->flip();

        $created = 0;

        for ($attempt = 0; $created < $count && $attempt < $count * 30; $attempt++) {
            $overNight = $created === 0 && $count >= 3; // khung đầu tiên (khi tạo ≥ 3) là qua đêm

            $start = $overNight
                ? sprintf('%02d:%02d', random_int(21, 23), 10 * random_int(0, 5))
                : sprintf('%02d:%02d', random_int(6, 19), 10 * random_int(0, 5));
            $end = $overNight
                ? sprintf('%02d:%02d', random_int(7, 10), 10 * random_int(0, 5))
                : date('H:i', strtotime($start) + 60 * (120 + 10 * random_int(0, 6)));

            if (isset($taken["{$start}-{$end}"])) {
                continue;
            }

            TimeSlot::create([
                'partner_id' => $partnerId,
                'start_time' => $start,
                'end_time'   => $end,
                'over_night' => $overNight,
                'label'      => "{$start} - {$end}" . ($overNight ? ' (Qua đêm)' : ''),
                'type'       => 'time',
            ]);

            $taken["{$start}-{$end}"] = true;
            $created++;
        }

        return $created;
    }

    /** Bảng giá mẫu riêng: 3–5 khung giờ (ưu tiên khung riêng của đối tác), giá 100k–400k. */
    private function makeTemplates(string $partnerId, int $count): int
    {
        $slots = TimeSlot::usableBy($partnerId)
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'date'))
            ->orderByRaw('partner_id IS NULL')
            ->orderBy('start_time')
            ->get()
            // Bảng time_slots có nhiều dòng trùng giờ — mỗi giờ chỉ lấy 1 dòng.
            ->unique(fn (TimeSlot $t) => substr((string) $t->start_time, 0, 5) . '-' . substr((string) $t->end_time, 0, 5))
            ->values();

        if ($slots->count() < 3) {
            return 0;
        }

        $names = $this->freshNames(
            self::TEMPLATE_NAMES,
            PriceTemplate::where('partner_id', $partnerId)->pluck('name')
        );

        $created = 0;

        foreach (array_slice($names, 0, $count) as $name) {
            $picked = $slots->shuffle()->take(random_int(3, min(5, $slots->count())))->sortBy('start_time')->values();

            $template = PriceTemplate::create([
                'partner_id' => $partnerId,
                'name'       => $name,
                'note'       => 'Dữ liệu mẫu',
                'is_active'  => true,
                'sort_order' => 0,
            ]);

            $this->templates->replaceSlots($template, $picked->map(fn (TimeSlot $t) => [
                'timeslot_id' => $t->id,
                'price'       => $this->randomPrice($t->over_night ? 250000 : 100000, $t->over_night ? 450000 : 300000, 10000),
                'over_night'  => (bool) $t->over_night,
            ])->all());

            $created++;
        }

        return $created;
    }

    private function makeTags(string $partnerId, int $count): int
    {
        $locale = app()->getLocale();
        $taken  = Tag::usableBy($partnerId)->get()->map(fn (Tag $t) => $t->getTranslation('name', $locale, false));

        $created = 0;
        foreach (array_slice($this->freshNames(self::AMENITIES, $taken), 0, $count) as $name) {
            Tag::create(['name' => $name, 'type' => 'product', 'partner_id' => $partnerId]);
            $created++;
        }

        return $created;
    }

    /** Dịch vụ gắn cho chi nhánh. Tên trùng dịch vụ sẵn có của đối tác → bỏ qua (không gắn lại). */
    private function makeServices(string $partnerId, Category $branch, int $count): int
    {
        $taken = AdditionService::withoutGlobalScopes()->where('partner_id', $partnerId)->pluck('name');
        $pool  = collect(self::SERVICES)->keyBy(0);

        $created = 0;
        foreach (array_slice($this->freshNames($pool->keys()->all(), $taken), 0, $count) as $name) {
            [, $min, $max] = $pool[$name];

            $service = AdditionService::create([
                'partner_id' => $partnerId,
                'name'       => $name,
                'price'      => $this->randomPrice($min, $max, 5000),
                'is_active'  => true,
            ]);
            $service->branches()->sync([$branch->id]);
            $created++;
        }

        if ($created) {
            $this->branchServices->syncBranches([$branch->id]);
        }

        return $created;
    }

    /** Phòng theo khung giờ trong chi nhánh: gắn 3–6 tiện ích, áp 1 bảng giá mẫu dùng được. */
    private function makeRooms(string $partnerId, Category $branch, int $count): int
    {
        if ($count < 1) {
            return 0;
        }

        $template = PriceTemplate::usableBy($partnerId)
            ->where('is_active', true)
            ->whereHas('slots')
            ->orderByRaw('partner_id IS NULL')
            ->inRandomOrder()
            ->first();

        // Tiện ích riêng của đối tác + tiện ích hệ thống đang được dùng cho phòng (bảng tags dùng chung
        // với thẻ bài viết, cột type gần như luôn rỗng nên nhận diện qua taggables).
        $tagIds = Tag::usableBy($partnerId)
            ->where(fn ($q) => $q->where('partner_id', $partnerId)
                ->orWhere('type', 'product')
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('taggables')
                    ->whereColumn('taggables.tag_id', 'tags.id')
                    ->where('taggables.taggable_type', (new Product)->getMorphClass())))
            ->pluck('id');

        $taken = Product::withoutGlobalScopes()->where('partner_id', $partnerId)->pluck('name')->map(fn ($n) => Str::lower($n))->flip();
        $rooms = collect();

        for ($attempt = 0; $rooms->count() < $count && $attempt < $count * 30; $attempt++) {
            $name = self::ROOM_PREFIXES[array_rand(self::ROOM_PREFIXES)] . ' ' . (random_int(1, 9) * 100 + random_int(1, 20));

            if (isset($taken[Str::lower($name)])) {
                continue;
            }

            $room = Product::create([
                'partner_id'   => $partnerId,
                'name'         => $name,
                'slug'         => $this->uniqueSlug($name),
                'styles'       => 1,
                'is_activated' => true,
                'is_in_stock'  => true,
            ]);
            $room->categories()->attach($branch->id);

            if ($tagIds->isNotEmpty()) {
                $room->tags()->sync($tagIds->shuffle()->take(random_int(3, 6))->all());
            }

            $this->branchServices->syncRoom($room);

            $taken[Str::lower($name)] = true;
            $rooms->push($room);
        }

        if ($template && $rooms->isNotEmpty() && ! $this->templates->applyError($template, $rooms)) {
            $this->templates->apply($template, $rooms, true);
        }

        return $rooms->count();
    }

    /** Tên trong $pool chưa có trong $taken (so không phân biệt hoa thường), thứ tự ngẫu nhiên. */
    private function freshNames(array $pool, Collection $taken): array
    {
        $taken = $taken->filter()->map(fn ($n) => Str::lower(trim((string) $n)))->flip();

        return collect($pool)->reject(fn ($n) => isset($taken[Str::lower($n)]))->shuffle()->values()->all();
    }

    private function randomPrice(int $min, int $max, int $step): int
    {
        return intdiv(random_int($min, $max), $step) * $step;
    }

    private function uniqueSlug(string $name): string
    {
        do {
            $slug = Str::slug($name) . '-' . Str::lower(Str::random(5));
        } while (Product::withoutGlobalScopes()->where('slug', $slug)->exists());

        return $slug;
    }
}
