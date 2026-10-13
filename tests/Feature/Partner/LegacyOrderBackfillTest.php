<?php

namespace Tests\Feature\Partner;

use App\Models\Partner;
use App\Models\PartnerSettlement;
use App\Models\User;
use App\Services\LegacyOrderBackfillService;
use App\Services\OrderCommissionService;
use App\Services\SettlementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Modules\Payment\Entities\Order;
use Tests\TestCase;

// Đối tác ký offline: đơn cũ phải vào đối soát — chụp đúng người thu tiền, chốt hoa hồng theo ngày trả phòng thật, bảng NHÁP theo kỳ.
class LegacyOrderBackfillTest extends TestCase
{
    use DatabaseTransactions;

    private function partnerWithLegacyOrders(): Partner
    {
        $id = Order::withoutGlobalScopes()
            ->whereNull('collected_by')->whereIn('status', ['paid'])->where('order_status', 'checked_out')
            ->whereNotNull('partner_id')
            ->selectRaw('partner_id, count(*) c')->groupBy('partner_id')->orderByDesc('c')
            ->whereIn('partner_id', Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)->pluck('id'))
            ->value('partner_id');

        $this->assertNotNull($id, 'Cần ít nhất 1 đối tác Homestay có đơn đã trả phòng trong DB local.');

        return Partner::findOrFail($id);
    }

    public function test_activates_payment_flow_for_offline_contract_only_when_applied(): void
    {
        $partner = $this->partnerWithLegacyOrders();
        $partner->forceFill(['payment_flow_effective_at' => null])->saveQuietly();
        $admin = User::role('super_admin')->first();

        // Chạy thử: tính đủ số liệu nhưng KHÔNG bật luồng.
        $report = app(LegacyOrderBackfillService::class)->run($partner, Carbon::parse('2020-01-01'), null, 20.0, $admin, false);
        $this->assertGreaterThan(0, $report['orders']['snapshotted']);
        $this->assertNull(Partner::find($partner->id)->payment_flow_effective_at);

        // Ghi thật: bật luồng đối soát (đơn mới từ giờ được chụp hoa hồng tự động) + đưa đơn cũ vào.
        app(LegacyOrderBackfillService::class)->run(Partner::find($partner->id), Carbon::parse('2020-01-01'), null, 20.0, $admin, true);
        $this->assertNotNull(Partner::find($partner->id)->payment_flow_effective_at);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $partner = $this->partnerWithLegacyOrders();
        $partner->forceFill(['payment_flow_effective_at' => now()])->saveQuietly();

        $before = Order::withoutGlobalScopes()->where('partner_id', $partner->id)->whereNotNull('collected_by')->count();

        $report = app(LegacyOrderBackfillService::class)->run($partner, Carbon::parse('2020-01-01'), null, 20.0, null, false);

        $this->assertGreaterThan(0, $report['orders']['snapshotted']);
        $this->assertSame($before, Order::withoutGlobalScopes()->where('partner_id', $partner->id)->whereNotNull('collected_by')->count());
        $this->assertSame(0, PartnerSettlement::query()->where('partner_id', $partner->id)->count());
    }

    public function test_apply_snapshots_finalizes_and_builds_draft_settlements(): void
    {
        $partner = $this->partnerWithLegacyOrders();
        $partner->forceFill(['payment_flow_effective_at' => now()])->saveQuietly();

        $report = app(LegacyOrderBackfillService::class)->run($partner, Carbon::parse('2020-01-01'), null, 20.0, null, true, true);

        $this->assertGreaterThan(0, $report['orders']['finalized']);

        // Mọi đơn đã chốt: có người thu tiền, tỉ lệ 20%, hoa hồng = 20% × (doanh thu giữ lại + bù), mốc chốt không ở tương lai.
        $commission = app(OrderCommissionService::class);
        $finalized = Order::withoutGlobalScopes()->where('partner_id', $partner->id)->whereNotNull('commission_finalized_at')->get();
        $this->assertNotEmpty($finalized);

        foreach ($finalized as $order) {
            $this->assertContains($order->collected_by, ['platform', 'cash']);
            $this->assertSame(strtolower((string) $order->payment_method) === 'cod' ? 'cash' : 'platform', $order->collected_by);
            $this->assertEquals(20.0, (float) $order->commission_rate);
            $this->assertSame(
                (int) round(($commission->retainedAmount($order) + (int) $order->platform_subsidy) * 20 / 100),
                (int) $order->commission_amount,
            );
            $this->assertFalse($order->commission_finalized_at->isFuture());
        }

        // Bảng sinh ra đều NHÁP (chưa gửi đối tác) và tổng khớp báo cáo.
        $sheets = PartnerSettlement::query()->where('partner_id', $partner->id)->get();
        $this->assertCount(count($report['settlements']), $sheets);

        foreach ($sheets as $sheet) {
            $this->assertSame(PartnerSettlement::STATUS_DRAFT, $sheet->status);
        }

        $this->assertSame(
            $report['totals']['commission'],
            (int) Order::withoutGlobalScopes()->whereIn('settlement_id', $sheets->pluck('id'))->sum('commission_amount')
                + (int) Order::withoutGlobalScopes()->where('partner_id', $partner->id)->whereNull('settlement_id')->whereNotNull('commission_finalized_at')->sum('commission_amount')
                - 0,
        );

        // Chạy lại không nhân đôi: đơn đã chụp thì bị bỏ qua.
        $again = app(LegacyOrderBackfillService::class)->run($partner, Carbon::parse('2020-01-01'), null, 20.0, null, true);
        $this->assertSame(0, $again['orders']['snapshotted']);
    }

    private function adminHeaders(): array
    {
        $admin = User::role('super_admin')->first();

        return ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    public function test_api_is_limited_to_allowed_partners(): void
    {
        $other = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)
            ->where('legal_name', 'not like', '%về nhà%')->where('legal_name', 'not like', '%pinus%')->first();

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/partners/{$other->id}/legacy-orders/settle", ['from' => '2026-01-01'])
            ->assertStatus(403);
    }

    public function test_only_in_period_limits_the_sheet_to_orders_finalized_in_that_period(): void
    {
        $partner = $this->partnerWithLegacyOrders();
        $partner->forceFill(['payment_flow_effective_at' => now()])->saveQuietly();
        app(LegacyOrderBackfillService::class)->run($partner, Carbon::parse('2020-01-01'), null, 20.0, null, true, false);

        $orders = Order::withoutGlobalScopes()->where('partner_id', $partner->id)->whereNull('settlement_id')
            ->whereNotNull('commission_finalized_at')->whereNull('subsidy_held_at')->get(); // đơn bị giữ khoản bù không vào bảng
        $this->assertGreaterThan(1, $orders->count());

        // Chọn 1 kỳ nhỏ chứa đúng một phần đơn: từ ngày chốt của đơn mới nhất lùi 0 ngày.
        $latest = $orders->max('commission_finalized_at');
        $start = Carbon::parse($latest)->startOfDay();
        $end = Carbon::parse($latest)->startOfDay();
        $expected = $orders->filter(fn ($o) => $o->commission_finalized_at->between($start->copy()->startOfDay(), $end->copy()->endOfDay()))->count();
        $this->assertGreaterThan(0, $expected);
        $this->assertLessThan($orders->count(), $expected, 'Cần dữ liệu có đơn chốt ở nhiều ngày khác nhau.');

        $strict = app(SettlementService::class)->generate($partner, $start, $end, null, true);
        $this->assertNotNull($strict);
        $this->assertSame($expected, (int) $strict->orders_count);

        // Không giới hạn kỳ (lệnh tự động): kỳ sau gom nốt các đơn còn lại.
        $rest = app(SettlementService::class)->generate($partner, $start->copy()->addDay(), $end->copy()->addDays(2), null, false);
        $this->assertNotNull($rest);
        $this->assertSame($orders->count() - $expected, (int) $rest->orders_count);
    }

    public function test_pinus_and_ve_nha_are_eligible(): void
    {
        foreach (['về nhà', 'pinus'] as $keyword) {
            $partner = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('legal_name', 'like', "%{$keyword}%")->first();
            $this->assertNotNull($partner, "Cần đối tác {$keyword} trong DB local.");
            $this->assertTrue(LegacyOrderBackfillService::isEligiblePartner($partner), $keyword);
        }
    }

    public function test_api_previews_for_pinus_without_writing(): void
    {
        $partner = Partner::query()->where('legal_name', 'like', '%pinus%')->first();
        $this->assertNotNull($partner, 'Cần đối tác Pinus trong DB local.');
        $before = Order::withoutGlobalScopes()->where('partner_id', $partner->id)->whereNotNull('collected_by')->count();

        $json = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/partners/{$partner->id}/legacy-orders/settle", ['from' => '2020-01-01', 'rate' => 20])
            ->assertOk()
            ->assertJsonPath('applied', false)
            ->json('data');

        // Chạy thử: trả về đủ cấu trúc số liệu và không ghi gì (không đổi số đơn đã chụp).
        $this->assertArrayHasKey('snapshotted', $json['orders']);
        $this->assertSame($before, Order::withoutGlobalScopes()->where('partner_id', $partner->id)->whereNotNull('collected_by')->count());
    }

    public function test_api_previews_for_ve_nha_partner_without_writing(): void
    {
        $partner = Partner::query()->where('legal_name', 'like', '%về nhà%')->first();
        $this->assertNotNull($partner, 'Cần đối tác Về Nhà trong DB local.');
        $partner->forceFill(['payment_flow_effective_at' => now()])->saveQuietly();

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/partners/{$partner->id}/legacy-orders/settle", ['from' => '2020-01-01', 'rate' => 20])
            ->assertOk()
            ->assertJsonPath('applied', false)
            ->assertJsonStructure(['data' => ['orders' => ['snapshotted', 'finalized'], 'totals', 'settlements']]);

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/partners/{$partner->id}/legacy-orders/settle", [])
            ->assertStatus(422);
    }
}
