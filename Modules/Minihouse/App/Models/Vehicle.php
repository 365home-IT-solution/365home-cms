<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingId;

// Xe của khách thuê — xem migration create_minihouse_vehicles_tables và VehicleService (chuẩn hoá biển
// số, giới hạn, phí gửi xe). status: pending (khách tự khai, chờ nhân viên duyệt) → active (đang gửi) →
// inactive (đã ngưng), hoặc rejected (từ chối).
class Vehicle extends Model
{
    use SoftDeletes;
    use ScopedToActiveBuildingId;
    use LogsMinihouseActivity;

    public const TYPE_MOTORBIKE = 'motorbike';
    public const TYPE_CAR       = 'car';

    public const TYPES = [
        self::TYPE_MOTORBIKE => 'Xe máy',
        self::TYPE_CAR       => 'Ô tô',
    ];

    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING  => 'Chờ duyệt',
        self::STATUS_ACTIVE   => 'Đang gửi',
        self::STATUS_INACTIVE => 'Đã ngưng',
        self::STATUS_REJECTED => 'Từ chối',
    ];

    protected $table = 'minihouse_vehicles';

    protected $fillable = [
        'building_id', 'contract_id', 'tenant_id', 'plate', 'plate_display', 'vehicle_type', 'name', 'document_photo',
        'status', 'start_date', 'end_date', 'requested_by', 'approved_by', 'approved_at', 'reject_reason',
    ];

    protected $casts = [
        'start_date'  => 'date',
        'end_date'    => 'date',
        'approved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Luôn giữ plate (đã chuẩn hoá) đồng bộ với plate_display người nhập.
        static::saving(function (Vehicle $vehicle) {
            if (filled($vehicle->plate_display)) {
                $vehicle->plate_display = mb_strtoupper(trim($vehicle->plate_display));
                $vehicle->plate         = \Modules\Minihouse\App\Services\VehicleService::normalizePlate($vehicle->plate_display);
            }

            // Đổi trạng thái ngay trên form (tab Phương tiện của hợp đồng): duyệt → đang gửi thì ghi ngày bắt đầu
            // + người duyệt; ngưng → ghi ngày ngưng. Đặt lại ngày khi quay về đang gửi.
            if ($vehicle->isDirty('status')) {
                if ($vehicle->status === self::STATUS_ACTIVE) {
                    $vehicle->start_date  = $vehicle->start_date ?? now()->toDateString();
                    $vehicle->end_date    = null;
                    $vehicle->approved_at = $vehicle->approved_at ?? now();
                    $vehicle->approved_by = $vehicle->approved_by ?? (auth()->user() instanceof \App\Models\User ? auth()->id() : null);
                } elseif ($vehicle->status === self::STATUS_INACTIVE) {
                    $vehicle->end_date = $vehicle->end_date ?? now()->toDateString();
                }
            }
        });
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class, 'building_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->vehicle_type] ?? (string) $this->vehicle_type;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }

    public function documentPhotoUrl(): ?string
    {
        return $this->document_photo ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->document_photo) : null;
    }

    protected function activityLabel(): string
    {
        return trim($this->plate_display . ($this->name ? ' — ' . $this->name : ''));
    }
}
