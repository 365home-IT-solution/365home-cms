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
 * DỮ LIỆU MẪU ngẫu nhiên cho 1 đối tác + 1 chi nhánh — dùng cho API POST/DELETE /api/admin/sample-data
 * (đối tác tự bấm) và trang quản trị "Dữ liệu mẫu" (super_admin tạo hộ).
 *
 * generate(): khung giờ riêng, bảng giá mẫu, tiện ích riêng, dịch vụ đi kèm của chi nhánh, phòng theo
 * khung giờ đã áp bảng giá + điều kiện giảm giá — xong là phòng đặt được ngay. Mỗi loại lấy ngẫu nhiên
 * từ một bộ có sẵn và BỎ QUA những gì đối tác đã có (cùng giờ, cùng tên) → chạy lại không sinh trùng.
 * Mọi bản ghi tạo ra được ghi vào sample_data_records.
 *
 * purge(): xoá đúng những bản ghi đã ghi ở sample_data_records — GIỮ LẠI mục đã có đơn (phòng có đơn,
 * dịch vụ nằm trong đơn) hoặc đang được dữ liệu thật dùng (khung giờ gán cho phòng thật, tiện ích gắn
 * phòng thật) để không mất lịch sử đơn / giá phòng thật.
 */
class PartnerSampleDataGenerator
{
    public const TYPES = ['time_slot', 'price_template', 'tag', 'service', 'room'];

    private const TEMPLATE_NAMES = [
        'Giá ngày thường', 'Giá cuối tuần', 'Giá ngày lễ', 'Giá thấp điểm', 'Giá cao điểm',
        'Giá sinh viên', 'Giá khai trương', 'Giá mùa hè', 'Giá Tết', 'Giá đêm muộn',
    ];

    private const AMENITIES = [
        'Wifi tốc độ cao', 'Máy lạnh', 'Smart TV', 'Netflix', 'Máy chiếu', 'Bồn tắm', 'Bếp mini',
        'Tủ lạnh', 'Ban công', 'Gương toàn thân', 'Máy sấy tóc', 'Bàn làm việc', 'Loa Bluetooth',
        'Board game', 'Ấm siêu tốc', 'Két sắt', 'Rèm cản sáng', 'Máy lọc không khí',
        'Máy pha cà phê', 'Lò vi sóng', 'Nồi lẩu điện', 'Bàn ủi', 'Máy giặt', 'Dép đi trong phòng',
        'Khăn tắm cao cấp', 'Dầu gội sữa tắm', 'Đèn ngủ', 'Ổ cắm USB', 'Bể sục', 'Phòng xông hơi',
        'Karaoke', 'Máy chơi game', 'Võng', 'Hồ bơi', 'Chỗ đậu xe', 'Thang máy', 'Khóa vân tay',
        'Cửa sổ view thành phố', 'Bàn trà', 'Bình nước lọc',
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

    private string $batchId = '';

    private string $partnerId = '';

    private ?int $branchId = null;

    public function __construct(
        private PriceTemplateService $templates,
        private BranchServiceSync $branchServices,
    ) {
    }

    /**
     * @param array{time_slots?:int, templates?:int, tags?:int, services?:int, rooms?:int} $counts
     * @return array{counts: array<string,int>, rooms: list<array>, price_templates: list<array>, applied_price_template: ?array}
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

        $this->batchId   = (string) Str::uuid();
        $this->partnerId = $partnerId;
        $this->branchId  = $branch->id;

        return DB::transaction(function () use ($partnerId, $branch, $counts) {
            $timeSlots = $this->makeTimeSlots($partnerId, (int) ($counts['time_slots'] ?? 0));
            $tags      = $this->makeTags($partnerId, (int) ($counts['tags'] ?? 0));
            $services  = $this->makeServices($partnerId, $branch, (int) ($counts['services'] ?? 0));
            $templates = $this->makeTemplates($partnerId, (int) ($counts['templates'] ?? 0));
            [$rooms, $applied] = $this->makeRooms($partnerId, $branch, (int) ($counts['rooms'] ?? 0));

            // Mẫu tự tạo thêm để phòng có giá (khi chưa có mẫu nào dùng được) cũng tính vào kết quả.
            if ($applied && $applied->wasRecentlyCreated && ! $templates->contains('id', $applied->id)) {
                $templates->push($applied);
            }

            return [
                'counts' => [
                    'time_slots' => $timeSlots,
                    'templates'  => $templates->count(),
                    'tags'       => $tags,
                    'services'   => $services,
                    'rooms'      => $rooms->count(),
                ],
                'rooms' => $rooms->map(fn (Product $r) => [
                    'id'                    => $r->id,
                    'name'                  => $r->name,
                    'full_booking_discount' => $r->full_booking_discount,
                    'bulk_discount_rules'   => $r->bulk_discount_rules,
                ])->values()->all(),
                'price_templates'        => $templates->map(fn (PriceTemplate $t) => ['id' => $t->id, 'name' => $t->name])->values()->all(),
                'applied_price_template' => $applied ? ['id' => $applied->id, 'name' => $applied->name] : null,
            ];
        });
    }

    /** Số bản ghi mẫu còn lưu của đối tác, theo từng loại. */
    public function summary(string $partnerId): array
    {
        $counts = DB::table('sample_data_records')
            ->where('partner_id', $partnerId)
            ->selectRaw('record_type, count(*) as n')
            ->groupBy('record_type')
            ->pluck('n', 'record_type');

        return collect(self::TYPES)->mapWithKeys(fn ($t) => [$t => (int) ($counts[$t] ?? 0)])->all();
    }

    /**
     * Xoá dữ liệu mẫu của đối tác. Trả về số đã xoá + những mục giữ lại (kèm lý do). Mục giữ lại vẫn
     * nằm trong sample_data_records → lần xoá sau (khi hết bị dùng) sẽ xoá tiếp.
     *
     * @return array{deleted: array<string,int>, kept: list<array{type:string,id:string,name:?string,reason:string}>}
     */
    public function purge(string $partnerId): array
    {
        $records   = DB::table('sample_data_records')->where('partner_id', $partnerId)->get();
        $ids       = fn (string $type) => $records->where('record_type', $type)->pluck('record_id')->all();
        $branchIds = $records->pluck('branch_id')->filter()->unique()->values()->all();

        $deleted = array_fill_keys(self::TYPES, 0);
        $kept    = [];

        DB::transaction(function () use ($partnerId, $ids, &$deleted, &$kept, &$branchIds) {
            // 1) Phòng — giữ phòng đã có đơn.
            $rooms = Product::withoutGlobalScopes()->whereIn('id', $ids('room'))->where('partner_id', $partnerId)->get();
            foreach ($rooms as $room) {
                if ($room->orderItems()->exists()) {
                    $kept[] = ['type' => 'room', 'id' => (string) $room->id, 'name' => $room->name, 'reason' => 'Phòng đã có đơn đặt phòng.'];
                    continue;
                }

                $room->tags()->detach();
                $room->delete();
                $this->forget('room', (string) $room->id);
                $deleted['room']++;
            }
            $this->forgetMissing('room', $ids('room'), fn ($id) => Product::withoutGlobalScopes()->whereKey($id)->exists());

            // 2) Bảng giá mẫu — giá đã áp xuống phòng là bản copy nên xoá mẫu không ảnh hưởng phòng.
            foreach (PriceTemplate::whereIn('id', $ids('price_template'))->where('partner_id', $partnerId)->get() as $template) {
                $template->delete();
                $this->forget('price_template', (string) $template->id);
                $deleted['price_template']++;
            }
            $this->forgetMissing('price_template', $ids('price_template'), fn ($id) => PriceTemplate::whereKey($id)->exists());

            // 3) Khung giờ — room_time_slots/price_board_time_slots xoá dây chuyền theo khung giờ, nên
            //    khung giờ còn được phòng thật, bảng giá thật dùng thì GIỮ (xoá là mất giá phòng thật).
            foreach (TimeSlot::whereIn('id', $ids('time_slot'))->where('partner_id', $partnerId)->get() as $slot) {
                $inUse = DB::table('room_time_slots')->where('timeslot_id', $slot->id)->exists()
                    || DB::table('price_template_slots')->where('timeslot_id', $slot->id)->exists()
                    || DB::table('price_board_time_slots')->where('timeslot_id', $slot->id)->exists();

                if ($inUse) {
                    $kept[] = ['type' => 'time_slot', 'id' => (string) $slot->id, 'name' => $slot->label, 'reason' => 'Khung giờ đang được phòng hoặc bảng giá khác dùng.'];
                    continue;
                }

                $slot->delete();
                $this->forget('time_slot', (string) $slot->id);
                $deleted['time_slot']++;
            }
            $this->forgetMissing('time_slot', $ids('time_slot'), fn ($id) => TimeSlot::whereKey($id)->exists());

            // 4) Tiện ích — giữ tiện ích đang gắn cho phòng (thật) còn lại.
            foreach (Tag::whereIn('id', $ids('tag'))->where('partner_id', $partnerId)->get() as $tag) {
                if (DB::table('taggables')->where('tag_id', $tag->id)->exists()) {
                    $kept[] = ['type' => 'tag', 'id' => (string) $tag->id, 'name' => $tag->name, 'reason' => 'Tiện ích đang gắn cho phòng khác.'];
                    continue;
                }

                $tag->delete();
                $this->forget('tag', (string) $tag->id);
                $deleted['tag']++;
            }
            $this->forgetMissing('tag', $ids('tag'), fn ($id) => Tag::whereKey($id)->exists());

            // 5) Dịch vụ — giữ dịch vụ đã nằm trong đơn (order_services xoá dây chuyền theo dịch vụ).
            foreach (AdditionService::withoutGlobalScopes()->whereIn('id', $ids('service'))->where('partner_id', $partnerId)->get() as $service) {
                if (DB::table('order_services')->where('service_id', $service->id)->exists()) {
                    $kept[] = ['type' => 'service', 'id' => (string) $service->id, 'name' => $service->name, 'reason' => 'Dịch vụ đã có trong đơn đặt phòng.'];
                    continue;
                }

                $branchIds = array_merge($branchIds, $service->branches()->pluck('categories.id')->all());
                $service->delete();
                $this->forget('service', (string) $service->id);
                $deleted['service']++;
            }
            $this->forgetMissing('service', $ids('service'), fn ($id) => AdditionService::withoutGlobalScopes()->whereKey($id)->exists());
        });

        // Phòng thật trong chi nhánh bỏ dịch vụ mẫu vừa xoá.
        $this->branchServices->syncBranches($branchIds);

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    private function track(string $type, string $id): void
    {
        DB::table('sample_data_records')->insert([
            'partner_id'  => $this->partnerId,
            'batch_id'    => $this->batchId,
            'branch_id'   => $this->branchId,
            'record_type' => $type,
            'record_id'   => $id,
            'created_by'  => auth()->id(),
            'created_at'  => now(),
        ]);
    }

    private function forget(string $type, string $id): void
    {
        DB::table('sample_data_records')->where('record_type', $type)->where('record_id', $id)->delete();
    }

    /** Bản ghi mẫu đã bị xoá bằng tay từ trước → bỏ khỏi danh sách theo dõi. */
    private function forgetMissing(string $type, array $ids, callable $exists): void
    {
        foreach ($ids as $id) {
            if (! $exists($id)) {
                $this->forget($type, (string) $id);
            }
        }
    }

    /** Khung giờ riêng: bắt đầu 06:00–19:50 (bước 10 phút), dài 2–3 tiếng; thêm 1 khung qua đêm. */
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

            $slot = TimeSlot::create([
                'partner_id' => $partnerId,
                'start_time' => $start,
                'end_time'   => $end,
                'over_night' => $overNight,
                'label'      => "{$start} - {$end}" . ($overNight ? ' (Qua đêm)' : ''),
                'type'       => 'time',
            ]);
            $this->track('time_slot', (string) $slot->id);

            $taken["{$start}-{$end}"] = true;
            $created++;
        }

        return $created;
    }

    /** Bảng giá mẫu riêng: 3–5 khung giờ ngẫu nhiên, giá 100k–300k (qua đêm 250k–450k). */
    private function makeTemplates(string $partnerId, int $count): Collection
    {
        $slots = TimeSlot::usableBy($partnerId)
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'date'))
            ->orderBy('start_time')
            ->get()
            // Bảng time_slots có nhiều dòng trùng giờ — mỗi giờ chỉ lấy 1 dòng.
            ->unique(fn (TimeSlot $t) => substr((string) $t->start_time, 0, 5) . '-' . substr((string) $t->end_time, 0, 5))
            ->values();

        if ($slots->count() < 3) {
            return collect();
        }

        $names = $this->freshNames(
            self::TEMPLATE_NAMES,
            PriceTemplate::where('partner_id', $partnerId)->pluck('name')
        );

        $created = collect();

        foreach (array_slice($names, 0, $count) as $name) {
            $picked = $slots->shuffle()->take(random_int(3, min(5, $slots->count())))->sortBy('start_time')->values();

            $template = PriceTemplate::create([
                'partner_id' => $partnerId,
                'name'       => $name,
                'note'       => 'Dữ liệu mẫu',
                'is_active'  => true,
                'sort_order' => 0,
            ]);
            $this->track('price_template', (string) $template->id);

            $this->templates->replaceSlots($template, $picked->map(fn (TimeSlot $t) => [
                'timeslot_id' => $t->id,
                'price'       => $this->randomPrice($t->over_night ? 250000 : 100000, $t->over_night ? 450000 : 300000, 10000),
                'over_night'  => (bool) $t->over_night,
            ])->all());

            $created->push($template);
        }

        return $created;
    }

    private function makeTags(string $partnerId, int $count): int
    {
        $locale = app()->getLocale();
        $taken  = Tag::usableBy($partnerId)->get()->map(fn (Tag $t) => $t->getTranslation('name', $locale, false));

        $created = 0;
        foreach (array_slice($this->freshNames(self::AMENITIES, $taken), 0, $count) as $name) {
            $tag = Tag::create(['name' => $name, 'type' => 'product', 'partner_id' => $partnerId]);
            $this->track('tag', (string) $tag->id);
            $created++;
        }

        return $created;
    }

    /** Dịch vụ gắn cho chi nhánh. Tên trùng dịch vụ sẵn có của đối tác → bỏ qua. */
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
            $this->track('service', (string) $service->id);
            $created++;
        }

        if ($created) {
            $this->branchServices->syncBranches([$branch->id]);
        }

        return $created;
    }

    /**
     * Phòng theo khung giờ trong chi nhánh: gắn 3–6 tiện ích, điều kiện giảm giá (đặt cả ngày, theo số
     * khung giờ, phụ thu khách), áp 1 bảng giá mẫu — chưa có mẫu nào dùng được thì tạo luôn 1 mẫu, để
     * phòng luôn có khung giờ và giá.
     *
     * @return array{0: Collection, 1: ?PriceTemplate} phòng vừa tạo + mẫu đã áp
     */
    private function makeRooms(string $partnerId, Category $branch, int $count): array
    {
        if ($count < 1) {
            return [collect(), null];
        }

        $template = PriceTemplate::usableBy($partnerId)
            ->where('is_active', true)
            ->whereHas('slots')
            ->orderByRaw('partner_id IS NULL')
            ->inRandomOrder()
            ->first()
            ?? $this->makeTemplates($partnerId, 1)->first();

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
                'partner_id'            => $partnerId,
                'name'                  => $name,
                'slug'                  => $this->uniqueSlug($name),
                'styles'                => 1,
                'is_activated'          => true,
                'is_in_stock'           => true,
                // Cùng định dạng dữ liệu thật: "10%" = giảm % khi đặt hết khung giờ trong ngày.
                'full_booking_discount' => (5 * random_int(2, 6)) . '%',
                'bulk_discount_rules'   => [
                    ['slots' => 2, 'discount' => random_int(1, 2) * 5],
                    ['slots' => 3, 'discount' => random_int(2, 3) * 5],
                ],
                'room_config'           => [
                    'max_free_guests' => 2,
                    'extra_guest_fee' => $this->randomPrice(30000, 100000, 10000),
                ],
            ]);
            $room->categories()->attach($branch->id);
            $this->track('room', (string) $room->id);

            if ($tagIds->isNotEmpty()) {
                $room->tags()->sync($tagIds->shuffle()->take(random_int(3, 6))->all());
            }

            $this->branchServices->syncRoom($room);

            $taken[Str::lower($name)] = true;
            $rooms->push($room);
        }

        if ($template && $rooms->isNotEmpty() && ! $this->templates->applyError($template, $rooms)) {
            $this->templates->apply($template, $rooms, true);
        } else {
            $template = null;
        }

        return [$rooms, $template];
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
