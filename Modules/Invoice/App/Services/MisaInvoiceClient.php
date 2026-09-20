<?php

declare(strict_types=1);

namespace Modules\Invoice\App\Services;

use App\Settings\InvoiceSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Invoice\App\Models\Invoice;
use RuntimeException;

// Tích hợp MISA meInvoice theo tài liệu chính thức (doc.meinvoice.vn/api/) — quy trình 3 bước:
//   1. createInvoice()  POST {base_url}/itg/invoicepublishing/createinvoice
//   2. signInvoice()    ký XML — SignService cục bộ (USB token) HOẶC API HSM từ xa
//   3. publishInvoice() POST {base_url}/itg/invoicepublishing
//
// getAccessToken() ĐÃ cài đặt thật — MISA công khai đủ field-level spec cho bước này (appid/
// taxcode/username/password → token), không cần đoán.
//
// createInvoice()/publishInvoice() CỐ Ý CHƯA điền phần body request (chỉ có endpoint + header
// đúng) — tài liệu công khai của MISA chỉ mô tả nội dung chung chung ("gồm thông tin người mua/
// bán, dòng hàng hoá, thuế suất...") mà KHÔNG liệt kê chính xác tên từng trường JSON. Bộ field-
// level spec đầy đủ (hoặc Postman collection mẫu) MISA chỉ cấp sau khi đăng ký gói tích hợp API
// thật (có AppID). Đoán bừa tên trường cho một tài liệu có giá trị pháp lý là rủi ro không chấp
// nhận được — khi có AppID + tài liệu/Postman collection MISA gửi, đưa cho tôi để điền chính xác
// $buildCreateInvoicePayload() bên dưới, phần còn lại (gọi HTTP, xử lý lỗi, cập nhật Invoice) đã
// sẵn sàng.
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
     * Điểm vào duy nhất để phát hành 1 Invoice — điều phối đủ 3 bước create → sign → publish.
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

        $created = $this->createInvoice($invoice);
        $signed = $this->signInvoice($created);
        $published = $this->publishInvoice($signed);

        $invoice->update([
            'status'         => Invoice::STATUS_ISSUED,
            'invoice_number' => $published['invoice_number'] ?? null,
            'lookup_code'    => $published['lookup_code'] ?? null,
            'issued_at'      => now(),
            'raw_response'   => $published,
        ]);
    }

    /**
     * POST {base_url}/auth/token — appid/taxcode/username/password → JWT token.
     * Token sống 15 ngày (theo tài liệu MISA) — cache 13 ngày để có biên an toàn, tự lấy lại khi hết.
     */
    public function getAccessToken(): string
    {
        return Cache::remember('misa_invoice_access_token', now()->addDays(13), function () {
            $response = Http::baseUrl((string) $this->settings->base_url)
                ->acceptJson()
                ->post('/auth/token', [
                    'appid'    => $this->settings->app_id,
                    'taxcode'  => $this->settings->tax_code,
                    'username' => $this->settings->username,
                    'password' => $this->settings->password,
                ]);

            $body = $response->json();

            if (! $response->successful() || empty($body['Success']) || empty($body['Data'])) {
                throw new RuntimeException(
                    'Đăng nhập MISA meInvoice thất bại: '
                    . ($body['ErrorCode'] ?? $response->status()) . ' — '
                    . json_encode($body['Errors'] ?? $body, JSON_UNESCAPED_UNICODE)
                );
            }

            return $body['Data'];
        });
    }

    // Ép lấy token mới ngay (bỏ qua cache) — dùng khi publishInvoice()/createInvoice() trả lỗi
    // "token hết hạn" dù chưa tới 13 ngày (server MISA có thể thu hồi token sớm hơn dự kiến).
    public function forgetAccessToken(): void
    {
        Cache::forget('misa_invoice_access_token');
    }

    /**
     * TODO: điền đúng field JSON theo Postman collection/spec MISA gửi khi có AppID thật — hiện
     * $buildCreateInvoicePayload() chỉ là khung với TÊN trường suy đoán hợp lý, CHƯA xác nhận đúng
     * với MISA. Endpoint + cách gọi (header, auth) đã đúng theo tài liệu công khai.
     */
    private function createInvoice(Invoice $invoice): array
    {
        $response = Http::baseUrl((string) $this->settings->base_url)
            ->withToken($this->getAccessToken())
            ->withHeaders(['CompanyTaxCode' => $this->settings->tax_code])
            ->acceptJson()
            ->post('/itg/invoicepublishing/createinvoice', $this->buildCreateInvoicePayload($invoice));

        $body = $response->json();

        if (! $response->successful() || empty($body['Success'])) {
            throw new RuntimeException(
                'Tạo hoá đơn MISA thất bại (đơn #' . $invoice->order_id . '): '
                . json_encode($body['Errors'] ?? $body, JSON_UNESCAPED_UNICODE)
            );
        }

        return $body['Data'];
    }

    // CHƯA XÁC NHẬN — tên trường dưới đây là suy đoán từ mô tả chung của MISA, không phải spec
    // chính thức. Phải thay bằng đúng field name MISA cấp trước khi dùng thật.
    private function buildCreateInvoicePayload(Invoice $invoice): array
    {
        $invoice->loadMissing('lines');

        return [
            'invoiceTemplateCode' => $this->settings->invoice_template_code,
            'invoiceSeries'       => $this->settings->invoice_series,
            'buyerName'           => $invoice->buyer_name,
            'buyerTaxCode'        => $invoice->buyer_tax_code,
            'buyerAddress'        => $invoice->buyer_address,
            'buyerEmail'          => $invoice->buyer_email,
            'items'               => $invoice->lines->map(fn ($line) => [
                'description' => $line->description,
                'unit'        => $line->unit,
                'quantity'    => (float) $line->quantity,
                'unitPrice'   => (int) $line->unit_price,
                'vatRate'     => (float) $line->vat_rate,
                'amount'      => (int) $line->amount,
            ])->values()->all(),
            'totalAmount' => (int) $invoice->total_amount,
        ];
    }

    /**
     * Ký XML hoá đơn — 2 nhánh tuỳ InvoiceSettings::$signing_mode.
     */
    private function signInvoice(array $created): array
    {
        return match ($this->settings->signing_mode) {
            'usb_token' => $this->signWithUsbToken($created),
            'hsm'       => $this->signWithHsm($created),
            default     => throw new RuntimeException(
                'Chưa chọn phương thức ký số — vào Cấu hình web > Hoá đơn điện tử chọn USB Token hoặc HSM.'
            ),
        };
    }

    // Gọi SignService cục bộ (chương trình MISA chạy cạnh máy có cắm USB Token) — CHƯA cài đặt vì
    // cần thêm 2 thông tin chưa có ô cấu hình: địa chỉ/cổng SignService đang chạy trên máy nào, và
    // cách truyền PIN token an toàn (nhập tay mỗi lần hay lưu mã hoá). Bổ sung khi biết bạn triển
    // khai SignService ở đâu.
    private function signWithUsbToken(array $created): array
    {
        throw new RuntimeException(
            'Ký bằng USB Token chưa được cài đặt — cần thêm địa chỉ SignService (host:port chạy '
            . 'cạnh máy cắm token) và cách nhập PIN trước khi hoàn thiện bước này.'
        );
    }

    // Gọi API ký từ xa của nhà cung cấp HSM (VNPT-CA/Viettel-CA/FastCA...) — CHƯA cài đặt vì mỗi
    // nhà cung cấp có API riêng, cần biết bạn dùng HSM của ai + tài liệu API của họ mới viết đúng.
    private function signWithHsm(array $created): array
    {
        throw new RuntimeException(
            'Ký bằng HSM chưa được cài đặt — cần biết nhà cung cấp HSM (VNPT-CA/Viettel-CA/FastCA...) '
            . 'và tài liệu API của họ trước khi hoàn thiện bước này.'
        );
    }

    /**
     * TODO: cùng lý do như createInvoice() — endpoint/header đúng, body/response field cần xác
     * nhận lại với spec thật của MISA.
     */
    private function publishInvoice(array $signed): array
    {
        $response = Http::baseUrl((string) $this->settings->base_url)
            ->withToken($this->getAccessToken())
            ->withHeaders(['CompanyTaxCode' => $this->settings->tax_code])
            ->acceptJson()
            ->post('/itg/invoicepublishing', $signed);

        $body = $response->json();

        if (! $response->successful() || empty($body['Success'])) {
            throw new RuntimeException(
                'Phát hành hoá đơn MISA thất bại: '
                . json_encode($body['Errors'] ?? $body, JSON_UNESCAPED_UNICODE)
            );
        }

        return [
            'invoice_number' => $body['Data']['invoiceNumber'] ?? null,
            'lookup_code'    => $body['Data']['lookupCode'] ?? null,
        ];
    }
}
