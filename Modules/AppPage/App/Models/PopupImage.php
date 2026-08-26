<?php

declare(strict_types=1);

namespace Modules\AppPage\App\Models;

use App\Support\ImagePresetUrls;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PopupImage extends Model
{
    protected $fillable = [
        'image',
        'disk',
        'image_width',
        'image_height',
        'url',
        'sort_order',
    ];

    protected $casts = [
        'sort_order'   => 'integer',
        'image_width'  => 'integer',
        'image_height' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('sort_order', fn ($query) => $query->orderBy('sort_order'));
    }

    public function getImageUrlAttribute(): ?string
    {
        if (empty($this->image)) {
            return null;
        }

        return Storage::disk($this->disk ?? 'public')->url($this->image);
    }

    public function getThumbnailAttribute(): ?array
    {
        return ImagePresetUrls::build($this->image, $this->disk ?? 'public', $this->image_width, $this->image_height);
    }
}
