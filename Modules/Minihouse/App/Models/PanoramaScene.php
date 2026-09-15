<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// 1 ảnh toàn cảnh 360° (equirectangular) đại diện 1 điểm đứng thật trong Toà nhà — xem giải thích
// đầy đủ ở migration create_minihouse_panorama_scenes_table.
class PanoramaScene extends Model
{
    protected $table = 'minihouse_panorama_scenes';

    protected $fillable = [
        'building_id', 'room_id', 'title', 'floor', 'image_path', 'thumbnail_path',
        'initial_yaw', 'initial_pitch', 'sort_order', 'is_published',
    ];

    protected $casts = [
        'initial_yaw'   => 'float',
        'initial_pitch' => 'float',
        'is_published'  => 'boolean',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    // NULL = điểm đứng chung (sảnh/hành lang/cầu thang), không gắn phòng cụ thể nào.
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    // Điểm nóng ĐI RA từ scene này (khách đang đứng đây, bấm để sang chỗ khác).
    public function hotspots(): HasMany
    {
        return $this->hasMany(PanoramaHotspot::class, 'scene_id')->orderBy('sort_order');
    }

    public function label(): string
    {
        return $this->room?->code ? $this->title . ' (' . $this->room->code . ')' : $this->title;
    }
}
