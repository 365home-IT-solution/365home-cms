<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use App\Services\SlotRealtimeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Modules\Product\App\Models\Product;
use Tests\TestCase;

// App admin: khoá phòng theo ngày (styles=2) qua POST /rooms/{id}/block rồi đọc lại GET /rooms/{id}/dates phải thấy ngày đã khoá.
class RoomDailyBlockApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_blocked_range_shows_in_admin_dates(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->mock(SlotRealtimeService::class)->shouldIgnoreMissing();

        $room = Product::withoutGlobalScopes()->where('styles', 2)->where('is_activated', true)->where('is_variant', false)->first();
        if (! $room) {
            $this->markTestSkipped('Cần 1 phòng theo ngày đang bật.');
        }

        $token   = User::role('super_admin')->first()->createToken('t')->plainTextToken;
        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json', 'X-Partner-Id' => $room->partner_id];

        $from = now()->addDays(10)->toDateString();
        $to   = now()->addDays(12)->toDateString();

        $this->withHeaders($headers)->postJson("/api/admin/rooms/{$room->id}/block", ['date_from' => $from, 'date_to' => $to])->assertOk();

        $res = $this->withHeaders($headers)
            ->getJson("/api/admin/rooms/{$room->id}/dates?from={$from}&to=" . now()->addDays(14)->toDateString())
            ->assertOk();

        $byDate = collect($res->json('dates'))->keyBy('date');
        foreach ([10, 11, 12] as $i) {
            $d = now()->addDays($i)->toDateString();
            $this->assertTrue($byDate[$d]['blocked'], "Ngày {$d} phải hiện là đã khoá.");
            $this->assertFalse($byDate[$d]['available']);
        }
        $this->assertFalse($byDate[now()->addDays(13)->toDateString()]['blocked']);
        $this->assertContains(['start' => $from, 'end' => $to, 'rooms' => null], $res->json('blocked_ranges'));
    }
}
