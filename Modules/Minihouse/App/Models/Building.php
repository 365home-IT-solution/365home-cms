<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Building extends Model
{
    use SoftDeletes;

    protected $table = 'minihouse_buildings';

    protected $fillable = ['name', 'address', 'note'];

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
