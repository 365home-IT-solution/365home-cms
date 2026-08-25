<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ImagePresetUrls;
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
        'image_width',
        'image_height',
        'lat',
        'lng',
        'code',
        'division_type',
        'codename',
        'phone_code',
    ];

    protected $casts = [
        'lat'          => 'float',
        'lng'          => 'float',
        'code'         => 'integer',
        'phone_code'   => 'integer',
        'image_width'  => 'integer',
        'image_height' => 'integer',
    ];

    public function getThumbnailAttribute(): ?array
    {
        return ImagePresetUrls::build($this->image, 'public', $this->image_width, $this->image_height);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(ProvinceBranch::class);
    }

    public function wards(): HasMany
    {
        return $this->hasMany(Ward::class, 'province_code', 'code');
    }
}
