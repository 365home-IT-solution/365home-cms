<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\LockNotificationMail;
use App\Models\Partner;
use App\Models\PartnerContractVersion;
use App\Models\User;
use App\Services\ContractSigning\Contracts\DigitalSignatureProvider;
use App\Services\PdfSigning\ContractPdfSigningService;
use App\Support\PartnerContractRenderer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PartnerContractWorkflowService
{
    public function __construct(private readonly PartnerLegalDocumentService $documents)
    {
    }

    /** @return array{version: PartnerContractVersion, mail_sent: bool, email: ?string, signing_url: string} */
    public function createAndSend(Partner $partner, User $actor): array
    {
        $this->documents->assertContractEligible($partner);
        $content = PartnerContractRenderer::render($partner);
        $token = Str::random(48);
        $version = $partner->contractVersions()->create([
            'version_label' => 'Hợp đồng điện tử — '.now()->format('d/m/Y H:i'),
            'change_note' => 'Tạo tự động để ký điện tử',
            'changed_by' => $actor->id,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'legal_document_snapshot' => $this->documents->snapshot($partner),
            'signing_token' => $token,
        ]);
        $partner->update(['contract_status' => 'pending']);

        $email = $partner->email ?? $partner->owner()?->email;
        $signingUrl = route('contract.sign.show', $token);
        $mailSent = false;
        if (filled($email)) {
            try {
                Mail::to($email)->send(new LockNotificationMail(
                    'Yêu cầu ký hợp đồng điện tử',
                    "<p>Vui lòng mở liên kết để xem và xác nhận hợp đồng:</p><p><a href=\"{$signingUrl}\">{$signingUrl}</a></p>"
                ));
                $mailSent = true;
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return compact('version', 'mailSent', 'email', 'signingUrl');
    }

    /** @return array{pdf: string, file_name: string} */
    public function platformSign(Partner $partner, User $actor, string $ip, string $userAgent): array
    {
        $this->documents->assertContractEligible($partner);
        $version = $partner->contractVersions()->first();
        if (! $version || ! $version->isPartnerConfirmed()) {
            throw ValidationException::withMessages(['contract' => 'Đối tác chưa xác nhận hợp đồng bằng OTP.']);
        }
        if ($version->isPlatformSigned()) {
            throw ValidationException::withMessages(['contract' => 'Hợp đồng đã được nền tảng ký số.']);
        }

        $signingTime = now();
        $version->platform_signed_at = $signingTime;
        $version->setRelation('platformSignedBy', $actor);
        $result = app(ContractPdfSigningService::class)->signAndEmbed($version, [
            'role' => 'platform', 'name' => $actor->name, 'user_id' => $actor->id,
        ]);
        $version->update([
            'platform_signing_provider' => app(DigitalSignatureProvider::class)->name(),
            'platform_signed_at' => $signingTime,
            'platform_signed_by' => $actor->id,
            'platform_signed_ip' => $ip,
            'platform_signed_user_agent' => $userAgent,
        ]);
        $partner->update(['contract_status' => 'active', 'contract_signed_at' => $signingTime]);

        return ['pdf' => $result['pdf'], 'file_name' => "hop-dong-{$version->id}-{$signingTime->format('YmdHis')}.pdf"];
    }
}
