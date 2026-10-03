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

    // Mã cổng TRÙNG mã phòng (khách nhớ 1 số) hay là 1 số RIÊNG — xem ContractTtlockService.
    public const GATE_CODE_SHARED   = 'shared';
    public const GATE_CODE_SEPARATE = 'separate';

    // Cấp mã ngay khi tạo hợp đồng, hay chỉ khi đã thu cọc HOẶC đã thanh toán hoá đơn đầu tiên.
    public const ISSUE_ON_CONTRACT = 'contract';
    public const ISSUE_ON_PAYMENT  = 'payment';

    protected $fillable = [
        'building_id', 'client_id', 'client_secret', 'username', 'password_md5', 'api_base', 'is_active',
        'gate_lock_ids', 'gate_code_mode', 'issue_mode',
    ];

    protected $casts = [
        'client_secret' => 'encrypted',
        'password_md5'  => 'encrypted',
        'is_active'     => 'boolean',
        'gate_lock_ids' => 'array',
    ];

    /** @return list<int> */
    public function gateLockIds(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $this->gate_lock_ids ?? []))));
    }

    public function usesSeparateGateCode(): bool
    {
        return $this->gate_code_mode === self::GATE_CODE_SEPARATE;
    }

    public function issuesOnPayment(): bool
    {
        return $this->issue_mode === self::ISSUE_ON_PAYMENT;
    }

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
