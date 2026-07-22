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
        $options->set('defaultFont', 'DejaVu Sans'); // hỗ trợ tiếng Việt có dấu

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml('<meta charset="utf-8">' . $html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }
}
