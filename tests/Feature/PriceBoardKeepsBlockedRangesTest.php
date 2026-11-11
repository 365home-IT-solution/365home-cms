<?php

namespace Tests\Feature;

use App\Services\PriceBoardSyncService;
use App\Support\ApprovalContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Product\App\Models\PriceBoardItem;
use Modules\Product\App\Models\Product;
use Tests\TestCase;

// Job price-boards:sync-due (00:05 mỗi đêm) áp bảng giá xuống MỌI phòng — không được xoá ngày khoá
// phòng theo ngày (room_config.blocked_ranges) admin đã đặt.
class PriceBoardKeepsBlockedRangesTest extends TestCase
{
    use DatabaseTransactions;

    public function test_nightly_price_board_sync_keeps_blocked_ranges(): void
    {
        $room = Product::withoutGlobalScopes()->where('styles', 2)->first();
        if (! $room) {
            $this->markTestSkipped('Cần ít nhất 1 phòng theo ngày.');
        }

        $service = app(PriceBoardSyncService::class);

        $item = PriceBoardItem::where('price_board_id', $service->defaultBoard()->id)
            ->where('product_id', $room->id)->first()
            ?? $service->seedDefaultBoard($room);

        // Bảng giá chỉ mang cấu hình giá, không có blocked_ranges (đúng như dữ liệu thật).
        $item->update(['room_config' => ['max_free_guests' => 3, 'extra_guest_fee' => 50000]]);

        $ranges = [['start' => now()->addDay()->toDateString(), 'end' => now()->addDays(3)->toDateString()]];
        ApprovalContext::bypass(fn () => $room->update([
            'room_config' => ['max_free_guests' => 2, 'extra_guest_fee' => 0, 'blocked_ranges' => $ranges],
        ]));

        $this->artisan('price-boards:sync-due')->assertSuccessful();

        $config = Product::withoutGlobalScopes()->find($room->id)->room_config;

        $this->assertSame($ranges, $config['blocked_ranges'] ?? null, 'Ngày khoá bị bảng giá xoá mất.');
    }

    // Form "Cài đặt đặt phòng" (SettingBook) / API sửa cấu hình giá dựng room_config mới không có blocked_ranges.
    public function test_saving_room_config_without_blocked_ranges_keeps_them(): void
    {
        $room = Product::withoutGlobalScopes()->where('styles', 2)->first();
        if (! $room) {
            $this->markTestSkipped('Cần ít nhất 1 phòng theo ngày.');
        }

        $ranges = [['start' => now()->addDay()->toDateString(), 'end' => now()->addDays(2)->toDateString()]];
        ApprovalContext::bypass(fn () => $room->update(['room_config' => ['max_free_guests' => 2, 'blocked_ranges' => $ranges]]));

        ApprovalContext::bypass(fn () => $room->fresh()->update(['room_config' => ['max_free_guests' => 4, 'extra_guest_fee' => 10000]]));
        $config = Product::withoutGlobalScopes()->find($room->id)->room_config;
        $this->assertSame($ranges, $config['blocked_ranges'] ?? null);
        $this->assertSame(4, $config['max_free_guests']);

        // Mở khoá hết (gửi [] chủ động) vẫn phải xoá được.
        ApprovalContext::bypass(fn () => $room->fresh()->update(['room_config' => ['max_free_guests' => 4, 'blocked_ranges' => []]]));
        $this->assertSame([], Product::withoutGlobalScopes()->find($room->id)->room_config['blocked_ranges']);
    }
}
