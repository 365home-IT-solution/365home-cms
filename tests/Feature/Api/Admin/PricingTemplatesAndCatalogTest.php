<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Tag;
use App\Models\User;
use App\Services\PriceBoardSyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\PriceBoardItem;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\TimeSlot;
use Tests\TestCase;

// Khung giờ/tiện ích tách theo đối tác (mẫu hệ thống = partner_id NULL), bảng giá mẫu (tách bản riêng
// khi đối tác sửa mẫu hệ thống, áp xuống phòng), dịch vụ đi kèm gắn theo chi nhánh.
class PricingTemplatesAndCatalogTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    /** 2 chi nhánh gốc thuộc 2 đối tác khác nhau, mỗi chi nhánh có ít nhất 1 phòng theo khung giờ. */
    private object $a;
    private object $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        $admin       = User::role('super_admin')->first();
        $this->token = $admin->createToken('t')->plainTextToken;

        $branches = DB::table('categories as c')
            ->join('categorizables as cz', 'cz.category_id', '=', 'c.id')
            ->join('products as p', 'p.id', '=', 'cz.categorizable_id')
            ->whereNull('c.parent_id')->where('c.category_type', 'product')->whereNull('c.deleted_at')
            ->whereColumn('p.partner_id', 'c.partner_id')->where('p.styles', 1)->whereNull('p.deleted_at')
            ->where('cz.categorizable_type', (new Product)->getMorphClass())
            ->select('c.id as branch_id', 'c.partner_id', DB::raw('min(cms_p.id) as room_id'))
            ->groupBy('c.id', 'c.partner_id')
            ->get()
            ->unique('partner_id')
            ->values();

        if ($branches->count() < 2) {
            $this->markTestSkipped('Cần ít nhất 2 đối tác có chi nhánh + phòng theo khung giờ.');
        }

        [$this->a, $this->b] = [$branches[0], $branches[1]];
    }

    private function as(?object $partner): self
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json'];
        if ($partner) {
            $headers['X-Partner-Id'] = $partner->partner_id;
        }

        return $this->withHeaders($headers);
    }

    private function systemSlotIds(int $n): array
    {
        return TimeSlot::whereNull('partner_id')->where(fn ($q) => $q->whereNull('type')->orWhere('type', 'time'))
            ->orderBy('id')->limit($n)->pluck('id')->all();
    }

    public function test_partner_time_slots_are_private_and_system_slots_read_only(): void
    {
        $created = $this->as($this->a)->postJson('/api/admin/time-slots', ['start_time' => '05:17', 'end_time' => '06:43'])
            ->assertCreated()
            ->assertJsonPath('data.partner_id', $this->a->partner_id)
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.editable', true)
            ->json('data.id');

        // Trùng giờ → 422 kèm khung giờ có sẵn.
        $this->as($this->a)->postJson('/api/admin/time-slots', ['start_time' => '05:17', 'end_time' => '06:43'])
            ->assertStatus(422)->assertJsonPath('existing.id', $created);

        // Đối tác khác không thấy, không sửa được.
        $this->as($this->b)->putJson("/api/admin/time-slots/{$created}", ['label' => 'x'])->assertNotFound();
        $idsSeenByB = collect($this->as($this->b)->getJson('/api/admin/time-slots')->json('data'))->pluck('slots')->flatten(1)->pluck('id');
        $this->assertNotContains($created, $idsSeenByB);

        // Mẫu hệ thống: đối tác chỉ đọc.
        $system = $this->systemSlotIds(1)[0];
        $this->as($this->a)->putJson("/api/admin/time-slots/{$system}", ['label' => 'x'])->assertForbidden();
        $this->as($this->a)->deleteJson("/api/admin/time-slots/{$system}")->assertForbidden();

        // Phòng của B không gán được khung giờ riêng của A.
        $this->as($this->b)->postJson("/api/admin/products/{$this->b->room_id}/time-slots", ['slots' => [['timeslot_id' => $created, 'price' => 1000]]])
            ->assertStatus(422);
    }

    public function test_partner_edit_forks_system_template_and_apply_writes_room_and_default_board(): void
    {
        [$s1, $s2] = $this->systemSlotIds(2);

        $systemId = $this->as(null)->postJson('/api/admin/price-templates', [
            'name'  => 'Mẫu test',
            'slots' => [['timeslot_id' => $s1, 'price' => 100000], ['timeslot_id' => $s2, 'price' => 200000]],
        ])->assertCreated()->assertJsonPath('data.is_system', true)->json('data.id');

        // Đối tác sửa mẫu hệ thống → tách bản riêng, mẫu gốc giữ nguyên.
        $res = $this->as($this->a)->putJson("/api/admin/price-templates/{$systemId}", [
            'slots' => [['timeslot_id' => $s1, 'price' => 111000]],
        ])->assertOk()->assertJsonPath('forked_from_id', $systemId)->assertJsonPath('data.partner_id', $this->a->partner_id);
        $forkId = $res->json('data.id');
        $this->assertNotSame($systemId, $forkId);

        // Sửa tiếp bằng id gốc → cập nhật đúng bản đã tách, không tạo bản mới.
        $this->as($this->a)->putJson("/api/admin/price-templates/{$systemId}", ['name' => 'Mẫu của A'])
            ->assertOk()->assertJsonPath('data.id', $forkId);

        $this->assertSame(100000, (int) DB::table('price_template_slots')->where('price_template_id', $systemId)->where('timeslot_id', $s1)->value('price'));

        // Danh sách của A: thấy bản riêng, ẩn mẫu gốc đã tách. B vẫn thấy mẫu gốc, không thấy bản của A.
        $idsA = collect($this->as($this->a)->getJson('/api/admin/price-templates')->json('data'))->pluck('id');
        $this->assertContains($forkId, $idsA);
        $this->assertNotContains($systemId, $idsA);
        $idsB = collect($this->as($this->b)->getJson('/api/admin/price-templates')->json('data'))->pluck('id');
        $this->assertContains($systemId, $idsB);
        $this->assertNotContains($forkId, $idsB);

        // B không áp được mẫu riêng của A; A không áp mẫu cho phòng của B.
        $this->as($this->b)->postJson("/api/admin/price-templates/{$forkId}/apply", ['room_ids' => [$this->b->room_id]])->assertNotFound();
        $this->as($this->a)->postJson("/api/admin/price-templates/{$forkId}/apply", ['room_ids' => [$this->b->room_id]])->assertNotFound();

        // Áp (replace) → phòng chỉ còn đúng khung giờ của mẫu, bảng giá mặc định được cập nhật theo.
        $this->as($this->a)->postJson("/api/admin/price-templates/{$forkId}/apply", ['room_ids' => [$this->a->room_id]])->assertOk();

        $roomSlots = RoomTimeSlot::where('room_id', $this->a->room_id)->whereNull('date')
            ->whereHas('timeSlot', fn ($q) => $q->where(fn ($q2) => $q2->whereNull('type')->orWhere('type', '!=', 'date')))
            ->pluck('price', 'timeslot_id')->map(fn ($p) => (int) $p)->all();
        $this->assertSame([$s1 => 111000], $roomSlots);

        $defaultItem = PriceBoardItem::where('price_board_id', app(PriceBoardSyncService::class)->defaultBoard()->id)
            ->where('product_id', $this->a->room_id)->with('timeSlots')->first();
        $this->assertSame([$s1 => 111000], $defaultItem->timeSlots->pluck('price', 'timeslot_id')->map(fn ($p) => (int) $p)->all());

        // Đối tác không xoá được mẫu hệ thống, xoá được bản riêng.
        $this->as($this->a)->deleteJson("/api/admin/price-templates/{$forkId}")->assertOk();
        $this->as($this->b)->deleteJson("/api/admin/price-templates/{$systemId}")->assertForbidden();
    }

    public function test_services_attach_to_branch_and_rooms_inherit(): void
    {
        $id = $this->as($this->a)->postJson('/api/admin/services', [
            'name' => 'Dịch vụ test chi nhánh', 'price' => 50000, 'branch_ids' => [$this->a->branch_id],
        ])->assertCreated()->assertJsonPath('data.branch_ids', [$this->a->branch_id])->json('data.id');

        $this->assertTrue(Product::withoutGlobalScopes()->find($this->a->room_id)->additionalServices()->where('additional_services.id', $id)->exists());

        $this->as($this->a)->getJson("/api/admin/services?room_id={$this->a->room_id}")
            ->assertOk()->assertJsonFragment(['id' => $id]);

        // Chi nhánh của đối tác khác → 422.
        $this->as($this->a)->putJson("/api/admin/services/{$id}", ['branch_ids' => [$this->b->branch_id]])->assertStatus(422);

        $this->as($this->a)->deleteJson("/api/admin/services/{$id}")->assertOk();
        $this->assertFalse(DB::table('room_additional_service_assigns')->where('additional_service_id', $id)->exists());
    }

    public function test_partner_tags_are_private_and_validated_on_rooms(): void
    {
        $tagId = $this->as($this->a)->postJson('/api/admin/tags', ['name' => 'Tiện ích test riêng A'])
            ->assertCreated()->assertJsonPath('data.is_system', false)->json('data.id');

        $this->assertSame($this->a->partner_id, Tag::find($tagId)->partner_id);

        $this->as($this->a)->postJson('/api/admin/tags', ['name' => 'Tiện ích test riêng A'])
            ->assertStatus(422)->assertJsonPath('existing.id', $tagId);

        $idsB = collect($this->as($this->b)->getJson('/api/admin/tags')->json('data'))->pluck('id');
        $this->assertNotContains($tagId, $idsB);

        $this->as($this->b)->putJson("/api/admin/products/{$this->b->room_id}", ['tags' => [$tagId]])->assertStatus(422);

        $systemTag = Tag::whereNull('partner_id')->value('id');
        if ($systemTag) {
            $this->as($this->a)->deleteJson("/api/admin/tags/{$systemTag}")->assertForbidden();
        }

        $this->as($this->a)->deleteJson("/api/admin/tags/{$tagId}")->assertOk();
    }
}
