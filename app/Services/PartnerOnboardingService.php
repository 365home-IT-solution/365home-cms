<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\LockNotificationMail;
use App\Models\Partner;
use App\Models\PartnerContractVersion;
use App\Models\PartnerLegalDocument;
use App\Models\PartnerStatusLog;
use App\Models\User;
use App\Support\PartnerContractRenderer;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

// Đăng ký hợp tác CÔNG KHAI (Homestay / MiniHouse), không cần tài khoản:
// đăng ký → nộp giấy tờ pháp lý → điền thông tin ký hợp đồng → tạo & gửi hợp đồng ký (link + OTP email, lưu phiên bản hợp đồng)
// → gửi hồ sơ chờ duyệt. Đối tác truy cập hồ sơ bằng mã hồ sơ trả về lúc đăng ký (DB chỉ lưu sha256 của mã).
class PartnerOnboardingService
{
    // Giấy tờ MiniHouse cấp toà nhà (PCCC, ANTT, quyền khai thác) gắn với toà nhà — bổ sung sau khi được duyệt và tạo toà nhà.
    public const BUILDING_TYPES = ['fire_safety', 'security_order', 'property_ownership_or_use'];

    public const CONTRACT_FIELDS = [
        'legal_name', 'tax_code', 'address', 'email', 'representative_name', 'representative_id_number',
        'representative_dob', 'business_license_date', 'business_license_issuer',
        'bank_name', 'bank_branch', 'bank_account_number', 'bank_account_holder',
    ];

    // Bắt buộc có trước khi tạo hợp đồng.
    public const CONTRACT_REQUIRED = ['legal_name', 'address', 'email', 'representative_name', 'representative_id_number'];

    public const LABELS = [
        'partner_type' => 'loại hình hợp tác', 'full_name' => 'họ tên người đăng ký', 'phone' => 'số điện thoại', 'email' => 'email',
        'business_name' => 'tên cơ sở kinh doanh', 'address' => 'địa chỉ', 'note' => 'ghi chú',
        'legal_name' => 'tên pháp lý', 'tax_code' => 'mã số thuế', 'representative_name' => 'họ tên người đại diện',
        'representative_id_number' => 'số CMND/CCCD người đại diện', 'representative_dob' => 'ngày sinh người đại diện',
        'business_license_date' => 'ngày cấp giấy phép kinh doanh', 'business_license_issuer' => 'nơi cấp giấy phép kinh doanh',
        'bank_name' => 'ngân hàng', 'bank_branch' => 'chi nhánh ngân hàng', 'bank_account_number' => 'số tài khoản',
        'bank_account_holder' => 'chủ tài khoản', 'type' => 'loại giấy tờ', 'name' => 'tên giấy tờ', 'document_number' => 'số giấy tờ',
        'issuer' => 'nơi cấp', 'issued_at' => 'ngày cấp', 'expires_at' => 'ngày hết hạn', 'file' => 'tệp giấy tờ',
    ];

    public function __construct(private readonly PartnerLegalDocumentService $documents)
    {
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function findByToken(string $token): Partner
    {
        return Partner::query()->where('onboarding_token', self::hashToken($token))->firstOrFail();
    }

    /** @return array{partner: Partner, token: string} */
    public function register(array $data): array
    {
        $phone = preg_replace('/^\+84/', '0', $data['phone']);

        if (Partner::query()->where('partner_type', $data['partner_type'])->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => 'Số điện thoại này đã đăng ký hợp tác. Vui lòng dùng mã hồ sơ đã nhận hoặc liên hệ 365 Home.']);
        }

        $token = Str::random(48);

        $partner = DB::transaction(function () use ($data, $phone, $token) {
            $partner = Partner::create([
                'partner_type'        => $data['partner_type'],
                'name'                => $data['business_name'],
                'legal_name'          => $data['business_name'],
                'representative_name' => $data['full_name'],
                'phone'               => $phone,
                'email'               => $data['email'],
                'address'             => $data['address'],
                'status'              => false,
                'verification_status' => 'pending',
                'contract_status'     => 'draft',
                'onboarding_token'    => self::hashToken($token),
            ]);

            PartnerStatusLog::create([
                'partner_id' => $partner->id,
                'to_status'  => 'pending',
                'note'       => 'Đăng ký hợp tác từ website (đang hoàn thiện hồ sơ).' . (filled($data['note'] ?? null) ? ' Ghi chú: ' . $data['note'] : ''),
            ]);

            return $partner;
        });

        $url = route('partner-onboarding.page') . '?ma=' . $token;
        $this->mailPartner(
            $partner,
            'Đã nhận đăng ký hợp tác với 365 Home',
            '<p>Xin chào <strong>' . e($partner->representative_name ?: $partner->name) . '</strong>,</p>'
            . '<p>365 Home đã tạo hồ sơ đăng ký hợp tác cho <strong>' . e($partner->name) . '</strong>. Mở liên kết sau để tiếp tục nộp giấy tờ, điền thông tin hợp đồng và gửi duyệt (lưu lại email này để quay lại hồ sơ):</p>'
            . "<p><a href=\"{$url}\">{$url}</a></p>"
        );
        $this->notifyAdmins($partner, 'Có đăng ký hợp tác mới', $this->partnerLabel($partner) . ' vừa tạo hồ sơ đăng ký hợp tác, đang hoàn thiện giấy tờ.', 'partner_onboarding_registered', 'info', 'heroicon-o-user-plus');

        return ['partner' => $partner, 'token' => $token];
    }

    public function addDocument(Partner $partner, array $data, UploadedFile $file): PartnerLegalDocument
    {
        $this->assertEditable($partner);

        if ($partner->isMinihouse() && in_array($data['type'], self::BUILDING_TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Giấy tờ PCCC, ANTT và quyền khai thác toà nhà được bổ sung sau khi hồ sơ được duyệt và tạo toà nhà.']);
        }

        return DB::transaction(function () use ($partner, $data, $file) {
            $document = $partner->legalDocuments()->create([
                'type'            => $data['type'],
                'name'            => $data['name'] ?? null,
                'document_number' => $data['document_number'] ?? null,
                'issuer'          => $data['issuer'] ?? null,
                'issued_at'       => $data['issued_at'] ?? null,
                'expires_at'      => $data['expires_at'] ?? null,
                'status'          => 'draft',
            ]);
            $document->addMedia($file)->toMediaCollection('file');

            return $document;
        });
    }

    public function deleteDocument(Partner $partner, PartnerLegalDocument $document): void
    {
        $this->assertEditable($partner);

        if (! in_array($document->status, ['draft', 'changes_requested', 'rejected'], true)) {
            throw ValidationException::withMessages(['document' => 'Chỉ xoá được giấy tờ nháp, cần bổ sung hoặc bị từ chối.']);
        }

        $document->delete();
    }

    public function updateContractInfo(Partner $partner, array $data): Partner
    {
        $this->assertEditable($partner);
        $partner->update(collect($data)->only(self::CONTRACT_FIELDS)->all());

        return $partner->fresh();
    }

    /**
     * Admin đã DUYỆT giấy tờ → tự tạo hợp đồng từ thông tin đối tác đã khai, lưu phiên bản và gửi link ký (OTP email) cho đối tác.
     * Gọi từ PartnerLegalDocumentService::approveDossier. Đã có hợp đồng đang chờ ký/đã ký thì bỏ qua.
     *
     * @return array{version: ?PartnerContractVersion, mail_sent: bool, signing_url: ?string}
     */
    public function sendContractAfterApproval(Partner $partner): array
    {
        $latest = $this->latestContract($partner);
        if ($latest && ($latest->isPartnerConfirmed() || $latest->signing_token !== null)) {
            return ['version' => $latest, 'mail_sent' => false, 'signing_url' => null];
        }
        if (blank($partner->email)) {
            $this->log($partner, 'Không gửi được hợp đồng cho đối tác: hồ sơ không có email.');

            return ['version' => null, 'mail_sent' => false, 'signing_url' => null];
        }

        $token = Str::random(48);
        $content = PartnerContractRenderer::render($partner);
        $version = DB::transaction(function () use ($partner, $content, $token) {
            $version = $partner->contractVersions()->create([
                'version_label'           => 'Hợp đồng đăng ký hợp tác — ' . now()->format('d/m/Y H:i'),
                'change_note'             => 'Tự động tạo sau khi admin duyệt giấy tờ (đăng ký hợp tác trên website)',
                'changed_by'              => auth()->id(),
                'content'                 => $content,
                'content_hash'            => hash('sha256', $content),
                'legal_document_snapshot' => $this->documents->snapshot($partner),
                'signing_token'           => $token,
            ]);
            $partner->update(['contract_status' => 'pending']);

            return $version;
        });

        $signingUrl = route('contract.sign.show', $token);
        $mailSent = false;
        try {
            Mail::to($partner->email)->send(new LockNotificationMail(
                'Hồ sơ đã được duyệt — vui lòng ký hợp đồng hợp tác 365 Home',
                "<p>Giấy tờ pháp lý của bạn đã được 365 Home duyệt. Vui lòng mở liên kết để xem và ký xác nhận hợp đồng hợp tác (xác thực bằng mã OTP gửi về email này):</p><p><a href=\"{$signingUrl}\">{$signingUrl}</a></p>"
            ));
            $mailSent = true;
        } catch (\Throwable $e) {
            report($e);
        }
        $this->log($partner, 'Đã tạo hợp đồng và gửi link ký cho đối tác' . ($mailSent ? '.' : ' — gửi email thất bại, đối tác vẫn ký được trên trang đăng ký hợp tác.'));

        return ['version' => $version, 'mail_sent' => $mailSent, 'signing_url' => $signingUrl];
    }

    /** Đối tác đã ký xác nhận hợp đồng → báo super admin vào ký phía nền tảng (sau đó hệ thống tự cấp tài khoản). */
    public function notifyPartnerSigned(Partner $partner): void
    {
        if (blank($partner->onboarding_token)) {
            return;
        }
        $this->mailPartner($partner, 'Đã nhận chữ ký hợp đồng hợp tác của bạn', '<p>365 Home đã nhận xác nhận ký hợp đồng của bạn. Bước cuối: 365 Home ký xác nhận phía nền tảng, sau đó tài khoản quản trị sẽ được gửi về email này.</p>');
        try {
            app(AdminNotificationService::class)->notify(
                User::role(config('filament-shield.super_admin.name'))->get(),
                'Đối tác đã ký hợp đồng hợp tác',
                ($partner->legal_name ?: $partner->name) . ' (' . $partner->phone . ') đã ký xác nhận hợp đồng. Vào Đối tác → tab Hợp đồng để ký phía nền tảng; sau đó hệ thống tự cấp tài khoản.',
                ['type' => 'partner_contract_signed', 'partner_id' => $partner->id],
                'heroicon-o-pencil-square',
                'info',
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function submit(Partner $partner): Partner
    {
        if ($partner->verification_status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'Hồ sơ không ở trạng thái chờ hoàn thiện.']);
        }
        if (! $this->hasBusinessLicense($partner)) {
            throw ValidationException::withMessages(['documents' => 'Phải nộp Giấy phép kinh doanh (có tệp).']);
        }
        $missing = $this->missingContractFields($partner);
        if ($missing !== []) {
            throw ValidationException::withMessages(['contract_info' => 'Thiếu thông tin ký hợp đồng: ' . implode(', ', array_map(fn ($f) => self::LABELS[$f] ?? $f, $missing)) . '.']);
        }

        // Gửi các giấy tờ mới/bổ sung sang "chờ duyệt"; không còn giấy tờ mới (đã gửi trước đó) → báo lỗi như API hồ sơ pháp lý.
        $this->documents->submit($partner);

        try {
            app(AdminNotificationService::class)->notify(
                User::role(config('filament-shield.super_admin.name'))->get(),
                'Hồ sơ đăng ký hợp tác chờ duyệt',
                ($partner->legal_name ?: $partner->name) . ' (' . ($partner->isMinihouse() ? 'MiniHouse' : 'Homestay') . ', ' . $partner->phone . ') đã nộp giấy tờ pháp lý và thông tin hợp đồng. Vào Đối tác → tab Hồ sơ pháp lý để xem và duyệt; duyệt xong hệ thống gửi hợp đồng cho đối tác ký.',
                ['type' => 'partner_onboarding', 'partner_id' => $partner->id],
                'heroicon-o-document-check',
                'info',
            );
        } catch (\Throwable $e) {
            report($e);
        }

        $this->mailPartner(
            $partner,
            'Đã nhận hồ sơ hợp tác — chờ 365 Home duyệt',
            '<p>365 Home đã nhận hồ sơ hợp tác của <strong>' . e($partner->legal_name ?: $partner->name) . '</strong>. Chúng tôi sẽ xem xét giấy tờ; nếu hợp lệ, hợp đồng sẽ được gửi về email này để bạn ký trực tuyến. Nếu cần bổ sung, chúng tôi sẽ báo lý do qua email.</p>'
        );

        return $partner->fresh();
    }

    /**
     * Lấy lại mã hồ sơ khi đối tác mất mã (đổi trình duyệt/máy): cấp mã MỚI và gửi link về email đã đăng ký.
     * Chỉ khớp khi SĐT + email trùng hồ sơ chưa có tài khoản; mã cũ mất hiệu lực. Luôn trả như nhau để không lộ SĐT nào đã đăng ký.
     */
    public function recover(string $phone, string $email): void
    {
        $phone = preg_replace('/^\+84/', '0', $phone);

        // Tối đa 3 yêu cầu/giờ cho mỗi SĐT (chống spam email người khác).
        if (! RateLimiter::attempt('onb-recover:' . $phone, 3, fn () => true, 3600)) {
            return;
        }

        $partner = Partner::query()->whereNotNull('onboarding_token')->where('phone', $phone)->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();
        if (! $partner || $partner->contract_status === 'active' || $partner->users()->exists()) {
            return;
        }

        $token = Str::random(48);
        $partner->forceFill(['onboarding_token' => self::hashToken($token)])->save();
        $url = route('partner-onboarding.page') . '?ma=' . $token;

        try {
            Mail::to($partner->email)->send(new LockNotificationMail(
                'Mã hồ sơ đăng ký hợp tác 365 Home',
                "<p>Bạn vừa yêu cầu lấy lại hồ sơ đăng ký hợp tác. Mở liên kết sau để tiếp tục (liên kết thay cho mã hồ sơ cũ, mã cũ không còn dùng được):</p><p><a href=\"{$url}\">{$url}</a></p><p>Nếu không phải bạn yêu cầu, hãy bỏ qua email này.</p>"
            ));
            $this->log($partner, 'Đối tác lấy lại mã hồ sơ qua email.');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Gửi email cho đối tác đăng ký (không làm hỏng luồng chính nếu lỗi). */
    private function mailPartner(Partner $partner, string $subject, string $html): bool
    {
        if (blank($partner->email)) {
            return false;
        }

        try {
            Mail::to($partner->email)->send(new LockNotificationMail($subject, $html));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /** Thông báo cho Super Admin (chuông trong trang quản trị). */
    private function notifyAdmins(Partner $partner, string $title, string $body, string $type, string $color = 'info', string $icon = 'heroicon-o-bell'): void
    {
        try {
            app(AdminNotificationService::class)->notify(
                User::role(config('filament-shield.super_admin.name'))->get(),
                $title,
                $body,
                ['type' => $type, 'partner_id' => $partner->id],
                $icon,
                $color,
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function partnerLabel(Partner $partner): string
    {
        return ($partner->legal_name ?: $partner->name) . ' (' . ($partner->isMinihouse() ? 'MiniHouse' : 'Homestay') . ', ' . $partner->phone . ')';
    }

    /** Admin yêu cầu bổ sung / từ chối một giấy tờ của hồ sơ đăng ký công khai → báo cho đối tác qua email (họ không đăng nhập nên không thấy thông báo trong app). */
    public function notifyDocumentReviewed(PartnerLegalDocument $document, string $status, ?string $note): void
    {
        $partner = $document->partner;
        if (blank($partner->onboarding_token) || $status === 'approved') {
            return;
        }

        $docName = e(PartnerLegalDocument::TYPES[$document->type] ?? $document->type);
        $what = $status === 'rejected' ? 'bị từ chối' : 'cần bổ sung';
        $url = route('partner-onboarding.page');
        $this->mailPartner(
            $partner,
            "Hồ sơ hợp tác 365 Home: giấy tờ {$what}",
            "<p>Giấy tờ <strong>{$docName}</strong> trong hồ sơ hợp tác của bạn {$what}.</p><p>Lý do: <strong>" . e((string) $note) . "</strong></p>"
            . "<p>Vui lòng mở <a href=\"{$url}\">{$url}</a> (chọn “Đã đăng ký trước đó? Lấy lại hồ sơ” nếu chưa mở được hồ sơ), chỉnh sửa giấy tờ rồi gửi duyệt lại.</p>"
        );
    }

    /** Đối tác rút hồ sơ đã gửi (admin chưa duyệt) về trạng thái nháp để chỉnh sửa rồi gửi lại. */
    public function withdraw(Partner $partner): Partner
    {
        if ($partner->verification_status !== 'pending' || $partner->verification_submitted_at === null) {
            throw ValidationException::withMessages(['status' => 'Hồ sơ không ở trạng thái chờ duyệt nên không thể rút lại.']);
        }
        if ($this->latestContract($partner) !== null) {
            throw ValidationException::withMessages(['status' => 'Hồ sơ đã được duyệt giấy tờ và gửi hợp đồng, không thể rút lại.']);
        }

        DB::transaction(function () use ($partner) {
            // Giấy tờ đang chờ duyệt quay về nháp để sửa/xoá và nộp lại; giấy tờ đã duyệt giữ nguyên.
            $partner->legalDocuments()->where('status', 'pending_review')->update(['status' => 'draft', 'submitted_at' => null]);
            $partner->update(['verification_submitted_at' => null]);
            $this->log($partner, 'Đối tác rút hồ sơ chờ duyệt để chỉnh sửa.');
        });

        $this->notifyAdmins($partner, 'Đối tác rút hồ sơ để chỉnh sửa', $this->partnerLabel($partner) . ' đã rút hồ sơ chờ duyệt về bản nháp để sửa; hồ sơ sẽ được gửi duyệt lại sau.', 'partner_onboarding_withdrawn', 'warning', 'heroicon-o-arrow-uturn-left');

        return $partner->fresh();
    }

    /**
     * Hồ sơ đăng ký hợp tác được admin DUYỆT → tự tạo tài khoản chủ đối tác (không cần tạo tay) và gửi thông tin đăng nhập qua email.
     * Homestay: vai trò "partner" (panel /admin). MiniHouse: vai trò "Quản lý MiniHouse" (panel /minihouse/admin).
     * Bỏ qua nếu đối tác đã có tài khoản; email đã thuộc tài khoản khác → không tạo, ghi chú để admin xử lý.
     *
     * @return array{created: bool, email: ?string, mail_sent: bool, reason: ?string}
     */
    public function provisionAccount(Partner $partner): array
    {
        if ($partner->users()->exists()) {
            return ['created' => false, 'email' => null, 'mail_sent' => false, 'reason' => 'Đối tác đã có tài khoản.'];
        }

        $email = $partner->email;
        if (blank($email) || User::query()->where('email', $email)->exists()) {
            $reason = blank($email) ? 'Hồ sơ không có email.' : "Email {$email} đã thuộc một tài khoản khác.";
            $this->log($partner, "Không tự tạo được tài khoản đối tác: {$reason} Vui lòng tạo tài khoản thủ công.");
            $this->notifyAdmins($partner, 'Không tự tạo được tài khoản đối tác', $this->partnerLabel($partner) . ": {$reason} Hãy xử lý rồi bấm “Gửi lại tài khoản đăng nhập” ở trang đối tác.", 'partner_account_failed', 'danger', 'heroicon-o-exclamation-triangle');

            return ['created' => false, 'email' => $email, 'mail_sent' => false, 'reason' => $reason];
        }

        $roleName = $partner->isMinihouse() ? 'Quản lý MiniHouse' : 'partner';
        $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
        if (! $role) {
            $reason = "Chưa có vai trò \"{$roleName}\".";
            $this->log($partner, "Không tự tạo được tài khoản đối tác: {$reason}");
            $this->notifyAdmins($partner, 'Không tự tạo được tài khoản đối tác', $this->partnerLabel($partner) . ": {$reason} Hãy xử lý rồi bấm “Gửi lại tài khoản đăng nhập” ở trang đối tác.", 'partner_account_failed', 'danger', 'heroicon-o-exclamation-triangle');

            return ['created' => false, 'email' => $email, 'mail_sent' => false, 'reason' => $reason];
        }

        $password = Str::password(12, true, true, false);

        // SĐT người dùng là UNIQUE: đã thuộc tài khoản khác (vd đối tác khác dùng chung SĐT) thì tạo tài khoản KHÔNG kèm SĐT thay vì lỗi 500.
        $phone = filled($partner->phone) && ! User::query()->where('phone', $partner->phone)->exists() ? $partner->phone : null;
        if ($phone === null && filled($partner->phone)) {
            $this->log($partner, "SĐT {$partner->phone} đã thuộc tài khoản khác nên tài khoản đối tác được tạo không kèm SĐT.");
        }

        DB::transaction(function () use ($partner, $email, $password, $role, $phone) {
            $user = User::create([
                'fullname'   => $partner->representative_name ?: ($partner->legal_name ?: $partner->name),
                'email'      => $email,
                'phone'      => $phone,
                'password'   => $password,
                'partner_id' => $partner->id,
            ]);
            $user->roles()->syncWithoutDetaching([$role->id]);
        });

        $mailSent = $this->sendWelcomeMail($partner, $email, $password);

        $this->log($partner, "Đã tự tạo tài khoản đối tác {$email}" . ($mailSent ? ' và gửi email xác nhận hợp tác kèm thông tin đăng nhập.' : '. Gửi email thất bại — dùng nút "Gửi lại tài khoản đăng nhập" ở trang đối tác sau khi kiểm tra cấu hình mail.'));
        $this->toastAdmin($mailSent, $partner, $email);

        return ['created' => true, 'email' => $email, 'mail_sent' => $mailSent, 'reason' => null];
    }

    /** Gửi lại thông tin đăng nhập: chưa có tài khoản thì tạo; đã có thì đặt mật khẩu mới cho tài khoản chủ đối tác rồi gửi email. */
    public function resendCredentials(Partner $partner): array
    {
        $user = $partner->users()->orderBy('created_at')->first();
        if (! $user) {
            return $this->provisionAccount($partner);
        }

        $password = Str::password(12, true, true, false);
        $user->forceFill(['password' => $password])->save();
        $mailSent = $this->sendWelcomeMail($partner, $user->email, $password);
        $this->log($partner, "Admin gửi lại thông tin đăng nhập cho {$user->email}" . ($mailSent ? '.' : ' — gửi email thất bại.'));
        $this->toastAdmin($mailSent, $partner, $user->email);

        return ['created' => false, 'email' => $user->email, 'mail_sent' => $mailSent, 'reason' => null];
    }

    /** Email "Xác nhận hợp tác" kèm tài khoản đăng nhập của ĐÚNG đối tác đang đăng ký. */
    private function sendWelcomeMail(Partner $partner, string $email, string $password): bool
    {
        $loginUrl = $this->loginUrl($partner);
        $name = e($partner->legal_name ?: $partner->name);
        $next = $partner->isMinihouse()
            ? 'Sau khi đăng nhập, bạn tạo toà nhà, phòng và bổ sung giấy tờ cấp toà nhà (PCCC, an ninh trật tự, quyền khai thác) để bắt đầu vận hành.'
            : 'Sau khi đăng nhập, bạn tạo chi nhánh, phòng và bảng giá để bắt đầu nhận khách.';

        try {
            Mail::to($email)->send(new LockNotificationMail(
                'Xác nhận hợp tác với 365 Home — tài khoản đăng nhập của bạn',
                "<p>Xin chào <strong>{$name}</strong>,</p>"
                . '<p>365 Home xác nhận đã nhận hợp đồng hợp tác đã ký và hồ sơ của bạn đã được duyệt. Chào mừng bạn trở thành đối tác của 365 Home.</p>'
                . "<p>Thông tin đăng nhập trang quản trị:<br>Địa chỉ: <a href=\"{$loginUrl}\">{$loginUrl}</a><br>Email đăng nhập: <strong>" . e($email) . '</strong><br>Mật khẩu: <strong>' . e($password) . '</strong></p>'
                . "<p>{$next}</p><p>Vui lòng đổi mật khẩu sau khi đăng nhập lần đầu.</p>"
            ));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    // Báo ngay cho admin đang thao tác (ký hợp đồng / gửi lại) kết quả tạo tài khoản + gửi mail, tránh im lặng khi lỗi.
    private function toastAdmin(bool $mailSent, Partner $partner, string $email): void
    {
        if (! auth()->check()) {
            return;
        }

        try {
            $n = \Filament\Notifications\Notification::make()
                ->title($mailSent ? 'Đã gửi email xác nhận hợp tác kèm tài khoản' : 'Đã tạo tài khoản nhưng GỬI EMAIL THẤT BẠI')
                ->body(($partner->legal_name ?: $partner->name) . " — {$email}" . ($mailSent ? '' : '. Kiểm tra cấu hình mail rồi bấm "Gửi lại tài khoản đăng nhập" ở trang đối tác.'));
            ($mailSent ? $n->success() : $n->danger()->persistent())->send();
        } catch (\Throwable) {
            // thông báo chỉ để tiện theo dõi — không được làm hỏng luồng chính
        }
    }

    public function loginUrl(Partner $partner): string
    {
        try {
            return Filament::getPanel($partner->isMinihouse() ? 'minihouse-admin' : 'admin')->getLoginUrl();
        } catch (\Throwable) {
            return url($partner->isMinihouse() ? '/minihouse/admin/login' : '/admin/login');
        }
    }

    private function log(Partner $partner, string $note): void
    {
        PartnerStatusLog::create([
            'partner_id'  => $partner->id,
            'from_status' => $partner->verification_status,
            'to_status'   => $partner->verification_status,
            'note'        => $note,
            'changed_by'  => auth()->id(),
        ]);
    }

    public function status(Partner $partner): array
    {
        $documents = $partner->legalDocuments()->with('media')->latest()->get();
        $contract = $this->latestContract($partner);
        $missing = $this->missingContractFields($partner);

        $approved = $partner->verification_status === 'approved';
        $signingActive = $contract && $contract->signing_token !== null && ! $contract->isPartnerConfirmed();
        $hasChanges = $documents->contains(fn ($d) => in_array($d->status, ['changes_requested', 'rejected'], true));

        $stage = match (true) {
            $partner->contract_status === 'active' => 'active',
            (bool) $contract?->isPartnerConfirmed() => 'contract_signed',
            $approved && $signingActive => 'contract_sent',
            $approved => 'approved',
            $partner->verification_status === 'rejected' => 'rejected',
            $hasChanges => 'changes_requested',
            $partner->verification_submitted_at !== null => 'pending_review',
            $missing === [] && $this->hasBusinessLicense($partner) => 'ready_to_submit',
            $documents->isNotEmpty() => 'documents_uploaded',
            default => 'registered',
        };

        return [
            'stage'        => $stage,
            'partner_type' => $partner->partner_type,
            'partner'      => collect(['name', 'phone', 'partner_type', ...self::CONTRACT_FIELDS])
                ->mapWithKeys(fn ($f) => [$f => $partner->{$f} instanceof \DateTimeInterface ? $partner->{$f}->format('Y-m-d') : $partner->{$f}])->all(),
            'steps' => [
                'registered'          => true,
                'documents_uploaded'  => $this->hasBusinessLicense($partner),
                'contract_info_ready' => $missing === [],
                'submitted'           => $partner->verification_submitted_at !== null,
                'documents_approved'  => $approved,
                'contract_sent'       => $contract !== null,
                'contract_signed'     => (bool) $contract?->isPartnerConfirmed(),
                'active'              => $partner->contract_status === 'active',
            ],
            'missing_contract_fields' => $missing,
            'documents'    => $documents->map(fn (PartnerLegalDocument $d) => $this->formatDocument($d))->values()->all(),
            'contract'     => $contract ? [
                'version_id'           => $contract->id,
                'content_hash'         => $contract->content_hash,
                'signing_active'       => $signingActive,
                'signing_token'        => $signingActive ? $contract->signing_token : null,
                'signing_url'          => $signingActive ? route('contract.sign.show', $contract->signing_token) : null,
                'partner_confirmed_at' => $contract->partner_confirmed_at?->toIso8601String(),
                'partner_signed_by'    => $contract->partner_signed_by_name,
                'platform_signed_at'   => $contract->platform_signed_at?->toIso8601String(),
            ] : null,
            'account' => [
                'created'   => $partner->users()->exists(),
                'login_url' => $partner->contract_status === 'active' ? $this->loginUrl($partner) : null,
            ],
            'verification' => [
                'status'       => $partner->verification_status,
                'submitted_at' => $partner->verification_submitted_at?->toIso8601String(),
                'verified_at'  => $partner->verified_at?->toIso8601String(),
                'note'         => $partner->verification_note,
            ],
        ];
    }

    public function formatDocument(PartnerLegalDocument $document): array
    {
        $media = $document->getFirstMedia('file');

        return [
            'id'              => $document->id,
            'type'            => $document->type,
            'type_label'      => PartnerLegalDocument::TYPES[$document->type] ?? $document->type,
            'name'            => $document->name,
            'document_number' => $document->document_number,
            'issuer'          => $document->issuer,
            'issued_at'       => $document->issued_at?->toDateString(),
            'expires_at'      => $document->expires_at?->toDateString(),
            'status'          => $document->status,
            'status_label'    => PartnerLegalDocument::STATUSES[$document->status] ?? $document->status,
            'review_note'     => $document->review_note,
            'file_name'       => $media?->file_name,
            'file_size'       => $media?->size,
        ];
    }

    public function latestContract(Partner $partner): ?PartnerContractVersion
    {
        return $partner->contractVersions()->first();
    }

    private function missingContractFields(Partner $partner): array
    {
        return array_values(array_filter(self::CONTRACT_REQUIRED, fn ($f) => blank($partner->{$f})));
    }

    private function hasBusinessLicense(Partner $partner): bool
    {
        return $partner->legalDocuments()->where('type', 'business_license')->whereNull('building_id')
            ->whereNotIn('status', ['rejected'])->get()->contains(fn (PartnerLegalDocument $d) => $d->hasMedia('file'));
    }

    // Chỉ sửa hồ sơ khi đang hoàn thiện, hoặc khi admin yêu cầu bổ sung (có giấy tờ cần bổ sung/bị từ chối).
    private function assertEditable(Partner $partner): void
    {
        if ($partner->verification_status === 'approved') {
            throw ValidationException::withMessages(['status' => 'Hồ sơ đã được duyệt. Vui lòng đăng nhập trang quản trị để cập nhật.']);
        }
        if ($partner->verification_status === 'rejected') {
            throw ValidationException::withMessages(['status' => 'Hồ sơ đã bị từ chối. Vui lòng liên hệ 365 Home.']);
        }
        if ($partner->verification_submitted_at !== null) {
            throw ValidationException::withMessages(['status' => 'Hồ sơ đang chờ duyệt, chưa thể chỉnh sửa.']);
        }
    }
}
