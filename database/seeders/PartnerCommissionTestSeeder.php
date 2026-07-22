<?php

namespace Database\Seeders;

use App\Models\Partner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;

// Seeder RIÊNG để test phần "Doanh thu hoa hồng" trên Dashboard (super_admin) với NHIỀU đối tác
// khác nhau cùng lúc — chỉ tạo đơn hàng cho các đối tác MẪU/TEST (Golden Bee, Sunrise Homestay,
// Ocean View, Công ty tui nè), KHÔNG đụng tới đối tác thật (Monaco/Pinus/89 Xuân Thủy). Chạy
// tay khi cần:
//   php artisan db:seed --class=PartnerCommissionTestSeeder --force
// An toàn khi chạy lại nhiều lần (tự dọn đơn test cũ trước khi tạo mới).
class PartnerCommissionTestSeeder extends Seeder
{
    private const TEST_MARKER = 'TEST_COMMISSION_SEEDER';

    // Mỗi đối tác 1 tỷ lệ hoa hồng khác nhau + số đơn/khoảng tiền khác nhau cho dễ phân biệt.
    private const PARTNERS = [
        'Đối tác Golden Bee'      => ['rate' => '10%', 'orders' => [4500000, 6200000, 3100000, 8900000]],
        'Đối tác Sunrise Homestay' => ['rate' => '8%', 'orders' => [2200000, 5400000, 3300000]],
        'Đối tác Ocean View'      => ['rate' => '12%', 'orders' => [7100000, 4600000]],
        'Công ty tui nè'          => ['rate' => '15%', 'orders' => [1500000, 2800000, 990000, 4200000, 3300000]],
    ];

    public function run(): void
    {
        // Dọn đơn test cũ trước (tránh cộng dồn doanh thu mỗi lần chạy lại seeder này).
        Order::where('note_for_admin', self::TEST_MARKER)->delete();

        foreach (self::PARTNERS as $partnerName => $config) {
            $partner = Partner::where('name', $partnerName)->first();

            if (! $partner) {
                $this->command->warn("Không tìm thấy đối tác \"{$partnerName}\" — bỏ qua.");

                continue;
            }

            $branch = Category::where('partner_id', $partner->id)
                ->where('category_type', 'product')
                ->first();

            if (! $branch) {
                $this->command->warn("Đối tác \"{$partnerName}\" chưa có chi nhánh nào — bỏ qua.");

                continue;
            }

            // Đảm bảo đối tác đã được phê duyệt + có tỷ lệ hoa hồng — commission chỉ tính cho
            // đối tác verification_status = approved (xem CommissionSummaryService).
            $partner->update([
                'verification_status' => 'approved',
                'status'              => true,
                'commission_rate'     => $config['rate'],
            ]);

            foreach ($config['orders'] as $i => $amount) {
                $order = Order::create([
                    'partner_id'     => $partner->id,
                    'category_id'    => $branch->id,
                    'status'         => 'paid',
                    'buyer_name'     => 'Khách test hoa hồng ' . ($i + 1),
                    'buyer_phone'    => '09' . random_int(10000000, 99999999),
                    'amount'         => $amount,
                    'full_amount'    => $amount,
                    'guest_count'    => random_int(1, 4),
                    'note_for_admin' => self::TEST_MARKER,
                ]);

                // 'created_at' KHÔNG có trong $fillable của Order (bị bỏ qua nếu truyền qua
                // create()) — phải ép bằng raw update để rải đơn ra nhiều ngày trong tháng thay
                // vì dồn hết vào đúng lúc chạy seeder.
                DB::table('orders')->where('id', $order->id)->update([
                    'created_at' => now()->subDays(random_int(0, now()->day - 1)),
                ]);
            }

            $totalRevenue = array_sum($config['orders']);
            $this->command->info("{$partnerName}: đã tạo " . count($config['orders']) . " đơn, doanh thu " . number_format($totalRevenue) . "đ, hoa hồng {$config['rate']}.");
        }

        $this->command->info('=== Xong. Vào Dashboard (super_admin) để xem phần "Doanh thu hoa hồng". ===');
    }
}
