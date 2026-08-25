<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TbltProvince;
use App\Models\TbltWard;
use Illuminate\Database\Seeder;
use PhpOffice\PhpSpreadsheet\IOFactory;

// Nạp nguyên văn 2 sheet TINH_THANH (34 tỉnh/thành) + PHUONG_XA (3323 phường/xã/đặc khu) của mẫu
// chính thức Bộ Công an (tblt_vn_import.xlsx) vào 2 bảng RIÊNG (tblt_provinces/tblt_wards) —
// không đụng tới bảng provinces/wards hiện có của hệ thống.
class TbltAddressSeeder extends Seeder
{
    public function run(): void
    {
        $path = base_path('tblt_vn_import.xlsx');

        if (! file_exists($path)) {
            $this->command->error("Không tìm thấy file mẫu: {$path}");

            return;
        }

        $reader      = IOFactory::createReaderForFile($path);
        $spreadsheet = $reader->load($path);

        $this->seedProvinces($spreadsheet->getSheetByName('TINH_THANH'));
        $this->seedWards($spreadsheet->getSheetByName('PHUONG_XA'));
    }

    private function seedProvinces(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
    {
        TbltProvince::truncate();

        $rows = [];
        for ($r = 2; $r <= $sheet->getHighestRow(); $r++) {
            $code = trim((string) $sheet->getCell("A{$r}")->getValue());

            if ($code === '') {
                continue;
            }

            $rows[] = [
                'code'       => $code,
                'name'       => trim((string) $sheet->getCell("B{$r}")->getValue()),
                'display'    => trim((string) $sheet->getCell("C{$r}")->getValue()),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        TbltProvince::insert($rows);
        $this->command->info('Đã nạp ' . count($rows) . ' tỉnh/thành (TINH_THANH).');
    }

    private function seedWards(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
    {
        TbltWard::truncate();

        $rows = [];
        for ($r = 2; $r <= $sheet->getHighestRow(); $r++) {
            $code = trim((string) $sheet->getCell("A{$r}")->getValue());

            if ($code === '') {
                continue;
            }

            $rows[] = [
                'code'          => $code,
                'name'          => trim((string) $sheet->getCell("B{$r}")->getValue()),
                'province_code' => trim((string) $sheet->getCell("C{$r}")->getValue()),
                'display'       => trim((string) $sheet->getCell("D{$r}")->getValue()),
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            TbltWard::insert($chunk);
        }

        $this->command->info('Đã nạp ' . count($rows) . ' phường/xã/đặc khu (PHUONG_XA).');
    }
}
