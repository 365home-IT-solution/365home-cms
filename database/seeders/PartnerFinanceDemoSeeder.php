<?php

namespace Database\Seeders;

use App\Models\Partner;
use App\Models\PartnerEscrowDeduction;
use App\Models\PartnerRefundClaim;
use App\Models\PartnerSettlement;
use App\Models\User;
use App\Services\EscrowService;
use App\Services\OrderRefundService;
use App\Services\RefundClaimService;
use App\Services\SettlementService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\PartnerPayOsAccount;
use Modules\Product\App\Models\Product;
use Modules\Promotion\App\Models\Coupon;

// DỮ LIỆU MẪU để thử luồng tiền Homestay (ký quỹ, đối soát hoa hồng, hoàn tiền, khuyến mãi, chấm dứt hợp đồng) — 5 đối tác "[DEMO] …" mỗi đối tác một tình huống.
// Chỉ chạy trên máy dev/test:
//   php artisan db:seed --class=PartnerFinanceDemoSeeder --force
// Chạy lại được nhiều lần (tự xoá dữ liệu [DEMO] cũ trước). KHÔNG đụng tới đối tác thật. Không gọi PayOS/ngân hàng nào: khoá PayOS trong dữ liệu mẫu là giả.
class PartnerFinanceDemoSeeder extends Seeder
{
    private const PREFIX = '[DEMO] ';

    private const PASSWORD = 'demo12345';

    private const COUPONS = ['DEMO365', 'DEMOTET', 'DEMODOITAC'];

    private User $admin;

    private array $accounts = [];

    public function run(): void
    {
        // Máy dev có thể đặt APP_ENV=production (Herd) nên không dựa vào APP_ENV: chỉ chạy khi CSDL ở máy này, trừ khi cố ý đặt DEMO_SEED_FORCE=true.
        $host = (string) config('database.connections.' . config('database.default') . '.host');
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) && ! filter_var(env('DEMO_SEED_FORCE'), FILTER_VALIDATE_BOOLEAN)) {
            $this->command->error("CSDL ở {$host} (không phải máy này) — không chạy dữ liệu mẫu. Đặt DEMO_SEED_FORCE=true nếu thật sự muốn.");

            return;
        }

        $this->admin = User::role(config('filament-shield.super_admin.name'))->first()
            ?? tap(User::create(['fullname' => 'Super Admin Demo', 'email' => 'demo-superadmin@example.test', 'password' => self::PASSWORD]), fn (User $u) => $u->assignRole(config('filament-shield.super_admin.name')));

        $this->cleanup();
        $coupons = $this->coupons();

        // 1) Luồng mới đầy đủ: kênh PayOS riêng, ký quỹ đủ, hoa hồng + khuyến mãi, bảng đối soát nhiều trạng thái.
        $a = $this->partner('Biển Xanh', ['phone' => '0971000001', 'tax_code' => '0312345601', 'commission_rate' => '20', 'payment_cycle' => 'monthly'], true, 3);
        $this->payosChannel($a);
        $this->escrow($a, 6_000_000, 5_000_000);
        $this->fullFlowOrders($a, $coupons);

        // 2) Luồng mới nhưng CHƯA có kênh PayOS (365home thu hộ) và ký quỹ thấp → đang tạm ngưng bán.
        $b = $this->partner('Hoàng Hôn', ['phone' => '0971000002', 'tax_code' => '0312345602', 'commission_rate' => '15', 'payment_cycle' => 'biweekly'], true, 2);
        $this->escrow($b, 1_500_000, 5_000_000, grace: false);
        $this->platformCollectedOrders($b);

        // 3) Hợp đồng cũ (365home thu hộ, chưa ký phụ lục) — thử tạo & ký phụ lục.
        $this->partner('Núi Xanh', ['phone' => '0971000003', 'tax_code' => '0312345603', 'commission_rate' => '18', 'payment_cycle' => 'monthly'], false, 2);

        // 4) Đã chấm dứt hợp đồng: ngưng bán, đang giữ ký quỹ, còn điều kiện chặn hoàn.
        $d = $this->partner('Rừng Thông', ['phone' => '0971000004', 'tax_code' => '0312345604', 'commission_rate' => '20', 'payment_cycle' => 'monthly'], true, 2);
        $this->escrow($d, 3_000_000, 3_000_000);
        $this->terminated($d);

        // 5) Tình huống xử lý: yêu cầu hoàn tiền (mở + quá hạn), chargeback, đề xuất trừ khẩn, đơn bị giữ khoản bù.
        $e = $this->partner('Mặt Trời', ['phone' => '0971000005', 'tax_code' => '0312345605', 'commission_rate' => '20', 'payment_cycle' => 'weekly'], true, 4);
        $this->payosChannel($e);
        $this->escrow($e, 8_000_000, 5_000_000);
        $this->incidents($e, $coupons);

        $this->report();
    }

    // ───────────────────────── Dọn dẹp ─────────────────────────

    private function cleanup(): void
    {
        $partners = Partner::withTrashed()->where('name', 'like', self::PREFIX . '%')->get();
        $ids = $partners->pluck('id');

        if ($ids->isNotEmpty()) {
            $orderIds = Order::withoutGlobalScopes()->whereIn('partner_id', $ids)->pluck('id');
            DB::table('order_items')->whereIn('order_id', $orderIds)->delete();
            Order::withoutGlobalScopes()->whereIn('id', $orderIds)->delete();
            Product::withoutGlobalScopes()->whereIn('partner_id', $ids)->get()->each(fn ($p) => $p->categories()->detach());
            Product::withoutGlobalScopes()->whereIn('partner_id', $ids)->delete();
            User::query()->whereIn('partner_id', $ids)->each(fn (User $u) => $u->delete());
            Category::withoutGlobalScopes()->whereIn('partner_id', $ids)->delete();
            // Partner chặn xoá khi hợp đồng còn hiệu lực — đánh dấu chấm dứt (dữ liệu mẫu) rồi mới xoá.
            $partners->each(function (Partner $p) {
                $p->forceFill(['contract_status' => 'terminated'])->saveQuietly();
                $p->forceDelete();
            });
        }

        DB::table('coupon_partner_participations')->whereIn('coupon_id', Coupon::withoutGlobalScopes()->whereIn('code', self::COUPONS)->pluck('id'))->delete();
        Coupon::withoutGlobalScopes()->whereIn('code', self::COUPONS)->delete();
    }

    // ───────────────────────── Dựng đối tác ─────────────────────────

    /** @return array<string, Coupon> */
    private function coupons(): array
    {
        $make = fn (string $code, string $name, array $extra) => Coupon::create([
            'code' => $code, 'name' => $name, 'type' => 'fixed', 'value' => 50_000, 'apply_type' => 'all_rooms', 'start_at' => now()->subMonths(2),
            'end_at' => now()->addMonths(2), 'is_active' => true, 'used_count' => 0, ...$extra,
        ]);

        return [
            'platform' => $make('DEMO365', 'Voucher 365home (365home chịu 100%)', ['partner_id' => null, 'funded_by' => 'platform']),
            'shared'   => $make('DEMOTET', 'Chiến dịch Tết đồng tài trợ (đối tác chịu 40%)', ['partner_id' => null, 'funded_by' => 'shared', 'partner_share_pct' => 40, 'type' => 'percentage', 'value' => 10]),
        ];
    }

    private function partner(string $name, array $attrs, bool $newFlow, int $rooms): Partner
    {
        $partner = Partner::create([
            'partner_type' => Partner::TYPE_HOMESTAY, 'name' => self::PREFIX . $name, 'legal_name' => 'Công ty TNHH ' . $name . ' (DEMO)', 'email' => 'demo.' . Str::slug($name) . '@example.test',
            'address' => '123 Đường Mẫu, Ninh Kiều, Cần Thơ', 'representative_name' => 'Nguyễn Văn Mẫu', 'representative_position' => 'Giám đốc', 'representative_id_number' => '0123456789' . random_int(10, 99),
            'representative_id_issued_at' => '2020-01-01', 'representative_id_issued_place' => 'Cục CSQLHC về TTXH', 'bank_name' => 'MB Bank', 'bank_account_number' => '9704' . random_int(100000, 999999),
            'bank_account_holder' => 'CONG TY TNHH ' . Str::upper(Str::ascii($name)) . ' DEMO', 'status' => true, 'verification_status' => 'approved', 'contract_status' => 'active',
            'contract_signed_at' => now()->subMonths(3), 'contract_expires_at' => now()->addMonths(9), ...$attrs,
        ]);

        if ($newFlow) {
            $partner->forceFill(['payment_flow_effective_at' => now()->subMonths(2)->startOfMonth()])->saveQuietly();
        }

        $owner = $this->user($partner, 'chu', 'Chủ ' . $name, true);
        $this->user($partner, 'nhanvien', 'Nhân viên ' . $name, false);

        $branch = Category::create(['name' => 'Chi nhánh ' . $name, 'slug' => Str::slug($name) . '-' . Str::random(4), 'category_type' => 'product', 'parent_id' => null, 'partner_id' => $partner->id, 'status' => true]);
        foreach (range(1, $rooms) as $i) {
            $room = Product::create(['name' => "{$name} — Phòng {$i}", 'slug' => Str::slug($name) . "-phong-{$i}-" . Str::random(4), 'price' => 500_000 + $i * 100_000, 'partner_id' => $partner->id, 'is_activated' => true]);
            $room->categories()->attach($branch->id);
        }

        $this->accounts[] = [$partner->name, $owner->email, self::PASSWORD];

        return $partner->fresh();
    }

    private function user(Partner $partner, string $role, string $fullname, bool $owner): User
    {
        $user = User::create(['fullname' => $fullname, 'email' => "demo.{$role}." . Str::slug(Str::after($partner->name, self::PREFIX)) . '@example.test', 'password' => self::PASSWORD, 'partner_id' => $partner->id]);
        if ($owner) {
            $user->assignRole(config('permission.models.role')::firstOrCreate(['name' => 'partner', 'guard_name' => 'web']));
        }

        return $user;
    }

    private function payosChannel(Partner $partner): void
    {
        PartnerPayOsAccount::create([
            'partner_id' => $partner->id, 'is_active' => true, 'client_id' => 'demo-client-' . Str::random(6), 'api_key' => 'demo-api-' . Str::random(10), 'checksum_key' => 'demo-checksum-' . Str::random(10),
            'account_holder' => $partner->bank_account_holder, 'note' => 'DỮ LIỆU MẪU — khoá PayOS giả, không dùng được để thu tiền thật.', 'webhook_confirmed_at' => now(), 'updated_by' => $this->admin->id,
        ]);
    }

    private function escrow(Partner $partner, int $balance, int $min, bool $grace = true): void
    {
        $escrow = app(EscrowService::class);
        $escrow->setMinAmount($partner, $min, now()->subDays(40), $this->admin);
        $escrow->deposit($partner->fresh(), $balance, ['reason' => 'Nạp ký quỹ mẫu (chuyển khoản ngoài)', 'reference' => 'DEMO-' . strtoupper(Str::random(6))], $this->admin);
        $escrow->refreshState($partner->fresh());
    }

    // ───────────────────────── Đơn hàng ─────────────────────────

    private function order(Partner $partner, int $amount, string $buyer, array $extra = [], string $method = 'PayOS'): Order
    {
        $room = Product::withoutGlobalScopes()->where('partner_id', $partner->id)->inRandomOrder()->first();
        $checkin = now()->subDays(2)->setTime(14, 0);

        $order = Order::create([
            'partner_id' => $partner->id, 'order_code' => (string) random_int(1_700_000_000_000, 1_799_999_999_999), 'amount' => $amount, 'full_amount' => $amount,
            'description' => 'Đặt phòng - ' . $room->name . ' (DEMO)', 'buyer_name' => $buyer, 'buyer_phone' => '09' . random_int(10_000_000, 99_999_999), 'payment_method' => $method,
            'status' => 'paid', 'order_status' => 'pending', 'guest_count' => 2, 'category_id' => $room->categories()->value('categories.id'), ...$extra,
        ]);
        $order->items()->create(['product_id' => $room->id, 'name' => $room->name, 'price' => $amount, 'quantity' => 1, 'checkin_date' => $checkin, 'checkout_date' => $checkin->copy()->addDay()->setTime(12, 0)]);

        return $order->fresh();
    }

    /** Trả phòng → chốt hoa hồng; rồi dời mốc chốt về $finalizedAt để đơn rơi đúng vào kỳ đối soát muốn demo. */
    private function complete(Order $order, Carbon $finalizedAt): Order
    {
        $order->update(['order_status' => 'checked_out', 'checked_out_at' => $finalizedAt, 'checked_in_at' => $finalizedAt->copy()->subDay()]);
        $order = $order->fresh();
        if ($order->commission_finalized_at) {
            DB::table('orders')->where('id', $order->id)->update(['commission_finalized_at' => $finalizedAt, 'created_at' => $finalizedAt->copy()->subDays(5)]);
        }

        return $order->fresh();
    }

    private function voucher(Coupon $coupon, int $amount): array
    {
        return ['coupon_codes' => [$coupon->code], 'coupon_discount_amounts' => [$coupon->code => $amount]];
    }

    private function fullFlowOrders(Partner $partner, array $coupons): void
    {
        $settlements = app(SettlementService::class);
        $prev = now()->subMonthNoOverflow();
        $older = now()->subMonthsNoOverflow(2);

        // Kỳ cách đây 2 tháng → ĐÃ NỘP.
        foreach ([[1_200_000, null], [900_000, null], [1_500_000, null]] as $i => [$amount, $coupon]) {
            $this->complete($this->order($partner, $amount, "Khách kỳ cũ {$i}"), $older->copy()->day(10 + $i));
        }
        $old = $settlements->generate($partner->fresh(), $older->copy()->startOfMonth(), $older->copy()->endOfMonth()->startOfDay(), $this->admin);
        if ($old) {
            $old = $settlements->send($old, $this->admin);
            $settlements->markPaid($old->fresh(), 'DEMO-FT-' . strtoupper(Str::random(6)));
        }

        // Kỳ tháng trước → ĐÃ GỬI (đối tác phải nộp, còn hạn) — có voucher 365home, đồng tài trợ, đơn hoàn một phần, đơn huỷ nhận tiền.
        $this->complete($this->order($partner, 1_000_000, 'Trần Thị B'), $prev->copy()->day(5));
        $this->complete($this->order($partner, 950_000, 'Lê Văn C', $this->voucher($coupons['platform'], 50_000)), $prev->copy()->day(8));
        $this->complete($this->order($partner, 1_350_000, 'Phạm Thị D', $this->voucher($coupons['shared'], 150_000)), $prev->copy()->day(12));
        $this->complete($this->order($partner, 2_200_000, 'Hoàng Văn E', [], 'cod'), $prev->copy()->day(15));
        $partial = $this->order($partner, 1_000_000, 'Võ Thị F', $this->voucher($coupons['platform'], 50_000));
        $partial->update(['status' => 'refunded', 'refund_amount' => 400_000, 'refund_method' => 'transfer', 'refund_reason' => 'Khách rút ngắn kỳ nghỉ', 'refunded_at' => $prev->copy()->day(18), 'refund_paid_by' => 'partner']);
        $partial = $partial->fresh();
        if ($partial->commission_finalized_at) {
            DB::table('orders')->where('id', $partial->id)->update(['commission_finalized_at' => $prev->copy()->day(18)]);
        }

        $sent = $settlements->generate($partner->fresh(), $prev->copy()->startOfMonth(), $prev->copy()->endOfMonth()->startOfDay(), $this->admin);
        if ($sent) {
            $sent = $settlements->send($sent, $this->admin);
            // Một khiếu nại đang chờ để thử xử lý.
            $owner = User::query()->where('partner_id', $partner->id)->role('partner')->first();
            $first = $sent->orders()->orderBy('id')->first();
            if ($owner && $first) {
                $settlements->disputeOrder($sent->fresh(), $first->order_code, 'Khách đã huỷ một phần dịch vụ, đề nghị xem lại hoa hồng (DEMO).', $owner);
            }
        }

        // Tháng này: đơn đã hoàn thành chưa vào kỳ nào + đơn đang ở + đơn sắp tới (để thấy "chưa chốt").
        $this->complete($this->order($partner, 1_800_000, 'Đặng Văn G'), now()->subDays(3));
        $this->complete($this->order($partner, 1_100_000, 'Bùi Thị H', $this->voucher($coupons['platform'], 50_000)), now()->subDays(2));
        $this->order($partner, 900_000, 'Ngô Văn I');
        $staying = $this->order($partner, 1_250_000, 'Đỗ Thị K');
        $staying->update(['order_status' => 'staying', 'checked_in_at' => now()->subHours(6)]);

        // Một bảng NHÁP của tuần/tháng hiện tại do Super Admin sinh tay để thử gửi.
        $settlements->generate($partner->fresh(), now()->startOfMonth(), now()->endOfMonth()->startOfDay(), $this->admin);

        // Đề xuất trừ đang chờ đối tác phản hồi.
        app(EscrowService::class)->proposeDeduction($partner->fresh(), [
            'type' => 'deduct_penalty', 'case_code' => 'contract_violation', 'amount' => 500_000, 'reason' => 'Đối tác tự ý đổi giá phòng trên kênh khác thấp hơn giá cam kết (DEMO).', 'reference' => 'BB-DEMO-01',
        ], $this->admin);
    }

    private function platformCollectedOrders(Partner $partner): void
    {
        $settlements = app(SettlementService::class);
        $prev = now()->subMonthNoOverflow();

        // Đối tác chưa có kênh PayOS → 365home thu hộ: bảng đối soát ra kết quả "365home chi cho đối tác".
        foreach ([[2_000_000, 3], [1_500_000, 7], [2_400_000, 11]] as $i => [$amount, $day]) {
            $this->complete($this->order($partner, $amount, "Khách thu hộ {$i}"), $prev->copy()->day($day));
        }
        $sent = $settlements->generate($partner->fresh(), $prev->copy()->startOfMonth(), $prev->copy()->endOfMonth()->startOfDay(), $this->admin);
        $sent && $settlements->send($sent, $this->admin);
        $this->complete($this->order($partner, 1_300_000, 'Khách thu hộ mới'), now()->subDay());
    }

    private function terminated(Partner $partner): void
    {
        $this->complete($this->order($partner, 1_000_000, 'Khách cuối cùng'), now()->subDays(20));
        $serving = $this->order($partner, 800_000, 'Khách đang phục vụ');
        $serving->update(['order_status' => 'staying', 'checked_in_at' => now()->subHours(3)]);

        $escrow = app(EscrowService::class);
        $escrow->proposeDeduction($partner->fresh(), ['type' => 'deduct_damage', 'case_code' => 'damage_compensation', 'amount' => 700_000, 'reason' => 'Bồi thường tài sản khách bị mất tại cơ sở (DEMO).', 'is_urgent' => true], $this->admin);
        $partner->fresh()->update(['contract_status' => 'terminated']);
        $escrow->onContractTerminated($partner->fresh(), $this->admin);
        $partner->forceFill(['escrow_terminated_at' => now()->subDays(12)])->saveQuietly();
        $escrow->refreshState($partner->fresh());
    }

    private function incidents(Partner $partner, array $coupons): void
    {
        $claims = app(RefundClaimService::class);
        $escrow = app(EscrowService::class);

        // Yêu cầu hoàn tiền: một còn hạn, một QUÁ HẠN (thử "Hoàn thay & trừ ký quỹ"), một đã hoàn thay.
        $open = $this->complete($this->order($partner, 700_000, 'Khách cần hoàn (còn hạn)', [], 'cod'), now()->subDay());
        $claims->open($open->fresh()->forceFill(['status' => 'paid']), 700_000, 'Khách huỷ hợp lệ trước 48 giờ (DEMO).', $this->admin);

        $late = $this->order($partner, 1_200_000, 'Khách cần hoàn (quá hạn)', [], 'cod');
        $lateClaim = $claims->open($late, 1_200_000, 'Phòng không đúng mô tả, khách yêu cầu hoàn tiền (DEMO).', $this->admin);
        $lateClaim->update(['due_at' => now()->subHours(5), 'requested_at' => now()->subHours(29)]);
        $claims->escalateOverdue();

        $done = $this->order($partner, 600_000, 'Khách đã được hoàn thay', [], 'cod');
        $doneClaim = $claims->open($done, 600_000, 'Đối tác không phản hồi yêu cầu hoàn (DEMO).', $this->admin);
        $doneClaim->update(['due_at' => now()->subDays(2), 'requested_at' => now()->subDays(3)]);
        $claims->escalateOverdue();
        $claims->refundOnBehalf($doneClaim->fresh(), 'transfer', $this->admin);

        // Chargeback + đề xuất trừ khẩn đã trừ ngay (đối tác có thể khiếu nại).
        $cb = $this->order($partner, 1_500_000, 'Khách khiếu nại ngân hàng', [], 'PayOS');
        $escrow->proposeDeduction($partner->fresh(), ['type' => 'deduct_refund', 'case_code' => 'chargeback', 'amount' => 1_500_000, 'reason' => 'Chargeback đơn #' . $cb->order_code . ' (DEMO).', 'order_code' => $cb->order_code, 'reference' => 'PAYOS-CB-DEMO-1'], $this->admin);
        $escrow->proposeDeduction($partner->fresh(), ['type' => 'deduct_penalty', 'case_code' => 'host_cancelled', 'amount' => 900_000, 'reason' => 'Đối tác huỷ phòng đã xác nhận (hết phòng) (DEMO).', 'order_code' => $cb->order_code, 'is_urgent' => true], $this->admin);

        // Đơn nghi giả → bị giữ khoản bù (người đặt trùng SĐT chủ đối tác).
        $this->complete($this->order($partner, 950_000, 'Chủ đối tác tự đặt', $this->voucher($coupons['platform'], 50_000) + ['buyer_phone' => $partner->phone]), now()->subDays(2));
    }

    // ───────────────────────── Báo cáo ─────────────────────────

    private function report(): void
    {
        $this->command->info('Đã tạo dữ liệu mẫu luồng tiền Homestay (5 đối tác [DEMO]).');
        $this->command->table(['Đối tác', 'Tài khoản chủ đối tác', 'Mật khẩu'], $this->accounts);
        $this->command->line('Mã giảm giá mẫu: DEMO365 (365home chịu 100%), DEMOTET (đồng tài trợ, đối tác chịu 40%).');
        $this->command->line('Super Admin dùng tài khoản sẵn có. Xem: Quản lý › Ký quỹ đối tác / Đối soát hoa hồng / Yêu cầu hoàn tiền; chạy php artisan finance:check --partners.');
        $this->command->line('Xoá dữ liệu mẫu: chạy lại seeder này sẽ dọn dữ liệu [DEMO] cũ trước khi tạo mới.');
    }
}
