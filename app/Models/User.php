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
        'partner_id',
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
        if (! $this->roles()->where('name', '!=', config('filament-shield.panel_user.name'))->exists()) {
            return false;
        }

        // super_admin không thuộc đối tác nào (partner_id null) — luôn không bị chặn ở đây.
        // Chủ đối tác VÀ toàn bộ nhân viên của đối tác đó CHỈ được đăng nhập khi đối tác đã
        // 'approved' — chặn CẢ 'pending' (chưa được duyệt lần nào, trạng thái MẶC ĐỊNH lúc mới
        // tạo đối tác) chứ không chỉ 'rejected'/'suspended'. Trước đây chỉ chặn 2 trạng thái xấu,
        // vô tình để lọt đối tác CHƯA TỪNG được duyệt vẫn đăng nhập được bình thường.
        if ($this->partner_id) {
            // withTrashed() CỐ Ý — Partner dùng Soft Delete, "xoá" đối tác không xoá bản ghi thật,
            // chỉ gán deleted_at. Quan hệ $this->partner() mặc định LOẠI TRỪ bản ghi đã xoá nên nếu
            // dùng thẳng $this->partner ở đây, giá trị sẽ luôn null với đối tác đã xoá — vô tình
            // "chặn đúng" nhờ null-safe operator (null !== 'approved') nhưng không tường minh, dễ vỡ
            // âm thầm nếu sau này có ai đổi cách load quan hệ. Viết rõ ràng: đối tác đã xoá (trashed)
            // PHẢI chặn đăng nhập ngay, tách biệt với lý do verification_status khác 'approved'.
            $partner = \App\Models\Partner::withTrashed()->find($this->partner_id);

            if (! $partner || $partner->trashed() || $partner->verification_status !== 'approved') {
                return false;
            }
        }

        return true;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(config('filament-shield.super_admin.name'));
    }

    // Trước đây check hasRole('partner') — nhưng hệ thống thực tế tạo 1 ROLE RIÊNG cho mỗi đối
    // tác (vd "monaco_super_admin", "Quản lý Monaco", "Quyền 1.1"...), KHÔNG có tài khoản nào thực
    // sự mang đúng role tên "partner", nên hasRole('partner') LUÔN LUÔN false. Hậu quả: chủ đối tác
    // bị coi nhầm thành "nhân viên vận hành" ở $isPlainEmployee (dashboard.blade.php) và mọi nơi
    // khác dùng isPartnerOwner() để cấp quyền xem tài chính/quản lý (Doanh thu/KPI, SalaryReport,
    // RoomHousekeepingResource...) — ẩn mất hết, dù đây chính là tài khoản chủ đối tác.
    //
    // Dấu hiệu ĐÁNG TIN CẬY hơn để phân biệt CHỦ đối tác với NHÂN VIÊN của đối tác: chủ đối tác có
    // partner_id nhưng KHÔNG có hồ sơ Employee liên kết (employee() luôn null với chủ đối tác/
    // super_admin — xem ghi chú tại employee() bên dưới và UserResource\Pages\CreateUser, nơi hồ sơ
    // Employee CHỈ được tạo khi account_type = 'employee').
    public function isPartnerOwner(): bool
    {
        return (bool) $this->partner_id && ! $this->employee;
    }

    // Tài khoản thuộc đối tác ĐẶC BIỆT do nền tảng vận hành (vd "365home") — nhân viên đối tác
    // này được phép tạo đơn đặt phòng cho BẤT KỲ đối tác nào khác (xem Partner::isPlatformPartner()
    // và điểm bypass ở luồng tạo booking). KHÔNG cấp quyền xem toàn bộ dữ liệu như super_admin.
    public function belongsToPlatformPartner(): bool
    {
        return $this->partner?->isPlatformPartner() ?? false;
    }

    // Đối tác mà tài khoản này thuộc về (chủ đối tác trỏ tới chính đối tác của mình;
    // nhân viên trỏ tới đối tác chủ quản). Null với super_admin.
    public function partner()
    {
        return $this->belongsTo(\App\Models\Partner::class);
    }


    // Hồ sơ nhân viên liên kết (lương, chi nhánh làm việc, phòng ban...). Trang tạo/sửa tài
    // khoản (UserResource) tạo/đồng bộ đồng thời bản ghi này — null với super_admin/chủ đối tác
    // (những tài khoản chỉ cần đăng nhập, không cần hồ sơ nhân viên).
    public function employee()
    {
        return $this->hasOne(\Modules\Employee\Entities\Employee::class);
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
     * Danh sách category_id CHI NHÁNH GỐC (parent_id = null, loại 'product') mà user được phép
     * xem — CHÍNH XÁC là tập hợp trả về ở field "categories" của POST /api/admin/login (xem
     * AuthController::branchCategories(), dùng chung nguồn này). super_admin thấy hết chi nhánh
     * gốc; user thường chỉ thấy chi nhánh gốc thuộc đúng partner_id của mình, thu hẹp thêm theo
     * allowedBranchIds() nếu bị cấp quyền chi nhánh cụ thể (chủ đối tác thì không bị giới hạn).
     */
    public function rootProductCategoryIds(): array
    {
        $query = \Modules\Category\Entities\Category::whereNull('parent_id')->where('category_type', 'product');

        if ($this->isSuperAdmin()) {
            return $query->pluck('id')->toArray();
        }

        if (empty($this->partner_id)) {
            return [];
        }

        $query->where('partner_id', $this->partner_id);

        $allowedBranchIds = $this->allowedBranchIds();
        if (! empty($allowedBranchIds)) {
            $query->whereIn('id', $allowedBranchIds);
        }

        return $query->pluck('id')->toArray();
    }

    /**
     * Toàn bộ category_id loại 'product' (chi nhánh gốc — rootProductCategoryIds() — VÀ khu vực
     * con mọi cấp) mà user được phép CHỌN/GÁN — nguồn xác thực dùng chung cho mọi API cần validate
     * 1 category_id do FE gửi lên nằm đúng phạm vi quyền.
     */
    public function visibleProductCategoryIds(): array
    {
        $categoryModel = \Modules\Category\Entities\Category::class;

        $branchIds = $this->rootProductCategoryIds();

        if (empty($branchIds)) {
            return [];
        }

        $allIds       = $branchIds;
        $currentLevel = $branchIds;

        while (! empty($currentLevel)) {
            $children = $categoryModel::whereIn('parent_id', $currentLevel)->pluck('id')->toArray();
            if (empty($children)) {
                break;
            }
            $allIds       = array_merge($allIds, $children);
            $currentLevel = $children;
        }

        return array_unique($allIds);
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

        $allIds      = $branchIds;
        $currentLevel = $branchIds;

        while (! empty($currentLevel)) {
            $children = \Modules\Category\Entities\Category::whereIn('parent_id', $currentLevel)
                ->pluck('id')
                ->toArray();

            if (empty($children)) {
                break;
            }

            $allIds       = array_merge($allIds, $children);
            $currentLevel = $children;
        }

        return array_unique($allIds);
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

        // 1. Danh mục post được gán trực tiếp + toàn bộ con cháu (đệ quy)
        $directPostIds = $this->allowedDirectPostRootIds();
        if (! empty($directPostIds)) {
            $allPostIds   = $directPostIds;
            $currentLevel = $directPostIds;

            while (! empty($currentLevel)) {
                $children = \Modules\Category\Entities\Category::where('category_type', 'post')
                    ->whereIn('parent_id', $currentLevel)
                    ->pluck('id')
                    ->toArray();

                if (empty($children)) {
                    break;
                }

                $allPostIds   = array_merge($allPostIds, $children);
                $currentLevel = $children;
            }

            $result = $result->merge($allPostIds);
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
