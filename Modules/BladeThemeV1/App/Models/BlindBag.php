<?php

namespace Modules\BladeThemeV1\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BlindBag extends Model
{
    use HasFactory;

    protected $fillable = [
        'description'
    ];
}
