<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Amenity extends Model
{
    protected $table = 'minihouse_amenities';

    protected $fillable = ['name', 'image'];

    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'minihouse_room_amenity');
    }
}
