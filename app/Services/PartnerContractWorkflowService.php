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

// MỘT nơi duy nhất cho nghiệp vụ hợp đồng đối tác — Filament (tab Hợp đồng), API admin và luồng đăng ký hợp tác công khai
// đều gọi vào đây để không lệch logic: điều kiện tạo hợp đồng, tạo phiên bản, vô hiệu link cũ, ký nền tảng.
class PartnerContractWorkflowService
{
    public function __construct(private readonly PartnerLegalDocumentService $documents)
    {
    }

    /**
     * Điều khoản/thông tin PHẢI có trước khi tạo hợp đồng (nếu thiếu, hợp đồng gửi khách sẽ hiện dấu chấm). Mã số hợp đồng không nằm trong danh sách này vì được TỰ SINH.
     * Homestay: bắt buộc tỷ lệ hoa hồng. MiniHouse: không thu hoa hồng (chỉ phí gói) nên không đòi.
     *
     * @return array<string, string> trường => thông báo
     */
    public function missingTerms(Partner $partner): array
    {
        $missing = [];

        if (! $partner->isMinihouse()) {
            $rate = $this->commissionValue($partner->commission_rate);
            if ($rate === null) {
                $missing['commission_rate'] = 'Chưa nhập tỷ lệ hoa hồng (số từ 0 đến 100).';
            }
        }

        if (blank($partner->contract_expires_at)) {
            $missing['contract_expires_at'] = 'Chưa nhập ngày hết hạn hợp đồng.';
        } elseif ($partner->contract_expires_at->lte(today())) {
            $missing['contract_expires_at'] = 'Ngày hết hạn hợp đồng phải sau hôm nay.';
        } elseif ($partner->contract_signed_at && $partner->contract_expires_at->lt($partner->contract_signed_at)) {
            $missing['contract_expires_at'] = 'Ngày hết hạn phải sau ngày ký kết.';
        }

        foreach (['legal_name' => 'tên pháp lý', 'address' => 'địa chỉ', 'email' => 'email', 'representative_name' => 'họ tên người đại diện', 'representative_id_number' => 'số CMND/CCCD người đại diện', 'representative_id_issued_at' => 'ngày cấp CMND/CCCD người đại diện'] as $field => $label) {
            if (blank($partner->{$field})) {
                $missing[$field] = "Thiếu {$label} của đối tác.";
            }
        }

        return $missing;
    }

    /** Tỷ lệ hoa hồng hợp lệ ("10", "10%", "7.5") → số; ngoài 0–100 hoặc sai định dạng → null. */
    public function commissionValue(?string $raw): ?float
    {
        $raw = trim((string) $raw);
        if ($raw === '' || ! preg_match('/^\d{1,3}([.,]\d{1,2})?\s*%?$/', $raw)) {
            return null;
        }
        $value = (float) str_replace(',', '.', rtrim($raw, "% \t"));

        return $value >= 0 && $value <= 100 ? $value : null;
    }

    /** Hồ sơ đủ điều kiện để tạo/gửi hợp đồng? (không ném lỗi) */
    public function canCreate(Partner $partner): bool
    {
        return $this->documents->isContractEligible($partner) && $this->missingTerms($partner) === [] && ! $this->isLocked($partner);
    }

    /**
     * Phiên bản mới nhất đã được đối tác xác nhận → không tạo lại được nữa (chỉ còn bước nền tảng ký).
     * Luồng "đối tác ký trước": đối tác ký bản điều khoản chuẩn TRƯỚC khi 365 Home xem hồ sơ, nên vẫn cho tạo lại (đối tác ký lại bản mới)
     * cho tới khi nền tảng đã ký.
     */
    public function isLocked(Partner $partner): bool
    {
        $latest = $partner->contractVersions()->first();

        return $partner->signsBeforeReview() ? (bool) $latest?->isPlatformSigned() : (bool) $latest?->isPartnerConfirmed();
    }

    /** $preApproval = true: tạo hợp đồng cho đối tác ký TRƯỚC khi hồ sơ được duyệt (chỉ áp dụng cho Partner::signsBeforeReview đang chờ duyệt). */
    public function assertCanCreate(Partner $partner, bool $preApproval = false): void
    {
        if (! ($preApproval && $partner->signsBeforeReview() && $partner->verification_status === 'pending')) {
            $this->documents->assertContractEligible($partner);
        }

        if ($this->isLocked($partner)) {
            throw ValidationException::withMessages(['contract' => 'Đối tác đã xác nhận hợp đồng này — không thể tạo lại. Hãy ký phía nền tảng để hoàn tất.']);
        }

        $missing = $this->missingTerms($partner);
        if ($missing !== []) {
            throw ValidationException::withMessages(['contract_terms' => array_values($missing)]);
        }
    }

    /** Tạo phiên bản hợp đồng (KHÔNG gửi email): link ký của các phiên bản cũ chưa ký tự bị vô hiệu (xem PartnerContractVersion). */
    public function createVersion(Partner $partner, ?User $actor, string $label, string $note, bool $preApproval = false): PartnerContractVersion
    {
        $this->assertCanCreate($partner, $preApproval);

        // Mã số hợp đồng TỰ SINH (001/2026/HĐHT-365) — cấp 1 lần cho đối tác, giữ nguyên khi tạo lại phiên bản.
        app(ContractCodeService::class)->assign($partner);

        $content = PartnerContractRenderer::render($partner);
        $version = $partner->contractVersions()->create([
            'version_label' => $label,
            'change_note' => $note,
            'changed_by' => $actor?->id,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'legal_document_snapshot' => $this->documents->snapshot($partner),
            'signing_token' => Str::random(48),
        ]);
        $partner->update(['contract_status' => 'pending']);

        return $version;
    }

    /** @return array{version: PartnerContractVersion, mailSent: bool, email: ?string, signingUrl: string} */
    public function createAndSend(Partner $partner, ?User $actor): array
    {
        $version = $this->createVersion($partner, $actor, 'Hợp đồng điện tử — ' . now()->format('d/m/Y H:i'), 'Tạo tự động để ký điện tử');

        // Ưu tiên email LIÊN HỆ của đối tác; chỉ dùng email đăng nhập khi chưa khai báo.
        $email = $partner->email ?? $partner->owner()?->email;
        $signingUrl = route('contract.sign.show', $version->signing_token);
        $mailSent = false;
        if (filled($email)) {
            try {
                Mail::to($email)->send(new LockNotificationMail(
                    'Yêu cầu ký hợp đồng điện tử',
                    "<p>Vui lòng mở liên kết để xem toàn văn và ký hợp đồng hợp tác:</p><p><a href=\"{$signingUrl}\">{$signingUrl}</a></p>"
                ));
                $mailSent = true;
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return compact('version', 'mailSent', 'email', 'signingUrl');
    }

    /**
     * Nền tảng ký số hợp đồng sau khi đối tác đã xác nhận OTP. $reExport = true: xuất thêm 1 bản PDF khi đã ký rồi
     * (không đổi trạng thái hợp đồng).
     *
     * @return array{pdf: string, file_name: string}
     */
    public function platformSign(Partner $partner, User $actor, string $ip, string $userAgent, bool $reExport = false): array
    {
        $this->documents->assertContractEligible($partner);
        $version = $partner->contractVersions()->first();
        if (! $version || ! $version->isPartnerConfirmed()) {
            throw ValidationException::withMessages(['contract' => 'Đối tác chưa xác nhận hợp đồng bằng OTP.']);
        }
        if (! $reExport && $version->isPlatformSigned()) {
            throw ValidationException::withMessages(['contract' => 'Hợp đồng đã được nền tảng ký số.']);
        }
        if ($reExport && ! $version->isPlatformSigned()) {
            throw ValidationException::withMessages(['contract' => 'Hợp đồng chưa được nền tảng ký số nên chưa xuất lại được.']);
        }

        // Tải lại: đã có file PDF ký số lưu trên server thì trả đúng file đó, KHÔNG ký lại.
        if ($reExport && ($stored = $version->getFirstMedia('signed_pdf'))) {
            return ['pdf' => file_get_contents($stored->getPath()), 'file_name' => $stored->file_name];
        }

        $signingTime = now();
        if (! $reExport) {
            // Gán tạm vào bộ nhớ trước khi render PDF để khung ký "Nền tảng" trong PDF không hiện sai "Chưa ký".
            $version->platform_signed_at = $signingTime;
            $version->setRelation('platformSignedBy', $actor);
        }

        $result = app(ContractPdfSigningService::class)->signAndEmbed($version, [
            'role' => 'platform', 'name' => $actor->name, 'user_id' => $actor->id,
        ]);

        if (! $reExport) {
            $version->update([
                'platform_signing_provider' => app(DigitalSignatureProvider::class)->name(),
                'platform_signature_certificate' => $result['certificate'],
                'platform_signed_at' => $signingTime,
                'platform_signed_by' => $actor->id,
                'platform_signed_ip' => $ip,
                'platform_signed_user_agent' => $userAgent,
            ]);
            $attributes = ['contract_status' => 'active', 'contract_signed_at' => $signingTime];
            // Điều 7 + 15 (mẫu Homestay): thời hạn N tháng tính từ ngày Hợp đồng có hiệu lực = ngày Bên A ký. Hợp đồng ĐẦU TIÊN của đối tác:
            // tính lại ngày hết hạn = ngày ký này + đúng số tháng đã in trong bản đối tác ký (số tháng suy từ ngày hết hạn lúc tạo bản),
            // để đối tác ký sớm, Bên A ký trễ thì thời hạn thực tế vẫn đủ N tháng. Bản tạo lại cho hợp đồng đã từng có hiệu lực thì giữ nguyên.
            if (! $partner->isMinihouse() && blank($partner->contract_signed_at) && $partner->contract_expires_at && $version->created_at) {
                $months = PartnerContractRenderer::termMonths($partner->contract_expires_at, $version->created_at);
                $attributes['contract_expires_at'] = $signingTime->copy()->startOfDay()->addMonths($months);
            }
            $partner->update($attributes);
        }

        $fileName = "hop-dong-{$version->id}-{$signingTime->format('YmdHis')}.pdf";
        // Lưu bản đã ký lên server (hợp đồng cũ ký trước khi có tính năng này sẽ được lưu ở lần xuất lại đầu tiên).
        $version->addMediaFromString($result['pdf'])->usingFileName($fileName)->toMediaCollection('signed_pdf', 'local');

        return ['pdf' => $result['pdf'], 'file_name' => $fileName];
    }
}
