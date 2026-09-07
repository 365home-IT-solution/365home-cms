<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingId;

class Surcharge extends Model
{
    use SoftDeletes;
    use ScopedToActiveBuildingId;
    use LogsMinihouseActivity;

    protected $table = 'minihouse_surcharges';

    protected $fillable = ['building_id', 'name', 'amount', 'note', 'is_active'];

    // float — 'decimal:2' luôn ép hiện đủ 2 số lẻ dù giá trị là số nguyên.
    protected $casts = [
        'amount'    => 'float',
        'is_active' => 'boolean',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }
}
