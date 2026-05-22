<?php

declare(strict_types=1);

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomAmenity extends Model
{
    protected $fillable = [
        'amenity_type',
        'icon',
        'name',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'status'     => 'boolean',
        'sort_order' => 'integer',
    ];

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_amenity', 'amenity_id', 'product_id');
    }
}
