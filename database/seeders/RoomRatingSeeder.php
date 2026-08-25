<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\RoomRating;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Product\App\Models\Product;

/**
 * Dữ liệu mẫu cho tính năng đánh giá sao — seed ~24 đánh giá cho vài phòng đang active để có
 * đủ dữ liệu test giao diện (lưới 2 cột, modal "xem tất cả", phân trang cuộn vô hạn...).
 * Chạy: php artisan db:seed --class=RoomRatingSeeder
 */
class RoomRatingSeeder extends Seeder
{
    private const NAMES = [
        'Minh Triết', 'Thu Hà', 'Anh Quân', 'Ngọc Linh', 'Hoàng Long', 'Bảo Trân',
        'Will Nguyen', 'Stephen Cheuk Hang', 'Fola Adeyemi', 'Leah Johnson', 'Manda Lee',
        'Phương Thảo', 'Đức Anh', 'Khánh Vy', 'Tuấn Kiệt', 'Mai Linh', 'Gia Bảo',
        'Thanh Tùng', 'Hồng Nhung', 'Quốc Bảo', 'Yến Nhi', 'Trọng Nghĩa', 'Cẩm Tú', 'Việt Hoàng',
    ];

    private const COMMENTS = [
        'Phòng rất tốt, sạch sẽ và đúng như hình ảnh. Sẽ quay lại lần sau!',
        'Chủ nhà thân thiện, phản hồi tin nhắn nhanh. Vị trí thuận tiện di chuyển.',
        'Không gian thoáng mát, nội thất đầy đủ tiện nghi. Rất hài lòng với trải nghiệm này.',
        'Giá cả hợp lý so với chất lượng phòng. Wifi mạnh, tiện cho công việc.',
        'Phòng đẹp hơn mong đợi, view thoáng. Nhân viên dọn dẹp kỹ càng trước khi nhận phòng.',
        'Nhận phòng đúng giờ, hướng dẫn rõ ràng. Sẽ giới thiệu cho bạn bè.',
        'Trải nghiệm tuyệt vời, giường ngủ êm ái. Khu vực yên tĩnh, phù hợp nghỉ dưỡng.',
        'Có chút khó khăn khi tìm đường vào ban đêm nhưng chủ nhà hỗ trợ nhiệt tình.',
        'Phòng tắm sạch sẽ, đầy đủ đồ dùng cá nhân. Rất đáng tiền.',
        'Không gian ấm cúng, phù hợp cho cặp đôi. Sẽ đặt lại vào dịp tới.',
        'Máy lạnh mát, cách âm tốt. Ngủ rất ngon giấc.',
        null, // để trống comment để test trường hợp chỉ có sao
        'Dịch vụ chăm sóc khách hàng tốt, hỗ trợ 24/7 khi có sự cố.',
        null,
        'Rất đáng để trải nghiệm, không gian sống ảo đẹp, chụp hình lên hình.',
    ];

    public function run(): void
    {
        $slugs = ['lumen', 'pixel', 'cozy', 'minimalism'];

        $products = Product::whereIn('slug', $slugs)->get();

        if ($products->isEmpty()) {
            $products = Product::where('is_activated', true)->where('type', 'simple')->limit(4)->get();
        }

        foreach ($products as $index => $product) {
            $count = $index === 0 ? 24 : random_int(6, 14);
            $this->seedForProduct($product, $count);
        }

        $this->command?->info('Đã tạo xong dữ liệu đánh giá mẫu cho ' . $products->count() . ' phòng.');
    }

    private function seedForProduct(Product $product, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $customer = Customer::create([
                'fullname' => self::NAMES[array_rand(self::NAMES)] . ' ' . Str::random(3),
                'phone'    => '09' . random_int(10000000, 99999999),
                'status'   => Customer::STATUS_ACTIVE,
            ]);

            // Phân bố sao thiên về tích cực (4-5 sao) cho giống thực tế, thỉnh thoảng có 3 sao,
            // hiếm khi 1-2 sao — giống phân bố đánh giá thật của đa số chỗ ở được đánh giá tốt.
            $star = collect([5, 5, 5, 5, 4, 4, 4, 3, 2, 1])->random();

            // created_at không nằm trong $fillable của RoomRating (chặn mass-assign timestamp
            // tuỳ tiện ở API công khai) — set trực tiếp trên instance rồi save() để Eloquent giữ
            // nguyên giá trị này thay vì tự ghi đè bằng now() lúc insert.
            $rating = new RoomRating([
                'customer_id' => $customer->id,
                'room_id'     => $product->id,
                'star'        => $star,
                'comment'     => self::COMMENTS[array_rand(self::COMMENTS)],
            ]);
            $rating->created_at = now()->subDays(random_int(1, 365))->subHours(random_int(0, 23));
            $rating->save();
        }

        $avg = RoomRating::where('room_id', $product->id)->avg('star');
        $product->update(['rating_score' => $avg !== null ? round((float) $avg, 1) : null]);
    }
}
