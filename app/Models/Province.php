<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'image',
        'lat',
        'lng',
        'code',
        'division_type',
        'codename',
        'phone_code',
    ];

    protected $casts = [
        'lat'        => 'float',
        'lng'        => 'float',
        'code'       => 'integer',
        'phone_code' => 'integer',
    ];

    public function branches(): HasMany
    {
        return $this->hasMany(ProvinceBranch::class);
    }

    public function wards(): HasMany
    {
        return $this->hasMany(Ward::class, 'province_code', 'code');
    }
}
