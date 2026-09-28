<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Tài khoản TTLock của TỪNG Toà nhà (khoá chính = building_id) — xem migration
// create_minihouse_ttlock_settings_table. Dùng bởi TTLockService::forBuilding().
class TtlockSetting extends Model
{
    use Concerns\LogsMinihouseActivity;

    protected $table = 'minihouse_ttlock_settings';

    protected $primaryKey = 'building_id';

    protected $keyType = 'int';

    public $incrementing = false;

    protected $fillable = ['building_id', 'client_id', 'client_secret', 'username', 'password_md5', 'api_base', 'is_active'];

    protected $casts = [
        'client_secret' => 'encrypted',
        'password_md5'  => 'encrypted',
        'is_active'     => 'boolean',
    ];

    public static function forBuilding(?int $buildingId): self
    {
        if (blank($buildingId)) {
            return new self();
        }

        return static::query()->find($buildingId) ?? new self(['building_id' => $buildingId, 'api_base' => 'https://euapi.ttlock.com']);
    }

    public function isConfigured(): bool
    {
        return filled($this->client_id) && filled($this->client_secret) && filled($this->username) && filled($this->password_md5);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class, 'building_id');
    }
}
