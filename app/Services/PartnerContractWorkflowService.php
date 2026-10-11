<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\LockNotificationMail;
use App\Models\Partner;
use App\Models\PartnerContractVersion;
use App\Models\User;
use App\Services\ContractSigning\Contracts\DigitalSignatureProvider;
use App\Services\PdfSigning\ContractPdfRenderer;
use App\Services\PdfSigning\ContractPdfSigningService;
use App\Services\PdfSigning\UploadedPdfSignatureVerifier;
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
    public function createVersion(Partner $partner, ?User $actor, string $label, string $note, bool $preApproval = false, string $kind = 'contract'): PartnerContractVersion
    {
        $isAddendum = $kind === 'addendum';

        if ($isAddendum) {
            $this->assertCanCreateAddendum($partner);
        } else {
            $this->assertCanCreate($partner, $preApproval);
        }

        // Mã số hợp đồng TỰ SINH (001/2026/HĐHT-365) — cấp 1 lần cho đối tác, giữ nguyên khi tạo lại phiên bản.
        app(ContractCodeService::class)->assign($partner);

        $content = PartnerContractRenderer::render($partner, $kind);
        $version = $partner->contractVersions()->create([
            'version_label' => $label,
            'kind' => $kind,
            'change_note' => $note,
            'changed_by' => $actor?->id,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'legal_document_snapshot' => $this->documents->snapshot($partner),
            'signing_token' => Str::random(48),
        ]);

        // Phụ lục không làm hợp đồng đang hiệu lực quay về "chờ ký" — đối tác vẫn hoạt động bình thường trong lúc ký.
        if (! $isAddendum) {
            $partner->update(['contract_status' => 'pending']);
        }

        return $version;
    }

    /**
     * Phụ lục "Ký quỹ và thanh toán" chỉ dành cho Homestay đã có hợp đồng hiệu lực (mẫu cũ — 365home thu hộ) mà chưa chuyển sang luồng mới;
     * chưa có phiên bản mới nhất nào được đối tác xác nhận (đã xác nhận thì chỉ còn bước nền tảng ký).
     */
    public function assertCanCreateAddendum(Partner $partner): void
    {
        if ($partner->isMinihouse()) {
            throw ValidationException::withMessages(['contract' => 'MiniHouse không dùng hợp đồng đối tác.']);
        }
        if ($partner->contract_status !== 'active' || blank($partner->contract_signed_at)) {
            throw ValidationException::withMessages(['contract' => 'Đối tác chưa có hợp đồng đang hiệu lực — hãy tạo hợp đồng mới (đã gồm điều Ký quỹ) thay vì phụ lục.']);
        }
        if ($partner->usesDirectPayment()) {
            throw ValidationException::withMessages(['contract' => 'Đối tác đã chuyển sang luồng thanh toán mới — không cần phụ lục.']);
        }
        if ($partner->contractVersions()->first()?->isPartnerConfirmed() && ! $partner->contractVersions()->first()?->isPlatformSigned()) {
            throw ValidationException::withMessages(['contract' => 'Đối tác đã xác nhận bản hiện tại — hãy ký phía nền tảng để hoàn tất.']);
        }
        $missing = $this->missingTerms($partner);
        if ($missing !== []) {
            throw ValidationException::withMessages(['contract_terms' => array_values($missing)]);
        }
    }

    /** @return array{version: PartnerContractVersion, mailSent: bool, email: ?string, signingUrl: string} */
    public function createAddendumAndSend(Partner $partner, ?User $actor): array
    {
        return $this->createAndSend($partner, $actor, 'addendum');
    }

    /** @return array{version: PartnerContractVersion, mailSent: bool, email: ?string, signingUrl: string} */
    public function createAndSend(Partner $partner, ?User $actor, string $kind = 'contract'): array
    {
        $isAddendum = $kind === 'addendum';
        $version = $this->createVersion(
            $partner,
            $actor,
            ($isAddendum ? 'Phụ lục ký quỹ & thanh toán — ' : 'Hợp đồng điện tử — ') . now()->format('d/m/Y H:i'),
            $isAddendum ? 'Phụ lục chuyển sang luồng tiền đặt phòng về thẳng đối tác và ký quỹ' : 'Tạo tự động để ký điện tử',
            kind: $kind,
        );

        // Ưu tiên email LIÊN HỆ của đối tác; chỉ dùng email đăng nhập khi chưa khai báo.
        $email = $partner->email ?? $partner->owner()?->email;
        $signingUrl = route('contract.sign.show', $version->signing_token);
        $mailSent = false;
        if (filled($email)) {
            try {
                Mail::to($email)->send(new LockNotificationMail(
                    $isAddendum ? 'Yêu cầu ký phụ lục ký quỹ & thanh toán' : 'Yêu cầu ký hợp đồng điện tử',
                    '<p>Vui lòng mở liên kết để xem toàn văn và ký ' . ($isAddendum ? 'phụ lục hợp đồng (ký quỹ và thanh toán doanh thu)' : 'hợp đồng hợp tác') . ":</p><p><a href=\"{$signingUrl}\">{$signingUrl}</a></p>"
                ));
                $mailSent = true;
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return compact('version', 'mailSent', 'email', 'signingUrl');
    }

    /**
     * Chuyển đối tác sang luồng tiền mới (tiền đặt phòng về thẳng đối tác, ký quỹ, đối soát hoa hồng) vào đúng lúc hợp đồng mẫu mới/phụ lục có
     * hiệu lực. Chưa có mức ký quỹ thì đặt mức gợi ý (1.000.000đ × số phòng, tối thiểu 5.000.000đ): đối tác mới phải nạp đủ trước khi mở bán
     * trực tuyến; đối tác đang hoạt động ($alreadyOperating) có hạn nạp ban đầu (escrow.initial_grace_days) kể từ hôm nay.
     */
    public function activatePaymentFlow(Partner $partner, \DateTimeInterface $at, User $by, bool $alreadyOperating, ?PartnerContractVersion $version = null): void
    {
        if ($partner->usesDirectPayment()) {
            return;
        }

        $partner->forceFill(['payment_flow_effective_at' => $at])->saveQuietly();

        // Miễn phí tháng đầu (chỉ đối tác mới): căn cứ là điều "Ưu đãi tháng đầu" đã ghi trong hợp đồng được ký — công tắc bật/tắt sau đó không làm đổi hợp đồng đã tạo.
        // Trong thời gian miễn phí ký quỹ chưa bắt buộc: hạn áp dụng = ngày hết miễn phí (hết hạn mà chưa nạp đủ thì ngưng bán như đối tác mới ban đầu).
        $trial = app(PartnerTrialService::class);
        $trialUntil = (! $alreadyOperating && $version && $trial->contractPromisesTrial($version)) ? $trial->grant($partner, $at, $by) : null;

        $escrow = app(EscrowService::class);
        $min = (int) ($partner->escrow_min_amount ?: $escrow->suggestedMinAmount($partner));
        $escrow->setMinAmount($partner, $min, $trialUntil ?? ($alreadyOperating ? now()->addDays((int) config('escrow.initial_grace_days')) : now()->subMinute()), $by);

        $escrow->notifySuperAdmins('payment_flow_effective', 'Đối tác chuyển sang luồng thanh toán mới', ($partner->legal_name ?: $partner->name)
            . ': hợp đồng/phụ lục đã có hiệu lực — tiền đặt phòng về thẳng đối tác (khi đã cấu hình kênh PayOS)' . ($trialUntil ? '; MIỄN PHÍ tháng đầu đến ' . $trialUntil->format('d/m/Y') . ' (không hoa hồng, chưa bắt nạp ký quỹ)' : '') . '; ký quỹ tối thiểu ' . number_format($min, 0, ',', '.') . 'đ.', $partner);
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

        if (! $reExport) {
            $manager = app(\App\Services\ContractSigning\ContractSigningManager::class);
            if ($manager->isTestProvider() && ! $manager->localSigningAllowed()) {
                throw ValidationException::withMessages(['contract' => 'Nhà cung cấp chữ ký số đang là "Local" (ký thử nghiệm, KHÔNG có giá trị pháp lý) nên không dùng để ký hợp đồng thật. Vào Cấu hình web › Chữ ký số, chọn VNPT SmartCA hoặc MISA eSign, lưu rồi bấm Kiểm tra kết nối.']);
            }
        }

        $signingTime = now();
        if (! $reExport) {
            // Gán tạm vào bộ nhớ trước khi render PDF để khung ký "Nền tảng" trong PDF không hiện sai "Chưa ký".
            $version->platform_signed_at = $signingTime;
            $version->setRelation('platformSignedBy', $actor);
        }

        $context = ['role' => 'platform', 'name' => $actor->name, 'user_id' => $actor->id];
        $partnerPdf = $version->getFirstMedia('partner_signed_pdf');
        if ($partnerPdf && is_file($partnerPdf->getPath())) {
            // Đối tác đã ký bằng CKS: ký chồng lên đúng file đó để PDF cuối có đủ 2 chữ ký số.
            try {
                $result = app(ContractPdfSigningService::class)->signExisting((string) file_get_contents($partnerPdf->getPath()), $context);
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages(['contract' => 'Không ký chồng được lên file đối tác đã ký: ' . $e->getMessage() . ' Hãy dùng cách ký USB (tải PDF về ký bằng Adobe/Foxit rồi tải lên).']);
            }
        } else {
            $result = app(ContractPdfSigningService::class)->signAndEmbed($version, $context);
        }

        if (! $reExport) {
            $this->completePlatformSigning($partner, $version, $actor, $ip, $userAgent, $signingTime, app(DigitalSignatureProvider::class)->name(), 'auto', $result['certificate']);
        }

        $fileName = "hop-dong-{$version->id}-{$signingTime->format('YmdHis')}.pdf";
        // Lưu bản đã ký lên server (hợp đồng cũ ký trước khi có tính năng này sẽ được lưu ở lần xuất lại đầu tiên).
        $version->addMediaFromString($result['pdf'])->usingFileName($fileName)->toMediaCollection('signed_pdf', 'local');

        return ['pdf' => $result['pdf'], 'file_name' => $fileName];
    }

    /** Hoàn tất khi Bên A đã ký (tự động hoặc USB): ghi chữ ký, chuyển hợp đồng sang hiệu lực, kích hoạt luồng tiền, lưu PDF đã ký. */
    private function completePlatformSigning(Partner $partner, PartnerContractVersion $version, User $actor, string $ip, string $userAgent, \DateTimeInterface $signingTime, string $provider, string $method, array $certificate): void
    {
        $signingTime = \Illuminate\Support\Carbon::instance($signingTime);
        $version->update([
            'platform_signing_provider' => $provider,
            'platform_sign_method' => $method,
            'platform_signature_certificate' => $certificate,
            'platform_signed_at' => $signingTime,
            'platform_signed_by' => $actor->id,
            'platform_signed_ip' => $ip,
            'platform_signed_user_agent' => $userAgent,
        ]);
        // Phụ lục không đổi ngày hiệu lực/hết hạn của hợp đồng gốc — chỉ chuyển đối tác sang luồng tiền mới (activatePaymentFlow).
        $attributes = $version->isAddendum() ? [] : ['contract_status' => 'active', 'contract_signed_at' => $signingTime];
        $isFirstContract = blank($partner->contract_signed_at);
        // Điều 7 + 15 (mẫu Homestay): thời hạn N tháng tính từ ngày Hợp đồng có hiệu lực = ngày Bên A ký. Hợp đồng ĐẦU TIÊN của đối tác:
        // tính lại ngày hết hạn = ngày ký này + đúng số tháng đã in trong bản đối tác ký (số tháng suy từ ngày hết hạn lúc tạo bản),
        // để đối tác ký sớm, Bên A ký trễ thì thời hạn thực tế vẫn đủ N tháng. Bản tạo lại cho hợp đồng đã từng có hiệu lực thì giữ nguyên.
        if (! $version->isAddendum() && ! $partner->isMinihouse() && $isFirstContract && $partner->contract_expires_at && $version->created_at) {
            $months = PartnerContractRenderer::termMonths($partner->contract_expires_at, $version->created_at);
            $attributes['contract_expires_at'] = $signingTime->copy()->startOfDay()->addMonths($months);
        }
        if ($attributes !== []) {
            $partner->update($attributes);
        }

        // Hợp đồng mẫu mới / phụ lục có hiệu lực → tiền đặt phòng về thẳng đối tác + ký quỹ + đối soát.
        if (! $partner->isMinihouse()) {
            $this->activatePaymentFlow($partner->fresh(), $signingTime, $actor, $version->isAddendum() || ! $isFirstContract, $version);
        }

    }

    /**
     * KÝ LẠI phần nền tảng cho hợp đồng đã được ký THỬ NGHIỆM (nhà cung cấp "local", khoá tự sinh, không có giá trị pháp lý) khi nay đã cấu hình
     * nhà cung cấp thật. Chỉ thay chữ ký và file PDF đã ký — KHÔNG đổi trạng thái hợp đồng, ngày hiệu lực, luồng tiền, tài khoản đối tác
     * (những thứ đó đã được kích hoạt ở lần ký trước). Bản đã ký bằng chữ ký số thật thì không cho ký lại.
     *
     * @return array{pdf: string, file_name: string}
     */
    public function platformResign(Partner $partner, User $actor, string $ip, string $userAgent): array
    {
        $this->documents->assertContractEligible($partner);
        $version = $partner->contractVersions()->first();
        if (! $version || ! $version->isPlatformSigned()) {
            throw ValidationException::withMessages(['contract' => 'Hợp đồng chưa được nền tảng ký — hãy ký bình thường.']);
        }
        if ($version->platform_signing_provider !== 'local') {
            throw ValidationException::withMessages(['contract' => 'Hợp đồng đã được ký bằng chữ ký số thật, không ký lại được.']);
        }
        $manager = app(\App\Services\ContractSigning\ContractSigningManager::class);
        if ($manager->isTestProvider()) {
            throw ValidationException::withMessages(['contract' => 'Nhà cung cấp chữ ký số vẫn đang là "Local" (thử nghiệm). Vào Cấu hình web › Chữ ký số chọn VNPT SmartCA hoặc MISA eSign, lưu rồi thử lại.']);
        }

        $signingTime = now();
        // Khung "ĐẠI DIỆN BÊN A" trong PDF lấy theo thời gian/người ký: gán tạm trong bộ nhớ trước khi render.
        $version->platform_signed_at = $signingTime;
        $version->setRelation('platformSignedBy', $actor);

        $context = ['role' => 'platform', 'name' => $actor->name, 'user_id' => $actor->id];
        $partnerPdf = $version->getFirstMedia('partner_signed_pdf');
        try {
            if ($partnerPdf && is_file($partnerPdf->getPath())) {
                $result = app(ContractPdfSigningService::class)->signExisting((string) file_get_contents($partnerPdf->getPath()), $context);
            } else {
                $result = app(ContractPdfSigningService::class)->signAndEmbed($version, $context);
            }
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['contract' => 'Không ký lại được: ' . $e->getMessage()]);
        }

        $version->update([
            'platform_signing_provider' => app(DigitalSignatureProvider::class)->name(),
            'platform_sign_method' => 'auto',
            'platform_signature_certificate' => $result['certificate'],
            'platform_signed_at' => $signingTime,
            'platform_signed_by' => $actor->id,
            'platform_signed_ip' => $ip,
            'platform_signed_user_agent' => $userAgent,
        ]);

        $fileName = "hop-dong-{$version->id}-{$signingTime->format('YmdHis')}.pdf";
        $version->addMediaFromString($result['pdf'])->usingFileName($fileName)->toMediaCollection('signed_pdf', 'local');

        \App\Models\PartnerStatusLog::create([
            'partner_id' => $partner->id, 'from_status' => $partner->verification_status, 'to_status' => $partner->verification_status, 'changed_by' => $actor->id,
            'note' => 'Nền tảng đã KÝ LẠI hợp đồng bằng chữ ký số thật (thay bản ký thử nghiệm Local).',
        ]);

        return ['pdf' => $result['pdf'], 'file_name' => $fileName];
    }

    /** Bản hợp đồng đang chờ nền tảng ký: đối tác đã xác nhận OTP, nền tảng chưa ký. */
    private function versionAwaitingPlatform(Partner $partner): PartnerContractVersion
    {
        $this->documents->assertContractEligible($partner);
        $version = $partner->contractVersions()->first();
        if (! $version || ! $version->isPartnerConfirmed()) {
            throw ValidationException::withMessages(['contract' => 'Đối tác chưa xác nhận hợp đồng bằng OTP.']);
        }
        if ($version->isPlatformSigned()) {
            throw ValidationException::withMessages(['contract' => 'Hợp đồng đã được nền tảng ký số.']);
        }

        return $version;
    }

    /**
     * Ký bằng USB token, bước 1: PDF hợp đồng CHƯA ký để người giữ chứng thư số ký trên máy của họ. Giữ nguyên từng byte giữa các lần tải
     * (lưu lại bản đã đưa đi ký) để bản tải lên đối chiếu được.
     *
     * @return array{pdf: string, file_name: string}
     */
    public function preparePlatformPdf(Partner $partner): array
    {
        $version = $this->versionAwaitingPlatform($partner);
        $fileName = "hop-dong-{$version->id}-can-ky.pdf";

        $stored = $version->getFirstMedia('platform_base_pdf');
        if ($stored && is_file($stored->getPath()) && filled($version->platform_sign_base_hash)) {
            $pdf = (string) file_get_contents($stored->getPath());
            if (hash_equals($version->platform_sign_base_hash, hash('sha256', $pdf))) {
                return ['pdf' => $pdf, 'file_name' => $fileName];
            }
        }

        // Đối tác đã ký bằng CKS: bản cần ký tiếp CHÍNH LÀ file đối tác đã ký (không render lại, nếu không sẽ mất chữ ký của đối tác).
        $partnerSigned = $version->getFirstMedia('partner_signed_pdf');
        $pdf = $partnerSigned && is_file($partnerSigned->getPath())
            ? (string) file_get_contents($partnerSigned->getPath())
            : ContractPdfRenderer::render(PartnerContractRenderer::renderFramed($version->content, $version->partner, $version));
        $version->addMediaFromString($pdf)->usingFileName($fileName)->toMediaCollection('platform_base_pdf', 'local');
        $version->update([
            'platform_sign_base_hash' => hash('sha256', $pdf),
            'platform_sign_base_length' => strlen($pdf),
            'platform_sign_prepared_at' => now(),
        ]);

        return ['pdf' => $pdf, 'file_name' => $fileName];
    }

    /**
     * Ký bằng USB token, bước 2: nhận PDF đã ký, kiểm tra rồi hoàn tất như khi nền tảng ký tự động.
     *
     * @return array{pdf: string, file_name: string, certificate: array<string, mixed>}
     */
    public function platformUpload(Partner $partner, User $actor, string $ip, string $userAgent, string $signedPdf): array
    {
        $version = $this->versionAwaitingPlatform($partner);
        if (blank($version->platform_sign_base_hash)) {
            throw ValidationException::withMessages(['signed_pdf' => 'Chưa tải PDF hợp đồng để ký — hãy tải file cần ký từ hệ thống trước.']);
        }

        $check = app(UploadedPdfSignatureVerifier::class)->verify($signedPdf, $version->platform_sign_base_hash, (int) $version->platform_sign_base_length);
        if (! $check['ok']) {
            throw ValidationException::withMessages(['signed_pdf' => implode(' ', $check['errors'])]);
        }

        $signingTime = now();
        $this->completePlatformSigning($partner, $version, $actor, $ip, $userAgent, $signingTime, 'usb_token', 'usb', $check['certificate']);

        // Bản tải lên chính là bản ký — hợp đồng đã hoàn tất, lưu đúng file đó (không render lại).
        $fileName = "hop-dong-{$version->id}-{$signingTime->format('YmdHis')}.pdf";
        $version->addMediaFromString($signedPdf)->usingFileName($fileName)->toMediaCollection('signed_pdf', 'local');

        return ['pdf' => $signedPdf, 'file_name' => $fileName, 'certificate' => $check['certificate']];
    }
}
