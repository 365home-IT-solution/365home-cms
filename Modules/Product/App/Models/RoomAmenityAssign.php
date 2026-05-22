<?php

declare(strict_types=1);

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomAmenityAssign extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'room_id',
        'amenity_id',
    ];

    public function room()
    {
        return $this->belongsTo(Product::class, 'room_id');
    }

    public function amenity()
    {
        return $this->belongsTo(RoomAmenity::class, 'amenity_id');
    }
}
