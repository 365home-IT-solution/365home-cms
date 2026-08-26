<?php

namespace Modules\Category\Entities;

use App\Support\ImagePresetUrls;
use Illuminate\Database\Eloquent\Model;
use Modules\AccessCode\Entities\AccessCode;
use Modules\DataPermission\Entities\UserBranchPermission;
use Modules\Post\Entities\Post;
use Modules\Product\App\Models\Product;
use Modules\Payment\Entities\Order;

class Category extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'parent_id',
        'sort_order',
        'category_type',
        'status',
        'image',
        'image_width',
        'image_height',
        'partner_id',
        // Chi tiết vận hành (chỉ có ý nghĩa với category_type = product, xem
        // Modules/Category/App/Filament/Resources/CategoryResource/Pages/BranchDetail.php)
        'area_sqm',
        'established_year',
        'lodging_type',
        'timezone',
        'operation_manager_name',
        'checkin_time',
        'checkout_time',
        'default_policy',
    ];

    protected $casts = [
        'status'       => 'boolean',
        'area_sqm'     => 'decimal:2',
        'sort_order'   => 'integer',
        'image_width'  => 'integer',
        'image_height' => 'integer',
    ];

    public function getThumbnailAttribute(): ?array
    {
        return ImagePresetUrls::build($this->image, 'public', $this->image_width, $this->image_height);
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function partner()
    {
        return $this->belongsTo(\App\Models\Partner::class);
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function posts()
    {
        return $this->morphedByMany(Post::class, 'categorizable');
    }

    public function products()
    {
        return $this->morphedByMany(Product::class, 'categorizable');
    }

    public function accessCodes()
    {
        return $this->hasMany(AccessCode::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'category_id');
    }

    public function branchPermissions()
    {
        return $this->hasMany(UserBranchPermission::class, 'category_id');
    }

    public function isAssignedToUserPermission(): bool
    {
        return $this->branchPermissions()->exists();
    }

    /**
     * ID của chi nhánh (category gốc, parent_id null, category_type=product) đang KHÔNG active
     * (status=false), gộp cả category con của các chi nhánh đó — dùng để loại phòng/chi nhánh
     * khỏi mọi danh sách/tìm kiếm công khai. Nguồn dùng chung cho Product::scopeActiveBranch()
     * và các nơi liệt kê/xem chi tiết chi nhánh (BranchController, ProvinceController...), tránh
     * lặp lại truy vấn root+children ở nhiều nơi.
     */
    public static function inactiveBranchCategoryIds(): \Illuminate\Support\Collection
    {
        $inactiveBranchIds = static::whereNull('parent_id')
            ->where('category_type', 'product')
            ->where('status', false)
            ->pluck('id');

        if ($inactiveBranchIds->isEmpty()) {
            return $inactiveBranchIds;
        }

        return $inactiveBranchIds
            ->merge(static::whereIn('parent_id', $inactiveBranchIds)->pluck('id'))
            ->unique()
            ->values();
    }
}
