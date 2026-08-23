<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Event extends Model
{
    protected $fillable = [
        'title',
        'description',
        'image',
        'disk',
        'image_width',
        'image_height',
        'is_active',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'sort_order'   => 'integer',
        'image_width'  => 'integer',
        'image_height' => 'integer',
    ];

    public function getImageUrlAttribute(): ?string
    {
        if (empty($this->image)) {
            return null;
        }

        return Storage::disk($this->disk ?? 'public')->url($this->image);
    }
}
