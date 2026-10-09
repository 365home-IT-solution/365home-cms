<?php

declare(strict_types=1);

namespace App\Services\PdfSigning;

use App\Services\ContractSigning\Contracts\DigitalSignatureProvider;

// Ký số PAdES (chữ ký số CA) lên MỘT FILE PDF BẤT KỲ bằng provider được chọn — dùng chung cho hợp đồng đối tác Homestay
// (ContractPdfSigningService) và hợp đồng thuê MiniHouse (Mức C: chủ trọ ký số). Không biết gì về nhà cung cấp cụ thể: chỉ cần
// DigitalSignatureProvider (certificate() rồi đúng 1 lần sign()).
class PdfPkiSigner
{
    public function __construct(private readonly PdfIncrementalSigner $pdfSigner)
    {
    }

    /**
     * @param  array<string, mixed>  $signerContext
     * @return array{pdf: string, signature: string, certificate: array<string, mixed>}
     */
    public function sign(string $pdfBytes, DigitalSignatureProvider $signer, array $signerContext): array
    {
        $certData = $signer->certificate($signerContext)['cert_data'] ?? null;

        if (blank($certData)) {
            throw new \RuntimeException('Không lấy được chứng thư số để ký (provider->certificate() trả về rỗng).');
        }

        $prepared = $this->pdfSigner->prepare($pdfBytes);
        $byteRangeHash = $this->pdfSigner->computeByteRangeHash($prepared);

        $cmsBuilder = new CmsSignedDataBuilder($certData);
        $signedAttrsDer = $cmsBuilder->buildSignedAttributesDer($byteRangeHash, new \DateTimeImmutable());

        // ĐÚNG 1 lần gọi sign() thật trong toàn bộ luồng này.
        $result = $signer->sign(hash('sha256', $signedAttrsDer), $signerContext);

        $signedPdf = $this->pdfSigner->embedSignature($prepared, $cmsBuilder->buildSignedData($signedAttrsDer, $result->signature));

        // Chủ thể chứng thư số (người/đơn vị đứng tên chữ ký) — để hiển thị "đã ký số với ai".
        $certificate = $result->certificate;
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split((string) preg_replace('/\s+/', '', (string) $certData), 64, "\n") . "-----END CERTIFICATE-----\n";
        $parsed = @openssl_x509_parse($pem) ?: [];
        $certificate['cert_subject'] = $parsed['subject']['CN'] ?? null;
        $certificate['cert_org'] = $parsed['subject']['O'] ?? null;
        $certificate['cert_issuer'] = $parsed['issuer']['CN'] ?? null;
        $certificate['provider'] = $signer->name();

        return ['pdf' => $signedPdf, 'signature' => $result->signature, 'certificate' => $certificate];
    }
}
