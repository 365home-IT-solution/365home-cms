<?php

declare(strict_types=1);

namespace App\Services\PdfSigning;

use App\Models\PartnerContractVersion;
use App\Services\ContractSigning\Contracts\DigitalSignatureProvider;
use App\Support\PartnerContractRenderer;

// Điều phối toàn bộ luồng: render PDF → chừa chỗ ký (PAdES) → gửi hash cho VNPT ký thật → ráp CMS
// → nhúng vào PDF → trả về file PDF hoàn chỉnh, có thể nộp thẳng lên NEAC kiểm tra.
//
// CHỈ 1 LƯỢT KÝ THẬT DUY NHẤT cho toàn bộ luồng — lấy chứng thư qua provider->certificate() TRƯỚC
// (không tốn lượt ký, chỉ là bước tra cứu), rồi mới gọi provider->sign() ĐÚNG 1 LẦN trên hash thật
// của file PDF. Không còn khái niệm "ký riêng cho đối tác" — phía đối tác chỉ cần xác nhận qua OTP
// (ContractSignController::sign(), không dùng chữ ký số PKI), còn chữ ký số PKI thật CHỈ đại diện
// cho việc NỀN TẢNG niêm phong hợp đồng sau khi đối tác đã đồng ý (đúng bản chất pháp lý — xem
// thảo luận: không có lý do gì để dùng chứng thư số CỦA NỀN TẢNG "ký thay" cho đối tác).
class ContractPdfSigningService
{
    public function __construct(
        private readonly PdfIncrementalSigner $pdfSigner,
        private readonly DigitalSignatureProvider $signer,
    ) {
    }

    public function signAndEmbed(PartnerContractVersion $version, array $signerContext): array
    {
        $certData = $this->signer->certificate($signerContext)['cert_data'] ?? null;

        if (blank($certData)) {
            throw new \RuntimeException('Không lấy được chứng thư số để ký (provider->certificate() trả về rỗng).');
        }

        $html = PartnerContractRenderer::renderFramed($version->content, $version->partner, $version);
        $pdfBytes = ContractPdfRenderer::render($html);

        $prepared = $this->pdfSigner->prepare($pdfBytes);
        $byteRangeHash = $this->pdfSigner->computeByteRangeHash($prepared);

        $cmsBuilder = new CmsSignedDataBuilder($certData);
        $signedAttrsDer = $cmsBuilder->buildSignedAttributesDer($byteRangeHash, new \DateTimeImmutable());
        $hashToSign = hash('sha256', $signedAttrsDer);

        // ĐÚNG 1 lần gọi sign() thật trong toàn bộ luồng này.
        $result = $this->signer->sign($hashToSign, $signerContext);

        $cmsDer = $cmsBuilder->buildSignedData($signedAttrsDer, $result->signature);
        $signedPdf = $this->pdfSigner->embedSignature($prepared, $cmsDer);

        return [
            'pdf'         => $signedPdf,
            'signature'   => $result->signature,
            'certificate' => $result->certificate,
        ];
    }
}
