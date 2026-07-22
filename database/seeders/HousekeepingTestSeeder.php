<?php

namespace Database\Seeders;

use App\Models\Partner;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Modules\Category\Entities\Category;
use Modules\Employee\Entities\Employee;
use Modules\Payment\Entities\Order;
use Modules\Product\App\Models\Product;

// Seeder RIÊNG để test nhanh tính năng "dọn vệ sinh" cho đối tác Golden Bee — không chạy trong
// DatabaseSeeder mặc định, chạy tay khi cần:
//   php artisan db:seed --class=HousekeepingTestSeeder
// Dựng sẵn 3 kịch bản (mỗi chi nhánh 1 kịch bản khác nhau) để không cần chờ scheduler 5 phút:
// kịch bản 1 test lệnh `housekeeping:mark-cleaning` chạy tay; kịch bản 2 bỏ qua luôn bước chờ,
// vào thẳng trạng thái "đang dọn" để test action xác nhận; kịch bản 3 kiểm tra command KHÔNG
// đụng vào phòng chưa hết giờ. Chạy lại seeder này nhiều lần an toàn (tự dọn dữ liệu test cũ).
class HousekeepingTestSeeder extends Seeder
{
    private const TEST_MARKER = 'TEST_HOUSEKEEPING_SEEDER';

    public function run(): void
    {
        $partner = Partner::where('name', 'like', '%Golden Bee%')->first();

        if (! $partner) {
            $this->command->warn('Không tìm thấy đối tác Golden Bee — bỏ qua seeder này.');

            return;
        }

        // Dọn sạch dữ liệu test cũ (nếu chạy lại seeder) để không tích luỹ đơn ảo qua nhiều lần chạy.
        Order::where('note_for_admin', self::TEST_MARKER)->each(function (Order $order) {
            $order->items()->delete();
            $order->delete();
        });

        $branches  = Category::where('partner_id', $partner->id)->where('category_type', 'product')->orderBy('id')->get();
        $products  = Product::where('partner_id', $partner->id)->orderBy('id')->get();
        $employees = Employee::where('partner_id', $partner->id)->whereNotNull('user_id')->orderBy('id')->get();

        if ($branches->count() < 3 || $products->count() < 3 || $employees->count() < 3) {
            $this->command->warn('Đối tác Golden Bee chưa đủ 3 chi nhánh/phòng/nhân viên — bỏ qua seeder này.');

            return;
        }

        [$branch1, $branch2, $branch3]     = $branches->take(3)->values()->all();
        [$product1, $product2, $product3]  = $products->take(3)->values()->all();
        [$employee1, $employee2, $employee3] = $employees->take(3)->values()->all();

        // Gán rõ mỗi nhân viên đúng 1 chi nhánh, để test được đúng luật "chỉ nhân viên trực chi
        // nhánh đó mới xác nhận được" (ghi đè workBranches hiện có của 3 nhân viên này).
        $employee1->workBranches()->sync([$branch1->id]);
        $employee2->workBranches()->sync([$branch2->id]);
        $employee3->workBranches()->sync([$branch3->id]);

        $this->command->info('=== Kịch bản test dọn vệ sinh — Đối tác Golden Bee ===');

        // ── Kịch bản 1: đã hết giờ 15 phút, CHƯA kích hoạt — test lệnh mark-cleaning ──────────
        $product1->update(['housekeeping_status' => 'available']);
        $product1->cleaningLogs()->delete();
        $this->seedOrder($partner, $branch1, $product1, now()->subMinutes(15));

        $this->command->info("1) {$product1->name} ({$branch1->name}) — đơn đã hết giờ 15 phút trước, CHƯA chuyển trạng thái.");
        $this->command->line("   → Chạy: php artisan housekeeping:mark-cleaning để chuyển phòng sang \"Đang dọn vệ sinh\".");
        $this->command->line("   → Rồi đăng nhập {$employee1->user->email} (mật khẩu mặc định của seeder) để xác nhận đã dọn xong.");

        // ── Kịch bản 2: đã ở sẵn trạng thái "đang dọn" — test action xác nhận ngay, khỏi chờ ──
        $product2->update(['housekeeping_status' => 'cleaning']);
        $product2->cleaningLogs()->delete();
        $order2 = $this->seedOrder($partner, $branch2, $product2, now()->subMinutes(30), triggered: true);
        $product2->cleaningLogs()->create([
            'order_item_id'          => $order2->items()->first()->id,
            'marked_for_cleaning_at' => now()->subMinutes(30),
        ]);

        $this->command->info("2) {$product2->name} ({$branch2->name}) — ĐÃ ở trạng thái \"Đang dọn vệ sinh\" sẵn, không cần chạy command.");
        $this->command->line("   → Đăng nhập {$employee2->user->email}, vào bảng Phòng, bấm \"Xác nhận đã dọn xong\" ngay.");

        // ── Kịch bản 3: đơn còn 30 phút mới hết giờ — command KHÔNG được đụng vào ────────────
        $product3->update(['housekeeping_status' => 'available']);
        $product3->cleaningLogs()->delete();
        $this->seedOrder($partner, $branch3, $product3, now()->addMinutes(30));

        $this->command->info("3) {$product3->name} ({$branch3->name}) — đơn còn 30 phút mới hết giờ.");
        $this->command->line('   → Chạy command thì phòng này PHẢI vẫn giữ "Sẵn sàng" (kiểm tra không bị chuyển nhầm).');

        $this->command->info('=== Xong. Chạy "php artisan housekeeping:mark-cleaning" bất cứ lúc nào để test ngay, không cần chờ lịch chạy mỗi 5 phút. ===');
    }

    private function seedOrder(Partner $partner, Category $branch, Product $product, Carbon $checkoutDate, bool $triggered = false): Order
    {
        $order = Order::create([
            'partner_id'     => $partner->id,
            'category_id'    => $branch->id,
            'status'         => 'paid',
            'buyer_name'     => 'Khách test dọn phòng',
            'buyer_phone'    => '0900000000',
            'amount'         => 100000,
            'full_amount'    => 100000,
            'guest_count'    => 2,
            'note_for_admin' => self::TEST_MARKER,
        ]);

        $order->items()->create([
            'product_id'             => $product->id,
            'name'                   => $product->name,
            'price'                  => 100000,
            'quantity'               => 1,
            'checkin_date'           => $checkoutDate->copy()->subHours(3),
            'checkout_date'          => $checkoutDate,
            'housekeeping_triggered' => $triggered,
        ]);

        return $order;
    }
}
