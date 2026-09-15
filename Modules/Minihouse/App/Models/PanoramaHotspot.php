<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Xem giải thích đầy đủ ở migration create_minihouse_panorama_hotspots_table.
class PanoramaHotspot extends Model
{
    protected $table = 'minihouse_panorama_hotspots';

    protected $fillable = ['scene_id', 'target_scene_id', 'yaw', 'pitch', 'label', 'sort_order'];

    protected $casts = [
        'yaw'   => 'float',
        'pitch' => 'float',
    ];

    public function scene(): BelongsTo
    {
        return $this->belongsTo(PanoramaScene::class, 'scene_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(PanoramaScene::class, 'target_scene_id');
    }
}
