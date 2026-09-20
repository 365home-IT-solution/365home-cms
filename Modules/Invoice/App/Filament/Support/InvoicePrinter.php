<?php

declare(strict_types=1);

namespace Modules\Invoice\App\Filament\Support;

use Modules\Invoice\App\Models\Invoice;
use Modules\Invoice\App\Support\InvoicePdfRenderer;
use Modules\Invoice\App\Support\VietnameseMoney;
use Modules\SettingCompany\Entities\Business;
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

        // Business = hồ sơ công ty DUY NHẤT của toàn hệ thống (Modules\SettingCompany), KHÔNG phân
        // theo partner — đúng thực trạng hiện tại (xem trao đổi trước: quyết định 1-pháp-nhân hay
        // theo-từng-partner vẫn còn để mở). Nếu sau này chọn theo-từng-partner, đổi nguồn seller ở
        // đây, KHÔNG cần sửa view.
        $seller = Business::first();

        $html = view('invoice::pdf.invoice-draft', [
            'invoice'    => $invoice,
            'seller'     => $seller,
            'amountText' => VietnameseMoney::toWords((int) $invoice->total_amount),
        ])->render();

        $pdf = InvoicePdfRenderer::render($html);

        return response()->streamDownload(
            fn () => print ($pdf),
            "hoa-don-nhap-{$invoice->id}.pdf",
            ['Content-Type' => 'application/pdf']
        );
    }
}
