<?php

namespace App\Models;

use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAvatar, HasName, HasMedia
{
    use HasApiTokens,
        HasFactory,
        Notifiable,
        HasUuids,
        InteractsWithMedia;

    use HasRoles, HasPanelShield;

    protected static function boot()
    {
        parent::boot();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email',
        'fullname',
        'date_of_birth',
        'password',
        'phone',
        'phone_verified_at',
        'created_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at'  => 'datetime',
        'phone_verified_at'  => 'datetime',
        'date_of_birth'      => 'date',
        'password'           => 'hashed',
    ];

    public function getFilamentName(): string
    {
        return $this->fullname ?? $this->email;
    }

public function getFilamentAvatarUrl(): ?string
    {
        return $this->getMedia('avatars')?->first()?->getUrl() ?? $this->getMedia('avatars')?->first()?->getUrl('thumb') ?? null;
    }

    public function getNameAttribute()
    {
        return "{$this->fullname}";
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Chỉ user có ít nhất 1 role thật (không phải panel_user) mới vào được admin panel
        // Khách hàng đăng ký qua OTP không có role → không vào được
        return $this->roles()
            ->where('name', '!=', config('filament-shield.panel_user.name'))
            ->exists();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(config('filament-shield.super_admin.name'));
    }

    /**
     * Trả về tất cả ID con cháu (đệ quy) của user này qua cột created_by.
     * Nhân viên 1 → [NV2, NV3, NV4 (NV3 tạo), ...]
     */
    public function getAllDescendantIds(): array
    {
        $allIds    = [];
        $parentIds = [$this->id];

        while (! empty($parentIds)) {
            $children  = static::whereIn('created_by', $parentIds)->pluck('id')->toArray();
            $allIds    = array_merge($allIds, $children);
            $parentIds = $children;
        }

        return $allIds;
    }

    public function wishlists()
    {
        return $this->hasMany(Wishlist::class);
    }

    public function branchPermissions()
    {
        return $this->hasMany(\Modules\DataPermission\Entities\UserBranchPermission::class);
    }

    /**
     * Trả về danh sách category_id loại PRODUCT (chi nhánh gốc) mà user được phép xem.
     */
    public function allowedBranchIds(): array
    {
        return $this->branchPermissions()
            ->whereHas('branch', fn ($q) => $q->where('category_type', 'product'))
            ->pluck('category_id')
            ->toArray();
    }

    /**
     * Trả về danh sách category_id loại POST được gán trực tiếp cho user.
     */
    public function allowedDirectPostRootIds(): array
    {
        return $this->branchPermissions()
            ->whereHas('branch', fn ($q) => $q->where('category_type', 'post'))
            ->pluck('category_id')
            ->toArray();
    }

    /**
     * Trả về tất cả category_id (cha + con, loại product) mà user được phép xem.
     * Dùng cho các resource cần lọc theo nhánh + khu vực con.
     */
    public function allowedCategoryIds(): array
    {
        $branchIds = $this->allowedBranchIds();

        if (empty($branchIds)) {
            return [];
        }

        return \Modules\Category\Entities\Category::query()
            ->where(function ($q) use ($branchIds) {
                $q->whereIn('id', $branchIds)
                  ->orWhereIn('parent_id', $branchIds);
            })
            ->pluck('id')
            ->toArray();
    }

    /**
     * Trả về danh sách category_id (type='post') mà user được phép dùng.
     * Gồm 2 nguồn:
     *  1. Gán trực tiếp post categories qua Phân quyền Chi nhánh
     *  2. Name-matching từ product branch (3 từ đầu tên chi nhánh)
     */
    public function allowedPostCategoryIds(): array
    {
        $result = collect();

        // 1. Danh mục post được gán trực tiếp + con của chúng
        $directPostIds = $this->allowedDirectPostRootIds();
        if (! empty($directPostIds)) {
            $ids = \Modules\Category\Entities\Category::where('category_type', 'post')
                ->where(function ($q) use ($directPostIds) {
                    $q->whereIn('id', $directPostIds)
                      ->orWhereIn('parent_id', $directPostIds);
                })
                ->pluck('id');
            $result = $result->merge($ids);
        }

        // 2. Name-matching từ chi nhánh product
        $branchIds = $this->allowedBranchIds();
        if (! empty($branchIds)) {
            $branchNames = \Modules\Category\Entities\Category::whereIn('id', $branchIds)
                ->where('category_type', 'product')
                ->pluck('name')
                ->toArray();

            if (! empty($branchNames)) {
                $ids = \Modules\Category\Entities\Category::where('category_type', 'post')
                    ->where(function ($q) use ($branchNames) {
                        foreach ($branchNames as $name) {
                            $keyword = implode(' ', array_slice(explode(' ', trim($name)), 0, 3));
                            $q->orWhere('name', 'LIKE', $keyword . '%');
                        }
                    })
                    ->pluck('id');
                $result = $result->merge($ids);
            }
        }

        return $result->unique()->values()->toArray();
    }

    public function registerMediaConversions(Media|null $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Contain, 300, 300)
            ->nonQueued();
    }
}
