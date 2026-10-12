<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Partner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Modules\Product\App\Models\Product;

// DỮ LIỆU MẪU doanh thu của CHÍNH 365home (đối tác nền tảng, is_platform_partner) trong 30 ngày gần đây để so sánh với doanh thu của các đối tác
// ở tab Dashboard › Doanh thu của 365home / Tổng quan. Đơn dùng phòng thật của 365home, giá = giá phòng × số đêm, mã đơn bắt đầu bằng "MAU"
// để xoá lại bằng --purge mà không đụng dữ liệu thật. Chỉ chạy trên máy local.
class SeedPlatformSampleData extends Command
{
    protected $signature = 'stats:seed-platform-sample
        {--purge : Chỉ xoá các đơn mẫu (mã MAU...) rồi dừng}
        {--force : Cho phép chạy khi APP_ENV=production (máy local thường để production)}';

    protected $description = 'Tạo đơn mẫu trong 30 ngày gần đây cho các phòng của chính 365home để so sánh doanh thu';

    private const PREFIX = 'MAU';

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

        config(['mail.default' => 'log']);
        \Illuminate\Support\Facades\Http::fake();

        $own = Partner::query()->where('is_platform_partner', true)->first();
        if (! $own) {
            $this->error('Không có đối tác nền tảng (is_platform_partner).');

            return self::FAILURE;
        }

        $this->purge($own);
        if ($this->option('purge')) {
            return self::SUCCESS;
        }

        // Phòng 365home hay được đặt nhất trong lịch sử; giá/đêm lấy theo giá phòng, nếu phòng không để giá thì theo giá một đêm thường gặp ở đơn thật.
        $roomIds = DB::table('order_items')->whereIn('order_id', DB::table('orders')->where('partner_id', $own->id)->select('id'))
            ->whereNotNull('product_id')->selectRaw('product_id, count(*) c')->groupBy('product_id')->orderByDesc('c')->limit(4)->pluck('product_id');
        $rooms = Product::withoutGlobalScopes()->where('partner_id', $own->id)->whereIn('id', $roomIds)->get()->map(function (Product $room) {
            $unit = (int) $room->price;
            if ($unit <= 0) {
                $unit = (int) DB::table('order_items')->where('product_id', $room->id)->where('price', '>', 0)->orderByDesc('id')->limit(30)->avg('price');
            }
            $room->setAttribute('sample_price', $unit > 0 ? $unit : 400_000);

            return $room;
        })->values();
        if ($rooms->count() < 3) {
            $this->error('365home cần ít nhất 3 phòng từng có đơn để tạo đơn mẫu.');

            return self::FAILURE;
        }
        $branchId = Category::withoutGlobalScopes()->where('partner_id', $own->id)->orderBy('id')->value('id');

        // [số ngày trước, chỉ số phòng, số đêm, thanh toán, khách, trạng thái]
        $plan = [
            [27, 0, 1, 'PayOS', 'Nguyễn Văn An', 'done'], [25, 1, 2, 'cod', 'Trần Thị Bích', 'done'], [22, 2, 1, 'PayOS', 'Lê Hoàng Nam', 'done'],
            [20, 0, 2, 'PayOS', 'Phạm Quỳnh Như', 'done'], [18, 1, 1, 'cod', 'Võ Thành Đạt', 'done'], [15, 2, 3, 'PayOS', 'Đặng Mỹ Linh', 'done'],
            [12, 0, 1, 'cod', 'Huỳnh Minh Tâm', 'done'], [10, 1, 2, 'PayOS', 'Bùi Gia Hân', 'done'], [8, 2, 1, 'PayOS', 'Ngô Đức Huy', 'done'],
            [5, 0, 2, 'cod', 'Dương Thu Trang', 'done'], [3, 1, 1, 'PayOS', 'Lý Khánh Vy', 'done'], [1, 2, 2, 'PayOS', 'Mai Quốc Việt', 'done'],
            [0, 0, 1, 'PayOS', 'Trịnh Bảo Châu', 'staying'],
        ];

        $total = 0;
        DB::transaction(function () use ($plan, $rooms, $own, $branchId, &$total) {
            foreach ($plan as $i => [$ago, $roomIdx, $nights, $pay, $guest, $state]) {
                $room = $rooms[$roomIdx % $rooms->count()];
                $checkin = Carbon::today()->subDays($ago + $nights)->setTime(14, 0);
                $checkout = $checkin->copy()->addDays($nights)->setTime(12, 0);
                if ($state === 'staying') {
                    $checkin = Carbon::today()->setTime(14, 0);
                    $checkout = $checkin->copy()->addDays($nights)->setTime(12, 0);
                }
                $amount = (int) $room->sample_price * $nights;
                $bookedAt = $checkin->copy()->subDays(2)->setTime(9, 30);

                $order = Order::create([
                    'partner_id' => $own->id, 'order_code' => self::PREFIX . random_int(10_000_000, 99_999_999),
                    'amount' => $amount, 'full_amount' => $amount,
                    'description' => sprintf('Đặt phòng - %s (%s → %s, %d đêm) [dữ liệu mẫu]', $room->name, $checkin->format('d/m'), $checkout->format('d/m'), $nights),
                    'buyer_name' => $guest, 'buyer_phone' => '09031235' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'payment_method' => $pay,
                    'status' => 'paid', 'order_status' => 'pending', 'guest_count' => 2, 'category_id' => $branchId,
                ]);
                $order->items()->create([
                    'product_id' => $room->id, 'name' => $room->name, 'price' => $amount, 'quantity' => 1,
                    'checkin_date' => $checkin, 'checkout_date' => $checkout,
                ]);
                DB::table('orders')->where('id', $order->id)->update(['created_at' => $bookedAt, 'updated_at' => $bookedAt]);

                if ($state === 'done') {
                    $order->refresh()->update(['order_status' => 'checked_out', 'checked_in_at' => $checkin, 'checked_out_at' => $checkout]);
                } else {
                    $order->refresh()->update(['order_status' => 'checked_in', 'checked_in_at' => $checkin]);
                }
                $total += $amount;
            }
        });

        $this->info(count($plan) . ' đơn mẫu cho ' . ($own->legal_name ?: $own->name) . ' — tổng ' . number_format($total, 0, ',', '.') . 'đ, ' . $rooms->count() . ' phòng.');
        $this->line('Xoá lại bằng: php artisan stats:seed-platform-sample --purge');

        return self::SUCCESS;
    }

    private function purge(Partner $own): void
    {
        $ids = Order::withoutGlobalScopes()->where('partner_id', $own->id)->where('order_code', 'like', self::PREFIX . '%')->pluck('id')->all();
        if ($ids === []) {
            return;
        }
        DB::table('order_items')->whereIn('order_id', $ids)->delete();
        DB::table('orders')->whereIn('id', $ids)->delete();
        $this->line('Đã xoá ' . count($ids) . ' đơn mẫu cũ.');
    }
}
