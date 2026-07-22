<?php

declare(strict_types=1);

namespace Modules\Employee\Entities;

use App\Models\Concerns\BelongsToPartner;
use Illuminate\Database\Eloquent\Model;

class DeductionType extends Model
{
    use BelongsToPartner;

    protected $fillable = [
        'partner_id',
        'name',
        'slug',
        'calc_type',
        'default_amount',
        'description',
        'status',
    ];

    protected $casts = [
        'default_amount' => 'decimal:2',
        'status'         => 'boolean',
    ];
}
