<?php

declare(strict_types=1);

namespace Modules\Invoice\App\Services;

use App\Settings\InvoiceSettings;
use Modules\Invoice\App\Models\Invoice;
use RuntimeException;

// SƯỜN cho tích hợp thật với MISA meInvoice — CHƯA gọi bất kỳ HTTP request nào ra ngoài. Đúng quy
// trình MISA yêu cầu (xem doc.meinvoice.vn/api/Document/InvoicePublishing.html) là 3 bước tuần tự:
//   1. createInvoice()  POST {base_url}/itg/invoicepublishing/createinvoice
//   2. signInvoice()    ký XML — qua SignService cục bộ (USB token) HOẶC API HSM từ xa, tuỳ
//                       InvoiceSettings::$signing_mode
//   3. publishInvoice() POST {base_url}/itg/invoicepublishing
//
// CỐ Ý chưa cài đặt thân các hàm dưới đây: nếu viết sẵn logic gọi HTTP mà chưa có AppID/tài khoản
// kỹ thuật thật từ MISA, rất dễ vô tình để lại code "phát hành" ra hoá đơn không hợp lệ hoặc gọi
// nhầm vào production. issue() luôn throw khi chưa cấu hình xong — Invoice tạo ra ở giai đoạn này
// sẽ mãi ở STATUS_DRAFT, không có cách nào lách qua để tự set STATUS_ISSUED.
class MisaInvoiceClient
{
    public function __construct(private readonly InvoiceSettings $settings)
    {
    }

    public function isReady(): bool
    {
        return $this->settings->isConfigured();
    }

    /**
     * Điểm vào duy nhất để phát hành 1 Invoice — sẽ điều phối đủ 3 bước create → sign → publish khi
     * được cài đặt thật. Hiện tại luôn throw để không ai vô tình tưởng hoá đơn đã phát hành thật.
     */
    public function issue(Invoice $invoice): void
    {
        if (! $this->isReady()) {
            throw new RuntimeException(
                'Chưa cấu hình kết nối MISA meInvoice (AppID/tài khoản/mẫu số-ký hiệu) — '
                . 'vào Cấu hình web > Hoá đơn điện tử để bổ sung trước khi phát hành thật. '
                . 'Hoá đơn #' . $invoice->id . ' vẫn ở trạng thái bản nháp.'
            );
        }

        // TODO (giai đoạn tích hợp thật, chưa làm):
        // $draftXml = $this->createInvoice($invoice);
        // $signedXml = $this->signInvoice($draftXml);
        // $result = $this->publishInvoice($signedXml);
        // $invoice->update(['status' => Invoice::STATUS_ISSUED, 'invoice_number' => ..., ...]);
        throw new RuntimeException('MisaInvoiceClient::issue() chưa được cài đặt — mới có sườn.');
    }

    private function createInvoice(Invoice $invoice): array
    {
        throw new RuntimeException('Chưa cài đặt: MisaInvoiceClient::createInvoice()');
    }

    private function signInvoice(array $draft): array
    {
        throw new RuntimeException('Chưa cài đặt: MisaInvoiceClient::signInvoice()');
    }

    private function publishInvoice(array $signed): array
    {
        throw new RuntimeException('Chưa cài đặt: MisaInvoiceClient::publishInvoice()');
    }
}
