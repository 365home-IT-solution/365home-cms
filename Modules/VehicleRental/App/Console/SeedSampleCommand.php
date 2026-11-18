<?php

namespace Modules\VehicleRental\App\Console;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\VehicleRental\App\Models\Vehicle;
use Modules\VehicleRental\App\Models\VehicleBlock;
use Modules\VehicleRental\App\Models\VehicleBooking;
use Modules\VehicleRental\App\Models\VehicleCharge;
use Modules\VehicleRental\App\Models\VehicleDocument;
use Modules\VehicleRental\App\Models\VehicleHandover;
use Modules\VehicleRental\App\Models\VehicleRatePlan;
use Modules\VehicleRental\App\Services\VehicleRatePlanDefaults;

/**
 * Dữ liệu MẪU cho module thuê xe (xe máy + ô tô, gói giờ/ngày/km, giấy tờ đủ trạng thái, đơn thuê ở MỌI trạng thái, khoá xe).
 * An toàn: KHÔNG dùng khách thật (tạo 3 khách mẫu SĐT 0900000901-903, không có token thiết bị), KHÔNG gửi thông báo (tắt trong lúc chạy),
 * KHÔNG đụng dữ liệu thật. Mọi bản ghi mẫu nhận diện được: xe có mô tả bắt đầu "[MẪU]", đơn có mã "XEMAU…", khách có tên "Khách mẫu".
 *
 *   php artisan vehicle-rental:seed-sample           tạo (chạy lại: bỏ qua nếu đã có)
 *   php artisan vehicle-rental:seed-sample --fresh   xoá bộ mẫu cũ rồi tạo lại (mốc thời gian tính lại theo "bây giờ")
 */
class SeedSampleCommand extends Command
{
    protected $signature = 'vehicle-rental:seed-sample {--fresh : Xoá bộ mẫu cũ rồi tạo lại}';

    protected $description = 'Tạo dữ liệu mẫu cho thuê xe (xe, gói giá, giấy tờ, đơn đủ trạng thái)';

    private const PREFIX = 'XEMAU';
    private const TAG = '[MẪU]';
    private const PHONES = ['0900000901', '0900000902', '0900000903'];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Không chạy dữ liệu mẫu trên môi trường production.');

            return self::FAILURE;
        }

        config(['vehiclerental.notifications.enabled' => false]); // tuyệt đối không gửi push khi nạp mẫu

        if ($this->option('fresh')) {
            $this->purge();
        } elseif (Vehicle::withTrashed()->where('description', 'like', self::TAG . '%')->where('plate', 'like', '99%')->exists()) {
            $this->warn('Bộ mẫu đã có (dùng --fresh để tạo lại).');

            return self::SUCCESS;
        }

        $branchId = (int) config('vehiclerental.default_branch_id');
        if (! DB::table('categories')->where('id', $branchId)->exists()) {
            $branchId = (int) DB::table('categories')->value('id');
        }

        $customers = $this->customers();
        $docs = $this->documents($customers);
        $vehicles = $this->vehicles($branchId);
        $this->blocks($vehicles);
        $n = $this->bookings($vehicles, $customers);

        $this->info(sprintf('Đã tạo: %d xe (%d máy, %d ô tô), %d khách mẫu, %d bộ giấy tờ, %d đơn thuê.',
            count($vehicles), collect($vehicles)->where('type', 'motorbike')->count(), collect($vehicles)->where('type', 'car')->count(), count($customers), $docs, $n));

        return self::SUCCESS;
    }

    private function purge(): void
    {
        $vehicleIds = Vehicle::withTrashed()->where('description', 'like', self::TAG . '%')->where('plate', 'like', '99%')->pluck('id');
        $bookingIds = VehicleBooking::query()->where('code', 'like', self::PREFIX . '%')->pluck('id');
        DB::table('rental_vehicle_handovers')->whereIn('booking_id', $bookingIds)->delete();
        DB::table('rental_vehicle_charges')->whereIn('booking_id', $bookingIds)->delete();
        DB::table('rental_vehicle_gps_alerts')->whereIn('booking_id', $bookingIds)->delete();
        DB::table('rental_vehicle_bookings')->whereIn('id', $bookingIds)->delete();
        DB::table('rental_vehicle_blocks')->whereIn('vehicle_id', $vehicleIds)->delete();
        DB::table('rental_vehicle_rate_plans')->whereIn('vehicle_id', $vehicleIds)->delete();
        DB::table('rental_vehicles')->whereIn('id', $vehicleIds)->delete();

        $customerIds = Customer::withTrashed()->whereIn('phone', self::PHONES)->pluck('id');
        DB::table('rental_vehicle_documents')->whereIn('customer_id', $customerIds)->delete();
        Customer::withTrashed()->whereIn('id', $customerIds)->forceDelete();
        $this->line('Đã xoá bộ mẫu cũ.');
    }

    /** @return list<Customer> */
    private function customers(): array
    {
        $out = [];
        foreach (['Nguyễn Minh Anh', 'Trần Quốc Bảo', 'Lê Thu Cúc'] as $i => $name) {
            $out[] = Customer::withTrashed()->firstOrCreate(['phone' => self::PHONES[$i]], [
                'id' => (string) Str::uuid(), 'fullname' => 'Khách mẫu ' . ($i + 1) . ' — ' . $name, 'status' => Customer::STATUS_ACTIVE,
            ]);
        }

        return $out;
    }

    /** Ảnh giấy tờ mẫu (ảnh xám có chữ "MẪU") để màn duyệt giấy tờ có ảnh xem thử. */
    private function placeholderImage(): string
    {
        $path = 'rental-sample/giay-to-mau.png';
        if (! Storage::disk('public')->exists($path)) {
            $img = imagecreatetruecolor(640, 400);
            imagefilledrectangle($img, 0, 0, 640, 400, imagecolorallocate($img, 228, 232, 235));
            imagestring($img, 5, 230, 190, 'GIAY TO MAU (SAMPLE)', imagecolorallocate($img, 90, 100, 110));
            ob_start();
            imagepng($img);
            Storage::disk('public')->put($path, (string) ob_get_clean());
        }

        return $path;
    }

    /** @param  list<Customer>  $customers */
    private function documents(array $customers): int
    {
        $img = $this->placeholderImage();
        $base = ['id_front' => $img, 'id_back' => $img, 'license_front' => $img, 'license_back' => $img, 'selfie' => $img];
        $rows = [
            [$customers[0], ['full_name' => 'Nguyễn Minh Anh', 'id_number' => '092090001234', 'license_number' => '920012345678', 'license_class' => 'B2', 'status' => VehicleDocument::STATUS_APPROVED, 'reviewed_by' => 'mau', 'reviewed_at' => now()->subDays(3)]],
            [$customers[1], ['full_name' => 'Trần Quốc Bảo', 'id_number' => '092090005678', 'license_number' => '920087654321', 'license_class' => 'A1', 'status' => VehicleDocument::STATUS_PENDING]],
            [$customers[2], ['full_name' => 'Lê Thu Cúc', 'id_number' => '092090009999', 'license_number' => '920011112222', 'license_class' => 'B1', 'status' => VehicleDocument::STATUS_REJECTED,
                'reject_reason' => 'Ảnh bằng lái bị mờ, vui lòng chụp lại rõ nét.', 'reviewed_by' => 'mau', 'reviewed_at' => now()->subDay()]],
        ];
        foreach ($rows as [$c, $attrs]) {
            VehicleDocument::query()->updateOrCreate(['customer_id' => $c->id], $base + $attrs + ['license_expires_on' => now()->addYears(4)->toDateString()]);
        }

        return count($rows);
    }

    /** @return list<Vehicle> */
    private function vehicles(int $branchId): array
    {
        $rows = [
            // type, brand, model, year, plate, seats, trans, fuel, color, km, deposit, status, kmPlan
            ['motorbike', 'Honda', 'Vision', 2023, '99-A1 101.01', null, 'auto', 'petrol', 'Trắng', 8200, 1000000, 'available', true],
            ['motorbike', 'Honda', 'Air Blade 160', 2024, '99-A1 101.02', null, 'auto', 'petrol', 'Đen nhám', 3100, 1000000, 'available', false],
            ['motorbike', 'Yamaha', 'Exciter 155', 2023, '99-A1 101.03', null, 'manual', 'petrol', 'Xanh GP', 12400, 1500000, 'available', false],
            ['motorbike', 'Honda', 'Wave Alpha', 2022, '99-A1 101.04', null, 'manual', 'petrol', 'Đỏ', 21800, 500000, 'maintenance', false],
            ['car', 'Kia', 'Morning', 2022, '99A-201.01', 5, 'auto', 'petrol', 'Trắng', 34500, 2000000, 'available', true],
            ['car', 'Toyota', 'Vios', 2023, '99A-201.02', 5, 'auto', 'petrol', 'Bạc', 18900, 3000000, 'available', true],
            ['car', 'Mitsubishi', 'Xpander', 2023, '99A-201.03', 7, 'auto', 'petrol', 'Cam', 22300, 3000000, 'available', false],
            ['car', 'VinFast', 'VF 5 Plus', 2024, '99A-201.04', 5, 'auto', 'electric', 'Xanh mint', 6400, 2500000, 'inactive', false],
        ];

        $out = [];
        foreach ($rows as $i => [$type, $brand, $model, $year, $plate, $seats, $trans, $fuel, $color, $km, $deposit, $status, $kmPlan]) {
            $v = Vehicle::create([
                'branch_id' => $branchId, 'type' => $type, 'brand' => $brand, 'model' => $model, 'year' => $year, 'plate_display' => $plate,
                'seats' => $seats, 'transmission' => $trans, 'fuel' => $fuel, 'color' => $color, 'odometer_km' => $km, 'deposit_amount' => $deposit,
                'status' => $status, 'sort_order' => 200 + $i,
                'registration_expires_on' => now()->addMonths(random_int(2, 10))->toDateString(), 'insurance_expires_on' => now()->addMonths(random_int(1, 9))->toDateString(),
                'description' => self::TAG . " {$brand} {$model} {$year} — xe mẫu để xem thử hệ thống. Hãy xoá hoặc thay bằng xe thật.",
            ]);
            VehicleRatePlanDefaults::seed($v);
            if ($kmPlan) {
                $v->ratePlans()->create([
                    'name' => 'Theo km', 'unit' => VehicleRatePlan::UNIT_KM, 'duration_hours' => 24,
                    'price' => $type === 'car' ? 3000 : 2000, 'min_km' => $type === 'car' ? 50 : 20, 'is_active' => true, 'sort_order' => 9,
                ]);
            }
            $out[] = $v;
        }

        // Xe bảo dưỡng: khoá lịch cho khớp trạng thái.
        return $out;
    }

    /** @param  list<Vehicle>  $v */
    private function blocks(array $v): void
    {
        VehicleBlock::create(['vehicle_id' => $v[3]->id, 'start_at' => now()->subDay(), 'end_at' => now()->addDays(5), 'reason' => 'Bảo dưỡng định kỳ (mẫu)', 'created_by' => 'mau']);
        VehicleBlock::create(['vehicle_id' => $v[6]->id, 'start_at' => now()->addDays(9)->setTime(8, 0), 'end_at' => now()->addDays(10)->setTime(18, 0), 'reason' => 'Đăng kiểm (mẫu)', 'created_by' => 'mau']);
    }

    /**
     * Đơn ở mọi trạng thái. Tạo trực tiếp (không qua service) để có cả đơn trong quá khứ; số tiền theo gói giá mẫu.
     *
     * @param  list<Vehicle>  $v
     * @param  list<Customer>  $c
     */
    private function bookings(array $v, array $c): int
    {
        $n = 0;
        $make = function (string $suffix, Vehicle $veh, Customer $cust, string $status, \Carbon\Carbon $start, int $hours, int $amount, array $over = []) use (&$n) {
            $n++;
            $end = $start->copy()->addHours($hours);
            $paid = ! in_array($status, [VehicleBooking::STATUS_PENDING_PAYMENT], true);

            return VehicleBooking::create($over + [
                'code' => self::PREFIX . $suffix, 'vehicle_id' => $veh->id, 'customer_id' => $cust->id,
                'pickup_branch_id' => $veh->branch_id, 'return_branch_id' => $veh->branch_id,
                'pickup_source' => 'branch', 'pickup_address' => 'Chi nhánh 365 HOME',
                'start_at' => $start, 'end_at' => $end, 'status' => $status,
                'hold_expires_at' => $status === VehicleBooking::STATUS_PENDING_PAYMENT ? now()->addMinutes(12) : null,
                'rental_amount' => $amount, 'discount_amount' => 0, 'extra_amount' => 0, 'deposit_amount' => $veh->deposit_amount, 'total_amount' => $amount,
                'paid_amount' => $paid && $status !== VehicleBooking::STATUS_CANCELLED ? $amount : 0, 'paid_at' => $paid ? $start->copy()->subDay() : null,
                'pricing_snapshot' => ['mode' => 'time', 'plans' => [], 'quote' => ['rental_amount' => $amount, 'included_km' => null, 'extra_km_price' => 0, 'lines' => [['label' => 'Gói mẫu', 'amount' => $amount]]]],
            ]);
        };
        $handover = fn (VehicleBooking $b, string $type, int $km, \Carbon\Carbon $at, ?string $note = null) => VehicleHandover::create([
            'booking_id' => $b->id, 'type' => $type, 'odometer_km' => $km, 'fuel_level' => 80, 'photos' => [], 'notes' => $note, 'staff_id' => 'mau', 'handled_at' => $at,
        ]);

        // 1) Chờ thanh toán (đang giữ chỗ 12 phút)
        $make('001', $v[0], $c[0], VehicleBooking::STATUS_PENDING_PAYMENT, now()->addDay()->setTime(9, 0), 8, 120000);
        // 2) Đã xác nhận, nhận xe ngày mai
        $make('002', $v[4], $c[0], VehicleBooking::STATUS_CONFIRMED, now()->addDay()->setTime(8, 0), 24, 1100000);
        // 3) Đã xác nhận, sắp tới giờ nhận (40 phút nữa) — thử nút "Giao xe"
        $make('003', $v[1], $c[1], VehicleBooking::STATUS_CONFIRMED, now()->addMinutes(40), 8, 120000);
        // 4) Đang thuê (đã giao 2 giờ trước)
        $b = $make('004', $v[5], $c[0], VehicleBooking::STATUS_PICKED_UP, now()->subHours(2), 12, 950000, ['actual_start_at' => now()->subHours(2), 'deposit_collected_amount' => 3000000, 'deposit_collected_at' => now()->subHours(2)]);
        $handover($b, VehicleHandover::TYPE_PICKUP, 18900, now()->subHours(2), 'Xe sạch, không xước.');
        // 5) Đã trả xe, chưa tất toán (có phụ phí vệ sinh)
        $b = $make('005', $v[2], $c[1], VehicleBooking::STATUS_RETURNED, now()->subHours(30), 8, 120000, ['actual_start_at' => now()->subHours(30), 'actual_end_at' => now()->subHours(21), 'km_driven' => 85, 'deposit_collected_amount' => 1500000, 'extra_amount' => 50000]);
        $handover($b, VehicleHandover::TYPE_PICKUP, 12315, now()->subHours(30));
        $handover($b, VehicleHandover::TYPE_RETURN, 12400, now()->subHours(21), 'Trễ 1 giờ.');
        VehicleCharge::create(['booking_id' => $b->id, 'type' => 'cleaning', 'label' => 'Vệ sinh xe', 'amount' => 50000, 'created_by' => 'mau']);
        // 6) Hoàn tất (đã tất toán, trừ phụ phí vào cọc)
        $b = $make('006', $v[4], $c[2], VehicleBooking::STATUS_SETTLED, now()->subDays(5)->setTime(8, 0), 24, 1100000, [
            'actual_start_at' => now()->subDays(5)->setTime(8, 10), 'actual_end_at' => now()->subDays(4)->setTime(8, 40), 'km_driven' => 420, 'deposit_collected_amount' => 2000000,
            'extra_amount' => 470000, 'total_amount' => 1570000, 'deposit_deducted_amount' => 470000, 'deposit_refund_amount' => 1530000, 'settled_at' => now()->subDays(4),
        ]);
        $handover($b, VehicleHandover::TYPE_PICKUP, 34080, now()->subDays(5)->setTime(8, 10));
        $handover($b, VehicleHandover::TYPE_RETURN, 34500, now()->subDays(4)->setTime(8, 40));
        VehicleCharge::create(['booking_id' => $b->id, 'type' => 'km', 'label' => 'Vượt 120 km', 'amount' => 600000, 'created_by' => 'mau']);
        // 7) Đã huỷ có hoàn tiền (chờ nhân viên chuyển khoản)
        $make('007', $v[6], $c[1], VehicleBooking::STATUS_CANCELLED, now()->addDays(3)->setTime(7, 0), 48, 2400000, [
            'paid_amount' => 2400000, 'refund_amount' => 2400000, 'cancelled_at' => now()->subHour(), 'cancel_reason' => 'Nhân viên huỷ: xe hỏng (mẫu)',
        ]);
        // 8) Không đến nhận
        $make('008', $v[0], $c[2], VehicleBooking::STATUS_NO_SHOW, now()->subDays(2)->setTime(9, 0), 8, 120000, ['cancelled_at' => now()->subDays(2)->setTime(11, 30), 'cancel_reason' => 'Khách không đến nhận xe']);
        // 9) Hết hạn giữ chỗ (tự huỷ)
        $make('009', $v[1], $c[0], VehicleBooking::STATUS_CANCELLED, now()->addDays(2)->setTime(10, 0), 8, 120000, ['cancelled_at' => now()->subHours(5), 'cancel_reason' => VehicleBooking::CANCEL_HOLD_EXPIRED, 'paid_at' => null, 'paid_amount' => 0]);
        // 10) Thuê THEO KM đã xác nhận (ước tính 100 km × 3.000)
        $make('010', $v[5], $c[1], VehicleBooking::STATUS_CONFIRMED, now()->addDays(4)->setTime(8, 0), 10, 300000, [
            'pricing_snapshot' => ['mode' => 'km', 'plans' => [], 'quote' => ['rental_amount' => 300000, 'included_km' => null, 'extra_km_price' => 0, 'mode' => 'km', 'price_per_km' => 3000, 'min_km' => 50, 'estimated_km' => 100,
                'lines' => [['label' => 'Ước tính 100 km × 3.000đ', 'amount' => 300000]]]],
        ]);
        // 11) Thuê THEO KM đã trả: đi ít hơn → cần hoàn phần dư
        $b = $make('011', $v[4], $c[0], VehicleBooking::STATUS_RETURNED, now()->subDays(1)->setTime(8, 0), 10, 150000, [
            'actual_start_at' => now()->subDays(1)->setTime(8, 5), 'actual_end_at' => now()->subDays(1)->setTime(17, 50), 'km_driven' => 50, 'deposit_collected_amount' => 2000000,
            'refund_amount' => 150000, 'pricing_snapshot' => ['mode' => 'km', 'plans' => [], 'quote' => ['rental_amount' => 300000, 'included_km' => null, 'extra_km_price' => 0, 'mode' => 'km', 'price_per_km' => 3000, 'min_km' => 50, 'estimated_km' => 100,
                'lines' => [['label' => 'Ước tính 100 km × 3.000đ', 'amount' => 300000]]]],
        ]);
        $b->update(['paid_amount' => 300000]);
        $handover($b, VehicleHandover::TYPE_PICKUP, 34450, now()->subDays(1)->setTime(8, 5));
        $handover($b, VehicleHandover::TYPE_RETURN, 34500, now()->subDays(1)->setTime(17, 50));

        return $n;
    }
}
