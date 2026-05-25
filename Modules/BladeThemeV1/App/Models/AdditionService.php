<?php

namespace Modules\BladeThemeV1\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class AdditionService extends Model
{
    use HasFactory;

    protected $table = 'additional_services';

    protected $fillable = [
        'name',
        'price',
        'image',
        'is_active',
    ];

    protected $casts = [
        'price'     => 'integer',
        'is_active' => 'boolean',
    ];

    public function products()
    {
        return $this->belongsToMany(
            \Modules\Product\App\Models\Product::class,
            'room_additional_service_assigns',
            'additional_service_id',
            'room_id'
        );
    }
}