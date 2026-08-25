<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;

// Render HTML (từ 1 trong 3 Blade template resources/views/pdf/stock-{in,out,check}.blade.php)
// thành PDF phiếu kho — tách riêng khỏi App\Services\PdfSigning\ContractPdfRenderer dù logic giống
// nhau vì đây là 2 mối quan tâm khác nhau (in phiếu nội bộ vs ký số hợp đồng pháp lý), không có lý
// do để module Warehouse phụ thuộc ngược vào App\Services\PdfSigning.
class WarehousePdfRenderer
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
