<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Category\Entities\Category;
use Modules\Employee\Entities\Employee;
use Modules\Product\App\Models\Product;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Partner extends Model implements HasMedia
{
    use HasUuids, SoftDeletes, InteractsWithMedia;

    protected $fillable = [
        // Cơ bản
        'name',
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
        'is_platform_partner',

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
        'status'                => 'boolean',
        'is_platform_partner'   => 'boolean',
        'representative_dob'    => 'date',
        'business_license_date' => 'date',
        'contract_signed_at'    => 'date',
        'contract_expires_at'   => 'date',
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
}
