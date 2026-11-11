<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\VoucherBlock;
use App\Services\VoucherBlockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Modules\Product\App\Models\Product;
use Modules\Promotion\App\Models\Coupon;
use Tests\TestCase;

// "Cấu hình web › Voucher theo đối tác": chi nhánh + ngày nào thì nhóm voucher nào không dùng được.
class VoucherBlockTest extends TestCase
{
    use DatabaseTransactions;

    private Product $room;
    private Product $otherRoom;
    private int $branchId;

    /**
     * cms_settings là bảng MyISAM — KHÔNG rollback theo DatabaseTransactions, phải tự sao lưu / khôi phục. Lưu 1 lần cho cả class
     * (static) để test nào cũng trả về đúng bản gốc, kể cả khi 1 test trước đó dừng giữa chừng.
     */
    private static ?array $scopeSettingsBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        self::$scopeSettingsBackup ??= DB::table('settings')->where('group', 'voucher_scope')->pluck('payload', 'name')->all();

        // Bỏ thiết lập thật đang có trên DB (VD đối tác đang "ngừng tất cả") — chỉ trong transaction của test, tự rollback.
        VoucherBlock::query()->delete();

        $livePartners = DB::table('partners')->whereNull('deleted_at')->pluck('id');
        $room = Product::withoutGlobalScopes()->where('styles', 2)->where('is_activated', true)->whereIn('partner_id', $livePartners)
            ->whereHas('categories', fn ($q) => $q->whereNull('parent_id'))->first();
        if (! $room) {
            $this->markTestSkipped('Cần 1 phòng theo ngày có chi nhánh.');
        }

        $this->room     = $room;
        $this->branchId = (int) $room->categories()->whereNull('parent_id')->value('categories.id');

        $other = Product::withoutGlobalScopes()->where('is_activated', true)->whereIn('partner_id', $livePartners)
            ->where('partner_id', '!=', $room->partner_id)->first();
        if (! $other) {
            $this->markTestSkipped('Cần phòng của 1 đối tác khác.');
        }
        $this->otherRoom = $other;
    }

    protected function tearDown(): void
    {
        foreach (self::$scopeSettingsBackup ?? [] as $name => $payload) {
            DB::table('settings')->where('group', 'voucher_scope')->where('name', $name)->update(['payload' => $payload]);
        }

        parent::tearDown();
    }

    private function coupon(array $attrs = []): Coupon
    {
        return Coupon::create($attrs + [
            'code' => 'VBT' . strtoupper(substr(md5((string) microtime(true) . random_int(0, 99999)), 0, 8)),
            'name' => 'Mã test', 'type' => 'fixed', 'value' => 50000, 'apply_type' => 'all_rooms',
            'is_active' => true, 'used_count' => 0, 'start_at' => now()->subDay(), 'end_at' => now()->addYears(2),
        ]);
    }

    private function rule(array $attrs): VoucherBlock
    {
        return VoucherBlock::create($attrs + [
            'partner_id'     => $this->room->partner_id,
            'name'           => 'Festival test',
            'category_ids'   => [$this->branchId],
            'blocked_groups' => array_keys(VoucherBlock::GROUPS),
        ]);
    }

    private function saturday(): string
    {
        return Carbon::parse('2027-03-01')->next(Carbon::SATURDAY)->toDateString();
    }

    public function test_groups_are_classified(): void
    {
        $this->assertSame(VoucherBlock::GROUP_PLATFORM, VoucherBlockService::groupOf($this->coupon()));
        $this->assertSame(VoucherBlock::GROUP_PARTNER, VoucherBlockService::groupOf($this->coupon(['partner_id' => $this->room->partner_id])));
        $this->assertSame(VoucherBlock::GROUP_GAME, VoucherBlockService::groupOf($this->coupon(['source' => 'scratch_card'])));

        $tierId = DB::table('membership_tiers')->value('id');
        if ($tierId) {
            $this->assertSame(VoucherBlock::GROUP_CHECKIN, VoucherBlockService::groupOf($this->coupon(['auto_issue_tier_id' => $tierId])));
        }
        $template = $this->coupon();
        $this->assertSame(VoucherBlock::GROUP_MEMBERSHIP, VoucherBlockService::groupOf($this->coupon(['template_coupon_id' => $template->id])));
    }

    public function test_date_range_weekday_except_and_mixed_mode(): void
    {
        $this->rule([
            'date_ranges'  => [['name' => 'Festival Hoa', 'from' => '2027-12-18', 'to' => '2027-12-19']],
            'weekdays'     => [6],
            'except_dates' => [Carbon::parse($this->saturday())->addWeek()->toDateString()],
        ]);
        $coupon = $this->coupon();
        $svc    = new VoucherBlockService;

        $msg = $svc->blockReason($coupon, $this->room, ['2027-12-17', '2027-12-18']);
        $this->assertStringContainsString('Festival Hoa', (string) $msg);
        $this->assertStringContainsString('18/12/2027', (string) $msg);

        $this->assertNull($svc->blockReason($coupon, $this->room, ['2027-12-20']));
        $this->assertStringContainsString('Thứ 7', (string) $svc->blockReason($coupon, $this->room, [$this->saturday()]));
        // Ngày ngoại lệ (Thứ 7 tuần sau) vẫn nhận voucher.
        $this->assertNull($svc->blockReason($coupon, $this->room, [Carbon::parse($this->saturday())->addWeek()->toDateString()]));
        // Chưa chọn ngày → không chặn (chỉ "Mọi ngày" mới chặn khi chưa có ngày).
        $this->assertNull($svc->blockReason($coupon, $this->room, []));
        // Phòng đối tác khác không bị ảnh hưởng.
        $this->assertNull($svc->blockReason($coupon, $this->otherRoom, ['2027-12-18']));

        // mixed_mode = all: đơn 17→19/12 (đêm 17 thường, 18 lễ) không bị chặn; chỉ đêm 18-19 thì bị.
        VoucherBlock::query()->update(['mixed_mode' => VoucherBlock::MIXED_ALL]);
        $svc = new VoucherBlockService;
        $this->assertNull($svc->blockReason($coupon, $this->room, ['2027-12-17', '2027-12-18']));
        $this->assertNotNull($svc->blockReason($coupon, $this->room, ['2027-12-18', '2027-12-19']));
    }

    public function test_only_selected_groups_and_branch_and_validity(): void
    {
        $this->rule(['all_days' => true, 'blocked_groups' => [VoucherBlock::GROUP_GAME], 'valid_to' => '2027-06-30']);
        $svc = new VoucherBlockService;

        $this->assertNull($svc->blockReason($this->coupon(), $this->room, ['2027-05-01']));
        $this->assertNotNull($svc->blockReason($this->coupon(['source' => 'caro']), $this->room, ['2027-05-01']));
        $this->assertNotNull($svc->blockReason($this->coupon(['source' => 'caro']), $this->room, []), 'Mọi ngày: chặn cả khi chưa chọn ngày');
        $this->assertNull($svc->blockReason($this->coupon(['source' => 'caro']), $this->room, ['2027-07-01']), 'Ngoài hiệu lực');

        VoucherBlock::query()->update(['is_active' => false]);
        $this->assertNull((new VoucherBlockService)->blockReason($this->coupon(['source' => 'caro']), $this->room, ['2027-05-01']));
    }

    public function test_recurring_range_across_new_year(): void
    {
        $this->rule(['recurring_ranges' => [['name' => 'Tết Dương lịch', 'from' => '12-31', 'to' => '01-01']]]);
        $svc = new VoucherBlockService;
        $c   = $this->coupon();

        $this->assertNotNull($svc->blockReason($c, $this->room, ['2028-12-31']));
        $this->assertNotNull($svc->blockReason($c, $this->room, ['2029-01-01']));
        $this->assertNull($svc->blockReason($c, $this->room, ['2029-01-02']));
    }

    public function test_price_board_holidays(): void
    {
        // Phòng theo ngày có bảng giá đặt tên, đang bật, không giới hạn ngày (thắng mọi ngày).
        $item = DB::table('price_board_items as i')->join('price_boards as b', 'b.id', '=', 'i.price_board_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('p.styles', 2)->where('b.is_active', true)->where('b.is_template', false)->where('b.is_default', false)
            ->whereNull('b.start_date')->whereNull('b.end_date')->select('i.id', 'i.product_id')->first();
        if (! $item) {
            $this->markTestSkipped('Chưa có phòng theo ngày nào gắn bảng giá đang bật.');
        }
        $this->room     = Product::withoutGlobalScopes()->find($item->product_id);
        $this->branchId = (int) ($this->room->categories()->whereNull('parent_id')->value('categories.id')
            ?? $this->room->categories()->value('categories.parent_id'));

        DB::table('price_board_items')->where('id', $item->id)->update(['holiday_prices' => json_encode([
            ['name' => 'Festival Hoa Đà Lạt', 'recurring' => false, 'from' => '2027-12-18', 'to' => '2027-12-19', 'mode' => 'price', 'value' => 700000],
        ])]);

        $this->rule(['use_price_board_holidays' => true]);
        $svc = new VoucherBlockService;

        $msg = $svc->blockReason($this->coupon(), $this->room, ['2027-12-19']);
        if ($msg === null) {
            $this->markTestSkipped('Bảng giá thắng của phòng không phải bảng vừa sửa.');
        }
        $this->assertStringContainsString('Festival Hoa Đà Lạt', $msg);
        $this->assertNull($svc->blockReason($this->coupon(), $this->room, ['2027-12-20']));
        $this->assertNotEmpty($svc->upcomingHolidays($this->room->partner_id, [$this->branchId]));
    }

    public function test_customer_validate_api_returns_block_message(): void
    {
        $this->rule(['date_ranges' => [['name' => 'Festival Hoa', 'from' => '2027-12-18', 'to' => '2027-12-19']]]);
        $coupon   = $this->coupon(['min_order_value' => null]);
        $customer = Customer::create(['fullname' => 'Khách VB', 'phone' => '09' . random_int(10000000, 99999999), 'status' => Customer::STATUS_ACTIVE]);
        $headers  = ['Authorization' => 'Bearer ' . $customer->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

        $base = ['coupon_code' => $coupon->code, 'room_id' => $this->room->id, 'amount' => 1000000];

        $this->withHeaders($headers)->postJson('/api/coupons/validate', $base + ['checkin_date' => '2027-12-17', 'checkout_date' => '2027-12-19'])
            ->assertStatus(422)->assertJsonPath('blocked', true)
            ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'Festival Hoa'))->etc());

        $this->withHeaders($headers)->postJson('/api/coupons/validate', $base + ['checkin_date' => '2027-12-20', 'checkout_date' => '2027-12-22'])
            ->assertOk();
    }

    private function period(string $name, array $ranges, array $extra = []): array
    {
        return $extra + [
            'id' => null, 'name' => $name, 'is_active' => true,
            'ranges' => collect($ranges)->mapWithKeys(fn ($r) => [(string) \Illuminate\Support\Str::uuid() => ['name' => null, 'from' => $r[0], 'to' => $r[1]]])->all(),
            'blocked_groups' => array_keys(VoucherBlock::GROUPS), 'use_price_board_holidays' => false, 'weekdays' => [],
            'recurring_ranges' => [], 'except_dates' => [], 'valid_from' => null, 'valid_to' => null,
            'mixed_mode' => VoucherBlock::MIXED_ANY, 'note' => null,
        ];
    }

    public function test_partner_voucher_page_saves_both_layers(): void
    {
        $this->actingAs(User::role('super_admin')->first());
        $partnerId = $this->room->partner_id;
        VoucherBlock::where('partner_id', $partnerId)->delete();

        $settings                       = app(\App\Settings\VoucherScopeSettings::class);
        $settings->checkin_partner_ids  = [$partnerId, $this->otherRoom->partner_id];
        $settings->checkin_category_ids = [];
        $settings->minigame_partner_ids = $settings->minigame_category_ids = [];
        $settings->save();

        $page = \App\Filament\Resources\PartnerVoucherResource\Pages\ManagePartnerVoucher::class;

        \Livewire\Livewire::test(\App\Filament\Resources\PartnerVoucherResource\Pages\ListPartnerVouchers::class)
            ->assertSuccessful()->assertSee('Voucher khách dùng được');

        $lw = \Livewire\Livewire::test($page, ['record' => $partnerId])->assertSuccessful()
            ->assertSee('① Áp dụng ở chi nhánh nào')->assertSee('④ Xem trước');
        $this->assertEqualsCanonicalizing(array_keys(VoucherBlock::GROUPS), $lw->get('data.accept'));

        // Đợt thiếu ngày → lỗi ngay ở đợt đó.
        $lw->set('data.periods', ['p1' => $this->period('Thiếu ngày', [])])->call('save')->assertHasErrors(['data.periods.p1.ranges']);

        // Đợt mới đã qua → lỗi.
        $lw->set('data.periods', ['p1' => $this->period('Đã qua', [['2020-01-01', '2020-01-02']])])->call('save');
        $this->assertNotEmpty(collect($lw->errors()->keys())->filter(fn ($k) => str_starts_with($k, 'data.periods.p1.ranges.')));

        // Lưu: bỏ "Quà điểm danh" + "Quà trò chơi", 1 đợt Festival + 1 đợt cuối tuần.
        $accept = array_values(array_diff(array_keys(VoucherBlock::GROUPS), [VoucherBlock::GROUP_CHECKIN, VoucherBlock::GROUP_GAME]));
        $lw->set('data.branch_mode', 'some')->set('data.category_ids', [(string) $this->branchId])
            ->set('data.accept', $accept)
            ->set('data.periods', [
                'p1' => $this->period('Festival UI', [['2027-03-05', '2027-03-07']]),
                'p2' => $this->period('Cuối tuần', [], ['weekdays' => ['6']]),
            ])
            ->set('data.test_date', '2027-03-06')
            ->assertSee('Festival UI')
            ->call('save')
            ->assertHasNoErrors();

        $base = VoucherBlock::where('partner_id', $partnerId)->where('all_days', true)->sole();
        $this->assertEqualsCanonicalizing([VoucherBlock::GROUP_CHECKIN, VoucherBlock::GROUP_GAME], $base->blocked_groups);
        $this->assertSame([$this->branchId], $base->category_ids);
        $periods = VoucherBlock::where('partner_id', $partnerId)->where('all_days', false)->orderBy('id')->get();
        $this->assertSame(['Festival UI', 'Cuối tuần'], $periods->pluck('name')->all());
        $this->assertSame('Festival UI', $periods[0]->date_ranges[0]['name']);
        $this->assertSame([6], $periods[1]->weekdays);

        // Danh sách nơi nhận quà điểm danh: gỡ đối tác này, đối tác khác giữ nguyên.
        $settings = app(\App\Settings\VoucherScopeSettings::class)->refresh();
        $this->assertSame([$this->otherRoom->partner_id], $settings->checkin_partner_ids);

        // Áp mã thật.
        $service = app(VoucherBlockService::class);
        $service->clearCache();
        $this->assertStringContainsString('Festival UI', (string) $service->blockReason($this->coupon(), $this->room, ['2027-03-06']));
        $this->assertNull($service->blockReason($this->coupon(), $this->room, ['2027-03-10'])); // T4
        $this->assertStringContainsString('không áp dụng tại', (string) $service->blockReason($this->coupon(['source' => 'caro']), $this->room, ['2027-03-10']));

        // Mở lại: đúng trạng thái; xoá 1 đợt + tick lại mọi loại → dòng "không nhận" bị xoá.
        $lw = \Livewire\Livewire::test($page, ['record' => $partnerId]);
        $this->assertEqualsCanonicalizing($accept, $lw->get('data.accept'));
        $this->assertSame('some', $lw->get('data.branch_mode'));
        $periodsState = $lw->get('data.periods');
        $this->assertCount(2, $periodsState);
        array_pop($periodsState);
        $lw->set('data.periods', $periodsState)->set('data.accept', array_keys(VoucherBlock::GROUPS))
            ->set('data.test_code', 'KHONGCOMA')->assertSee('Không tìm thấy mã')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(['Festival UI'], VoucherBlock::where('partner_id', $partnerId)->pluck('name')->all());
        $settings = app(\App\Settings\VoucherScopeSettings::class)->refresh();
        $this->assertSame([$this->otherRoom->partner_id], $settings->checkin_partner_ids);
        $this->assertSame([$this->branchId], $settings->checkin_category_ids);
    }

    public function test_list_tabs_bulk_and_quick_accept(): void
    {
        $this->actingAs(User::role('super_admin')->first());
        $partnerId = $this->room->partner_id;
        VoucherBlock::where('partner_id', $partnerId)->delete();
        $this->rule(['date_ranges' => [['name' => 'Festival', 'from' => '2027-03-05', 'to' => '2027-03-07']]]);

        $list = \App\Filament\Resources\PartnerVoucherResource\Pages\ListPartnerVouchers::class;
        $base = fn () => VoucherBlock::where('partner_id', $partnerId)->where('all_days', true)->first();

        foreach (['all', 'paused', 'limited'] as $tab) {
            \Livewire\Livewire::test($list)->set('activeTab', $tab)->assertSuccessful();
        }

        // Hàng loạt: KHÔNG cho dùng "Mã chung 365home".
        \Livewire\Livewire::test($list)
            ->callTableBulkAction('changeGroups', [$partnerId], ['mode' => 'disable', 'groups' => [VoucherBlock::GROUP_PLATFORM]])
            ->assertHasNoTableBulkActionErrors();
        $this->assertContains(VoucherBlock::GROUP_PLATFORM, $base()->blocked_groups);
        $this->assertSame(1, VoucherBlock::where('partner_id', $partnerId)->where('all_days', false)->count()); // đợt giữ nguyên

        // Tab "Không nhận một số loại" có đối tác này.
        \Livewire\Livewire::test($list)->set('activeTab', 'limited')->assertCanSeeTableRecords([\App\Models\Partner::withoutGlobalScopes()->find($partnerId)]);

        // Cho dùng tất cả → bỏ dòng "không nhận".
        \Livewire\Livewire::test($list)->callTableBulkAction('enableAll', [$partnerId]);
        $this->assertNull($base());

        // Sửa nhanh 1 dòng: chỉ cho dùng "Mã riêng của đối tác".
        // (callTableAction gộp dữ liệu theo chỉ số mảng với danh sách điền sẵn → đặt thẳng state của form.)
        \Livewire\Livewire::test($list)
            ->mountTableAction('quickAccept', $partnerId)
            ->assertTableActionDataSet(['groups' => array_keys(VoucherBlock::GROUPS)])
            ->set('mountedTableActionsData.0.groups', [VoucherBlock::GROUP_PARTNER])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();
        $this->assertEqualsCanonicalizing(
            array_values(array_diff(array_keys(VoucherBlock::GROUPS), [VoucherBlock::GROUP_PARTNER])),
            $base()->blocked_groups,
        );

        // Ngừng mọi voucher.
        \Livewire\Livewire::test($list)->callTableBulkAction('disableAll', [$partnerId]);
        $this->assertEqualsCanonicalizing(array_keys(VoucherBlock::GROUPS), $base()->blocked_groups);
    }

    public function test_cannot_untick_gift_for_the_only_partner(): void
    {
        $this->actingAs(User::role('super_admin')->first());
        $partnerId = $this->room->partner_id;

        $settings                       = app(\App\Settings\VoucherScopeSettings::class);
        $settings->checkin_partner_ids  = [$partnerId];
        $settings->checkin_category_ids = [];
        $settings->save();

        \Livewire\Livewire::test(\App\Filament\Resources\PartnerVoucherResource\Pages\ManagePartnerVoucher::class, ['record' => $partnerId])
            ->set('data.accept', [VoucherBlock::GROUP_PLATFORM])
            ->call('save')
            ->assertHasErrors(['data.accept']);

        $this->assertSame([$partnerId], app(\App\Settings\VoucherScopeSettings::class)->refresh()->checkin_partner_ids);
    }

    public function test_gift_scope_mixes_whole_partner_and_branch(): void
    {
        $settings                       = app(\App\Settings\VoucherScopeSettings::class);
        $settings->checkin_partner_ids  = [$this->otherRoom->partner_id];
        $settings->checkin_category_ids = [$this->branchId];
        $settings->save();

        $coupon = $this->coupon();
        \App\Support\VoucherScope::restrict($coupon, \App\Support\VoucherScope::CHECKIN);
        $ids = $coupon->categories()->pluck('categories.id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($this->branchId, $ids);
        $otherBranches = DB::table('categories')->whereNull('parent_id')->where('category_type', 'product')
            ->where('partner_id', $this->otherRoom->partner_id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach ($otherBranches as $id) {
            $this->assertContains($id, $ids);
        }
    }

    public function test_admin_api_crud_and_partner_scope(): void
    {
        $admin   = User::role('super_admin')->first();
        $headers = ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];

        $this->withHeaders($headers)->postJson('/api/admin/voucher-blocks', ['name' => 'Thiếu lịch', 'partner_id' => $this->room->partner_id])
            ->assertStatus(422)->assertJsonValidationErrors('all_days');

        $id = $this->withHeaders($headers)->postJson('/api/admin/voucher-blocks', [
            'name'             => 'Cuối tuần + Tết',
            'partner_id'       => $this->room->partner_id,
            'category_ids'     => [$this->branchId],
            'weekdays'         => [5, 6, 7],
            'recurring_ranges' => [['name' => 'Tết', 'from' => '31/12', 'to' => '01/01']],
        ])->assertCreated()
            ->assertJsonPath('data.recurring_ranges.0.from', '12-31')
            ->assertJsonPath('data.blocked_groups', array_keys(VoucherBlock::GROUPS))
            ->json('data.id');

        $this->withHeaders($headers)->putJson("/api/admin/voucher-blocks/{$id}", ['blocked_groups' => ['game']])
            ->assertOk()->assertJsonPath('data.blocked_groups', ['game']);

        // Chi nhánh của đối tác khác → 422.
        $otherBranch = (int) DB::table('categories')->whereNull('parent_id')->where('category_type', 'product')
            ->where('partner_id', $this->otherRoom->partner_id)->value('id');
        if ($otherBranch) {
            $this->withHeaders($headers)->postJson('/api/admin/voucher-blocks', [
                'name' => 'Sai chi nhánh', 'partner_id' => $this->room->partner_id, 'category_ids' => [$otherBranch], 'all_days' => true,
            ])->assertStatus(422)->assertJsonValidationErrors('category_ids');
        }

        // Đang làm việc trong đối tác khác (X-Partner-Id) → không thấy lịch này.
        $this->withHeaders($headers + ['X-Partner-Id' => $this->otherRoom->partner_id])->getJson("/api/admin/voucher-blocks/{$id}")->assertNotFound();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards(); // user đã đăng nhập giữ trạng thái "đang làm việc trong đối tác khác" giữa các request trong 1 test
        $this->withHeaders($headers)->deleteJson("/api/admin/voucher-blocks/{$id}")->assertOk();
        $this->assertDatabaseMissing('voucher_blocks', ['id' => $id]);
    }

    public function test_only_super_admin_can_manage(): void
    {
        $partnerUser = User::whereNotNull('partner_id')->get()->first(fn (User $u) => ! $u->isSuperAdmin());
        if (! $partnerUser) {
            $this->markTestSkipped('Không có tài khoản đối tác để thử.');
        }

        $headers = ['Authorization' => 'Bearer ' . $partnerUser->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
        $this->withHeaders($headers)->getJson('/api/admin/voucher-blocks')->assertForbidden();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->actingAs($partnerUser);
        $this->assertFalse(\App\Filament\Resources\PartnerVoucherResource::canViewAny());
    }
}
