<?php

declare(strict_types=1);

namespace App\Exports\Concerns;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Bộ định dạng dùng chung cho các file Excel đối soát: tiêu đề, tiêu đề cột, viền, định dạng tiền, khổ in.
 * Màu theo nhận diện của tài liệu nội bộ (xanh đậm 1F4E79, nền nhạt F2F6FA).
 */
trait StylesSettlementSheet
{
    public const COLOR_PRIMARY = '1F4E79';

    public const COLOR_SOFT = 'F2F6FA';

    public const COLOR_BORDER = 'BFC9D4';

    public const MONEY_FORMAT = '#,##0';

    public const MONEY_FORMAT_VND = '#,##0" đ"';

    public const DATE_FORMAT = 'dd/mm/yyyy';

    /** Chu kỳ đối soát hiển thị bằng tiếng Việt. */
    protected static function cycleLabel(?string $cycle, bool $short = false): string
    {
        return match ($cycle) {
            'biweekly' => $short ? '2 lần/tháng' : '2 lần/tháng (ngày 1 và ngày 15)',
            'monthly'  => '1 lần/tháng',
            'weekly'   => 'Hằng tuần',
            default    => (string) $cycle,
        };
    }

    protected static function col(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    /**
     * Khối tiêu đề đầu sheet: dòng thương hiệu + tiêu đề lớn + các dòng phụ, gộp ô theo bề ngang bảng.
     *
     * @param  array<int, string>  $subtitles
     * @return int dòng trống kế tiếp
     */
    protected function titleBlock(Worksheet $ws, int $lastCol, string $title, array $subtitles): int
    {
        $last = self::col($lastCol);

        // Dòng 1: tiêu đề
        $ws->mergeCells("A1:{$last}1");
        $ws->setCellValue('A1', $title);
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB(self::COLOR_PRIMARY);
        $ws->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $ws->getRowDimension(1)->setRowHeight(24);

        // Các dòng thông tin ngay dưới (thường chỉ 1 dòng)
        $row = 2;
        foreach ($subtitles as $line) {
            $ws->mergeCells("A{$row}:{$last}{$row}");
            $ws->setCellValue("A{$row}", $line);
            $ws->getStyle("A{$row}")->getFont()->setSize(10)->getColor()->setRGB('555555');
            $ws->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        return $row + 1; // chừa 1 dòng trống
    }

    /** Dòng tiêu đề cột: nền xanh đậm, chữ trắng đậm, căn giữa, tự xuống dòng. */
    protected function headerRow(Worksheet $ws, int $row, int $firstCol, int $lastCol, int $height = 34): void
    {
        $range = self::col($firstCol) . $row . ':' . self::col($lastCol) . $row;

        $style = $ws->getStyle($range);
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_PRIMARY);
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $this->border($ws, $range);

        $ws->getRowDimension($row)->setRowHeight($height);
    }

    protected function border(Worksheet $ws, string $range): void
    {
        $ws->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::COLOR_BORDER);
    }

    protected function soft(Worksheet $ws, string $range): void
    {
        $ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::COLOR_SOFT);
    }

    /** Dòng tổng cộng: chữ đậm, nền nhạt, viền trên đậm hơn. */
    protected function totalRow(Worksheet $ws, int $row, int $firstCol, int $lastCol): void
    {
        $range = self::col($firstCol) . $row . ':' . self::col($lastCol) . $row;

        $ws->getStyle($range)->getFont()->setBold(true);
        $this->soft($ws, $range);
        $this->border($ws, $range);
        $ws->getStyle($range)->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB(self::COLOR_PRIMARY);
    }

    /** Khổ in: A4 ngang, vừa chiều rộng 1 trang, lặp lại dòng tiêu đề cột ở mỗi trang, có số trang ở chân. */
    protected function printSetup(Worksheet $ws, bool $landscape = true, ?int $repeatHeaderRow = null): void
    {
        $setup = $ws->getPageSetup();
        $setup->setOrientation($landscape ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT);
        $setup->setPaperSize(PageSetup::PAPERSIZE_A4);
        $setup->setFitToWidth(1);
        $setup->setFitToHeight(0);

        if (method_exists($ws->getSheetView(), 'setView') && method_exists($setup, 'setFitToPage')) {
            $setup->setFitToPage(true);
        } elseif (method_exists($ws, 'getPageSetup')) {
            // Bản PhpSpreadsheet cũ: bật "fit to page" qua thuộc tính của sheet
            $ws->getSheetView()->setZoomScale(100);
        }

        if ($repeatHeaderRow !== null) {
            $ws->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($repeatHeaderRow, $repeatHeaderRow);
        }

        $ws->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.5)->setBottom(0.6);
        $ws->getHeaderFooter()->setOddFooter('&L365home — Bảng đối soát&RTrang &P / &N');
        $ws->setShowGridlines(false);
    }
}
