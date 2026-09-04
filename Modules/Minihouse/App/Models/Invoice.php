<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;

    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_PAID   = 'paid';

    protected $table = 'minihouse_invoices';

    protected $fillable = [
        'contract_id', 'month', 'room_price',
        'electric_start', 'electric_end', 'electric_unit_price', 'electric_amount',
        'water_start', 'water_end', 'water_unit_price', 'water_amount',
        'service_amount', 'total_amount', 'status',
    ];

    protected $casts = [
        'month' => 'date',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
