<?php

namespace App\Console\Commands;

use App\Models\Tag;
use App\Models\User;
use App\Services\BranchServiceSync;
use App\Services\PriceBoards\PriceBoardV2Service;
use App\Services\PriceBoardSyncService;
use App\Services\RoomSlotRandomizer;
use App\Support\ApprovalContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use RuntimeException;

/**
 * Dữ liệu mẫu RinBon Home cho 1 đối tác: 2 chi nhánh, 2 loại phòng.
 *  - "Thung Lũng Mơ": 6 phòng THEO KHUNG GIỜ (mỗi phòng khung giờ + giá riêng, có "Giá cả ngày").
 *  - "RinBon Home": 8 phòng THEO NGÀY, giá T2–T5 / T6–CN (bảng giá theo thứ), nhận 14:00 – trả 12:00.
 * Chạy lại không sinh trùng (trùng tên chi nhánh / phòng / tiện ích / dịch vụ thì dùng lại).
 */
class SeedRinbonSampleData extends Command
{
    protected $signature = 'sample:rinbon {partner : ID đối tác} {--dry-run : Tạo thử rồi huỷ, không lưu}';

    protected $description = 'Tạo dữ liệu mẫu RinBon Home (2 chi nhánh, phòng theo giờ + theo ngày, bảng giá)';

    private const HOTLINE = '0849273954';

    /** phòng theo khung giờ: [tên, [[bắt đầu, kết thúc, giá], ...], [bắt đầu, kết thúc, giá qua đêm], giá cả ngày] */
    private const HOURLY = [
        ['101 View thung lũng (phòng lớn)', [['08:10', '11:00'], ['11:30', '14:20'], ['14:50', '17:40'], ['18:10', '21:00']], 200000, ['21:30', '07:30', 280000], 450000],
        ['201 View thung lũng có ban công', [['08:30', '11:20'], ['11:50', '14:40'], ['15:10', '18:00'], ['18:30', '21:20']], 200000, ['21:50', '07:50', 280000], 450000],
        ['202 Không view', [['08:50', '11:40'], ['12:10', '15:00'], ['15:30', '18:20'], ['18:50', '21:40']], 190000, ['22:10', '08:10', 260000], 340000],
        ['501 View thung lũng', [['07:50', '10:40'], ['11:10', '14:00'], ['14:30', '17:20'], ['17:50', '20:40']], 200000, ['21:10', '07:10', 280000], 420000],
        ['503 View đồi', [['07:30', '10:20'], ['10:50', '13:40'], ['14:10', '17:00'], ['17:30', '20:20']], 200000, ['20:50', '06:50', 280000], 390000],
        ['505 Không view', [['09:50', '12:40'], ['13:10', '16:00'], ['16:30', '19:20'], ['19:50', '22:40']], 190000, ['23:10', '09:10', 260000], 340000],
    ];

    /** phòng theo ngày: [số phòng, loại, giường, giá T2–T5, giá T6–CN, ghi chú, có TV, thang máy] */
    private const DAILY = [
        ['001', 'Đơn Classic (Single Classic)', 'Giường đơn', 300000, 350000, 'Không cửa sổ, không TV.', false, true],
        ['101', 'Đôi Deluxe (Double Deluxe)', 'Giường đôi', 550000, 600000, null, true, true],
        ['102', 'Đơn Deluxe (Single Deluxe)', 'Giường đơn', 350000, 400000, null, true, true],
        ['201', 'Đôi Deluxe (Double Deluxe)', 'Giường đôi', 550000, 600000, null, true, true],
        ['202', 'Đơn Deluxe (Single Deluxe)', 'Giường đơn', 350000, 400000, null, true, true],
        ['301', 'Đơn Ban Công (Single Balcony)', 'Giường đơn', 400000, 450000, null, true, true],
        ['302', 'Đôi Classic (Double Classic)', 'Giường đôi', 500000, 550000, null, true, true],
        ['401', 'Đơn Classic (Single Classic)', 'Giường đơn', 300000, 350000, 'Lối cầu thang, WC nhỏ.', true, false],
    ];

    private const AMENITIES = ['Tivi', 'Tủ lạnh', 'Máy quạt', 'Máy sấy tóc', 'Ấm siêu tốc', 'Khăn tắm, khăn mặt', 'Bàn chải đánh răng', 'Dao cạo râu', 'Lược', 'Tăm bông', 'Miễn phí nước suối, trà, cà phê', 'Thang máy'];

    public function handle(PriceBoardSyncService $boards, BranchServiceSync $branchServices, RoomSlotRandomizer $slots, PriceBoardV2Service $v2): int
    {
        $partnerId = (string) $this->argument('partner');
        $admin     = User::role('super_admin')->first();

        if (! $admin) {
            $this->error('Không có tài khoản super_admin.');

            return self::FAILURE;
        }

        try {
            DB::beginTransaction();
            ApprovalContext::bypass(fn () => $this->seed($partnerId, $admin, $boards, $branchServices, $slots, $v2));

            if ($this->option('dry-run')) {
                DB::rollBack();
                $this->warn('Chạy thử: đã huỷ, không lưu gì.');
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function seed(string $partnerId, User $admin, PriceBoardSyncService $boards, BranchServiceSync $branchServices, RoomSlotRandomizer $slots, PriceBoardV2Service $v2): void
    {
        if (! DB::table('partners')->where('id', $partnerId)->exists()) {
            throw new RuntimeException("Không tìm thấy đối tác {$partnerId}.");
        }

        $hourlyBranch = $this->branch($partnerId, 'Thung Lũng Mơ');
        $dailyBranch  = $this->branch($partnerId, 'RinBon Home');

        $tags = [];
        foreach (self::AMENITIES as $name) {
            $tags[$name] = $this->tag($partnerId, $name);
        }

        // ── Dịch vụ đi kèm (cả 2 chi nhánh) ───────────────────────────────
        foreach ([['Thuê xe máy (120.000đ / ngày)', 120000], ['Giặt ủi (dưới 2kg)', 40000]] as [$name, $price]) {
            $service = AdditionService::withoutGlobalScopes()->firstOrCreate(
                ['partner_id' => $partnerId, 'name' => $name],
                ['price' => $price, 'is_active' => true]
            );
            $service->branches()->syncWithoutDetaching([$hourlyBranch->id, $dailyBranch->id]);
        }
        $branchServices->syncBranches([$hourlyBranch->id, $dailyBranch->id]);

        // ── Phòng theo khung giờ ──────────────────────────────────────────
        $hourlyRooms = [];
        foreach (self::HOURLY as [$name, $daySlots, $dayPrice, $night, $fullDay]) {
            if ($this->roomExists($partnerId, $name)) {
                $this->line("Bỏ qua (đã có): {$name}");
                $hourlyRooms[$name] = Product::withoutGlobalScopes()->where('partner_id', $partnerId)->where('name', $name)->first();

                continue;
            }

            $room = $this->room($partnerId, $hourlyBranch, [
                'name'              => $name,
                'styles'            => 1,
                'full_day_price'    => $fullDay,
                'short_description' => 'Giá cả ngày ' . number_format($fullDay, 0, ',', '.') . 'đ khi đặt đủ các khung giờ trong ngày.',
            ]);

            $rows = array_map(fn ($s) => [$s[0], $s[1], $dayPrice, false], $daySlots);
            $rows[] = [$night[0], $night[1], $night[2], true];

            foreach ($rows as [$start, $end, $price, $overNight]) {
                $slot = $slots->findOrCreateSlot($partnerId, $start, $end, $overNight);
                RoomTimeSlot::create(['room_id' => $room->id, 'timeslot_id' => $slot->id, 'price' => $price, 'over_night' => $overNight, 'status' => 'available']);
            }

            $hourlyRooms[$name] = $room;
            $branchServices->syncRoom($room);
            $boards->seedDefaultBoard($room->fresh());
            $this->info("Phòng theo giờ: {$name} — " . count($rows) . ' khung, cả ngày ' . number_format($fullDay, 0, ',', '.') . 'đ');
        }

        // ── Phòng theo ngày ───────────────────────────────────────────────
        $dailyRooms = [];
        foreach (self::DAILY as [$no, $type, $bed, $weekday, $weekend, $note, $hasTv, $elevator]) {
            $name = "{$no} - {$type}";

            if ($this->roomExists($partnerId, $name)) {
                $this->line("Bỏ qua (đã có): {$name}");
                $dailyRooms[$name] = [Product::withoutGlobalScopes()->where('partner_id', $partnerId)->where('name', $name)->first(), $weekday, $weekend];

                continue;
            }

            $room = $this->room($partnerId, $dailyBranch, [
                'name'              => $name,
                'styles'            => 2,
                'bed_type'          => $bed,
                'price'             => $weekday,
                'default_checkin'   => '14:00',
                'default_checkout'  => '12:00',
                'short_description' => trim(($note ?? '') . ' Nhận phòng 14:00 – trả phòng 12:00.'),
                'description'       => $this->rules($note),
            ]);

            $roomTags = collect(self::AMENITIES)
                ->reject(fn ($t) => ($t === 'Tivi' && ! $hasTv) || ($t === 'Thang máy' && ! $elevator))
                ->map(fn ($t) => $tags[$t]->id)->all();
            $room->tags()->sync($roomTags);

            $branchServices->syncRoom($room);
            $boards->seedDefaultBoard($room->fresh());
            $dailyRooms[$name] = [$room, $weekday, $weekend];
            $this->info("Phòng theo ngày: {$name} — T2–T5 " . number_format($weekday, 0, ',', '.') . 'đ, T6–CN ' . number_format($weekend, 0, ',', '.') . 'đ');
        }

        // ── Bảng giá cho phòng theo khung giờ (mỗi phòng 1 bộ giá riêng) ────
        $this->hourlyBoard($partnerId, $admin, $hourlyBranch, $hourlyRooms, $v2);

        // ── Bảng giá theo thứ (T2–T5 / T6–CN) cho phòng theo ngày ──────────
        $this->dailyBoard($partnerId, $admin, $dailyBranch, $dailyRooms, $v2);
    }

    private function hourlyBoard(string $partnerId, User $admin, Category $branch, array $rooms, PriceBoardV2Service $v2): void
    {
        $name = 'Bảng giá Thung Lũng Mơ';

        if (PriceBoard::withoutGlobalScopes()->where('partner_id', $partnerId)->where('name', $name)->exists()) {
            $this->line("Bỏ qua bảng giá (đã có): {$name}");

            return;
        }

        $byName = collect(self::HOURLY)->keyBy(0);
        $sheets = [];

        foreach ($rooms as $roomName => $room) {
            [, $daySlots, $dayPrice, $night, $fullDay] = $byName[$roomName];

            $slots = array_map(fn ($s) => ['start_time' => $s[0], 'end_time' => $s[1], 'price' => $dayPrice, 'over_night' => false], $daySlots);
            $slots[] = ['start_time' => $night[0], 'end_time' => $night[1], 'price' => $night[2], 'over_night' => true];

            $sheets[] = ['name' => $roomName, 'room_style' => 1, 'product_ids' => [(string) $room->id], 'time_slots' => $slots, 'full_day_price' => $fullDay];
        }

        $spec = $v2->specFromRequest([
            'name'       => $name,
            'note'       => 'Dữ liệu mẫu RinBon Home — khung giờ và giá từng phòng. Giá cả ngày nằm trong từng bộ giá.',
            'scope'      => PriceBoard::SCOPE_BRANCHES,
            'branch_ids' => [$branch->id],
            'is_active'  => true,
            'sheets'     => $sheets,
        ], $partnerId, $admin);

        $eval = $v2->evaluate($spec, null);

        if ($eval['errors'] || $eval['conflicts']) {
            throw new RuntimeException('Không tạo được bảng giá Thung Lũng Mơ: ' . json_encode($eval['errors'] ?: $eval['conflicts'], JSON_UNESCAPED_UNICODE));
        }

        $v2->persist($spec, null);

        // Bảng chỉ lặp lại đúng giá đã có ở phòng: kiểm tra giá từng khung không đổi.
        foreach ($rooms as $roomName => $room) {
            [, , $dayPrice, $night] = $byName[$roomName];
            $prices = RoomTimeSlot::where('room_id', $room->id)->whereNull('date')->pluck('price', 'over_night');

            if ((int) $prices[0] !== $dayPrice || (int) $prices[1] !== $night[2] || (int) Product::withoutGlobalScopes()->find($room->id)->full_day_price !== $byName[$roomName][4]) {
                throw new RuntimeException("Giá phòng {$roomName} bị đổi sau khi áp bảng giá: " . json_encode($prices));
            }
        }

        $this->info("Bảng giá: {$name} — " . count($sheets) . ' bộ giá, ' . count($rooms) . ' phòng.');
    }

    private function dailyBoard(string $partnerId, User $admin, Category $branch, array $rooms, PriceBoardV2Service $v2): void
    {
        $name = 'Bảng giá RinBon Home (T2–T5 / T6–CN)';

        if (PriceBoard::withoutGlobalScopes()->where('partner_id', $partnerId)->where('name', $name)->exists()) {
            $this->line("Bỏ qua bảng giá (đã có): {$name}");

            return;
        }

        if (! PriceBoard::phase2Enabled()) {
            $this->warn('Giai đoạn 2 của bảng giá đang TẮT nên chưa tạo được giá T6–CN: phòng đang lấy giá T2–T5. Bật ở CMS (Cài đặt → Bảng giá) hoặc PRICE_BOARDS_PHASE2=true rồi chạy lại lệnh này.');

            return;
        }

        // Gom các phòng cùng cặp giá thành 1 bộ giá.
        $groups = [];
        foreach ($rooms as [$room, $weekday, $weekend]) {
            $groups["{$weekday}-{$weekend}"][] = [$room, $weekday, $weekend];
        }

        $sheets = [];
        foreach ($groups as $group) {
            [, $weekday, $weekend] = $group[0];
            $sheets[] = [
                'name'           => 'T2–T5 ' . number_format($weekday / 1000, 0) . 'K / T6–CN ' . number_format($weekend / 1000, 0) . 'K',
                'room_style'     => 2,
                'product_ids'    => array_map(fn ($g) => (string) $g[0]->id, $group),
                'weekday_prices' => [1 => $weekday, 2 => $weekday, 3 => $weekday, 4 => $weekday, 5 => $weekend, 6 => $weekend, 7 => $weekend],
                'default_checkin' => '14:00', 'default_checkout' => '12:00',
            ];
        }

        $spec = $v2->specFromRequest([
            'name'        => $name,
            'note'        => 'Dữ liệu mẫu RinBon Home — giá T2–T5 và T6–CN theo bảng giá phòng.',
            'scope'       => PriceBoard::SCOPE_BRANCHES,
            'branch_ids'  => [$branch->id],
            'is_active'   => true,
            'sheets'      => $sheets,
        ], $partnerId, $admin);

        $eval = $v2->evaluate($spec, null);

        if ($eval['errors'] || $eval['conflicts']) {
            throw new RuntimeException('Không tạo được bảng giá: ' . json_encode($eval['errors'] ?: $eval['conflicts'], JSON_UNESCAPED_UNICODE));
        }

        $v2->persist($spec, null);

        // Kiểm tra giá từng đêm (thứ 2 và thứ 7 tới) đúng bảng giá.
        $resolver = app(\App\Services\PriceBoards\StayPriceResolver::class);
        $monday   = now()->next(\Carbon\Carbon::MONDAY)->toDateString();
        $saturday = now()->next(\Carbon\Carbon::SATURDAY)->toDateString();

        foreach ($rooms as [$room, $weekday, $weekend]) {
            $fresh = Product::withoutGlobalScopes()->find($room->id);
            $mon   = (int) $resolver->nightPrice($fresh, $monday, (float) $fresh->price);
            $sat   = (int) $resolver->nightPrice($fresh, $saturday, (float) $fresh->price);

            if ($mon !== $weekday || $sat !== $weekend) {
                throw new RuntimeException("Giá phòng {$fresh->name} sai: T2 {$mon} (cần {$weekday}), T7 {$sat} (cần {$weekend}).");
            }
        }
        $this->info("Bảng giá: {$name} — " . count($sheets) . ' bộ giá, ' . count($rooms) . ' phòng.');
    }

    private function branch(string $partnerId, string $name): Category
    {
        $existing = Category::withoutGlobalScopes()->where('partner_id', $partnerId)->whereNull('parent_id')->where('category_type', 'product')->where('name', $name)->first();

        if ($existing) {
            return $existing;
        }

        return Category::withoutGlobalScopes()->forceCreate([
            'name' => $name, 'slug' => Str::slug($name) . '-' . Str::lower(Str::random(5)),
            'category_type' => 'product', 'status' => 1, 'partner_id' => $partnerId,
            'checkin_time' => '14:00', 'checkout_time' => '12:00',
        ]);
    }

    private function tag(string $partnerId, string $name): Tag
    {
        $found = Tag::usableBy($partnerId)->get()->first(fn (Tag $t) => Str::lower($t->getTranslation('name', app()->getLocale(), false) ?? '') === Str::lower($name));

        return $found ?? Tag::create(['name' => $name, 'type' => 'product', 'partner_id' => $partnerId]);
    }

    private function roomExists(string $partnerId, string $name): bool
    {
        return Product::withoutGlobalScopes()->where('partner_id', $partnerId)->where('name', $name)->exists();
    }

    private function room(string $partnerId, Category $branch, array $attrs): Product
    {
        $room = Product::create($attrs + [
            'partner_id'   => $partnerId,
            'slug'         => Str::slug($attrs['name']) . '-' . Str::lower(Str::random(5)),
            'is_activated' => true,
            'is_in_stock'  => true,
            'hotline'      => self::HOTLINE,
        ]);
        $room->categories()->attach($branch->id);

        return $room;
    }

    private function rules(?string $note): string
    {
        return trim(($note ? "{$note}\n\n" : '')
            . "Phụ thu nhận sớm / trả muộn: dưới 3 giờ 50.000đ; 3–8 giờ 100.000đ; 8–12 giờ tính ½ ngày; trên 12 giờ tính 1 ngày (báo trước, áp dụng khi còn phòng trống).\n"
            . "Giá chưa bao gồm ngày lễ, Tết và có thể thay đổi tùy thời điểm.\n"
            . "Quy định chung: giữ yên tĩnh sau 22:00; không hút thuốc trong phòng; giữ vệ sinh chung (phòng quá bẩn sẽ phụ thu phí vệ sinh); trả lại chìa khóa khi trả phòng.");
    }
}
