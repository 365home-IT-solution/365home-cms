<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\LockNotificationMail;
use App\Models\Partner;
use App\Models\PartnerContractVersion;
use App\Models\PartnerLegalDocument;
use App\Models\PartnerStatusLog;
use App\Models\SubscriptionPlan;
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
        'legal_name', 'tax_code', 'address', 'email', 'representative_name', 'representative_position', 'representative_id_number', 'representative_id_issued_at', 'representative_id_issued_place',
        'representative_dob', 'business_license_date', 'business_license_issuer',
    ];

    // Ngân hàng KHÔNG còn là bước đăng ký: đối tác tự thiết lập sau khi đăng nhập (ngân hàng CHỌN từ danh sách config/banks.php, không nhập tay).
    public const BANK_FIELDS = ['bank_name', 'bank_branch', 'bank_account_number', 'bank_account_holder'];


    // Bắt buộc có trước khi tạo hợp đồng.
    public const CONTRACT_REQUIRED = ['legal_name', 'address', 'email', 'representative_name', 'representative_id_number', 'representative_id_issued_at'];

    public const LABELS = [
        'partner_type' => 'loại hình hợp tác', 'full_name' => 'họ tên người đăng ký', 'phone' => 'số điện thoại', 'email' => 'email',
        'business_name' => 'tên cơ sở kinh doanh', 'address' => 'địa chỉ', 'note' => 'ghi chú',
        'address_street' => 'số nhà, tên đường/phố', 'address_province_code' => 'tỉnh/thành phố', 'address_ward_code' => 'phường/xã',
        'address_unit' => 'căn hộ/tầng', 'address_building' => 'tên toà nhà', 'postal_code' => 'mã bưu điện',
        'legal_name' => 'tên pháp lý', 'tax_code' => 'mã số thuế', 'representative_name' => 'họ tên người đại diện',
        'representative_id_number' => 'số CMND/CCCD người đại diện', 'representative_position' => 'chức vụ người đại diện',
        'representative_id_issued_at' => 'ngày cấp CMND/CCCD', 'representative_id_issued_place' => 'nơi cấp CMND/CCCD', 'representative_dob' => 'ngày sinh người đại diện',
        'business_license_date' => 'ngày cấp giấy phép kinh doanh', 'business_license_issuer' => 'nơi cấp giấy phép kinh doanh',
        'bank_name' => 'ngân hàng', 'bank_code' => 'ngân hàng', 'bank_branch' => 'chi nhánh ngân hàng', 'bank_account_number' => 'số tài khoản',
        'bank_account_holder' => 'chủ tài khoản', 'type' => 'loại giấy tờ', 'name' => 'tên giấy tờ', 'document_number' => 'số giấy tờ',
        'issuer' => 'nơi cấp', 'issued_at' => 'ngày cấp', 'expires_at' => 'ngày hết hạn', 'file' => 'tệp giấy tờ',
    ];

    public function __construct(private readonly PartnerLegalDocumentService $documents)
    {
    }

    /** Email đã thuộc tài khoản đăng nhập còn dùng? Tài khoản "mồ côi" của đối tác đã xoá không tính (sẽ được giải phóng khi cấp tài khoản mới). */
    public function emailTaken(?string $email, ?string $exceptPartnerId = null): bool
    {
        if (blank($email)) {
            return false;
        }
        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            return false;
        }
        if ($exceptPartnerId && $user->partner_id === $exceptPartnerId) {
            return false;
        }

        return ! ($user->partner_id && Partner::onlyTrashed()->whereKey($user->partner_id)->exists());
    }

    private function assertEmailFree(?string $email, ?string $exceptPartnerId = null): void
    {
        if ($this->emailTaken($email, $exceptPartnerId)) {
            throw ValidationException::withMessages(['email' => 'Email này đã được dùng cho một tài khoản khác. Vui lòng dùng email khác.']);
        }
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function findByToken(string $token): Partner
    {
        return Partner::query()->where('onboarding_token', self::hashToken($token))->firstOrFail();
    }

    /**
     * Ghép địa chỉ đầy đủ từ các phần: "Căn hộ, Toà nhà, Số nhà đường, Phường/Xã, Tỉnh/TP". Không có phần có cấu trúc thì giữ nguyên `address`.
     * Phường/xã phải thuộc đúng tỉnh/thành đã chọn.
     */
    public function composeAddress(array $data): string
    {
        if (blank($data['address_street'] ?? null)) {
            return (string) ($data['address'] ?? '');
        }

        $province = \App\Models\Province::query()->where('code', $data['address_province_code'])->first();
        $ward = \App\Models\Ward::query()->where('code', $data['address_ward_code'])->first();
        if (! $province || ! $ward || (int) $ward->province_code !== (int) $province->code) {
            throw ValidationException::withMessages(['address_ward_code' => 'Phường/xã không thuộc tỉnh/thành phố đã chọn.']);
        }

        return collect([$data['address_unit'] ?? null, $data['address_building'] ?? null, $data['address_street'], $ward->name, $province->name])
            ->map(fn ($part) => trim((string) $part))
            ->filter()
            ->implode(', ');
    }

    /**
     * MiniHouse MUA GÓI: tạo hồ sơ đối tác CHƯA kích hoạt (chờ thanh toán) hoặc dùng lại hồ sơ chưa thanh toán cùng SĐT.
     * Không có bước duyệt giấy tờ/ký hợp đồng; không tặng dùng thử — gói bắt đầu khi thanh toán xong (xem provisionPurchasedAccount).
     *
     * @return array{partner: Partner, token: string}
     */
    public function createPurchasePartner(array $data): array
    {
        $phone = preg_replace('/^\+84/', '0', $data['phone']);

        $existing = Partner::query()->where('partner_type', Partner::TYPE_MINIHOUSE)->where('phone', $phone)->first();
        if ($existing && ($existing->status || $existing->users()->exists())) {
            throw ValidationException::withMessages(['phone' => 'Số điện thoại này đã có tài khoản MiniHouse. Vui lòng đăng nhập trang quản trị để gia hạn gói.']);
        }
        $this->assertEmailFree($data['email'], $existing?->id);

        $token = Str::random(48);
        $attributes = [
            'name'                => $data['business_name'],
            'legal_name'          => $data['business_name'],
            'representative_name' => $data['full_name'],
            'phone'               => $phone,
            'email'               => $data['email'],
            'address'             => $data['address'],
            'onboarding_token'    => self::hashToken($token),
        ];

        // Đủ điều kiện tặng dùng thử → hồ sơ CHỜ SUPER ADMIN DUYỆT (duyệt xong mới tạo tài khoản + gửi mật khẩu); ngược lại phải thanh toán mới được dùng.
        $needsApproval = $this->signupTrialEligible($phone, $data['email'], $existing?->id);

        $partner = $existing
            ? tap($existing)->update($attributes + ($existing->verification_status === 'rejected' ? [] : ['verification_status' => $needsApproval ? 'pending' : 'approved']))
            : Partner::create($attributes + [
                'partner_type'        => Partner::TYPE_MINIHOUSE,
                'status'              => false,
                'verification_status' => $needsApproval ? 'pending' : 'approved',
                'verified_at'         => $needsApproval ? null : now(),
                'contract_status'     => 'draft',
            ]);

        if (! $existing) {
            PartnerStatusLog::create(['partner_id' => $partner->id, 'to_status' => $needsApproval ? 'pending' : 'approved', 'note' => $needsApproval
                ? 'MiniHouse đăng ký trên website — chờ Super Admin duyệt để tặng dùng thử (hoặc thanh toán gói để kích hoạt ngay).'
                : 'MiniHouse mua gói trên website (chờ thanh toán).']);
            if ($needsApproval) {
                $this->notifyAdmins($partner, 'Có đăng ký MiniHouse chờ duyệt', $this->partnerLabel($partner) . ' vừa đăng ký MiniHouse — duyệt để tặng dùng thử và gửi tài khoản đăng nhập.', 'minihouse_signup_pending', 'info', 'heroicon-o-user-plus');
                $url = route('partner-onboarding.page') . '?mh=' . $token;
                $this->mailPartner($partner, 'Đã nhận đăng ký MiniHouse — chờ 365 Home duyệt', '<p>Xin chào <strong>' . e($partner->representative_name ?: $partner->name) . '</strong>,</p><p>365 Home đã nhận đăng ký MiniHouse của <strong>' . e($partner->name) . '</strong>. '
                    . (config('partner_flow.minihouse_trial_documents_required') && ! config('partner_flow.minihouse_contract_enabled')
                        ? 'Để được duyệt dùng thử, vui lòng nộp đủ giấy tờ pháp lý (' . e(self::requiredDocumentLabels(Partner::TYPE_MINIHOUSE)) . ") rồi gửi duyệt tại: <a href=\"{$url}\">{$url}</a></p><p>"
                        : '')
                    . 'Sau khi được duyệt, tài khoản đăng nhập và mật khẩu sẽ được gửi về email này.</p>');
            }
        }

        return ['partner' => $partner->fresh(), 'token' => $token];
    }

    /** Có tặng dùng thử cho SĐT/email này không: bật tính năng, và chưa từng có đối tác MiniHouse (kể cả đã xoá) dùng gói với cùng SĐT/email. */
    public function signupTrialEligible(?string $phone, ?string $email, ?string $exceptPartnerId = null): bool
    {
        if ((int) config('partner_flow.minihouse_signup_trial_months', 0) < 1) {
            return false;
        }
        $match = fn ($q) => $q->where(fn ($w) => $w->where('phone', $phone)->orWhere('email', $email));

        return ! Partner::onlyTrashed()->where('partner_type', Partner::TYPE_MINIHOUSE)->where($match)->exists()
            && ! Partner::query()->where('partner_type', Partner::TYPE_MINIHOUSE)->when($exceptPartnerId, fn ($q) => $q->whereKeyNot($exceptPartnerId))
                ->where($match)->whereHas('subscription')->exists();
    }

    /** Đối tác MiniHouse đang chờ Super Admin duyệt đăng ký (chưa có tài khoản, chưa thanh toán). */
    public function awaitingSignupApproval(Partner $partner): bool
    {
        return $partner->isMinihouse() && filled($partner->onboarding_token) && $partner->verification_status === 'pending' && ! $partner->users()->exists();
    }

    /**
     * Super Admin DUYỆT đăng ký MiniHouse → tặng dùng thử (mặc định 1 tháng), kích hoạt đối tác, tạo tài khoản và gửi email đăng nhập.
     * Gói lấy theo đơn khách đã chọn; khi khách thanh toán sau đó, hạn gói được cộng NỐI TIẾP sau ngày hết hạn dùng thử.
     *
     * @return array{created: bool, email: ?string, mail_sent: bool, reason: ?string, trial_expires_at: ?string}
     */
    public function approveSignup(Partner $partner, ?User $admin = null): array
    {
        if (! $this->awaitingSignupApproval($partner)) {
            throw ValidationException::withMessages(['partner' => 'Đăng ký này không ở trạng thái chờ duyệt.']);
        }
        // Đăng ký dùng thử có bước giấy tờ: chỉ duyệt khi đủ giấy tờ bắt buộc đã được duyệt và còn hạn.
        if ($partner->minihouseDocumentsFlow()) {
            $readiness = $this->documents->readiness($partner);
            if (! $readiness['ready']) {
                throw ValidationException::withMessages(['legal_documents' => $readiness['problems']]);
            }
        }
        $months = (int) config('partner_flow.minihouse_signup_trial_months', 0);
        if ($months < 1) {
            throw ValidationException::withMessages(['partner' => 'Tính năng tặng dùng thử đang tắt — đối tác cần thanh toán gói để được kích hoạt.']);
        }

        $plan = ($partner->signup_plan_id ? SubscriptionPlan::query()->find($partner->signup_plan_id) : null)
            ?? \App\Models\SubscriptionPayment::query()->where('partner_id', $partner->id)->latest('id')->first()?->plan
            ?? SubscriptionPlan::query()->where('is_active', true)->forPartnerType(Partner::TYPE_MINIHOUSE)->orderBy('sort_order')->orderBy('id')->first();
        if (! $plan) {
            throw ValidationException::withMessages(['partner' => 'Chưa có gói MiniHouse đang bán để gán dùng thử.']);
        }

        $expires = now()->addMonthsNoOverflow($months);
        app(SubscriptionService::class)->assignPlan($partner, $plan, $expires, true);
        $partner->update(['status' => true, 'verification_status' => 'approved', 'verified_at' => now()]);
        $this->log($partner, "Super Admin" . ($admin ? " {$admin->fullname}" : '') . " duyệt đăng ký MiniHouse — tặng dùng thử {$months} tháng đến " . $expires->format('d/m/Y') . '.');
        $result = $this->provisionAccount($partner->fresh());

        return $result + ['trial_expires_at' => $expires->toIso8601String()];
    }

    /** Super Admin TỪ CHỐI đăng ký MiniHouse: khoá hồ sơ, gửi email lý do (đối tác vẫn có thể thanh toán gói bằng đăng ký mới). */
    public function rejectSignup(Partner $partner, string $reason, ?User $admin = null): void
    {
        if (! $this->awaitingSignupApproval($partner)) {
            throw ValidationException::withMessages(['partner' => 'Đăng ký này không ở trạng thái chờ duyệt.']);
        }
        $partner->update(['verification_status' => 'rejected', 'status' => false]);
        $this->log($partner, 'Super Admin từ chối đăng ký MiniHouse. Lý do: ' . $reason);
        $this->mailPartner($partner, 'Đăng ký MiniHouse chưa được chấp nhận', '<p>Xin chào <strong>' . e($partner->representative_name ?: $partner->name) . '</strong>,</p><p>Đăng ký MiniHouse của bạn chưa được 365 Home chấp nhận.</p><p>Lý do: ' . e($reason) . '</p>');
    }

    /** Thanh toán gói xong → kích hoạt đối tác, tạo tài khoản quản lý MiniHouse và gửi email đăng nhập. */
    public function provisionPurchasedAccount(Partner $partner): array
    {
        $partner->update(['status' => true, 'verification_status' => 'approved', 'verified_at' => $partner->verified_at ?? now()]);
        $this->log($partner, 'MiniHouse đã thanh toán gói — kích hoạt đối tác.');

        return $this->provisionAccount($partner);
    }

    /** @return array{partner: Partner, token: string} */
    public function register(array $data): array
    {
        $phone = preg_replace('/^\+84/', '0', $data['phone']);

        if (Partner::query()->where('partner_type', $data['partner_type'])->where('phone', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => 'Số điện thoại này đã đăng ký hợp tác. Vui lòng dùng mã hồ sơ đã nhận hoặc liên hệ 365 Home.']);
        }

        $this->assertEmailFree($data['email']);

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

        if ($partner->isMinihouse() && ! $partner->minihouseDocumentsFlow() && in_array($data['type'], self::BUILDING_TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Giấy tờ PCCC, ANTT và quyền khai thác toà nhà được bổ sung sau khi hồ sơ được duyệt và tạo toà nhà.']);
        }

        // CCCD: BẮT BUỘC đọc được mã QR trên ảnh; số, họ tên, ngày sinh, giới tính, thường trú, ngày cấp lấy TỪ QR (bỏ qua giá trị client gửi).
        if ($data['type'] === 'citizen_id') {
            $data = array_merge($data, app(LegalDocumentScanService::class)->citizenIdValues($file));
        }

        return DB::transaction(function () use ($partner, $data, $file) {
            $document = $partner->legalDocuments()->create([
                'type'            => $data['type'],
                'name'            => $data['name'] ?? null,
                'document_number' => $data['document_number'] ?? null,
                'issuer'          => $data['issuer'] ?? null,
                'issued_at'       => $data['issued_at'] ?? null,
                'expires_at'      => $data['expires_at'] ?? null,
                // Cột riêng của đúng loại giấy tờ (dkkd_* / antt_* / pccc_*); 3 cột chung ở trên tự đồng bộ khi lưu.
                ...\App\Support\LegalDocumentFields::valuesFrom($data['type'], $data),
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

    /**
     * Tài khoản ngân hàng của ĐỐI TÁC (cùng các cột partners.bank_* mà Super Admin thấy ở tab Tài chính — một nguồn duy nhất, tự đồng bộ).
     * Đối tác tự thiết lập sau khi đăng nhập (không còn là bước đăng ký): ngân hàng chọn từ danh sách → lưu tên chuẩn.
     */
    public function updateBankAccount(Partner $partner, array $data): Partner
    {
        $bank = \App\Support\Banks::find($data['bank_code'] ?? null);
        if (! $bank) {
            throw ValidationException::withMessages(['bank_code' => 'Vui lòng chọn ngân hàng trong danh sách.']);
        }

        $partner->update([
            'bank_name'           => $bank['short_name'],
            'bank_branch'         => $data['bank_branch'] ?? null,
            'bank_account_number' => $data['bank_account_number'],
            'bank_account_holder' => mb_strtoupper(trim((string) $data['bank_account_holder'])),
        ]);

        return $partner->fresh();
    }

    /** Ghi lịch sử hồ sơ khi tài khoản ngân hàng đổi (đối tác hoặc Super Admin) để đối soát. */
    public function logBankUpdate(Partner $partner, ?User $actor): void
    {
        $this->log($partner, 'Cập nhật tài khoản ngân hàng' . ($actor ? " bởi {$actor->fullname}" : '') . ': ' . ($partner->bank_name ?: '—') . ' · ' . ($partner->bank_account_number ?: '—') . ' · ' . ($partner->bank_account_holder ?: '—') . '.');
    }

    public function updateContractInfo(Partner $partner, array $data): Partner
    {
        $this->assertEditable($partner);
        $this->assertEmailFree($data['email'] ?? null, $partner->id);
        $partner->update(collect($data)->only(self::CONTRACT_FIELDS)->all());

        // Đối tác ký trước: thông tin in trong hợp đồng vừa đổi mà đã có bản hợp đồng (đang chờ ký/đã ký) → bản đó không còn khớp,
        // tạo bản mới để đối tác ký lại (link và chữ ký của bản cũ hết hiệu lực vì chỉ phiên bản mới nhất được dùng).
        if ($partner->signsBeforeReview() && $partner->wasChanged(self::CONTRACT_FIELDS)
            && ($latest = $this->latestContract($partner)) && ! $latest->isPlatformSigned()
            && $this->missingContractFields($partner) === [] && $this->missingRequiredDocuments($partner) === []) {
            $this->createPreApprovalContract($partner, 'Tạo lại vì đối tác sửa thông tin ký hợp đồng');
        }

        return $partner->fresh();
    }

    /**
     * LUỒNG "ĐỐI TÁC KÝ TRƯỚC" (Partner::signsBeforeReview): đủ giấy tờ bắt buộc + thông tin hợp đồng → tạo hợp đồng ĐIỀU KHOẢN CHUẨN
     * (hoa hồng mặc định, thời hạn mặc định config contract.default_term_months) để đối tác ký bằng OTP ngay trên trang đăng ký.
     * Đã có bản đang chờ ký hoặc đã ký thì giữ nguyên. Ký xong hồ sơ tự gửi duyệt (notifyPartnerSigned).
     */
    public function prepareContract(Partner $partner): Partner
    {
        if (! $partner->signsBeforeReview()) {
            throw ValidationException::withMessages(['contract' => 'Hợp đồng sẽ được gửi sau khi 365 Home duyệt hồ sơ.']);
        }
        $this->assertEditable($partner);
        $missingDocs = $this->missingRequiredDocuments($partner);
        if ($missingDocs !== []) {
            throw ValidationException::withMessages(['documents' => 'Phải nộp đủ giấy tờ bắt buộc (có tệp). Còn thiếu: ' . implode(', ', array_map(fn ($t) => PartnerLegalDocument::TYPES[$t] ?? $t, $missingDocs)) . '.']);
        }
        $missing = $this->missingContractFields($partner);
        if ($missing !== []) {
            throw ValidationException::withMessages(['contract_info' => 'Thiếu thông tin ký hợp đồng: ' . implode(', ', array_map(fn ($f) => self::LABELS[$f] ?? $f, $missing)) . '.']);
        }

        $latest = $this->latestContract($partner);
        if (! $latest || (! $latest->isPartnerConfirmed() && $latest->signing_token === null)) {
            $this->createPreApprovalContract($partner, 'Tự động tạo để đối tác ký trước khi 365 Home duyệt hồ sơ (đăng ký hợp tác trên website)');
        }

        return $partner->fresh();
    }

    private function createPreApprovalContract(Partner $partner, string $note): PartnerContractVersion
    {
        // Điều 7 in SỐ THÁNG kể từ ngày hợp đồng có hiệu lực (suy từ ngày hết hạn tại lúc tạo bản). Bản đầu: thời hạn mặc định. Bản tạo lại:
        // giữ đúng số tháng của bản trước (kể cả khi admin đã chỉnh riêng) — đặt lại ngày hết hạn tính từ hôm nay để số tháng in ra không bị hụt.
        // Ngày hết hạn chính thức được tính lại lúc 365 Home ký. Hoa hồng mặc định đã gán lúc tạo đối tác.
        if (blank($partner->contract_signed_at)) {
            $latest = $this->latestContract($partner);
            $months = $latest && $latest->created_at && $partner->contract_expires_at
                ? PartnerContractRenderer::termMonths($partner->contract_expires_at, $latest->created_at)
                : max(1, (int) config('contract.default_term_months', 12));
            $partner->update(['contract_expires_at' => today()->addMonths($months)]);
        }
        $version = DB::transaction(fn () => app(PartnerContractWorkflowService::class)->createVersion(
            $partner, null, 'Hợp đồng đăng ký hợp tác — ' . now()->format('d/m/Y H:i'), $note, preApproval: true
        ));
        $this->log($partner, 'Đã tạo hợp đồng điều khoản chuẩn để đối tác ký trước khi duyệt hồ sơ.');

        return $version;
    }

    /**
     * Admin đã DUYỆT giấy tờ → tự tạo hợp đồng từ thông tin đối tác đã khai, lưu phiên bản và gửi link ký (OTP email) cho đối tác.
     * Gọi từ PartnerLegalDocumentService::approveDossier. Đã có hợp đồng đang chờ ký/đã ký thì bỏ qua.
     *
     * @return array{version: ?PartnerContractVersion, mail_sent: bool, signing_url: ?string}
     */
    public function sendContractAfterApproval(Partner $partner): array
    {
        // MiniHouse chỉ mua gói — không tạo/gửi hợp đồng (trừ khi bật lại MINIHOUSE_CONTRACT_ENABLED).
        if (! $partner->usesContract()) {
            return ['version' => null, 'mail_sent' => false, 'signing_url' => null];
        }

        $latest = $this->latestContract($partner);
        // Đối tác ĐÃ KÝ TRƯỚC (luồng ký trước): duyệt xong chỉ còn bước 365 Home ký phía nền tảng.
        if ($latest && $latest->isPartnerConfirmed() && ! $latest->isPlatformSigned()) {
            $this->notifyAdmins($partner, 'Đã duyệt hồ sơ — chờ ký phía nền tảng', $this->partnerLabel($partner) . ' đã ký hợp đồng từ trước. Vào Đối tác → tab Hợp đồng để ký phía nền tảng; sau đó hệ thống tự cấp tài khoản.', 'partner_contract_signed', 'info', 'heroicon-o-pencil-square');
            $this->mailPartner($partner, 'Hồ sơ hợp tác đã được duyệt', '<p>Giấy tờ pháp lý của bạn đã được 365 Home duyệt. Bước cuối: 365 Home ký xác nhận hợp đồng phía nền tảng, sau đó tài khoản quản trị sẽ được gửi về email này.</p>');

            return ['version' => $latest, 'mail_sent' => false, 'signing_url' => null];
        }
        if ($latest && ($latest->isPartnerConfirmed() || $latest->signing_token !== null)) {
            return ['version' => $latest, 'mail_sent' => false, 'signing_url' => null];
        }
        if (blank($partner->email)) {
            $this->log($partner, 'Không gửi được hợp đồng cho đối tác: hồ sơ không có email.');

            return ['version' => null, 'mail_sent' => false, 'signing_url' => null];
        }

        // Thiếu mã hợp đồng / hoa hồng / ngày hết hạn → KHÔNG tự gửi hợp đồng trống; báo admin nhập rồi bấm "Tạo & gửi hợp đồng ký".
        $workflow = app(PartnerContractWorkflowService::class);
        $missing = $workflow->missingTerms($partner);
        if ($missing !== []) {
            $list = implode(' ', array_values($missing));
            $this->log($partner, "Đã duyệt giấy tờ nhưng chưa gửi hợp đồng: {$list}");
            $this->notifyAdmins($partner, 'Đã duyệt giấy tờ — chưa gửi được hợp đồng', $this->partnerLabel($partner) . ": {$list} Nhập ở tab Hợp đồng rồi bấm “Tạo & gửi hợp đồng ký”.", 'partner_contract_blocked', 'warning', 'heroicon-o-exclamation-triangle');
            $this->toastContractBlocked($partner, $missing);

            return ['version' => null, 'mail_sent' => false, 'signing_url' => null, 'missing' => $missing];
        }

        try {
            $version = DB::transaction(fn () => $workflow->createVersion(
                $partner,
                auth()->user(),
                'Hợp đồng đăng ký hợp tác — ' . now()->format('d/m/Y H:i'),
                'Tự động tạo sau khi admin duyệt giấy tờ (đăng ký hợp tác trên website)'
            ));
        } catch (ValidationException $e) {
            $this->log($partner, 'Không tạo được hợp đồng: ' . collect($e->errors())->flatten()->implode(' '));

            return ['version' => null, 'mail_sent' => false, 'signing_url' => null];
        }

        $signingUrl = route('contract.sign.show', $version->signing_token);
        $mailSent = $this->mailPartner(
            $partner,
            'Hồ sơ đã được duyệt — vui lòng ký hợp đồng hợp tác 365 Home',
            "<p>Giấy tờ pháp lý của bạn đã được 365 Home duyệt. Vui lòng mở liên kết để xem và ký xác nhận hợp đồng hợp tác (xác thực bằng mã OTP gửi về email này):</p><p><a href=\"{$signingUrl}\">{$signingUrl}</a></p>"
        );
        $this->log($partner, 'Đã tạo hợp đồng và gửi link ký cho đối tác' . ($mailSent ? '.' : ' — gửi email thất bại, đối tác vẫn ký được trên trang đăng ký hợp tác.'));

        return ['version' => $version, 'mail_sent' => $mailSent, 'signing_url' => $signingUrl];
    }

    /** Đối tác đã ký xác nhận hợp đồng → báo super admin vào ký phía nền tảng (sau đó hệ thống tự cấp tài khoản). */
    public function notifyPartnerSigned(Partner $partner): void
    {
        if (blank($partner->onboarding_token)) {
            return;
        }
        // Đối tác ký TRƯỚC khi duyệt: ký xong thì hồ sơ tự gửi cho 365 Home duyệt (submit() lo phần thông báo).
        if ($partner->signsBeforeReview() && $partner->verification_status !== 'approved') {
            try {
                if ($partner->verification_submitted_at === null) {
                    $this->submit($partner->fresh());
                }
            } catch (ValidationException $e) {
                // Chưa gửi duyệt được (vd không còn giấy tờ mới để gửi): đối tác bấm "Gửi hồ sơ" trên trang đăng ký sau khi hoàn tất.
                $this->log($partner, 'Đối tác đã ký hợp đồng nhưng chưa tự gửi duyệt được: ' . collect($e->errors())->flatten()->implode(' '));
            }

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
        $missingDocs = $this->missingRequiredDocuments($partner);
        if ($missingDocs !== []) {
            throw ValidationException::withMessages(['documents' => 'Phải nộp đủ giấy tờ bắt buộc (có tệp). Còn thiếu: ' . implode(', ', array_map(fn ($t) => PartnerLegalDocument::TYPES[$t] ?? $t, $missingDocs)) . '.']);
        }
        $missing = $this->missingContractFields($partner);
        if ($missing !== []) {
            throw ValidationException::withMessages(['contract_info' => 'Thiếu thông tin ký hợp đồng: ' . implode(', ', array_map(fn ($f) => self::LABELS[$f] ?? $f, $missing)) . '.']);
        }

        // Đối tác ký trước: phải ký hợp đồng (bản mới nhất) rồi mới gửi duyệt.
        $signedFirst = $partner->signsBeforeReview();
        if ($signedFirst && ! $this->latestContract($partner)?->isPartnerConfirmed()) {
            throw ValidationException::withMessages(['contract' => 'Vui lòng ký hợp đồng trước khi gửi hồ sơ cho 365 Home duyệt.']);
        }

        // Gửi các giấy tờ mới/bổ sung sang "chờ duyệt"; không còn giấy tờ mới (đã gửi trước đó) → báo lỗi như API hồ sơ pháp lý.
        $this->documents->submit($partner);

        // MiniHouse đăng ký dùng thử: không có hợp đồng — duyệt giấy tờ xong là tặng dùng thử và cấp tài khoản.
        if ($partner->minihouseDocumentsFlow()) {
            $this->notifyAdmins($partner, 'Hồ sơ MiniHouse chờ duyệt giấy tờ', $this->partnerLabel($partner) . ' đã nộp giấy tờ pháp lý để đăng ký dùng thử. Vào MiniHouse → Đối tác → bảng Hồ sơ pháp lý để duyệt từng giấy tờ, rồi bấm “Duyệt toàn bộ hồ sơ”.', 'partner_onboarding', 'info', 'heroicon-o-document-check');
            $this->mailPartner(
                $partner,
                'Đã nhận giấy tờ MiniHouse — chờ 365 Home duyệt',
                '<p>365 Home đã nhận giấy tờ pháp lý của <strong>' . e($partner->legal_name ?: $partner->name) . '</strong>. Chúng tôi sẽ xem xét; nếu hợp lệ, tài khoản dùng thử sẽ được gửi về email này. Nếu cần bổ sung, chúng tôi sẽ báo lý do qua email.</p>'
            );

            return $partner->fresh();
        }

        try {
            app(AdminNotificationService::class)->notify(
                User::role(config('filament-shield.super_admin.name'))->get(),
                'Hồ sơ đăng ký hợp tác chờ duyệt',
                ($partner->legal_name ?: $partner->name) . ' (' . ($partner->isMinihouse() ? 'MiniHouse' : 'Homestay') . ', ' . $partner->phone . ') đã nộp giấy tờ pháp lý và thông tin hợp đồng' . ($signedFirst ? ', và ĐÃ KÝ hợp đồng điều khoản chuẩn. Vào Đối tác → tab Hồ sơ pháp lý để xem và duyệt; duyệt xong ký phía nền tảng ở tab Hợp đồng.' : '. Vào Đối tác → tab Hồ sơ pháp lý để xem và duyệt; duyệt xong hệ thống gửi hợp đồng cho đối tác ký.'),
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
            '<p>365 Home đã nhận hồ sơ hợp tác của <strong>' . e($partner->legal_name ?: $partner->name) . '</strong>'
            . ($signedFirst
                ? ' cùng chữ ký hợp đồng của bạn. Chúng tôi sẽ xem xét giấy tờ; nếu hợp lệ, 365 Home ký xác nhận hợp đồng và gửi tài khoản quản trị về email này. Hợp đồng chỉ có hiệu lực sau khi 365 Home ký. Nếu cần bổ sung, chúng tôi sẽ báo lý do qua email.</p>'
                : '. Chúng tôi sẽ xem xét giấy tờ; nếu hợp lệ, hợp đồng sẽ được gửi về email này để bạn ký trực tuyến. Nếu cần bổ sung, chúng tôi sẽ báo lý do qua email.</p>')
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
        // MiniHouse mua gói mở lại bằng ?mh=<mã đơn>; hồ sơ đăng ký hợp tác dùng ?ma=<mã hồ sơ>.
        $url = route('partner-onboarding.page') . ($partner->usesContract() ? '?ma=' : '?mh=') . $token;

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

    private function toastContractBlocked(Partner $partner, array $missing): void
    {
        if (! auth()->check()) {
            return;
        }

        try {
            \Filament\Notifications\Notification::make()
                ->title('Đã duyệt giấy tờ — chưa gửi được hợp đồng')
                ->body(implode("\n", array_map(fn ($m) => '• ' . $m, array_values($missing))) . "\nNhập ở tab Hợp đồng rồi bấm “Tạo & gửi hợp đồng ký”.")
                ->warning()
                ->persistent()
                ->send();
        } catch (\Throwable) {
        }
    }

    /** Hồ sơ bị TỪ CHỐI (cả hồ sơ) → báo lý do cho đối tác qua email. */
    public function notifyDossierRejected(Partner $partner, string $reason): void
    {
        if (blank($partner->onboarding_token)) {
            return;
        }

        $this->mailPartner(
            $partner,
            'Hồ sơ hợp tác 365 Home không được chấp nhận',
            '<p>Rất tiếc, hồ sơ hợp tác của <strong>' . e($partner->legal_name ?: $partner->name) . '</strong> chưa được 365 Home chấp nhận.</p>'
            . '<p>Lý do: <strong>' . e($reason) . '</strong></p>'
            . '<p>Nếu cần làm rõ hoặc nộp lại hồ sơ, vui lòng liên hệ 365 Home.</p>'
        );
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

        // Tài khoản "mồ côi" của đối tác đã bị xoá không được giữ email: giải phóng để cấp cho hồ sơ mới.
        if (filled($email)) {
            $orphan = User::query()->where('email', $email)->first();
            if ($orphan && $orphan->partner_id && $orphan->partner_id !== $partner->id && Partner::onlyTrashed()->whereKey($orphan->partner_id)->exists()) {
                $orphan->tokens()->delete();
                $orphan->delete();
                $this->log($partner, "Đã giải phóng email {$email} từ tài khoản của đối tác đã xoá.");
            }
        }

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
        $trial = $partner->isMinihouse() && (bool) $partner->subscription?->is_trial;
        $next = $partner->isMinihouse()
            ? ($partner->usesContract()
                ? 'Sau khi đăng nhập, bạn tạo toà nhà, phòng và bổ sung giấy tờ cấp toà nhà (PCCC, an ninh trật tự, quyền khai thác) để bắt đầu vận hành.'
                : 'Sau khi đăng nhập, bạn tạo toà nhà và phòng để bắt đầu vận hành.')
            : 'Sau khi đăng nhập, bạn tạo chi nhánh, phòng và bảng giá để bắt đầu nhận khách.';

        if ($partner->isMinihouse()) {
            try {
                Mail::to($email)->send(new LockNotificationMail(
                    $trial ? 'Đăng ký MiniHouse đã được duyệt — tài khoản đăng nhập của bạn' : 'Kích hoạt gói MiniHouse — tài khoản đăng nhập của bạn',
                    "<p>Xin chào <strong>{$name}</strong>,</p>"
                    . ($trial
                        ? '<p>365 Home đã <strong>duyệt đăng ký MiniHouse</strong> của bạn và tặng dùng thử đến ngày <strong>' . $partner->subscription->expires_at->format('d/m/Y') . '</strong>. Vui lòng thanh toán gói trước ngày này để tiếp tục sử dụng (thời hạn đã mua được cộng nối tiếp sau thời gian dùng thử).</p>'
                        : '<p>365 Home đã nhận thanh toán và kích hoạt gói dịch vụ MiniHouse cho bạn.</p>')
                    . "<p>Thông tin đăng nhập trang quản trị:<br>Địa chỉ: <a href=\"{$loginUrl}\">{$loginUrl}</a><br>Email đăng nhập: <strong>" . e($email) . '</strong><br>Mật khẩu: <strong>' . e($password) . '</strong></p>'
                    . "<p>{$next}</p><p>Vui lòng đổi mật khẩu sau khi đăng nhập lần đầu.</p>"
                ));

                return true;
            } catch (\Throwable $e) {
                report($e);

                return false;
            }
        }

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

        // Đối tác ký trước (signsBeforeReview): hợp đồng có thể đã tạo/đã ký khi hồ sơ CHƯA duyệt, nên giai đoạn xét theo cả hai:
        //  contract_sent   = có bản hợp đồng đang chờ đối tác ký (trước hoặc sau khi duyệt)
        //  contract_signed = đối tác đã ký VÀ hồ sơ đã duyệt → chờ 365 Home ký phía nền tảng
        //  đã ký nhưng chưa duyệt → theo trạng thái hồ sơ (pending_review / changes_requested / ready_to_submit).
        $signFirst = $partner->signsBeforeReview();
        $confirmed = (bool) $contract?->isPartnerConfirmed();

        $stage = match (true) {
            $partner->contract_status === 'active' => 'active',
            $partner->verification_status === 'rejected' => 'rejected',
            $confirmed && ($approved || ! $signFirst) => 'contract_signed',
            $hasChanges => 'changes_requested',
            $signingActive && ($approved || $signFirst) => 'contract_sent',
            $approved => 'approved',
            $partner->verification_submitted_at !== null => 'pending_review',
            $missing === [] && $this->missingRequiredDocuments($partner) === [] => 'ready_to_submit',
            $documents->isNotEmpty() => 'documents_uploaded',
            default => 'registered',
        };

        return [
            'stage'        => $stage,
            // Thứ tự ký: true = đối tác ký hợp đồng TRƯỚC khi gửi duyệt (POST .../contract để tạo hợp đồng); false = duyệt xong mới ký.
            'flow'         => ['sign_before_review' => $signFirst],
            'partner_type' => $partner->partner_type,
            'partner'      => collect(['name', 'phone', 'partner_type', ...self::CONTRACT_FIELDS])
                ->mapWithKeys(fn ($f) => [$f => $partner->{$f} instanceof \DateTimeInterface ? $partner->{$f}->format('Y-m-d') : $partner->{$f}])->all(),
            'steps' => [
                'registered'          => true,
                'documents_uploaded'  => $this->missingRequiredDocuments($partner) === [],
                'contract_info_ready' => $missing === [],
                'submitted'           => $partner->verification_submitted_at !== null,
                'documents_approved'  => $approved,
                'contract_sent'       => $contract !== null,
                'contract_signed'     => (bool) $contract?->isPartnerConfirmed(),
                'active'              => $partner->contract_status === 'active',
            ],
            'missing_contract_fields' => $missing,
            'required_documents'      => $this->requiredDocumentsStatus($partner),
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
            // Ô riêng theo loại (ĐKKD/ANTT/PCCC — mỗi loại một bộ cột riêng): đủ các ô theo thứ tự form kèm nhãn và giá trị.
            'fields'          => \App\Support\LegalDocumentFields::display($document),
            'fire_safety_stage' => $document->type === 'fire_safety' ? \App\Support\LegalDocumentFields::fireSafetyStage($document->document_number) : null,
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
        // MiniHouse mua gói không ký hợp đồng → không đòi thông tin ký hợp đồng.
        if (! $partner->usesContract()) {
            return [];
        }

        return array_values(array_filter(self::CONTRACT_REQUIRED, fn ($f) => blank($partner->{$f})));
    }

    /**
     * Loại giấy tờ BẮT BUỘC khi đăng ký: Homestay và MiniHouse đăng ký dùng thử theo config partner_flow.registration_required_documents
     * (hiện tại Homestay = ĐKKD + ANTT, MiniHouse = ĐKKD); MiniHouse đăng ký kiểu hợp đồng (MINIHOUSE_CONTRACT_ENABLED) chỉ Giấy phép kinh doanh
     * (giấy tờ toà nhà bổ sung sau).
     */
    private function requiredDocumentTypes(Partner $partner): array
    {
        return $partner->isMinihouse() && ! $partner->minihouseDocumentsFlow() ? ['business_license'] : PartnerLegalDocument::registrationRequiredFor($partner);
    }

    /** Nhãn các giấy tờ bắt buộc khi đăng ký của một loại đối tác, nối bằng dấu phẩy — dùng cho email và trang đăng ký. */
    public static function requiredDocumentLabels(string $partnerType): string
    {
        return implode(', ', array_map(fn (string $type) => PartnerLegalDocument::TYPES[$type] ?? $type, PartnerLegalDocument::registrationRequiredFor($partnerType)));
    }

    /** @return array<int, string> loại giấy tờ bắt buộc còn thiếu (chưa có tệp hoặc đã bị từ chối) */
    private function missingRequiredDocuments(Partner $partner): array
    {
        $uploaded = $partner->legalDocuments()->whereIn('type', $this->requiredDocumentTypes($partner))->whereNull('building_id')
            ->whereNotIn('status', ['rejected'])->get()->filter(fn (PartnerLegalDocument $d) => $d->hasMedia('file'))->pluck('type')->unique()->all();

        return array_values(array_diff($this->requiredDocumentTypes($partner), $uploaded));
    }

    /** Danh sách giấy tờ bắt buộc kèm trạng thái đã nộp — để màn hình đăng ký hiển thị đúng những gì khách phải gửi. */
    private function requiredDocumentsStatus(Partner $partner): array
    {
        $missing = $this->missingRequiredDocuments($partner);

        return array_map(fn (string $type) => ['type' => $type, 'label' => PartnerLegalDocument::TYPES[$type] ?? $type, 'uploaded' => ! in_array($type, $missing, true)], $this->requiredDocumentTypes($partner));
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
