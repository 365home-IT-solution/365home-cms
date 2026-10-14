<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Modules\BladeThemeV1\App\Models\AdditionService;
use Modules\Product\App\Models\PriceTemplate;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\TimeSlot;
use Tests\Concerns\CleansUpTags;
use Tests\TestCase;

// Nút "Tạo / Xoá dữ liệu mẫu" trên app: tạo đủ phòng + khung giờ + giá + giảm giá + tiện ích + dịch
// vụ; xoá đúng những gì đã tạo, giữ lại mục đang được dữ liệu thật dùng.
class SampleDataApiTest extends TestCase
{
    use CleansUpTags;
    use DatabaseTransactions;

    private string $token;

    private object $branch;

    protected function tearDown(): void
    {
        $this->cleanUpTags();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->markTags();

        $this->withoutMiddleware(ThrottleRequests::class);
        $this->token = User::role('super_admin')->first()->createToken('t')->plainTextToken;

        $this->branch = DB::table('categories as c')
            ->join('partners as p', 'p.id', '=', 'c.partner_id')
            ->whereNull('c.parent_id')->where('c.category_type', 'product')->whereNull('c.deleted_at')
            ->where('p.partner_type', 'homestay')
            ->select('c.id', 'c.partner_id')
            ->first();
    }

    private function as(?string $partnerId)
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json'];
        if ($partnerId) {
            $headers['X-Partner-Id'] = $partnerId;
        }

        return $this->withHeaders($headers);
    }

    private function create(array $body = [])
    {
        return $this->as($this->branch->partner_id)
            ->postJson('/api/admin/sample-data', array_merge(['branch_id' => $this->branch->id], $body))
            ->assertCreated();
    }

    public function test_super_admin_must_pick_a_partner(): void
    {
        $this->as(null)->postJson('/api/admin/sample-data')->assertStatus(422);
        $this->as(null)->getJson('/api/admin/sample-data')->assertStatus(422);
        $this->as(null)->deleteJson('/api/admin/sample-data')->assertStatus(422);
    }

    public function test_partner_without_branch_cannot_generate(): void
    {
        $partnerId = DB::table('partners')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('categories')
            ->whereColumn('categories.partner_id', 'partners.id')->whereNull('categories.parent_id')
            ->where('categories.category_type', 'product')->whereNull('categories.deleted_at'))->value('id');

        if (! $partnerId) {
            $this->markTestSkipped('Không có đối tác nào chưa có chi nhánh.');
        }

        $this->as($partnerId)->getJson('/api/admin/sample-data')->assertOk()->assertJsonPath('data.can_generate', false);
        $this->as($partnerId)->postJson('/api/admin/sample-data')->assertStatus(422);
    }

    public function test_generates_bookable_rooms_with_prices_discounts_amenities_and_services(): void
    {
        $partner = $this->branch->partner_id;

        $foreign = DB::table('categories')->whereNull('parent_id')->where('category_type', 'product')
            ->where('partner_id', '!=', $partner)->value('id');
        $this->as($partner)->postJson('/api/admin/sample-data', ['branch_id' => $foreign])->assertStatus(422);

        $res = $this->create(['rooms' => 2])->assertJsonPath('data.counts.rooms', 2);
        $this->assertNotNull($res->json('data.applied_price_template.id'));

        $this->as($partner)->getJson('/api/admin/sample-data')
            ->assertOk()->assertJsonPath('data.has_sample', true)->assertJsonPath('data.existing.room', 2);

        foreach ($res->json('data.rooms') as $item) {
            $room = Product::withoutGlobalScopes()->find($item['id']);

            $this->assertSame(1, (int) $room->styles);
            $this->assertTrue($room->categories()->where('categories.id', $this->branch->id)->exists());
            $this->assertMatchesRegularExpression('/^\d+%$/', $room->full_booking_discount);
            $this->assertCount(2, $room->bulk_discount_rules);
            $this->assertGreaterThanOrEqual(3, RoomTimeSlot::where('room_id', $room->id)->whereNull('date')->whereNotNull('price')->count());
            $this->assertTrue($room->tags()->exists());
            $this->assertTrue($room->additionalServices()->exists());
        }
    }

    public function test_delete_removes_exactly_what_was_generated(): void
    {
        $partner = $this->branch->partner_id;

        $realRooms     = Product::withoutGlobalScopes()->where('partner_id', $partner)->pluck('id')->all();
        $realTemplates = PriceTemplate::where('partner_id', $partner)->pluck('id')->all();

        $this->create();
        $this->create(); // 2 lần tạo → xoá cả 2

        $records = DB::table('sample_data_records')->where('partner_id', $partner)->get();
        $this->assertNotEmpty($records);

        $this->as($partner)->deleteJson('/api/admin/sample-data')
            ->assertOk()
            ->assertJsonPath('data.kept', []);

        $this->assertSame(0, DB::table('sample_data_records')->where('partner_id', $partner)->count());

        foreach ($records as $r) {
            $model = match ($r->record_type) {
                'room'           => Product::withoutGlobalScopes(),
                'price_template' => PriceTemplate::query(),
                'time_slot'      => TimeSlot::query(),
                'tag'            => Tag::query(),
                'service'        => AdditionService::withoutGlobalScopes(),
            };
            $this->assertFalse($model->whereKey($r->record_id)->exists(), "{$r->record_type} #{$r->record_id} chưa bị xoá");
        }

        // Dữ liệu thật của đối tác còn nguyên.
        $this->assertSame(count($realRooms), Product::withoutGlobalScopes()->whereIn('id', $realRooms)->count());
        $this->assertSame(count($realTemplates), PriceTemplate::whereIn('id', $realTemplates)->count());

        // Không còn gì để xoá.
        $this->as($partner)->deleteJson('/api/admin/sample-data')->assertNotFound();
    }

    public function test_delete_keeps_items_used_by_real_rooms(): void
    {
        $partner = $this->branch->partner_id;
        $this->create(['rooms' => 1]);

        $sampleTag  = DB::table('sample_data_records')->where('partner_id', $partner)->where('record_type', 'tag')->value('record_id');
        $sampleSlot = DB::table('sample_data_records')->where('partner_id', $partner)->where('record_type', 'time_slot')->value('record_id');
        $sampleIds  = DB::table('sample_data_records')->where('record_type', 'room')->pluck('record_id');

        // Đối tác lấy tiện ích + khung giờ mẫu dùng cho 1 phòng thật.
        $realRoom = Product::withoutGlobalScopes()->where('partner_id', $partner)->whereNotIn('id', $sampleIds)->firstOrFail();
        $realRoom->tags()->attach($sampleTag);
        RoomTimeSlot::create(['room_id' => $realRoom->id, 'timeslot_id' => $sampleSlot, 'price' => 99000, 'status' => 'available']);

        $res = $this->as($partner)->deleteJson('/api/admin/sample-data')->assertOk();

        $kept = collect($res->json('data.kept'))->map(fn ($k) => $k['type'] . ':' . $k['id'])->all();
        $this->assertContains('tag:' . $sampleTag, $kept);
        $this->assertContains('time_slot:' . $sampleSlot, $kept);

        // Giá phòng thật không mất, tiện ích vẫn gắn.
        $this->assertTrue(RoomTimeSlot::where('room_id', $realRoom->id)->where('timeslot_id', $sampleSlot)->exists());
        $this->assertTrue($realRoom->tags()->where('tags.id', $sampleTag)->exists());

        // Mục giữ lại vẫn được theo dõi để lần sau xoá tiếp.
        $this->assertTrue(DB::table('sample_data_records')->where('record_type', 'tag')->where('record_id', $sampleTag)->exists());
    }

    // Luồng app: thiết lập dữ liệu mẫu → danh sách bảng giá mẫu → xem chi tiết → chỉnh sửa.
    public function test_app_flow_sample_data_then_list_show_and_edit_price_template(): void
    {
        $partner = $this->branch->partner_id;

        $created = $this->create()->json('data.price_templates');
        $this->assertNotEmpty($created);

        $ids = collect($this->as($partner)->getJson('/api/admin/price-templates')->assertOk()->json('data'))->pluck('id');
        foreach ($created as $t) {
            $this->assertContains($t['id'], $ids);
        }

        $id   = $created[0]['id'];
        $show = $this->as($partner)->getJson("/api/admin/price-templates/{$id}")
            ->assertOk()->assertJsonPath('data.editable', true);

        $slots = collect($show->json('data.slots'))
            ->map(fn ($s) => ['timeslot_id' => $s['timeslot_id'], 'price' => $s['price'] + 10000])->all();

        $this->as($partner)->putJson("/api/admin/price-templates/{$id}", ['name' => 'Giá đã chỉnh', 'slots' => $slots])
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.name', 'Giá đã chỉnh')
            ->assertJsonPath('forked_from_id', null)
            ->assertJsonPath('data.slots.0.price', $slots[0]['price']);
    }
}
