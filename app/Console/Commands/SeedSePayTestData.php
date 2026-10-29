<?php

namespace App\Console\Commands;

use App\Models\Partner;
use App\Services\BranchServiceSync;
use App\Services\PriceBoardSyncService;
use App\Services\RoomSlotRandomizer;
use App\Support\ApprovalContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;

/**
 * Dữ liệu mẫu để thử thanh toán SePay với số tiền nhỏ: 1 chi nhánh, 1 phòng THEO KHUNG GIỜ (mỗi khung 1.000đ) và
 * 1 phòng THEO NGÀY (1.000đ/đêm), thanh toán đủ 100% (không cọc) để chỉ cần chuyển 1 lần. Chạy lại không sinh trùng.
 */
class SeedSePayTestData extends Command
{
    protected $signature = 'sample:sepay-test {partner : ID đối tác hoặc tên đối tác (vd TestEmail)} {--price=1000 : Giá mỗi khung / mỗi đêm (VND)}';

    protected $description = 'Tạo chi nhánh + phòng giá 1.000đ để thử thanh toán SePay';

    private const BRANCH = 'Test SePay';

    private const HOURLY = 'Test SePay - theo khung giờ';

    private const DAILY = 'Test SePay - theo ngày';

    public function handle(PriceBoardSyncService $boards, BranchServiceSync $branchServices, RoomSlotRandomizer $slots): int
    {
        $key = (string) $this->argument('partner');
        $partner = Partner::query()->whereKey($key)->first()
            ?? Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where(fn ($q) => $q->where('name', $key)->orWhere('legal_name', $key))->first();

        if (! $partner) {
            $this->error("Không tìm thấy đối tác \"{$key}\".");

            return self::FAILURE;
        }

        $price = max(1000, (int) $this->option('price'));

        try {
            DB::transaction(fn () => ApprovalContext::bypass(fn () => $this->seed($partner, $price, $boards, $branchServices, $slots)));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function seed(Partner $partner, int $price, PriceBoardSyncService $boards, BranchServiceSync $branchServices, RoomSlotRandomizer $slots): void
    {
        $branch = Category::withoutGlobalScopes()->where('partner_id', $partner->id)->whereNull('parent_id')->where('category_type', 'product')->where('name', self::BRANCH)->first()
            ?? Category::withoutGlobalScopes()->forceCreate([
                'name' => self::BRANCH, 'slug' => 'test-sepay-' . Str::lower(Str::random(5)), 'category_type' => 'product', 'status' => 1, 'partner_id' => $partner->id,
                'checkin_time' => '14:00', 'checkout_time' => '12:00',
            ]);

        // ── Phòng theo khung giờ: 3 khung ban ngày, mỗi khung $price ──────────
        if ($this->exists($partner, self::HOURLY)) {
            $this->line('Bỏ qua (đã có): ' . self::HOURLY);
        } else {
            $room = $this->room($partner, $branch, ['name' => self::HOURLY, 'styles' => 1, 'room_config' => ['max_free_guests' => 2, 'extra_guest_fee' => 0]]);

            foreach ([['08:00', '09:00'], ['10:00', '11:00'], ['13:00', '14:00']] as [$start, $end]) {
                $slot = $slots->findOrCreateSlot((string) $partner->id, $start, $end, false);
                RoomTimeSlot::create(['room_id' => $room->id, 'timeslot_id' => $slot->id, 'price' => $price, 'over_night' => false, 'status' => 'available']);
            }

            $branchServices->syncRoom($room);
            $boards->seedDefaultBoard($room->fresh());
            $this->info(self::HOURLY . " — 3 khung (08:00-09:00, 10:00-11:00, 13:00-14:00), mỗi khung {$price}đ");
        }

        // ── Phòng theo ngày: $price/đêm, thanh toán đủ 100% ──────────────────
        if ($this->exists($partner, self::DAILY)) {
            $this->line('Bỏ qua (đã có): ' . self::DAILY);
        } else {
            $room = $this->room($partner, $branch, [
                'name' => self::DAILY, 'styles' => 2, 'price' => $price, 'default_checkin' => '14:00', 'default_checkout' => '12:00',
                'deposit_1_night' => 100, 'deposit_multi_night' => 100, 'deposit_min_nights' => 1,
                'room_config' => ['max_free_guests' => 2, 'extra_guest_fee' => 0],
            ]);

            $branchServices->syncRoom($room);
            $boards->seedDefaultBoard($room->fresh());
            $this->info(self::DAILY . " — {$price}đ/đêm, nhận 14:00, trả 12:00, thanh toán đủ 100%");
        }

        $this->line("Chi nhánh: {$branch->name} (#{$branch->id}) — đối tác {$partner->name}");
    }

    private function exists(Partner $partner, string $name): bool
    {
        return Product::withoutGlobalScopes()->where('partner_id', $partner->id)->where('name', $name)->exists();
    }

    private function room(Partner $partner, Category $branch, array $attrs): Product
    {
        $room = Product::create($attrs + [
            'partner_id' => $partner->id, 'slug' => Str::slug($attrs['name']) . '-' . Str::lower(Str::random(5)),
            'is_activated' => true, 'is_in_stock' => true,
        ]);
        $room->categories()->attach($branch->id);

        return $room;
    }
}
