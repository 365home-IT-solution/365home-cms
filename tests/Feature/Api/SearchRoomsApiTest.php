<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Modules\Product\App\Models\Product;
use Tests\TestCase;

// GET /api/search?ver=1.1.0 — bản mới trả PHÒNG (q, date một ngày, guests, trộn theo giờ/theo ngày); không có ver thì giữ hành vi cũ.
class SearchRoomsApiTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/search?ver=1.1.0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_keyword_returns_rooms_not_branches(): void
    {
        $room = Product::where('is_activated', true)->where('is_in_stock', true)->activeBranch()->first();
        if (! $room) {
            $this->markTestSkipped('Cần ít nhất 1 phòng đang bật.');
        }

        $res = $this->getJson(self::URL . '&q=' . urlencode(mb_substr($room->name, 0, 4)))->assertOk();

        $this->assertNotEmpty($res->json('data'));
        $this->assertArrayHasKey('room_style', $res->json('data.0'));
        $this->assertArrayNotHasKey('room_count', $res->json('data.0'));
        $this->assertArrayHasKey('last_page', $res->json('meta'));
    }

    public function test_without_ver_keyword_still_returns_branches(): void
    {
        $res = $this->getJson('/api/v1/search?q=can')->assertOk();

        if ($res->json('data.0')) {
            $this->assertArrayHasKey('room_count', $res->json('data.0'));
        }
    }

    // ver chọn theo số phiên bản: 1 / không hợp lệ = bản cũ; 1.1, 1.2, 2 = bản mới.
    public function test_ver_selects_behavior_by_version_number(): void
    {
        foreach (['1', 'abc'] as $ver) {
            $first = $this->getJson("/api/v1/search?q=can&ver={$ver}")->assertOk()->json('data.0');
            if ($first) {
                $this->assertArrayHasKey('room_count', $first, "ver={$ver} phải là bản cũ (chi nhánh).");
            }
        }

        foreach (['1.1', '1.1.0', '1.2.0', '2'] as $ver) {
            $first = $this->getJson("/api/v1/search?q=can&ver={$ver}")->assertOk()->json('data.0');
            if ($first) {
                $this->assertArrayHasKey('room_style', $first, "ver={$ver} phải trả phòng.");
            }
        }
    }

    public function test_past_date_is_rejected(): void
    {
        $this->getJson(self::URL . '&date=' . now()->subDays(2)->toDateString())->assertStatus(422);
    }

    public function test_date_excludes_day_room_blocked_that_night(): void
    {
        $room = Product::where('styles', 2)->where('is_activated', true)->where('is_in_stock', true)
            ->where('is_variant', false)->activeBranch()->first();
        if (! $room) {
            $this->markTestSkipped('Cần 1 phòng theo ngày đang bật.');
        }

        $date = now()->addDays(40)->toDateString();
        $cfg  = $room->room_config ?? [];
        $cfg['blocked_ranges'] = [['start' => $date, 'end' => $date]];
        Product::withoutGlobalScopes()->whereKey($room->id)->update(['room_config' => json_encode($cfg)]);

        $ids = collect($this->getJson(self::URL . "&date={$date}&per_page=100")->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($room->id), 'Phòng bị khoá đêm này không được xuất hiện.');

        $other = now()->addDays(42)->toDateString();
        $ids   = collect($this->getJson(self::URL . "&date={$other}&per_page=100")->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($room->id), 'Đêm khác vẫn phải còn.');
    }

    public function test_guests_filter_narrows_results(): void
    {
        $all  = $this->getJson(self::URL)->assertOk()->json('meta.total');
        $many = $this->getJson(self::URL . '&guests=50')->assertOk()->json('meta.total');

        $this->assertLessThan($all, $many);
    }

    public function test_without_coordinates_results_interleave_styles(): void
    {
        $styles = collect($this->getJson(self::URL . '&per_page=20')->assertOk()->json('data'))->pluck('room_style')->unique();

        if (Product::where('styles', 1)->where('is_activated', true)->exists() && Product::where('styles', 2)->where('is_activated', true)->exists()) {
            $this->assertGreaterThan(1, $styles->count(), 'Trang 1 phải có cả phòng theo giờ lẫn theo ngày.');
        }
    }
}
