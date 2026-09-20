<?php

declare(strict_types=1);

namespace Modules\Invoice\App\Support;

use Dompdf\Dompdf;
use Dompdf\Options;

// Giống hệt pattern Modules\Warehouse\App\Support\WarehousePdfRenderer — tách riêng theo module,
// không dùng chung, vì đây là 2 loại tài liệu khác nhau (phiếu kho nội bộ vs hoá đơn).
class InvoicePdfRenderer
{
    public static function render(string $html): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml('<meta charset="utf-8">' . $html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }
}
