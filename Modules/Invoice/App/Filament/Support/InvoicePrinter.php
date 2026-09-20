<?php

declare(strict_types=1);

namespace Modules\Invoice\App\Filament\Support;

use Modules\Invoice\App\Models\Invoice;
use Modules\Invoice\App\Support\InvoicePdfRenderer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoicePrinter
{
    // Tải PDF hoá đơn — hiện tại MỌI Invoice đều status=draft (chưa tích hợp MISA thật, xem
    // MisaInvoiceClient) nên luôn dùng view có watermark "BẢN NHÁP". Khi giai đoạn sau tích hợp
    // xong và invoice thật sự được MISA phát hành, thêm nhánh render view khác KHÔNG watermark tại
    // đây — cố tình chưa làm sẵn nhánh đó để tránh lỡ tay in ra bản trông như hợp lệ.
    public static function draft(Invoice $invoice): StreamedResponse
    {
        $invoice->loadMissing(['order', 'lines']);

        $html = view('invoice::pdf.invoice-draft', [
            'invoice' => $invoice,
        ])->render();

        $pdf = InvoicePdfRenderer::render($html);

        return response()->streamDownload(
            fn () => print ($pdf),
            "hoa-don-nhap-{$invoice->id}.pdf",
            ['Content-Type' => 'application/pdf']
        );
    }
}
