<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PartnerContractVersion;
use App\Services\ContractOtpService;
use App\Services\PartnerContractCksService;
use App\Services\PartnerLegalDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PartnerContractSignController extends Controller
{
    public function show(string $token, PartnerLegalDocumentService $documents): JsonResponse
    {
        $version = $this->version($token);

        return response()->json(['data' => [
            'version_id' => $version->id,
            'partner_name' => $version->partner->legal_name ?? $version->partner->name,
            // content = văn bản ĐẦY ĐỦ như bản in/PDF (quốc hiệu, tiêu ngữ, thân hợp đồng, khung ĐẠI DIỆN BÊN A/B); content_body = thân hợp đồng đã lưu (nội dung tính content_hash).
            'content' => \App\Support\PartnerContractRenderer::renderFramed($version->content, $version->partner, $version),
            'content_body' => $version->content,
            'content_hash' => $version->content_hash,
            'can_confirm' => $documents->canPartnerSign($version->partner) && ! $version->isPartnerConfirmed(),
            'partner_confirmed_at' => $version->partner_confirmed_at?->toIso8601String(),
            'platform_signed_at' => $version->platform_signed_at?->toIso8601String(),
            // Đối tác có thể ký bằng chữ ký số (CKS) thay cho OTP: GET pdf → ký → POST confirm-cks.
            'can_sign_cks' => $documents->canPartnerSign($version->partner) && ! $version->isPartnerConfirmed(),
            'partner_sign_method' => $version->partner_sign_method ?? ($version->isPartnerConfirmed() ? 'otp' : null),
            'partner_certificate' => $version->isPartnerSignedByCks() ? [
                'subject' => $version->partner_signature_certificate['cert_subject'] ?? null,
                'organization' => $version->partner_signature_certificate['cert_org'] ?? null,
                'issuer' => $version->partner_signature_certificate['cert_issuer'] ?? null,
                'valid_to' => $version->partner_signature_certificate['valid_to'] ?? null,
            ] : null,
        ]]);
    }

    // GET /api/partner-contracts/{token}/pdf — tải PDF hợp đồng CHƯA ký để ký bằng chữ ký số (CKS) của đối tác.
    public function pdf(string $token, PartnerContractCksService $cks): StreamedResponse
    {
        $result = $cks->preparePdf($this->version($token));

        return response()->streamDownload(fn () => print ($result['pdf']), $result['file_name'], ['Content-Type' => 'application/pdf']);
    }

    // POST /api/partner-contracts/{token}/confirm-cks — tải PDF đã ký bằng CKS lên (multipart signed_pdf). Thay cho OTP.
    public function confirmCks(Request $request, string $token, PartnerContractCksService $cks): JsonResponse
    {
        $version = $this->version($token);
        $data = $request->validate([
            'signed_pdf' => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'signer_name' => ['nullable', 'string', 'max:255'],
            'agree' => ['required', 'accepted'],
        ]);

        $result = $cks->submit($version, (string) file_get_contents($request->file('signed_pdf')->getRealPath()), $data['signer_name'] ?? null, (string) $request->ip(), (string) $request->userAgent());
        $cert = $result['certificate'];

        return response()->json(['message' => 'Đã kiểm tra chữ ký số và ghi nhận hợp đồng.', 'data' => [
            'partner_confirmed_at' => $version->fresh()->partner_confirmed_at->toIso8601String(),
            'partner_sign_method' => 'cks',
            'partner_certificate' => [
                'subject' => $cert['cert_subject'] ?? null, 'organization' => $cert['cert_org'] ?? null, 'issuer' => $cert['cert_issuer'] ?? null,
                'tax_code' => $cert['tax_code'] ?? null, 'valid_to' => $cert['valid_to'] ?? null,
            ],
        ]]);
    }

    public function sendOtp(string $token, ContractOtpService $otp, PartnerLegalDocumentService $documents): JsonResponse
    {
        $version = $this->version($token);
        if (! \App\Services\ContractSigning\ContractSigningManager::otpAllowed()) {
            return response()->json(['message' => 'Hợp đồng được ký bằng chữ ký số: tải PDF về, ký rồi tải bản đã ký lên (POST confirm-cks).', 'code' => 'OTP_DISABLED'], 410);
        }
        if (! $documents->canPartnerSign($version->partner)) {
            return response()->json(['message' => 'Hồ sơ pháp lý chưa đủ điều kiện ký.'], 409);
        }
        if ($version->isPartnerConfirmed()) {
            return response()->json(['message' => 'Hợp đồng đã được đối tác xác nhận.'], 409);
        }
        if ($otp->hasCooldown($version)) {
            return response()->json(['message' => 'Vui lòng chờ trước khi yêu cầu mã mới.'], 429);
        }
        $email = $version->partner->email ?? $version->partner->owner()?->email;
        if (blank($email)) {
            return response()->json(['message' => 'Đối tác chưa có email nhận OTP.'], 422);
        }
        if (! $otp->send($version, $email)) {
            return response()->json([
                'message' => 'Không gửi được OTP qua email. Vui lòng kiểm tra cấu hình SMTP và thử lại.',
            ], 503);
        }

        return response()->json(['message' => 'Đã gửi OTP.', 'data' => ['email' => preg_replace('/^(.{2}).*(@.*)$/', '$1***$2', $email)]]);
    }

    public function confirm(Request $request, string $token, ContractOtpService $otp, PartnerLegalDocumentService $documents): JsonResponse
    {
        $version = $this->version($token);
        if (! \App\Services\ContractSigning\ContractSigningManager::otpAllowed()) {
            return response()->json(['message' => 'Hợp đồng được ký bằng chữ ký số: tải PDF về, ký rồi tải bản đã ký lên (POST confirm-cks).', 'code' => 'OTP_DISABLED'], 410);
        }
        if (! $documents->canPartnerSign($version->partner)) {
            return response()->json(['message' => 'Hồ sơ pháp lý chưa đủ điều kiện ký.'], 409);
        }
        if ($version->isPartnerConfirmed()) {
            return response()->json(['message' => 'Hợp đồng đã được đối tác xác nhận.'], 409);
        }
        $data = $request->validate([
            'otp' => ['required', 'string', 'size:6'],
            'signer_name' => ['required', 'string', 'max:255'],
            'agree' => ['required', 'accepted'],
        ]);
        if (! $otp->verify($version, $data['otp'])) {
            return response()->json(['message' => 'OTP không đúng hoặc đã hết hạn.'], 422);
        }
        $version->update([
            'partner_confirmed_at' => now(), 'partner_signed_by_name' => $data['signer_name'],
            'partner_signed_ip' => $request->ip(), 'partner_signed_user_agent' => (string) $request->userAgent(),
        ]);

        // Bên A đã ký trước thì hợp đồng có hiệu lực ngay khi đối tác xác nhận.
        app(\App\Services\PartnerContractWorkflowService::class)->completeAfterPartnerSignature($version);
        app(\App\Services\PartnerOnboardingService::class)->notifyPartnerSigned($version->partner);

        return response()->json(['message' => 'Đã xác nhận hợp đồng.', 'data' => [
            'partner_confirmed_at' => $version->fresh()->partner_confirmed_at->toIso8601String(),
            'is_fully_signed' => $version->fresh()->isFullySigned(),
            'contract_status' => $version->partner->fresh()->contract_status,
        ]]);
    }

    private function version(string $token): PartnerContractVersion
    {
        $version = PartnerContractVersion::query()->where('signing_token', $token)->with('partner')->firstOrFail();

        // Chỉ phiên bản mới nhất của đối tác mới ký được (phiên bản cũ bị thay thế → link hết hiệu lực).
        abort_unless($version->partner->contractVersions()->first()?->id === $version->id, 404);
        abort_unless($version->partner->usesContract(), 404);

        return $version;
    }
}
