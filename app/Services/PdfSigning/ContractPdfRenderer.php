<?php

declare(strict_types=1);

namespace App\Services\PdfSigning;

use Dompdf\Dompdf;
use Dompdf\Options;

// Render nội dung hợp đồng (HTML từ PartnerContractRenderer) thành PDF thật — bước đầu tiên trước
// khi nhúng chữ ký số PAdES (xem PdfIncrementalSigner).
//
// Định dạng theo ĐÚNG mẫu Word "HỢP ĐỒNG 365 HOME": A4, lề trái 3cm / phải 1,5cm / trên 2cm / dưới 2cm, chữ Times New Roman 13pt
// (tiêu đề 18pt), giãn dòng 1,5, căn đều hai bên. Dùng Times New Roman thật nếu có trong resources/fonts/times-new-roman, không thì Liberation Serif — phông tương thích số đo
// (metric-compatible) với Times New Roman, có đủ chữ tiếng Việt, giấy phép SIL OFL (resources/fonts/liberation-serif) — đăng ký dưới tên
// "Times New Roman" để ngắt dòng và số trang giống mẫu Word.
class ContractPdfRenderer
{
    private const FONT_DIR = 'fonts/liberation-serif';

    // Hệ số Dompdf nhân chiều cao dòng (đo thực tế: 22,4pt khai báo → 28,3pt).
    private const LINE_HEIGHT_SCALE = 1.2635;

    public static function render(string $html): string
    {
        $fontCache = storage_path('app/dompdf-fonts');
        if (! is_dir($fontCache)) {
            @mkdir($fontCache, 0775, true);
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('fontDir', $fontCache);
        $options->set('fontCache', $fontCache);
        $options->set('defaultFont', 'Times New Roman');
        $options->set('chroot', [resource_path('fonts')]); // cho phép đăng ký phông từ resources/fonts

        $dompdf = new Dompdf($options);
        $family = self::registerTimesNewRoman($dompdf);
        $html = str_replace(["'Times New Roman'", '"Times New Roman"'], ["'$family'", "\"$family\""], $html);

        // Khổ in theo mẫu Word: A4, lề trái 3cm / phải 1,5cm / trên 2cm / dưới 2cm. Dompdf đặt chữ trong ô dòng cao hơn Word 5,7pt (cùng chiều cao dòng),
        // nên dịch cả vùng nội dung lên 5,7pt (lề trên 2cm - 5,7pt, lề dưới 2cm + 5,7pt) để mỗi dòng nằm ĐÚNG vị trí và mỗi trang chứa đúng số dòng như Word.
        $css = '<style>@page{margin:51pt 42.52pt 93pt 85.04pt} body{font-family:"' . $family . '";font-size:13pt;margin:0} .hd-hint{display:none}</style>';
        // Dompdf nhân chiều cao dòng khai báo bằng pt lên ~1,2635 lần so với CSS chuẩn → chia lại để dòng cao đúng 22,4pt như Word.
        $html = preg_replace_callback('/line-height:\s*([0-9.]+)pt/', fn ($m) => 'line-height:' . round((float) $m[1] / self::LINE_HEIGHT_SCALE, 3) . 'pt', $html);
        $dompdf->loadHtml('<meta charset="utf-8">' . $css . $html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        // Số trang ở góc phải dưới như mẫu Word (chân trang, 11pt).
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('Helvetica');
        $canvas->page_text(547.4, 761.4, '{PAGE_NUM}', $font, 11);

        return $dompdf->output();
    }

    // Ưu tiên Times New Roman THẬT nếu có 4 file trong resources/fonts/times-new-roman (times.ttf, timesbd.ttf, timesi.ttf, timesbi.ttf — chép từ Windows,
    // cần giấy phép Microsoft); không có thì dùng Liberation Serif (cùng số đo, hình chữ hơi khác). Mỗi nguồn đăng ký dưới 1 tên riêng để cache phông không lẫn nhau.
    private static function registerTimesNewRoman(Dompdf $dompdf): string
    {
        $real = resource_path('fonts/times-new-roman');
        $useReal = is_file("$real/times.ttf") && is_file("$real/timesbd.ttf") && is_file("$real/timesi.ttf") && is_file("$real/timesbi.ttf");
        $family = $useReal ? 'TimesNewRomanWin' : 'TimesNewRomanLib';
        $key = strtolower($family);

        $metrics = $dompdf->getFontMetrics();
        $installed = $metrics->getFontFamilies();
        if (isset($installed[$key]['normal'], $installed[$key]['bold'], $installed[$key]['italic'], $installed[$key]['bold_italic'])
            && is_file($installed[$key]['normal'] . '.ttf')) {
            return $family;
        }

        $dir = $useReal ? $real : resource_path(self::FONT_DIR);
        $files = $useReal
            ? ['times.ttf', 'timesbd.ttf', 'timesi.ttf', 'timesbi.ttf']
            : ['LiberationSerif-Regular.ttf', 'LiberationSerif-Bold.ttf', 'LiberationSerif-Italic.ttf', 'LiberationSerif-BoldItalic.ttf'];
        $variants = [['normal', 'normal'], ['normal', 'bold'], ['italic', 'normal'], ['italic', 'bold']];
        foreach ($variants as $i => [$style, $weight]) {
            $metrics->registerFont(['family' => $family, 'style' => $style, 'weight' => $weight], $dir . DIRECTORY_SEPARATOR . $files[$i]);
        }

        return $family;
    }
}
