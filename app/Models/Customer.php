<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Modules\Promotion\App\Models\Coupon;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Authenticatable
{
    // CustomerObserver (app/Observers/CustomerObserver.php) chỉ xử lý gán hạng thành viên chào
    // mừng lúc tạo mới — KHÔNG hề ghi audit log — nên trước đây sửa/xoá khách hàng qua
    // CustomerResource hoàn toàn không có log nào. Thêm LogsAuditTrail (độc lập, không xung đột
    // với Observer đang có).
    use HasApiTokens, HasFactory, SoftDeletes, LogsAuditTrail;

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
        'province_name',
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

    // Cùng lý do như getNameAttribute() ở trên: Customer dùng chung Sanctum nên đôi lúc bị code
    // dùng chung cho cả User lẫn Customer gọi nhầm các method chỉ có ở User (vd isSuperAdmin()),
    // gây BadMethodCallException/500. Khách hàng KHÔNG BAO GIỜ là super admin nên trả về false là
    // đúng về nghĩa — chỉ chặn crash, không đổi hành vi phân quyền thật nào của User.
    public function isSuperAdmin(): bool
    {
        return false;
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

    // Chi nhánh GỐC (parent_id=null) mà khách hàng thuộc về — tự động gán TOÀN BỘ chi nhánh gốc
    // trong phạm vi quyền của user tạo (CustomerController::store(), cùng nguồn với field
    // "categories" ở POST /api/admin/login — xem User::rootProductCategoryIds()), KHÔNG cho FE tự
    // chọn tay. KHÔNG dùng để giới hạn ai xem được khách hàng này, xem ghi chú tại
    // CustomerController::index().
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'customer_categories', 'customer_id', 'category_id')
            ->withTimestamps();
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

    public function checkinCycles(): HasMany
    {
        return $this->hasMany(CustomerCheckinCycle::class, 'customer_id');
    }

    // Chu kỳ điểm danh đang mở (chưa đủ ngày) — mỗi khách chỉ có tối đa 1 chu kỳ đang mở tại
    // 1 thời điểm, xem CustomerCheckinService::getOrCreateActiveCycle().
    public function activeCheckinCycle(): HasOne
    {
        return $this->hasOne(CustomerCheckinCycle::class, 'customer_id')
            ->whereNull('completed_at')
            ->latestOfMany();
    }
}
