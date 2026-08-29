<?php

declare(strict_types=1);

namespace Modules\Product\App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

class RoomSpecial extends Model
{
    use LogsAuditTrail;

    protected $fillable = [
        'product_id',
        'icon',
        'title',
        'short_description',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
