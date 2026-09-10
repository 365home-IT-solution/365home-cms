<?php

namespace Modules\Minihouse\App\Services;

use Modules\Minihouse\App\Models\ResidenceDeclaration;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Xuất đúng mẫu chính thức "Thông báo lưu trú" của Bộ Công an (tblt_vn_import.xlsx, ở gốc dự án —
// dùng CHUNG với Home, xem App\Services\CccdDeclarationExcelExporter bên hệ Home, cùng 1 file mẫu,
// không nhân bản). Cùng logic: chỉ xuất nhóm "cần khai báo hôm nay" theo mặc định.
class ResidenceDeclarationExcelExporter
{
    private const SHEET_NAME     = 'DS_KHACH_VIET_NAM_LUU_TRU';
    private const FIRST_DATA_ROW = 5;

    public function build(?array $ids = null): Spreadsheet
    {
        $templatePath = base_path('tblt_vn_import.xlsx');

        $reader = IOFactory::createReaderForFile($templatePath);
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($templatePath);

        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);

        $declarations = ResidenceDeclaration::query()
            ->whereIn('id', $ids ?? ResidenceDeclaration::idsNeedingDeclarationToday())
            ->orderBy('checked_in_at')
            ->get();

        // Xoá trắng dòng ví dụ có sẵn của mẫu gốc trước khi ghi — xem giải thích chi tiết ở
        // CccdDeclarationExcelExporter bên hệ Home (cùng 1 vấn đề, cùng cách xử lý).
        foreach (range('A', 'S') as $col) {
            $sheet->setCellValue("{$col}" . self::FIRST_DATA_ROW, null);
        }

        $row = self::FIRST_DATA_ROW;

        foreach ($declarations as $index => $declaration) {
            $sheet->setCellValueExplicit("A{$row}", $index + 1, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $sheet->setCellValue("B{$row}", $declaration->full_name);
            $sheet->setCellValue("C{$row}", $declaration->date_of_birth);
            $sheet->setCellValue("D{$row}", $declaration->gender);
            $sheet->setCellValue("E{$row}", $declaration->nationality);
            $sheet->setCellValue("F{$row}", $declaration->document_type);
            $sheet->setCellValue("G{$row}", null);
            $sheet->setCellValue("H{$row}", $declaration->cccd_number);
            $sheet->setCellValue("I{$row}", $declaration->phone_number);
            $sheet->setCellValue("J{$row}", $declaration->residence_type);
            $sheet->setCellValue("K{$row}", $declaration->province);
            $sheet->setCellValue("L{$row}", $declaration->ward);
            $sheet->setCellValue("M{$row}", $declaration->address_detail);
            $sheet->setCellValue("N{$row}", optional($declaration->checked_in_at)->format('d/m/Y'));
            $sheet->setCellValue("O{$row}", optional($declaration->checked_out_at)->format('d/m/Y'));
            $sheet->setCellValue("P{$row}", $declaration->room_number);
            $sheet->setCellValue("Q{$row}", $declaration->reason_for_stay);
            $sheet->setCellValue("R{$row}", $declaration->custom_reason);
            $sheet->setCellValue("S{$row}", $declaration->notes);

            $row++;
        }

        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));

        return $spreadsheet;
    }

    public function stream(string $filename, ?array $ids = null): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $spreadsheet = $this->build($ids);

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
