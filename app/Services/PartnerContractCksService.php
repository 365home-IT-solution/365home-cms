<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PartnerContractVersion;
use App\Services\PdfSigning\ContractPdfRenderer;
use App\Services\PdfSigning\UploadedPdfSignatureVerifier;
use App\Support\PartnerContractRenderer;
use Illuminate\Validation\ValidationException;

// ĐỐI TÁC KÝ BẰNG CHỮ KÝ SỐ (CKS USB hoặc từ xa của bất kỳ nhà cung cấp nào) thay cho OTP email: tải PDF hợp đồng → ký bằng công cụ của họ → tải lên.
// Hệ thống kiểm tra chữ ký, nội dung nguyên vẹn, chứng thư còn hạn và đúng người (MST / CCCD khớp hồ sơ đối tác). Sau đó nền tảng ký chồng lên
// đúng file này (xem PartnerContractWorkflowService) nên PDF cuối có đủ 2 chữ ký số.
class PartnerContractCksService
{
    public function __construct(
        private readonly PartnerLegalDocumentService $documents,
        private readonly UploadedPdfSignatureVerifier $verifier,
    ) {
    }

    /**
     * PDF hợp đồng CHƯA ký để đối tác ký bằng CKS. Giữ nguyên từng byte giữa các lần tải để đối chiếu với bản tải lên.
     *
     * @return array{pdf: string, file_name: string}
     */
    public function preparePdf(PartnerContractVersion $version): array
    {
        $this->assertCanSign($version);
        $fileName = "hop-dong-{$version->id}-doi-tac-can-ky.pdf";

        $stored = $version->getFirstMedia('partner_base_pdf');
        if ($stored && is_file($stored->getPath()) && filled($version->partner_sign_base_hash)) {
            $pdf = (string) file_get_contents($stored->getPath());
            if (hash_equals($version->partner_sign_base_hash, hash('sha256', $pdf))) {
                return ['pdf' => $pdf, 'file_name' => $fileName];
            }
        }

        $pdf = ContractPdfRenderer::render(PartnerContractRenderer::renderFramed($version->content, $version->partner, $version));
        $version->addMediaFromString($pdf)->usingFileName($fileName)->toMediaCollection('partner_base_pdf', 'local');
        $version->update([
            'partner_sign_base_hash' => hash('sha256', $pdf),
            'partner_sign_base_length' => strlen($pdf),
            'partner_sign_prepared_at' => now(),
        ]);

        return ['pdf' => $pdf, 'file_name' => $fileName];
    }

    /**
     * Nhận PDF đã ký của đối tác, kiểm tra rồi ghi nhận đối tác đã ký (thay cho xác nhận OTP).
     *
     * @return array{certificate: array<string, mixed>}
     */
    public function submit(PartnerContractVersion $version, string $signedPdf, ?string $signerName, string $ip, string $userAgent): array
    {
        $this->assertCanSign($version);
        if (blank($version->partner_sign_base_hash)) {
            throw ValidationException::withMessages(['signed_pdf' => 'Chưa tải PDF hợp đồng để ký — hãy tải file cần ký từ hệ thống trước.']);
        }

        $check = $this->verifier->verify($signedPdf, $version->partner_sign_base_hash, (int) $version->partner_sign_base_length);
        if (! $check['ok']) {
            throw ValidationException::withMessages(['signed_pdf' => implode(' ', $check['errors'])]);
        }

        $certificate = $check['certificate'];
        $this->assertTrustworthy($version, $certificate);

        $name = trim((string) $signerName) !== '' ? trim((string) $signerName) : (string) ($certificate['cert_subject'] ?? 'Đối tác');
        $now = now();
        $certificate['warnings'] = ['Chưa đối chiếu chuỗi tin cậy tới CA gốc và trạng thái thu hồi chứng thư — kiểm tra thêm bằng công cụ của NEAC nếu cần.'];

        $version->addMediaFromString($signedPdf)->usingFileName("hop-dong-{$version->id}-doi-tac-da-ky.pdf")->toMediaCollection('partner_signed_pdf', 'local');
        $version->update([
            'partner_confirmed_at' => $now,
            'partner_signed_at' => $now,
            'partner_sign_method' => 'cks',
            'partner_signing_provider' => 'cks_upload',
            'partner_signed_by_name' => $name,
            'partner_signed_ip' => $ip,
            'partner_signed_user_agent' => $userAgent,
            'partner_signature_certificate' => $certificate,
            // Bản đối tác đã ký chính là bản nền tảng sẽ ký chồng lên.
            'platform_sign_base_hash' => hash('sha256', $signedPdf),
            'platform_sign_base_length' => strlen($signedPdf),
            'platform_sign_prepared_at' => $now,
        ]);
        $version->addMediaFromString($signedPdf)->usingFileName("hop-dong-{$version->id}-can-ky.pdf")->toMediaCollection('platform_base_pdf', 'local');

        app(PartnerOnboardingService::class)->notifyPartnerSigned($version->partner);

        return ['certificate' => $certificate];
    }

    private function assertCanSign(PartnerContractVersion $version): void
    {
        if (! $this->documents->canPartnerSign($version->partner)) {
            throw ValidationException::withMessages(['contract' => 'Hồ sơ pháp lý của đối tác chưa được phê duyệt hoặc cần xác minh lại.']);
        }
        if ($version->isPartnerConfirmed()) {
            throw ValidationException::withMessages(['contract' => 'Hợp đồng này đã được đối tác xác nhận trước đó.']);
        }
        if ($version->partner->contractVersions()->first()?->id !== $version->id) {
            throw ValidationException::withMessages(['contract' => 'Phiên bản hợp đồng này đã được thay bằng phiên bản mới hơn.']);
        }
    }

    /** Chứng thư phải của đúng đối tác (MST hoặc CCCD người đại diện khớp hồ sơ) và không phải chứng thư tự ký. */
    private function assertTrustworthy(PartnerContractVersion $version, array $certificate): void
    {
        $config = (array) config('contract_signing.partner_cks', []);

        if (($certificate['self_signed'] ?? false) && ! ($config['allow_self_signed'] ?? false)) {
            throw ValidationException::withMessages(['signed_pdf' => 'Chứng thư là chứng thư tự ký, không phải chữ ký số do tổ chức chứng thực công cộng cấp.']);
        }

        if (! ($config['require_identity_match'] ?? true)) {
            return;
        }

        $partner = $version->partner;
        $partnerTax = $this->digits((string) $partner->tax_code);
        $partnerId = $this->digits((string) $partner->representative_id_number);
        if ($partnerTax === '' && $partnerId === '') {
            throw ValidationException::withMessages(['signed_pdf' => 'Hồ sơ đối tác chưa có mã số thuế hoặc CCCD người đại diện để đối chiếu với chứng thư số.']);
        }

        $certTax = $this->digits((string) ($certificate['tax_code'] ?? ''));
        $certId = $this->digits((string) ($certificate['citizen_id'] ?? ''));
        // MST doanh nghiệp có thể kèm mã chi nhánh (13 số): so 10 số đầu.
        $taxMatch = $partnerTax !== '' && $certTax !== '' && substr($partnerTax, 0, 10) === substr($certTax, 0, 10);
        $idMatch = $partnerId !== '' && $certId !== '' && $partnerId === $certId;

        if (! $taxMatch && ! $idMatch) {
            throw ValidationException::withMessages(['signed_pdf' => 'Chứng thư số không khớp hồ sơ đối tác (mã số thuế hoặc CCCD người đại diện). Hãy ký bằng chữ ký số đứng tên đúng đối tác.']);
        }
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
