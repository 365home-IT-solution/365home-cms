<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Partner;
use App\Models\PartnerRefundClaim;
use App\Models\User;
use App\Services\EscrowService;
use App\Services\RefundClaimService;
use App\Services\SettlementService;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Modules\Product\App\Models\Product;
use Modules\Promotion\App\Models\Coupon;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

// CHUẨN BỊ DỮ LIỆU KIỂM TRA THỐNG KÊ HOA HỒNG trên máy local:
//  1) Giữ lại đúng 2 đối tác (mặc định "Test Cần Thơ" và "Test Ngoài Cần Thơ"), đối tác "365" cùng dữ liệu của nó và đối tác hệ thống;
//     xoá HẲN mọi đối tác khác (kèm đơn, phòng, chi nhánh, tài khoản, giấy tờ, hợp đồng, ký quỹ, đối soát...).
//  2) Xoá đơn cũ của 2 đối tác rồi tạo đơn mới TRONG THÁNG NÀY đúng như khách đặt phòng thật: phòng thật, số đêm thật, giá = giá phòng × số đêm
//     (trừ voucher nếu có), thanh toán PayOS hoặc tiền mặt; hoa hồng, khoản bù, thu hộ do chính các service nghiệp vụ tính (không ghi tay).
//  3) Nạp ký quỹ, tạo bảng đối soát kỳ này cho từng đối tác.
// Luôn sao lưu DB trước khi chạy. --dry-run: chạy thử trong transaction rồi huỷ, chỉ in số liệu.
class PrepareStatsTestData extends Command
{
    protected $signature = 'stats:prepare-test-data
        {--dry-run : Chạy thử trong transaction rồi huỷ, không thay đổi DB}
        {--force : Cho phép chạy khi APP_ENV=production (máy local thường để production)}
        {--keep=* : Id (hoặc tiền tố id) của đối tác giữ lại; mặc định hai đối tác Test Cần Thơ và Test Ngoài Cần Thơ}';

    protected $description = 'Xoá các đối tác không liên quan và tạo đơn tháng này của 2 đối tác để kiểm tra thống kê hoa hồng';

    private const NAME_CAN_THO = 'Test Cần Thơ';

    private const NAME_OUTSIDE = 'Test Ngoài CẦn Thơ';

    private string $prefix;

    private array $deleted = [];

    public function handle(): int
    {
        $host = (string) config('database.connections.' . config('database.default') . '.host');
        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $this->error("CSDL ở {$host} (không phải máy này) — không chạy.");

            return self::FAILURE;
        }
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('APP_ENV=production — thêm --force nếu bạn chắc chắn (đây là máy local).');

            return self::FAILURE;
        }

        // Lệnh này chỉ dựng dữ liệu: không gửi email thật và không gọi dịch vụ ngoài (FCM...) khi hệ thống phát thông báo cho đối tác.
        config(['mail.default' => 'log']);
        \Illuminate\Support\Facades\Http::fake();

        $this->prefix = (string) DB::getTablePrefix();
        $canTho = $this->resolve(self::NAME_CAN_THO);
        $outside = $this->resolve(self::NAME_OUTSIDE);
        if (! $canTho || ! $outside) {
            $this->error('Không tìm thấy 2 đối tác cần giữ.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $this->info(($dry ? '[CHẠY THỬ] ' : '') . 'Giữ lại: ' . $canTho->name . ' (' . substr($canTho->id, 0, 8) . ') và ' . $outside->name . ' (' . substr($outside->id, 0, 8) . ')');

        DB::beginTransaction();
        try {
            $this->removeUnrelatedPartners([$canTho->id, $outside->id]);
            $this->resetAndSeed($canTho->fresh(), $outside->fresh());

            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Lỗi, đã huỷ mọi thay đổi: ' . $e->getMessage());
            $this->line($e->getFile() . ':' . $e->getLine());

            return self::FAILURE;
        }

        $this->line('');
        $this->info($dry ? 'ĐÃ CHẠY THỬ — không có gì thay đổi.' : 'HOÀN TẤT.');

        return self::SUCCESS;
    }

    private function resolve(string $name): ?Partner
    {
        return Partner::withTrashed()->where('partner_type', Partner::TYPE_HOMESTAY)
            ->where(fn ($q) => $q->where('name', $name)->orWhere('legal_name', $name))->orderBy('created_at', 'desc')->first();
    }

    // ───────────────────────── 1. Xoá đối tác không liên quan ─────────────────────────

    /** @param list<string> $keepIds */
    private function removeUnrelatedPartners(array $keepIds): void
    {
        // Đối tác "365" (dữ liệu thật của công ty) giữ nguyên: lấy hồ sơ tên "365" Homestay có nhiều đơn nhất.
        $platformId = Order::withoutGlobalScopes()->selectRaw('partner_id, count(*) as c')
            ->whereIn('partner_id', Partner::withTrashed()->where('name', '365')->where('partner_type', Partner::TYPE_HOMESTAY)->select('id'))
            ->groupBy('partner_id')->orderByDesc('c')->value('partner_id');
        $keep = collect($keepIds)->push($platformId)->filter()->unique();
        $system = Partner::withTrashed()->get()->filter(fn (Partner $p) => $p->isSystemPartner())->pluck('id');
        $keep = $keep->merge($system)->unique();

        $victims = Partner::withTrashed()->whereNotIn('id', $keep)->get(['id', 'name', 'partner_type', 'deleted_at']);
        if ($victims->isEmpty()) {
            $this->line('Không có đối tác nào cần xoá.');

            return;
        }

        $this->line('');
        $this->line('Đối tác sẽ xoá hẳn (' . $victims->count() . '):');
        foreach ($victims as $v) {
            $this->line('  - ' . str_pad(mb_substr((string) $v->name, 0, 40), 42) . $v->partner_type . ($v->deleted_at ? '  (đã xoá mềm)' : ''));
        }
        $victimIds = $victims->pluck('id')->all();

        // Tài khoản đăng nhập giữ lại cho Test Cần Thơ: người dùng test nmquang1997@gmail.com đang gắn vào đối tác sắp xoá → chuyển sang Test Cần Thơ.
        $this->adoptOwner($keepIds[0], 'nmquang1997@gmail.com');

        $this->purgeOrders(Order::withoutGlobalScopes()->whereIn('partner_id', $victimIds)->pluck('id')->all());
        $this->purgePartnerTables($victimIds);

        $products = Product::withoutGlobalScopes()->whereIn('partner_id', $victimIds)->pluck('id')->all();
        $categories = Category::withoutGlobalScopes()->whereIn('partner_id', $victimIds)->pluck('id')->all();
        $this->purgeMedia($victimIds, $products, $categories);
        $this->deleteIn('categorizables', 'categorizable_id', array_merge($products, $categories));

        $users = User::withoutGlobalScopes()->whereIn('partner_id', $victimIds)->pluck('id')->all();
        foreach ([['model_has_roles', 'model_id'], ['model_has_permissions', 'model_id'], ['personal_access_tokens', 'tokenable_id'], ['notifications', 'notifiable_id'], ['fcm_tokens', 'user_id']] as [$t, $c]) {
            $this->deleteIn($t, $c, $users);
        }

        // Các bảng có FK tới products/categories/users được dọn tự động (CASCADE/SET NULL). Xoá gốc theo thứ tự phụ thuộc, lặp lại nếu còn ràng buộc.
        $this->deleteRepeatedly([['products', 'id', $products], ['categories', 'id', $categories], ['users', 'id', $users], ['partners', 'id', $victimIds]]);
    }

    private function adoptOwner(string $partnerId, string $email): void
    {
        $user = User::withoutGlobalScopes()->where('email', $email)->first();
        if ($user && $user->partner_id !== $partnerId && ! $user->isSuperAdmin()) {
            $user->forceFill(['partner_id' => $partnerId])->saveQuietly();
            $this->line("Chuyển tài khoản {$email} sang " . self::NAME_CAN_THO . '.');
        }
    }

    /** @param list<int|string> $orderIds */
    private function purgeOrders(array $orderIds): void
    {
        if ($orderIds === []) {
            return;
        }

        $schema = (string) DB::getDatabaseName();
        $tables = $this->tablesWithColumn($schema, 'order_id')->reject(fn ($t) => $t === 'orders')->values();

        foreach ($tables as $table) {
            $this->deleteIn($table, 'order_id', $orderIds);
        }

        // Bảng đối soát tham chiếu qua orders.settlement_id: gỡ liên kết các đơn khác (nếu có) rồi xoá bảng của đơn bị xoá.
        $settlementIds = DB::table('orders')->whereIn('id', $orderIds)->whereNotNull('settlement_id')->pluck('settlement_id')->unique()->all();
        DB::table('orders')->whereIn('id', $orderIds)->update(['settlement_id' => null]);
        $this->deleteIn('partner_settlement_disputes', 'settlement_id', $settlementIds);
        DB::table('orders')->whereIn('settlement_id', $settlementIds)->update(['settlement_id' => null]);
        $this->deleteIn('partner_settlements', 'id', $settlementIds);

        $this->deleteIn('orders', 'id', $orderIds);
    }

    /** Mọi bảng có cột partner_id (trừ bảng gốc xử lý riêng): xoá theo partner_id, lặp lại tới khi hết ràng buộc FK. @param list<string> $partnerIds */
    private function purgePartnerTables(array $partnerIds): void
    {
        $schema = (string) DB::getDatabaseName();
        $tables = $this->tablesWithColumn($schema, 'partner_id')->reject(fn ($t) => in_array($t, ['users', 'products', 'categories', 'orders'], true))->values()->all();

        $specs = array_map(fn ($t) => [$t, 'partner_id', $partnerIds], $tables);
        $this->deleteRepeatedly($specs);
    }

    /** @param list<array{0: string, 1: string, 2: array<int, int|string>}> $specs */
    private function deleteRepeatedly(array $specs): void
    {
        $pending = $specs;
        $errors = [];
        for ($pass = 0; $pass < 8 && $pending !== []; $pass++) {
            $next = [];
            foreach ($pending as [$table, $column, $ids]) {
                try {
                    $this->deleteIn($table, $column, $ids, throwOnFk: true);
                } catch (QueryException $e) {
                    $errors[$table] = $e->getMessage();
                    $next[] = [$table, $column, $ids];
                }
            }
            if (count($next) === count($pending)) {
                break;
            }
            $pending = $next;
        }

        if ($pending !== []) {
            throw new \RuntimeException('Không xoá được (còn ràng buộc): ' . collect($pending)->map(fn ($p) => $p[0] . ' — ' . mb_substr((string) ($errors[$p[0]] ?? ''), 0, 260))->implode(' | '));
        }
    }

    /** @param array<int, int|string> $ids */
    private function deleteIn(string $table, string $column, array $ids, bool $throwOnFk = false): void
    {
        if ($ids === []) {
            return;
        }

        try {
            $count = 0;
            foreach (array_chunk($ids, 500) as $chunk) {
                $count += DB::table($table)->whereIn($column, $chunk)->delete();
            }
            if ($count > 0) {
                $this->deleted[$table] = ($this->deleted[$table] ?? 0) + $count;
                $this->line("    đã xoá {$count} dòng ở {$table}");
            }
        } catch (QueryException $e) {
            if ($throwOnFk) {
                throw $e;
            }
            // Bảng không tồn tại hoặc cột không có: bỏ qua.
        }
    }

    /** Tên các bảng (đã bỏ tiền tố) có cột $column — đọc từ information_schema (không qua tiền tố bảng của Laravel). */
    private function tablesWithColumn(string $schema, string $column): \Illuminate\Support\Collection
    {
        return collect(DB::select("select c.table_name as t from information_schema.columns c join information_schema.tables t on t.table_schema = c.table_schema and t.table_name = c.table_name and t.table_type = 'BASE TABLE' where c.table_schema = ? and c.column_name = ?", [$schema, $column]))
            ->map(fn ($r) => $this->unprefix((string) $r->t))->filter(fn ($t) => $this->prefix === '' || true)->values();
    }

    private function unprefix(string $table): string
    {
        return $this->prefix !== '' && str_starts_with($table, $this->prefix) ? substr($table, strlen($this->prefix)) : $table;
    }

    /** Xoá tệp đính kèm (Spatie media) của các bản ghi sắp xoá — dùng model để file trên đĩa cũng bị xoá. */
    private function purgeMedia(array $partnerIds, array $productIds, array $categoryIds): void
    {
        $versionIds = DB::table('partner_contract_versions')->whereIn('partner_id', $partnerIds)->pluck('id')->all();
        $docIds = DB::table('partner_legal_documents')->whereIn('partner_id', $partnerIds)->pluck('id')->all();
        $sets = [
            [Partner::class, $partnerIds], [\App\Models\PartnerContractVersion::class, $versionIds], [\App\Models\PartnerLegalDocument::class, $docIds],
            [Product::class, $productIds], [Category::class, $categoryIds],
        ];

        $count = 0;
        foreach ($sets as [$class, $ids]) {
            if ($ids === []) {
                continue;
            }
            Media::query()->where('model_type', (new $class)->getMorphClass())->whereIn('model_id', $ids)->get()->each(function (Media $media) use (&$count) {
                $media->delete();
                $count++;
            });
        }
        if ($count > 0) {
            $this->line("    đã xoá {$count} tệp đính kèm");
        }
    }

    // ───────────────────────── 2. Làm lại dữ liệu của 2 đối tác ─────────────────────────

    private function resetAndSeed(Partner $canTho, Partner $outside): void
    {
        $this->line('');
        $this->info('Làm lại dữ liệu của 2 đối tác giữ lại…');

        $admin = User::role(config('filament-shield.super_admin.name'))->firstOrFail();
        $ids = [$canTho->id, $outside->id];

        // Xoá toàn bộ đơn cũ (và đối soát, hoàn tiền, đề xuất trừ ký quỹ gắn với đơn/đối tác) của 2 đối tác.
        $this->purgeOrders(Order::withoutGlobalScopes()->whereIn('partner_id', $ids)->pluck('id')->all());
        foreach (['partner_refund_claims', 'partner_escrow_entries', 'partner_escrow_deductions', 'partner_escrow_deposits', 'partner_deposit_transactions', 'partner_settlements'] as $t) {
            $this->deleteIn($t, 'partner_id', $ids);
        }
        $this->deleteIn('coupon_usage_logs', 'partner_id', $ids);

        // Voucher so sánh cũ.
        $oldCoupons = Coupon::withoutGlobalScopes()->where('code', 'SOSANH365')->pluck('id')->all();
        $this->deleteIn('coupon_partner_participations', 'coupon_id', $oldCoupons);
        $this->deleteIn('coupons', 'id', $oldCoupons);

        // Đưa 2 đối tác về trạng thái hoạt động đúng như đang kinh doanh.
        foreach ([$canTho, $outside] as $p) {
            if ($p->trashed()) {
                $p->restore();
            }
        }
        $canTho->forceFill([
            'status' => true, 'contract_status' => 'active', 'verification_status' => 'approved', 'contract_expires_at' => now()->addYear()->toDateString(),
            'escrow_terminated_at' => null, 'escrow_suspended_at' => null, 'escrow_balance' => 0,
        ])->saveQuietly();
        $outside->forceFill(['escrow_terminated_at' => null, 'escrow_suspended_at' => null, 'escrow_balance' => 0])->saveQuietly();

        $escrow = app(EscrowService::class);
        $escrow->setMinAmount($canTho->fresh(), 5_000_000, now()->subDays(30), $admin);
        $escrow->deposit($canTho->fresh(), 5_000_000, ['reason' => 'Nạp ký quỹ ban đầu (dữ liệu kiểm tra thống kê)']);

        // Đối tác ngoài Cần Thơ: đang trong tháng miễn phí đầu → chưa bắt ký quỹ, đơn tự thu không hoa hồng.
        if ($outside->fee_free_until === null || $outside->fee_free_until->isPast()) {
            $outside->forceFill(['fee_free_until' => now()->addDays(30)])->saveQuietly();
        }

        $voucher = Coupon::create([
            'code' => 'THANG10-365', 'name' => 'Voucher 365HOME tháng 10 (giảm 50.000đ)', 'type' => 'fixed', 'value' => 50_000, 'apply_type' => 'all_rooms', 'partner_id' => null,
            'funded_by' => 'platform', 'start_at' => now()->startOfMonth(), 'end_at' => now()->endOfMonth(), 'is_active' => true, 'used_count' => 0,
        ]);

        foreach ([$canTho->fresh(), $outside->fresh()] as $partner) {
            $this->seedPartner($partner, $voucher, $admin);
        }

        $this->report([$canTho->fresh(), $outside->fresh()]);
    }

    private function seedPartner(Partner $partner, Coupon $voucher, User $admin): void
    {
        $branch = Category::withoutGlobalScopes()->where('partner_id', $partner->id)->orderBy('id')->first();
        $rooms = Product::withoutGlobalScopes()->where('partner_id', $partner->id)->orderBy('name')->get()->values();
        if (! $branch || $rooms->count() < 2) {
            throw new \RuntimeException("Đối tác {$partner->name} thiếu chi nhánh hoặc phòng.");
        }

        // Tên rõ ràng, bỏ nhãn kỹ thuật.
        $branch->forceFill(['name' => 'Chi nhánh ' . $partner->name])->saveQuietly();
        foreach ($rooms as $i => $room) {
            $room->forceFill(['name' => 'Phòng ' . ($i + 1) . ' — ' . $partner->name])->saveQuietly();
        }
        [$r1, $r2] = [$rooms[0], $rooms[1]];

        $year = now()->year;
        $month = now()->month;
        $d = fn (int $day, int $hour = 14) => Carbon::create($year, $month, $day, $hour, 0, 0);

        // [số thứ tự, phòng, nhận phòng (ngày), số đêm, thanh toán, voucher, khách, điện thoại, trạng thái]
        $bookings = [
            ['r' => $r1, 'in' => 1, 'n' => 1, 'pay' => 'PayOS', 'v' => false, 'guest' => 'Nguyễn Thị Hồng', 'phone' => '0903123401', 'state' => 'done'],
            ['r' => $r2, 'in' => 3, 'n' => 2, 'pay' => 'cod', 'v' => false, 'guest' => 'Trần Văn Bình', 'phone' => '0903123402', 'state' => 'done'],
            ['r' => $r1, 'in' => 4, 'n' => 1, 'pay' => 'PayOS', 'v' => true, 'guest' => 'Lê Minh Khoa', 'phone' => '0903123403', 'state' => 'done'],
            ['r' => $r1, 'in' => 6, 'n' => 2, 'pay' => 'PayOS', 'v' => false, 'guest' => 'Phạm Thu Hà', 'phone' => '0903123404', 'state' => 'done'],
            ['r' => $r2, 'in' => 7, 'n' => 1, 'pay' => 'cod', 'v' => true, 'guest' => 'Võ Quốc Tuấn', 'phone' => '0903123405', 'state' => 'done'],
            ['r' => $r2, 'in' => 9, 'n' => 1, 'pay' => 'PayOS', 'v' => false, 'guest' => 'Đặng Ngọc Lan', 'phone' => '0903123406', 'state' => 'done'],
            ['r' => $r1, 'in' => 10, 'n' => 1, 'pay' => 'cod', 'v' => false, 'guest' => 'Huỳnh Gia Bảo', 'phone' => '0903123407', 'state' => 'refund'],
            ['r' => $r2, 'in' => 11, 'n' => 2, 'pay' => 'PayOS', 'v' => false, 'guest' => 'Bùi Anh Thư', 'phone' => '0903123408', 'state' => 'staying'],
            ['r' => $r1, 'in' => 13, 'n' => 1, 'pay' => 'PayOS', 'v' => false, 'guest' => 'Ngô Thanh Sơn', 'phone' => '0903123409', 'state' => 'upcoming'],
        ];

        $claims = app(RefundClaimService::class);
        foreach ($bookings as $b) {
            $checkin = $d($b['in']);
            $checkout = $checkin->copy()->addDays($b['n'])->setTime(12, 0);
            $gross = (int) $b['r']->price * $b['n'];
            $discount = $b['v'] ? 50_000 : 0;
            $amount = $gross - $discount;
            $extra = $b['v'] ? ['coupon_codes' => [$voucher->code], 'coupon_discount_amounts' => [$voucher->code => $discount]] : [];
            $bookedAt = $checkin->copy()->subDays(2)->setTime(9, 30);

            $order = Order::create([
                'partner_id' => $partner->id, 'order_code' => 'HS' . random_int(10_000_000, 99_999_999),
                'amount' => $amount, 'full_amount' => $amount,
                'description' => sprintf('Đặt phòng - %s (%s → %s, %d đêm)', $b['r']->name, $checkin->format('d/m'), $checkout->format('d/m'), $b['n']),
                'buyer_name' => $b['guest'], 'buyer_phone' => $b['phone'], 'payment_method' => $b['pay'], 'status' => 'paid', 'order_status' => 'pending',
                'guest_count' => 2, 'category_id' => $branch->id, ...$extra,
            ]);
            $order->items()->create([
                'product_id' => $b['r']->id, 'name' => $b['r']->name, 'price' => $gross, 'quantity' => 1,
                'checkin_date' => $checkin, 'checkout_date' => $checkout,
            ]);
            DB::table('orders')->where('id', $order->id)->update(['created_at' => $bookedAt, 'updated_at' => $bookedAt]);

            if (in_array($b['state'], ['done', 'refund'], true)) {
                $order->refresh()->update(['order_status' => 'checked_out', 'checked_in_at' => $checkin, 'checked_out_at' => $checkout]);
                // Hoa hồng được chốt đúng lúc khách trả phòng (không phải lúc chạy lệnh).
                DB::table('orders')->where('id', $order->id)->whereNotNull('commission_finalized_at')->update(['commission_finalized_at' => $checkout, 'updated_at' => $checkout]);
            } elseif ($b['state'] === 'staying') {
                $order->refresh()->update(['order_status' => 'checked_in', 'checked_in_at' => $checkin]);
            }

            if ($b['state'] === 'refund') {
                $claims->open($order->fresh(), $amount, 'Khách huỷ vì việc gia đình, đã báo trước 24 giờ (yêu cầu hoàn tiền kiểm tra thống kê)', $admin);
            }
        }

        // Bảng đối soát kỳ này (từ đầu tháng đến hôm nay) và gửi cho đối tác.
        $settlement = app(SettlementService::class)->generate($partner->fresh(), now()->startOfMonth(), now()->startOfDay(), $admin);
        if ($settlement) {
            app(SettlementService::class)->send($settlement->fresh(), $admin);
        }
    }

    // ───────────────────────── Báo cáo ─────────────────────────

    /** @param list<Partner> $partners */
    private function report(array $partners): void
    {
        $money = fn ($v) => number_format((int) $v, 0, ',', '.') . 'đ';
        foreach ($partners as $partner) {
            $this->line('');
            $this->info($partner->name . ' — ' . $partner->email . ' — hoa hồng hợp đồng ' . $partner->commission_rate . '%'
                . ($partner->fee_free_until ? ' — MIỄN PHÍ đến ' . $partner->fee_free_until->format('d/m/Y') : ''));

            $rows = Order::withoutGlobalScopes()->where('partner_id', $partner->id)->orderBy('created_at')->get()->map(function (Order $o) use ($money) {
                $item = $o->items()->first();

                return [
                    $o->order_code, $o->buyer_name,
                    $item ? $item->checkin_date->format('d/m') . '→' . $item->checkout_date->format('d/m') : '—',
                    $money($o->amount), $o->payment_method, $o->collected_by ?? '—', $o->order_status,
                    $o->commission_waived ? 'MIỄN' : ($o->commission_rate !== null ? rtrim(rtrim((string) $o->commission_rate, '0'), '.') . '%' : '—'),
                    $o->commission_amount !== null ? $money($o->commission_amount) : 'chưa chốt',
                    $money($o->platform_subsidy), $o->subsidy_held_at ? 'GIỮ' : '—', $o->status === 'refunded' ? 'đã hoàn' : '',
                ];
            })->all();
            $this->table(['Mã đơn', 'Khách', 'Lưu trú', 'Tiền', 'Thanh toán', 'Tiền về', 'Trạng thái', 'HH', 'Hoa hồng', 'Bù', 'Khoản bù', 'Hoàn'], $rows);

            $s = \App\Models\PartnerSettlement::query()->where('partner_id', $partner->id)->latest('id')->first();
            $this->line($s ? sprintf('  Đối soát %s (%s): %d đơn | hoa hồng %s | 365home bù %s | thu hộ %s | KẾT QUẢ %s', $s->code, $s->status, $s->orders_count, $money($s->commission_total), $money($s->subsidy_total), $money($s->platform_collected_total), $money($s->net_amount)) : '  Không có bảng đối soát.');
            $claims = PartnerRefundClaim::query()->where('partner_id', $partner->id)->count();
            $this->line('  Ký quỹ: ' . $money($partner->fresh()->escrow_balance) . ' | yêu cầu hoàn tiền: ' . $claims);
        }
    }
}
