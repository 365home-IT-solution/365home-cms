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
        'representative_id_number',
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
        'representative_dob' => 'date',
        'business_license_date' => 'date',
        'contract_signed_at' => 'date',
        'contract_expires_at' => 'date',
        'verification_submitted_at' => 'datetime',
        'verified_at' => 'datetime',
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
        return $this->hasMany(PartnerContractVersion::class)->latest();
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

    public function legalDocuments(): HasMany
    {
        return $this->hasMany(PartnerLegalDocument::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isMinihouse(): bool
    {
        return $this->partner_type === self::TYPE_MINIHOUSE;
    }

    public function isSystemPartner(): bool
    {
        return $this->id === HomestayBridge::PARTNER_ID;
    }

    protected static function booted(): void
    {
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
