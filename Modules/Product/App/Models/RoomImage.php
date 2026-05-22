<?php

declare(strict_types=1);

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class RoomImage extends Model
{
    protected $fillable = [
        'room_id',
        'type',
        'disk',
        'path',
        'alt',
        'title',
        'sort_order',
    ];

    protected $casts = [
        'path' => 'array',
    ];

    public function room()
    {
        return $this->belongsTo(Product::class, 'room_id');
    }

    /** First image URL */
    public function getUrlAttribute(): string
    {
        $first = $this->firstImagePath();
        return $first ? Storage::disk($this->disk)->url($first) : '';
    }

    /** All sections for gallery type: [['title','description','images'=>[url,...]]] */
    public function getSectionsAttribute(): array
    {
        $data = $this->path;
        if (! is_array($data) || ! isset($data[0]['images'])) return [];

        $storage = Storage::disk($this->disk);
        return array_map(function ($section) use ($storage) {
            $section['images'] = array_map(
                fn ($p) => $storage->url($p),
                $section['images'] ?? []
            );
            return $section;
        }, $data);
    }

    private function firstImagePath(): string
    {
        $data = $this->path;
        if (! is_array($data)) return (string) $data;

        // Sections format (gallery)
        if (isset($data[0]['images'])) {
            return $data[0]['images'][0] ?? '';
        }

        // Flat array (main)
        return $data[0] ?? '';
    }
}
