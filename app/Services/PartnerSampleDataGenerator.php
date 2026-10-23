<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use RuntimeException;

/**
 * DỮ LIỆU MẪU cho 1 đối tác + 1 chi nhánh — dùng cho API POST /api/admin/sample-data (đối tác tự bấm)
 * và trang quản trị "Dữ liệu mẫu" (super_admin tạo hộ).
 *
 * generate(): tiện ích riêng, dịch vụ đi kèm của chi nhánh, khung giờ riêng, phòng theo khung giờ trong
 * chi nhánh (mỗi phòng có khung giờ + giá + điều kiện giảm giá — đặt được ngay) và 1 bảng giá của chi
 * nhánh chứa các phòng đó. Cấu trúc khung giờ + giá lấy theo 1 phòng sẵn có của chi nhánh (ưu tiên
 * phòng có khung qua đêm), giá mỗi phòng lệch ngẫu nhiên quanh giá mẫu; chi nhánh chưa có phòng nào
 * thì dựng từ các khung giờ đối tác dùng được. Mục trùng (cùng giờ, cùng tên) bị bỏ qua → chạy lại
 * không sinh trùng.
 *
 * Không có "xoá dữ liệu mẫu": dữ liệu sinh ra là của đối tác, họ sửa tiếp rồi dùng thật.
 */
class PartnerSampleDataGenerator
{
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

    public function __construct(
        private PriceBoardSyncService $priceBoards,
        private BranchServiceSync $branchServices,
        private RoomSlotRandomizer $slots,
    ) {
    }

    /**
     * @param array{time_slots?:?int, tags?:int, services?:int, rooms?:int} $counts — time_slots = số khung giờ MỖI PHÒNG
     *        (null = theo phòng tham chiếu của chi nhánh, hoặc mặc định)
     * @return array{counts: array<string,int>, rooms: list<array>, price_board: ?array, reference_room: ?array}
     */
    public function generate(string $partnerId, int $branchId, array $counts): array
    {
        // Dữ liệu mẫu do hệ thống sinh ra → không qua duyệt (phòng mẫu vẫn tạo ở trạng thái tắt như cũ).
        return \App\Support\ApprovalContext::bypass(fn () => $this->doGenerate($partnerId, $branchId, $counts));
    }

    private function doGenerate(string $partnerId, int $branchId, array $counts): array
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
            $tags      = $this->makeTags($partnerId, (int) ($counts['tags'] ?? 0));
            $services  = $this->makeServices($partnerId, $branch, (int) ($counts['services'] ?? 0));

            $slotCount = isset($counts['time_slots']) && $counts['time_slots'] !== '' ? (int) $counts['time_slots'] : null;

            [$rooms, $board, $reference, $newSlots] = $this->makeRooms($partnerId, $branch, (int) ($counts['rooms'] ?? 0), $slotCount);

            return [
                'counts' => [
                    'time_slots'   => $newSlots,
                    'tags'         => $tags,
                    'services'     => $services,
                    'rooms'        => $rooms->count(),
                    'price_boards' => $board ? 1 : 0,
                ],
                'rooms' => $rooms->map(fn (Product $r) => [
                    'id'                    => $r->id,
                    'name'                  => $r->name,
                    'full_booking_discount' => $r->full_booking_discount,
                    'bulk_discount_rules'   => $r->bulk_discount_rules,
                ])->values()->all(),
                'price_board'    => $board ? ['id' => $board->id, 'name' => $board->name, 'branch_id' => $board->category_id] : null,
                'reference_room' => $reference ? ['id' => $reference->id, 'name' => $reference->name] : null,
            ];
        });
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
            $created++;
        }

        if ($created) {
            $this->branchServices->syncBranches([$branch->id]);
        }

        return $created;
    }

    /**
     * Phòng theo khung giờ trong chi nhánh: gắn 3–6 tiện ích, điều kiện giảm giá (đặt cả ngày, theo số
     * khung giờ, phụ thu khách), khung giờ + giá theo cấu trúc của 1 phòng tham chiếu, rồi gom các
     * phòng vào 1 bảng giá của chi nhánh.
     *
     * @return array{0: Collection, 1: ?PriceBoard, 2: ?Product, 3: int} phòng vừa tạo, bảng giá, phòng tham chiếu,
     *                                                                   số khung giờ mới phải tạo
     */
    private function makeRooms(string $partnerId, Category $branch, int $count, ?int $slotCount): array
    {
        if ($count < 1) {
            return [collect(), null, null, 0];
        }

        [$structure, $reference, $newSlots] = $this->slotStructure($partnerId, $branch, $slotCount);

        if ($structure === []) {
            throw new RuntimeException('Chưa có khung giờ nào để tạo phòng — tăng số khung giờ hoặc tạo khung giờ trước.');
        }

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

            if ($tagIds->isNotEmpty()) {
                $room->tags()->sync($tagIds->shuffle()->take(random_int(3, 6))->all());
            }

            // Mỗi phòng lấy ngẫu nhiên 1 phần khung giờ của cấu trúc mẫu, giá từng khung lệch ±10%.
            // Theo phòng tham chiếu: mỗi phòng lấy 1 phần khung giờ; đối tác tự chọn số khung: đủ số khung.
            foreach ($reference ? $this->pickSlots($structure) : $structure as $slot) {
                RoomTimeSlot::create([
                    'room_id'     => $room->id,
                    'timeslot_id' => $slot['timeslot_id'],
                    'price'       => max(50000, intdiv((int) round($slot['price'] * random_int(90, 110) / 100), 5000) * 5000),
                    'over_night'  => $slot['over_night'],
                    'status'      => 'available',
                ]);
            }

            $this->branchServices->syncRoom($room);
            // Điểm khôi phục khi sau này có bảng giá hết hạn — giống lúc đối tác lưu giá ở "Hệ thống giá".
            $this->priceBoards->seedDefaultBoard($room->fresh());

            $taken[Str::lower($name)] = true;
            $rooms->push($room);
        }

        $board = $rooms->isEmpty() ? null : $this->makeBoard($partnerId, $branch, $rooms);

        return [$rooms, $board, $reference, $newSlots];
    }

    /**
     * Lấy ngẫu nhiên 3..N khung giờ của cấu trúc mẫu (đủ N nếu mẫu ≤ 3 khung), giữ thứ tự giờ.
     * Khung qua đêm của mẫu luôn được giữ để phòng nào cũng có lựa chọn qua đêm như phòng tham chiếu.
     *
     * @param list<array{timeslot_id:int,price:int,over_night:bool}> $structure
     * @return list<array{timeslot_id:int,price:int,over_night:bool}>
     */
    private function pickSlots(array $structure): array
    {
        $total = count($structure);

        if ($total <= 3) {
            return $structure;
        }

        $keep      = random_int(3, $total);
        $overNight = array_keys(array_filter($structure, fn ($s) => $s['over_night']));
        $chosen    = array_slice($overNight, 0, 1);
        $others    = array_values(array_diff(array_keys($structure), $chosen));
        shuffle($others);
        $chosen = array_merge($chosen, array_slice($others, 0, $keep - count($chosen)));
        sort($chosen);

        return array_map(fn ($i) => $structure[$i], $chosen);
    }

    /**
     * Cấu trúc khung giờ + giá để sao cho phòng mới: lấy theo 1 phòng theo khung giờ sẵn có của chi
     * nhánh (ưu tiên phòng có khung qua đêm, rồi phòng nhiều khung giờ nhất). Đối tác chọn số khung,
     * hoặc chi nhánh chưa có phòng, thì dựng bộ khung giờ mới (RoomSlotRandomizer).
     *
     * @return array{0: list<array{timeslot_id:int,price:int,over_night:bool}>, 1: ?Product, 2: int}
     */
    private function slotStructure(string $partnerId, Category $branch, ?int $slotCount): array
    {
        // Đối tác chọn số khung → dựng bộ khung giờ mới theo quy tắc (sáng → tối + qua đêm).
        if ($slotCount !== null) {
            $picked = $this->slots->pick($partnerId, $slotCount);

            return [$picked['slots'], null, $picked['created']];
        }

        $candidates = Product::withoutGlobalScopes()
            ->where('partner_id', $partnerId)
            ->where('styles', 1)
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', PriceBoard::branchCategoryIds((int) $branch->id)))
            ->with(['roomTimeSlots' => fn ($q) => $q->whereNull('date')->whereNotNull('price')->with('timeSlot')])
            ->get()
            ->map(function (Product $room) {
                // Khung giờ ngày đặc biệt (type=date) không thuộc cấu trúc khung giờ tái sử dụng.
                $room->setRelation('roomTimeSlots', $room->roomTimeSlots
                    ->filter(fn ($s) => $s->timeSlot && $s->timeSlot->type !== 'date')
                    ->unique('timeslot_id')
                    ->values());

                return $room;
            })
            ->filter(fn (Product $room) => $room->roomTimeSlots->isNotEmpty());

        $reference = $candidates
            ->sortByDesc(fn (Product $room) => [
                (int) $room->roomTimeSlots->contains(fn ($s) => $s->over_night || $s->timeSlot->over_night),
                $room->roomTimeSlots->count(),
            ])
            ->first();

        if ($reference) {
            return [
                $reference->roomTimeSlots
                    ->sortBy(fn ($s) => $s->timeSlot->start_time)
                    ->map(fn ($s) => [
                        'timeslot_id' => (int) $s->timeslot_id,
                        'price'       => (int) $s->price,
                        'over_night'  => (bool) ($s->over_night || $s->timeSlot->over_night),
                    ])->values()->all(),
                $reference,
                0,
            ];
        }

        // Chi nhánh chưa có phòng và đối tác không chọn số khung: dựng bộ mặc định.
        $picked = $this->slots->pick($partnerId, RoomSlotRandomizer::DEFAULT_COUNT);

        return [$picked['slots'], null, $picked['created']];
    }

    /**
     * Bảng giá của chi nhánh chứa các phòng vừa tạo, giá = giá đang có ở từng phòng. Tạo ở trạng thái
     * TẮT: giá đã nằm sẵn ở phòng (đặt được ngay); bảng không có ngày hiệu lực nên bật lên là đè giá
     * phòng vĩnh viễn — đối tác bật khi muốn dùng.
     */
    private function makeBoard(string $partnerId, Category $branch, Collection $rooms): PriceBoard
    {
        $base = 'Bảng giá ' . $branch->name;
        $name = $base;
        for ($i = 2; PriceBoard::where('partner_id', $partnerId)->where('name', $name)->exists(); $i++) {
            $name = "{$base} ({$i})";
        }

        // Bảng giá của chi nhánh dựng từ bảng dựng sẵn "standard" (phạm vi = chi nhánh này, vẫn TẮT) —
        // phòng mới tạo sau cũng nhận bộ giá. Chưa có preset (DB cũ) thì rơi về cách v1 bên dưới.
        $preset = PriceBoard::query()->where('is_template', true)->where('code', 'standard')->first();

        if ($preset) {
            $v2    = app(\App\Services\PriceBoards\PriceBoardV2Service::class);
            $clone = $v2->cloneBoard($preset, $partnerId, [
                'name'        => $name,
                'note'        => 'Dữ liệu mẫu',
                'is_active'   => false,
                'scope'       => PriceBoard::SCOPE_BRANCHES,
                'branch_ids'  => [$branch->id],
                'source_preset' => 'standard',
            ], resolvePresetTimes: true);

            return $v2->persist($clone['spec'], null);
        }

        $board = PriceBoard::create([
            'partner_id'   => $partnerId,
            'category_id'  => $branch->id,
            'name'         => $name,
            'note'         => 'Dữ liệu mẫu',
            'is_active'    => false,
            'is_default'   => false,
            'pricing_mode' => PriceBoard::MODE_OVERRIDE,
        ]);

        $this->priceBoards->saveItems($board, ['items' => $rooms->map(fn (Product $room) => [
            'product_id'                  => $room->id,
            'full_booking_discount'       => $room->full_booking_discount,
            'bulk_discount_rules'         => $room->bulk_discount_rules,
            'room_config_max_free_guests' => $room->room_config['max_free_guests'] ?? 2,
            'room_config_extra_guest_fee' => $room->room_config['extra_guest_fee'] ?? 0,
            'roomTimeSlots'               => $room->roomTimeSlots()->whereNull('date')->get()->map(fn (RoomTimeSlot $s) => [
                'timeslot_id' => $s->timeslot_id,
                'price'       => (int) $s->price,
                'over_night'  => (bool) $s->over_night,
                'status'      => 'available',
            ])->all(),
        ])->all()]);

        return $board;
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
