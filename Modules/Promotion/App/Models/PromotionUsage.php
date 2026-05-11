<?php

namespace Modules\Promotion\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PromotionUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'promotion_id',
        'user_id',
        'orderable_id',
        'orderable_type',
        'used_at',
    ];

    protected $casts = [
        'used_at' => 'datetime',
    ];

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function orderable()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
