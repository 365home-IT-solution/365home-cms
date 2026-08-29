<?php

declare(strict_types=1);

namespace Modules\Product\App\Models;

use App\Models\Concerns\BelongsToPartner;
use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

class RoomAmenity extends Model
{
    use BelongsToPartner, LogsAuditTrail;

    protected $fillable = [
        'partner_id',
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
