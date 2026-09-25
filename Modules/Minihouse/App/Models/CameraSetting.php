<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Models;

use App\Models\CameraSetting as BaseCameraSetting;

// Cấu hình server go2rtc/Frigate THEO TỪNG TOÀ NHÀ (khoá chính = building_id) — kế thừa
// App\Models\CameraSetting để dùng lại nguyên vẹn casts encrypted + isConfigured()/
// hasFrigateCredentials(), chỉ đổi bảng/khoá chính/forXxx(). Xem migration
// create_minihouse_camera_settings_table và Modules\Minihouse\App\Models\Camera::resolveCameraSettings().
class CameraSetting extends BaseCameraSetting
{
    protected $table = 'minihouse_camera_settings';

    protected $primaryKey = 'building_id';

    protected $keyType = 'int';

    protected $fillable = ['building_id', 'base_url', 'api_key', 'username', 'password'];

    public static function forBuilding(?int $buildingId): self
    {
        if (blank($buildingId)) {
            return new self();
        }

        return static::query()->find($buildingId) ?? new self(['building_id' => $buildingId]);
    }

    public function building(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Building::class, 'building_id');
    }
}
