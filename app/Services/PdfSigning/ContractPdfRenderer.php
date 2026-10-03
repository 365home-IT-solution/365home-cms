<?php

declare(strict_types=1);

namespace App\Services\PdfSigning;

use Dompdf\Dompdf;
use Dompdf\Options;

// Render nội dung hợp đồng (HTML từ PartnerContractRenderer) thành PDF thật — bước đầu tiên trước
// khi nhúng chữ ký số PAdES (xem PdfIncrementalSigner).
class ContractPdfRenderer
{
    public static function render(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Serif'); // kiểu chữ có chân, hỗ trợ tiếng Việt có dấu (Times New Roman không có sẵn trong Dompdf)

        $dompdf = new Dompdf($options);
        // Mẫu hợp đồng dùng Times New Roman 13pt (xem PartnerContractRenderer): trong PDF thay bằng DejaVu Serif (rộng hơn nên giảm cỡ chữ để số trang tương đương).
        $html = str_replace(\App\Support\PartnerContractRenderer::FONT, "'DejaVu Serif'", $html);
        $html = str_replace(['font-size:13pt', 'font-size:18pt'], ['font-size:11pt', 'font-size:15pt'], $html);

        // Khổ in theo mẫu: A4, lề trái 3cm, phải 1,5cm, trên/dưới 2cm.
        $css = '<style>@page{margin:2cm 1.5cm 2cm 3cm} body{font-family:"DejaVu Serif";font-size:11pt;line-height:1.5} .hd-hint{display:none}</style>';
        $dompdf->loadHtml('<meta charset="utf-8">' . $css . $html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }
}
