<?php

namespace App\Services;

use App\Models\CccdDeclaration;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Xuất đúng nhóm khách "CẦN khai báo hôm nay" (chưa khai báo + đã/đang tới hạn trong ngày — xem
// CccdDeclaration::idsNeedingDeclarationToday()) ra ĐÚNG mẫu chính thức "Thông báo lưu trú" của
// Bộ Công an (tblt_vn_import.xlsx) — nạp thẳng file mẫu gốc (giữ nguyên các sheet TINH_THANH/
// PHUONG_XA/DANH_MUC + toàn bộ named range/dropdown của mẫu), CHỈ ghi thêm dữ liệu thật vào sheet
// DS_KHACH_VIET_NAM_LUU_TRU bắt đầu từ dòng 5 (dòng 1-4 của mẫu là tiêu đề/header/hướng dẫn/dòng
// ví dụ [EXAMPLE] — giữ nguyên y hệt mẫu gốc, không xoá).
//
// KHÔNG xuất khách chưa tới hạn (đặt phòng trước, còn ở tương lai) hay khách đã khai báo rồi —
// KBTT chỉ chấp nhận "Ngày đến" là hôm nay/hôm qua nên xuất khách chưa tới hạn cũng vô nghĩa, và
// khách đã khai báo thì không cần nộp lại.
class CccdDeclarationExcelExporter
{
    private const SHEET_NAME     = 'DS_KHACH_VIET_NAM_LUU_TRU';
    private const FIRST_DATA_ROW = 5;

    public function build(): Spreadsheet
    {
        $templatePath = base_path('tblt_vn_import.xlsx');

        $reader              = IOFactory::createReaderForFile($templatePath);
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($templatePath);

        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);

        $declarations = CccdDeclaration::query()
            ->whereIn('id', CccdDeclaration::idsNeedingDeclarationToday())
            ->orderBy('checked_in_at')
            ->get();

        // Bản thân file mẫu gốc đã có SẴN 1 dòng dữ liệu ví dụ thật ở dòng 5 ("NGUYEN VAN QUANG")
        // — không phải placeholder/[EXAMPLE] mà là dữ liệu trông như thật. Nếu danh sách cần khai
        // báo đang RỖNG (đã khai báo hết), không có dòng nào ghi đè lên nó nữa → dòng ví dụ của
        // Bộ Công an sẽ lộ ra trong file xuất dù danh sách thực tế không có ai. Xoá trắng dòng này
        // TRƯỚC khi ghi (chỉ xoá giá trị, không xoá/dịch dòng, để không ảnh hưởng data validation
        // đang trải dài theo dòng của mẫu), để danh sách rỗng thì xuất ra đúng rỗng.
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
            // "TÊN GIẤY TỜ" chỉ bắt buộc khi document_type = "9 - Giấy Tờ Khác" — hệ thống hiện
            // chưa có trường riêng lưu tên giấy tờ khác, để trống, nhân viên tự bổ sung nếu cần.
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

    public function stream(string $filename): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $spreadsheet = $this->build();

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
