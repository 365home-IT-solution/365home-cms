<?php

declare(strict_types=1);

namespace App\Services\PdfSigning;

use App\Models\PartnerContractVersion;
use App\Services\ContractSigning\Contracts\DigitalSignatureProvider;
use App\Support\PartnerContractRenderer;

// Hợp đồng đối tác HOMESTAY: render PDF → ký số PAdES bằng nhà cung cấp đã chọn cho Homestay (xem PdfPkiSigner) → trả về file PDF hoàn chỉnh,
// có thể nộp thẳng lên NEAC kiểm tra. (Hợp đồng thuê MiniHouse dùng chung PdfPkiSigner nhưng với nhà cung cấp chọn riêng cho MiniHouse.)
//
// CHỈ 1 LƯỢT KÝ THẬT DUY NHẤT cho toàn bộ luồng. Không còn khái niệm "ký riêng cho đối tác" — phía đối tác chỉ cần xác nhận qua OTP
// (ContractSignController::sign(), không dùng chữ ký số PKI), còn chữ ký số PKI thật CHỈ đại diện cho việc NỀN TẢNG niêm phong hợp đồng
// sau khi đối tác đã đồng ý (đúng bản chất pháp lý — không có lý do gì để dùng chứng thư số CỦA NỀN TẢNG "ký thay" cho đối tác).
class ContractPdfSigningService
{
    public function __construct(
        private readonly PdfPkiSigner $pkiSigner,
        private readonly DigitalSignatureProvider $signer,
    ) {
    }

    public function signAndEmbed(PartnerContractVersion $version, array $signerContext): array
    {
        $html = PartnerContractRenderer::renderFramed($version->content, $version->partner, $version);

        return $this->pkiSigner->sign(ContractPdfRenderer::render($html), $this->signer, $signerContext);
    }
}
