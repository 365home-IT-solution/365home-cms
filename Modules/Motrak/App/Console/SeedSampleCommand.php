<?php

namespace Modules\Motrak\App\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Motrak\App\Models\MotrakVehicle;
use Modules\Motrak\App\Services\MotrakMappingService;
use Modules\Motrak\App\Services\MotrakSyncService;
use Modules\Motrak\Database\Seeders\MotrakSampleData;

/**
 * Nạp dữ liệu GPS mẫu (ảnh chụp thật của response refreshvehicles, 13 phương tiện) để xem ngay trên CMS mà không cần Token.
 * Dữ liệu đi qua ĐÚNG đường nhập của đồng bộ (đổi toạ độ/thời gian, chính sách lịch sử, nhật ký) — chỉ khác nguồn là mảng mẫu thay vì gọi API.
 * Chạy lại nhiều lần an toàn (không tạo trùng). KHÔNG dùng trên môi trường chạy thật.
 *
 *   php artisan motrak:seed-sample              giữ nguyên mốc thời gian gốc của mẫu
 *   php artisan motrak:seed-sample --fresh      dịch mốc thời gian để xe mới nhất = "bây giờ" (giữ khoảng cách tương đối)
 *   php artisan motrak:seed-sample --with-rental  tạo thêm xe cho thuê mẫu (nhãn [MẪU], giá mẫu) và ghép GPS theo biển số
 */
class SeedSampleCommand extends Command
{
    protected $signature = 'motrak:seed-sample {--fresh : Dịch mốc thời gian về hiện tại} {--with-rental : Tạo xe cho thuê mẫu và ghép GPS}';

    protected $description = 'Nạp dữ liệu GPS Motrak mẫu (13 phương tiện) để xem thử trên CMS';

    public function handle(MotrakSyncService $sync, MotrakMappingService $mapping): int
    {
        if (app()->environment('production')) {
            $this->error('Không chạy dữ liệu mẫu trên môi trường production.');

            return self::FAILURE;
        }

        $items = MotrakSampleData::items();
        if ($this->option('fresh')) {
            $items = MotrakSampleData::shiftToNow($items);
        }

        $log = $sync->importSample($items);
        $this->info(sprintf('Đã nạp mẫu: %d xe, lưu được %d, lỗi %d, điểm lịch sử mới %d. %s', $log->total_vehicles, $log->success_count, $log->failed_count, $log->new_locations, $log->error_message ?? ''));

        if ($this->option('with-rental')) {
            $this->seedRentalFleet($mapping);
        }

        return $log->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    /** Mỗi thiết bị có vị trí hợp lệ → 1 xe cho thuê mẫu (ô tô, chi nhánh mặc định) + ghép GPS. Thiết bị trùng biển số: chỉ ghép thiết bị có vị trí. */
    private function seedRentalFleet(MotrakMappingService $mapping): void
    {
        $vehicleClass = \Modules\VehicleRental\App\Models\Vehicle::class;
        if (! class_exists($vehicleClass) || ! Schema::hasTable('rental_vehicles')) {
            $this->warn('Module thuê xe chưa sẵn sàng — bỏ qua phần xe cho thuê.');

            return;
        }

        $branchId = (int) config('vehiclerental.default_branch_id');
        if (! \Illuminate\Support\Facades\DB::table('categories')->where('id', $branchId)->exists()) {
            $branchId = (int) \Illuminate\Support\Facades\DB::table('categories')->value('id');
        }

        $created = 0;
        $linked = 0;
        foreach (MotrakVehicle::query()->whereNotNull('latitude')->orderBy('plate')->get() as $device) {
            $norm = $device->plate_normalized;
            $rental = $vehicleClass::withTrashed()->where('plate', $norm)->first();

            if (! $rental) {
                $rental = $vehicleClass::create([
                    'branch_id' => $branchId, 'type' => 'car', 'brand' => 'Ô tô', 'model' => $this->formatPlate($norm), 'plate_display' => $this->formatPlate($norm),
                    'status' => 'available', 'odometer_km' => 0, 'deposit_amount' => 2000000, 'sort_order' => 100,
                    'description' => '[MẪU] Tạo từ dữ liệu GPS Motrak. Hãy cập nhật hãng, dòng xe, số chỗ, ảnh và giá thuê thật ở CMS → Thuê xe → Đội xe.',
                ]);
                \Modules\VehicleRental\App\Services\VehicleRatePlanDefaults::seed($rental);
                $created++;
            }

            if ($rental->trashed()) {
                continue;
            }
            if ($device->rental_vehicle_id === null && ! MotrakVehicle::query()->where('rental_vehicle_id', $rental->id)->exists()) {
                $mapping->update($device, $device->imei, $rental->id);
                $linked++;
            }
        }

        $this->info("Xe cho thuê mẫu: tạo mới {$created}, ghép GPS {$linked}.");
    }

    /** "65A16503" → "65A-165.03" (biển ô tô: 2 số tỉnh + chữ + 5 số, định dạng XXA-XXX.XX). */
    private function formatPlate(string $normalized): string
    {
        return preg_match('/^(\d{2}[A-Z])(\d{3})(\d{2})$/', $normalized, $m) ? "{$m[1]}-{$m[2]}.{$m[3]}" : $normalized;
    }
}
