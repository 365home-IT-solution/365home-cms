<?php

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Category\Entities\Category;

class ManualLockPassword extends Model
{
    protected $fillable = [
        'name',
        'gate_password',
        'room_password',
        'category_id',
        'notes',
    ];

    public function products()
    {
        return $this->belongsToMany(
            Product::class,
            'manual_lock_password_product',
            'manual_lock_password_id',
            'product_id'
        );
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
