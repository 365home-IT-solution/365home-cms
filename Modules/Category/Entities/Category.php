<?php

namespace Modules\Category\Entities;

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
        'category_type',
        'status',
        'image',
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
        'status'    => 'boolean',
        'area_sqm'  => 'decimal:2',
    ];

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
}
