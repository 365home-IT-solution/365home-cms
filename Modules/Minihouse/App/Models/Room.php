<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Room extends Model
{
    use SoftDeletes;

    public const STATUS_EMPTY   = 'trong';
    public const STATUS_RENTED  = 'dang_thue';
    public const STATUS_REPAIR  = 'bao_tri';

    protected $table = 'minihouse_rooms';

    protected $fillable = ['building_id', 'code', 'area', 'price', 'status', 'note', 'photos'];

    protected $casts = [
        'photos' => 'array',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'minihouse_room_amenity');
    }
}
