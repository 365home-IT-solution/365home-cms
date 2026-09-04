<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Room extends Model
{
    use SoftDeletes;

    public const STATUS_EMPTY   = 'trong';
    public const STATUS_RENTED  = 'dang_thue';
    public const STATUS_REPAIR  = 'bao_tri';

    protected $table = 'minihouse_rooms';

    protected $fillable = ['building_id', 'code', 'area', 'price', 'status', 'note'];

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
}
