<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Category\Entities\Category;
use Modules\Employee\Entities\Employee;
use Modules\Minihouse\App\Support\HomestayBridge;
use Modules\Product\App\Models\Product;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Partner extends Model implements HasMedia
{
    public const TYPE_HOMESTAY = 'homestay';

    public const TYPE_MINIHOUSE = 'minihouse';

    use HasUuids, InteractsWithMedia, LogsAuditTrail, SoftDeletes;

    protected $fillable = [
        // Cơ bản
        'name',
        'partner_type',
        'tax_code',
        'phone',
        'email',
        'address',
        'status',
        'created_by',

        // Người đại diện
        'representative_name',
        'representative_dob',
        'representative_position',
        'representative_id_number',
        'representative_id_issued_at',
        'signup_plan_id',
        'signup_periods',
        'representative_id_issued_place',
        'representative_phone_secondary',

        // Doanh nghiệp
        'legal_name',
        'business_license_date',
        'business_license_issuer',

        // Tài chính
        'bank_name',
        'bank_branch',
        'bank_account_number',
        'bank_account_holder',
        'momo_phone',
        'zalopay_id',
        'vnpay_id',
        'paypal_email',
        'wise_account',
        'swift_code',
        'payment_cycle',

        // Xác minh & vận hành
        'verification_status',
        'verification_submitted_at',
        'verified_at',
        'verified_by',
        'verification_note',
        'is_platform_partner',
        'count_in_platform_stats',
        'onboarding_token',

        // Hợp đồng
        'contract_code',
        'contract_type',
        'contract_status',
        'contract_signed_at',
        'contract_expires_at',
        'commission_rate',
        'cancellation_policy',
    ];

    protected $casts = [
        'status' => 'boolean',
        'is_platform_partner' => 'boolean',
        'count_in_platform_stats' => 'boolean',
        'representative_dob' => 'date',
        'representative_id_issued_at' => 'date',
        'business_license_date' => 'date',
        'contract_signed_at' => 'date',
        'contract_expires_at' => 'date',
        'verification_submitted_at' => 'datetime',
        'verified_at' => 'datetime',
        // Ký quỹ (Homestay) — chỉ App\Services\EscrowService ghi các cột này (không nằm trong $fillable).
        'escrow_min_amount' => 'integer',
        'escrow_balance' => 'integer',
        'payment_flow_effective_at' => 'datetime',
        'escrow_enforced_from' => 'datetime',
        'escrow_topup_due_at' => 'datetime',
        'escrow_suspended_at' => 'datetime',
        'escrow_terminated_at' => 'datetime',
        // Super Admin bật thì chủ đối tác được tự nhập kênh PayOS (không nằm trong $fillable — xem PartnerPayOsChannelService).
        'payos_self_setup_enabled' => 'boolean',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('representative_id_front')->singleFile();
        $this->addMediaCollection('representative_id_back')->singleFile();
        $this->addMediaCollection('bank_card_image')->singleFile();
        $this->addMediaCollection('contract_file')->singleFile();
        $this->addMediaCollection('verification_documents');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Tài khoản chủ đối tác + toàn bộ nhân viên đăng nhập được của đối tác này.
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // Tài khoản đăng nhập của chủ đối tác (role 'partner') — dùng hiển thị "Email đăng nhập hệ
    // thống" ở tab Người đại diện, không lưu trùng lặp email đăng nhập trên bảng partners.
    public function owner(): ?User
    {
        return $this->users()->whereHas('roles', fn ($q) => $q->where('name', 'partner'))->first();
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function isPlatformPartner(): bool
    {
        return (bool) $this->is_platform_partner;
    }

    // Chi nhánh (categories loại 'product') do đối tác này quản lý.
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function contractVersions(): HasMany
    {
        return $this->hasMany(PartnerContractVersion::class)->latest()->latest('id');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(PartnerStatusLog::class)->latest();
    }

    // Gói dịch vụ hiện tại của đối tác (1 dòng/đối tác) và các lần thanh toán phí gói.
    public function subscription(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PartnerSubscription::class);
    }

    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class)->latest('id');
    }

    // Kênh PayOS riêng của đối tác (tiền đặt phòng về thẳng tài khoản đối tác) — xem PayOsAccountResolver.
    public function payOsAccount(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\Modules\Payment\Entities\PartnerPayOsAccount::class);
    }

    public function escrowEntries(): HasMany
    {
        return $this->hasMany(PartnerEscrowEntry::class)->latest('id');
    }

    public function escrowDeductions(): HasMany
    {
        return $this->hasMany(PartnerEscrowDeduction::class)->latest('id');
    }

    public function legalDocuments(): HasMany
    {
        return $this->hasMany(PartnerLegalDocument::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** Đối tác có dùng luồng hồ sơ pháp lý + hợp đồng không? MiniHouse chỉ mua gói (ẩn hợp đồng) trừ khi bật MINIHOUSE_CONTRACT_ENABLED. */
    public function usesContract(): bool
    {
        return ! $this->isMinihouse() || (bool) config('partner_flow.minihouse_contract_enabled');
    }

    /**
     * MiniHouse ĐĂNG KÝ DÙNG THỬ trên website có bước giấy tờ pháp lý (không ký hợp đồng): nộp + được duyệt đủ giấy tờ rồi mới tặng dùng thử/cấp tài khoản.
     * signup_plan_id chỉ được ghi ở nhánh đăng ký dùng thử (MinihousePurchaseController::purchase) nên dùng để nhận diện nhánh này.
     */
    public function minihouseDocumentsFlow(): bool
    {
        return $this->isMinihouse() && ! $this->usesContract() && (bool) config('partner_flow.minihouse_trial_documents_required')
            && filled($this->onboarding_token) && filled($this->signup_plan_id);
    }

    /** Có dùng hồ sơ pháp lý (nộp + duyệt giấy tờ) không: mọi đối tác có hợp đồng, và MiniHouse đăng ký dùng thử có bước giấy tờ. */
    public function usesLegalDocuments(): bool
    {
        return $this->usesContract() || $this->minihouseDocumentsFlow();
    }

    /**
     * Lý do KHÔNG được xoá đối tác (null = xoá được) — MỘT quy tắc dùng chung cho API admin và nút Xoá ở trang quản trị:
     * hợp đồng đang có hiệu lực thì phải chấm dứt trước.
     */
    public function deletionBlockedReason(): ?string
    {
        return $this->usesContract() && $this->contract_status === 'active'
            ? 'Hợp đồng đang có hiệu lực — hãy chấm dứt hợp đồng trước khi xoá đối tác.'
            : null;
    }

    /** Đối tác đăng ký trên website KÝ HỢP ĐỒNG TRƯỚC khi 365 Home duyệt hồ sơ (config partner_flow.partner_signs_before_review). */
    public function signsBeforeReview(): bool
    {
        return $this->usesContract() && filled($this->onboarding_token) && (bool) config('partner_flow.partner_signs_before_review');
    }

    /** Đăng ký trên website phải nộp đủ giấy tờ cấp đối tác (PartnerLegalDocument::registrationRequiredFor()): Homestay và MiniHouse đăng ký dùng thử. */
    public function requiresRegistrationDocuments(): bool
    {
        return filled($this->onboarding_token) && (! $this->isMinihouse() || $this->minihouseDocumentsFlow());
    }

    public function isMinihouse(): bool
    {
        return $this->partner_type === self::TYPE_MINIHOUSE;
    }

    /** Luồng tiền mới (về thẳng đối tác + ký quỹ + đối soát) đã có hiệu lực chưa? Chưa thì 365home vẫn thu hộ. */
    public function usesDirectPayment(): bool
    {
        return $this->payment_flow_effective_at !== null;
    }

    public function isSystemPartner(): bool
    {
        return $this->id === HomestayBridge::PARTNER_ID;
    }

    protected static function booted(): void
    {
        // Homestay mới chưa nhập hoa hồng → mặc định theo cấu hình (20%).
        static::creating(function (Partner $partner): void {
            if (! $partner->isMinihouse() && blank($partner->commission_rate)) {
                $partner->commission_rate = config('partner_flow.default_commission_rate');
            }
        });

        // Hồ sơ đăng ký hợp tác công khai: hợp đồng có hiệu lực (nền tảng đã ký xong) → tự tạo tài khoản chủ đối tác + gửi email đăng nhập.
        static::updated(function (Partner $partner): void {
            if ($partner->wasChanged('contract_status') && $partner->contract_status === 'active' && filled($partner->onboarding_token)) {
                // Lỗi tạo tài khoản không được làm hỏng thao tác ký hợp đồng của admin: ghi log để tạo tay.
                try {
                    app(\App\Services\PartnerOnboardingService::class)->provisionAccount($partner);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });

        static::deleting(function (Partner $partner): void {
            if ($partner->isSystemPartner()) {
                throw new \DomainException('Không thể xóa đối tác MiniHouse nội bộ của hệ thống.');
            }
            // Chốt chặn cuối: API và trang quản trị đã kiểm tra deletionBlockedReason() trước để báo lỗi tử tế.
            if ($reason = $partner->deletionBlockedReason()) {
                throw new \DomainException($reason);
            }
        });
    }

    // Query chi nhánh gốc (categories parent_id=null, category_type=product) được phép gán cho đối
    // tác này ở tab "Chi nhánh"/"Gán tòa nhà" và API branch-/building-assignments. Homestay: chi nhánh
    // của đối tác Homestay + chi nhánh chưa có chủ. MiniHouse: CHỈ tòa nhà đang thuộc đối tác
    // MiniHouse — không nhận chi nhánh chưa có chủ, vì gán vào sẽ biến chi nhánh Homestay đó thành
    // tòa nhà MiniHouse (Building scope lọc theo partner_type của đối tác sở hữu).
    public function assignableBranchesQuery(): Builder
    {
        return Category::query()
            ->where('category_type', 'product')
            ->whereNull('parent_id')
            ->where(function (Builder $q) {
                $q->whereHas('partner', fn (Builder $p) => $p->where('partner_type', $this->partner_type));
                if (! $this->isMinihouse()) {
                    $q->orWhereNull('partner_id');
                }
            });
    }

    // partner_id mới của chi nhánh bị BỎ gán khỏi đối tác này. Homestay: null (chi nhánh chưa có
    // chủ). MiniHouse: tòa nhà không được mồ côi (partner_id=null sẽ làm nó biến khỏi MiniHouse và lọt
    // sang danh sách Homestay) — trả về đối tác MiniHouse nội bộ, nơi giữ toàn bộ dữ liệu MiniHouse cũ.
    public function releasedBranchPartnerId(): ?string
    {
        return $this->isMinihouse() ? HomestayBridge::PARTNER_ID : null;
    }
}
