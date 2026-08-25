<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Models;

use App\Models\Concerns\BelongsToPartner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseCategory extends Model
{
    use BelongsToPartner;

    protected $fillable = [
        'partner_id',
        'name',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(WarehouseItem::class, 'warehouse_category_id');
    }
}
