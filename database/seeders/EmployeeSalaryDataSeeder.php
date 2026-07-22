<?php

namespace Database\Seeders;

use App\Models\Partner;
use Illuminate\Database\Seeder;
use Modules\Employee\Entities\AllowanceType;
use Modules\Employee\Entities\DeductionType;
use Modules\Employee\Entities\Employee;
use Modules\Employee\Entities\SalaryTemplate;
use Modules\Employee\Entities\SalaryType;

// Seeder RIÊNG để có dữ liệu lương ĐẦY ĐỦ, CHÍNH XÁC cho trang "Thống kê lương"
// (app/Filament/Pages/SalaryReport.php) — gán lương cơ bản + phụ cấp + giảm trừ THẬT cho toàn
// bộ nhân viên hiện có của 3 đối tác mẫu (Golden Bee/Sunrise Homestay/Ocean View), không đụng
// tới đối tác thật (Monaco/Pinus/89 Xuân Thủy) hay dữ liệu khác. Chạy tay khi cần:
//   php artisan db:seed --class=EmployeeSalaryDataSeeder --force
// An toàn khi chạy lại nhiều lần (dùng updateOrCreate/sync).
class EmployeeSalaryDataSeeder extends Seeder
{
    private const PARTNER_NAMES = [
        'Đối tác Golden Bee',
        'Đối tác Sunrise Homestay',
        'Đối tác Ocean View',
    ];

    public function run(): void
    {
        foreach (self::PARTNER_NAMES as $partnerName) {
            $partner = Partner::where('name', $partnerName)->first();

            if (! $partner) {
                $this->command->warn("Không tìm thấy đối tác \"{$partnerName}\" — bỏ qua.");

                continue;
            }

            $this->seedForPartner($partner);
        }
    }

    private function seedForPartner(Partner $partner): void
    {
        // ── Danh mục lương riêng của đối tác này ────────────────────────────
        $salaryType = SalaryType::updateOrCreate(
            ['partner_id' => $partner->id, 'slug' => 'nhan-vien-chinh-thuc'],
            ['name' => 'Nhân viên chính thức', 'status' => true]
        );

        $template = SalaryTemplate::updateOrCreate(
            ['partner_id' => $partner->id, 'slug' => 'muc-luong-tieu-chuan'],
            [
                'name'           => 'Mức lương tiêu chuẩn',
                'salary_type_id' => $salaryType->id,
                'base_amount'    => 8000000,
                'status'         => true,
            ]
        );

        $allowanceMeal = AllowanceType::updateOrCreate(
            ['partner_id' => $partner->id, 'slug' => 'phu-cap-an-trua'],
            ['name' => 'Phụ cấp ăn trưa', 'calc_type' => 'fixed', 'default_amount' => 730000, 'status' => true]
        );

        $allowanceTransport = AllowanceType::updateOrCreate(
            ['partner_id' => $partner->id, 'slug' => 'phu-cap-xang-xe'],
            ['name' => 'Phụ cấp xăng xe', 'calc_type' => 'fixed', 'default_amount' => 300000, 'status' => true]
        );

        $allowanceKpi = AllowanceType::updateOrCreate(
            ['partner_id' => $partner->id, 'slug' => 'thuong-kpi'],
            ['name' => 'Thưởng KPI', 'calc_type' => 'percentage', 'default_amount' => 10, 'status' => true]
        );

        $deductionLate = DeductionType::updateOrCreate(
            ['partner_id' => $partner->id, 'slug' => 'di-muon-ve-som'],
            ['name' => 'Đi muộn / về sớm', 'calc_type' => 'fixed', 'default_amount' => 100000, 'status' => true]
        );

        $deductionInsurance = DeductionType::updateOrCreate(
            ['partner_id' => $partner->id, 'slug' => 'bao-hiem-xa-hoi'],
            ['name' => 'Bảo hiểm xã hội (người lao động đóng)', 'calc_type' => 'percentage', 'default_amount' => 8, 'status' => true]
        );

        // ── Gán lương thật cho từng nhân viên hiện có của đối tác ───────────
        $employees = Employee::where('partner_id', $partner->id)->get();

        foreach ($employees as $index => $employee) {
            $baseAmount = 8000000 + ($index * 1500000); // mỗi nhân viên 1 mức khác nhau cho thật

            $employee->update([
                'salary_type_id'     => $salaryType->id,
                'salary_template_id' => $template->id,
                'base_amount'        => $baseAmount,
                'has_allowances'     => true,
                'has_deductions'     => true,
            ]);

            // Phụ cấp: ai cũng có ăn trưa + xăng xe; luân phiên thêm KPI (%) để có ví dụ quy đổi %.
            $allowanceSync = [
                $allowanceMeal->id      => ['amount' => $allowanceMeal->default_amount],
                $allowanceTransport->id => ['amount' => $allowanceTransport->default_amount],
            ];

            if ($index % 2 === 0) {
                $allowanceSync[$allowanceKpi->id] = [
                    'amount' => round($baseAmount * (float) $allowanceKpi->default_amount / 100),
                ];
            }

            $employee->allowanceTypes()->sync($allowanceSync);

            // Giảm trừ: bảo hiểm xã hội (%) luôn có; luân phiên thêm đi muộn/về sớm.
            $deductionSync = [
                $deductionInsurance->id => [
                    'amount' => round($baseAmount * (float) $deductionInsurance->default_amount / 100),
                ],
            ];

            if ($index % 2 === 1) {
                $deductionSync[$deductionLate->id] = ['amount' => $deductionLate->default_amount];
            }

            $employee->deductionTypes()->sync($deductionSync);
        }

        $this->command->info("{$partner->name}: đã cập nhật lương cho {$employees->count()} nhân viên.");
    }
}
