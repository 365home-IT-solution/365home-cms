<?php

declare(strict_types=1);

namespace Modules\Product\App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

class RoomService extends Model
{
    use LogsAuditTrail;

    protected $fillable = [
        'product_id',
        'name',
        'icon',
        'description',
        'price',
        'unit',
        'sort_order',
    ];

    protected $casts = [
        'price'      => 'integer',
        'sort_order' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
