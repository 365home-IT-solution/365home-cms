<?php

namespace Modules\Promotion\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PromotionEligible extends Model
{
    use HasFactory;

    protected $fillable = [
        'promotion_id',
        'eligible_type',
        'eligible_id',
    ];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function eligible()
    {
        return $this->morphTo();
    }
}
