<?php

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GasPrice extends Model
{
    use HasFactory;

    protected $table = 'gas_prices';

    protected $fillable = [
        'date',
        'meta',
    ];

    protected $casts = [
        'date' => 'date',
        'meta' => 'array',
    ];
}