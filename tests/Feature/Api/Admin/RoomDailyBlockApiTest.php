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

        // Lịch giá theo ngày (/date-pricing) cũng phải thấy ngày khoá.
        $pricing = $this->withHeaders($headers)
            ->getJson("/api/admin/rooms/{$room->id}/date-pricing?from={$from}&to=" . now()->addDays(13)->toDateString())
            ->assertOk();
        $p = collect($pricing->json('dates'))->pluck('blocked', 'date');
        $this->assertTrue($p[$from]);
        $this->assertFalse($p[now()->addDays(13)->toDateString()]);
        $this->assertContains(['start' => $from, 'end' => $to, 'rooms' => null], $pricing->json('blocked_ranges'));

        // GET /rooms/{id}/block — màn "Khoá phòng" đọc danh sách đang khoá.
        $list = $this->withHeaders($headers)->getJson("/api/admin/rooms/{$room->id}/block?from={$from}&to={$to}")->assertOk();
        $this->assertSame([$from, now()->addDays(11)->toDateString(), $to], $list->json('data.blocked_dates'));
        $this->assertContains(['start' => $from, 'end' => $to, 'rooms' => null], $list->json('data.blocked_ranges'));
    }

    // Mở khoá 1 phần khoảng (không khớp chính xác start/end) → cắt đúng ngày đó, giữ phần còn lại; khoảng trùng nhau gỡ hết.
    public function test_unblock_partial_and_overlapping_ranges(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->mock(SlotRealtimeService::class)->shouldIgnoreMissing();

        $room = Product::withoutGlobalScopes()->where('styles', 2)->where('is_activated', true)->where('is_variant', false)->first();
        if (! $room) {
            $this->markTestSkipped('Cần 1 phòng theo ngày đang bật.');
        }

        $token   = User::role('super_admin')->first()->createToken('t')->plainTextToken;
        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json', 'X-Partner-Id' => $room->partner_id];
        $d       = fn (int $i) => now()->addDays($i)->toDateString();
        $url     = "/api/admin/rooms/{$room->id}/block";

        // 2 khoảng trùng nhau (khoá trên web rồi khoá lại trên app): 20→23 và 20→23.
        $this->withHeaders($headers)->postJson($url, ['date_from' => $d(20), 'date_to' => $d(23)])->assertOk();
        $this->withHeaders($headers)->postJson($url, ['date_from' => $d(20), 'date_to' => $d(23)])->assertOk();

        // Mở 21→22 → còn 20 và 23.
        $this->withHeaders($headers)->deleteJson($url, ['date_from' => $d(21), 'date_to' => $d(22)])->assertOk();

        $days = collect($this->withHeaders($headers)->getJson("/api/admin/rooms/{$room->id}/dates?from={$d(20)}&to={$d(25)}")->json('dates'))
            ->pluck('blocked', 'date');
        $this->assertSame([true, false, false, true, false], [$days[$d(20)], $days[$d(21)], $days[$d(22)], $days[$d(23)], $days[$d(24)]]);

        // Mở cả 20→23 → hết khoá; mở lần nữa → 404.
        $this->withHeaders($headers)->deleteJson($url, ['date_from' => $d(20), 'date_to' => $d(23)])->assertOk();
        $this->assertSame([], collect(Product::withoutGlobalScopes()->find($room->id)->room_config['blocked_ranges'])
            ->filter(fn ($r) => $r['end'] >= $d(20) && $r['start'] <= $d(23))->values()->all());
        $this->withHeaders($headers)->deleteJson($url, ['date_from' => $d(20), 'date_to' => $d(23)])->assertNotFound();
    }
}
