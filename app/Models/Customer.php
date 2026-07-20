<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Payment\Entities\Order;
use Modules\Promotion\App\Models\Coupon;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory, SoftDeletes;

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'fullname',
        'date_of_birth',
        'phone',
        'phone_verified_at',
        'status',
        'password',
        'password_updated_at',
        'token_device',
        'cccd_front',
        'cccd_back',
        'cccd_data',
        'membership_tier_id',
        'total_spending',
        'welcome_coupon_sent_at',
        'province_id',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'phone_verified_at'      => 'datetime',
        'date_of_birth'          => 'date',
        'password'               => 'hashed',
        'password_updated_at'    => 'datetime',
        'cccd_data'              => 'array',
        'total_spending'         => 'decimal:2',
        'welcome_coupon_sent_at' => 'datetime',
    ];

    // Filament gọi $user->name — map về fullname để tránh TypeError
    public function getNameAttribute(): string
    {
        return $this->fullname ?? '';
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = Str::uuid()->toString();
            }
        });

        static::updating(function (self $model): void {
            if ($model->isDirty('status') && $model->status === self::STATUS_INACTIVE) {
                $model->tokens()->delete();
            }
        });
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'province_id');
    }

    public function membershipTier(): BelongsTo
    {
        return $this->belongsTo(MembershipTier::class, 'membership_tier_id');
    }

    public function membershipLogs(): HasMany
    {
        return $this->hasMany(CustomerMembershipLog::class, 'customer_id');
    }

    public function personalCoupons(): HasMany
    {
        return $this->hasMany(Coupon::class, 'customer_id');
    }

    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class, 'coupon_customers', 'customer_id', 'coupon_id')
            ->withPivot('assigned_at')
            ->withTimestamps();
    }

    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class, 'customer_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    // CCCD người đi cùng đã lưu sẵn, tái sử dụng cho các lần đặt phòng qua đêm sau này — xem
    // migration 2026_07_20_000002_create_customer_companions_table.
    public function companions(): HasMany
    {
        return $this->hasMany(CustomerCompanion::class);
    }
}
