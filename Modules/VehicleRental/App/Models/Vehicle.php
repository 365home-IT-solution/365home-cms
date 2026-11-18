<?php

namespace Modules\VehicleRental\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Category\Entities\Category;

class Vehicle extends Model
{
    use SoftDeletes;

    protected $table = 'rental_vehicles';

    public const TYPE_MOTORBIKE = 'motorbike';
    public const TYPE_CAR = 'car';

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'branch_id', 'type', 'brand', 'model', 'year', 'plate', 'plate_display', 'seats', 'transmission', 'fuel',
        'color', 'image', 'photos', 'description', 'status', 'odometer_km', 'deposit_amount',
        'registration_expires_on', 'insurance_expires_on', 'gps_device_id', 'sort_order',
    ];

    protected $casts = [
        'photos'                  => 'array',
        'year'                    => 'integer',
        'seats'                   => 'integer',
        'odometer_km'             => 'integer',
        'deposit_amount'          => 'integer',
        'registration_expires_on' => 'date',
        'insurance_expires_on'    => 'date',
    ];

    /** Biển số lưu dạng chuẩn hoá (chữ hoa, bỏ ký tự phân cách) để chặn trùng dù nhập "30A-123.45" hay "30a12345". */
    public static function normalizePlate(string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plate));
    }

    public function setPlateDisplayAttribute(string $value): void
    {
        $this->attributes['plate_display'] = trim($value);
        $this->attributes['plate'] = self::normalizePlate($value);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'branch_id')->withoutGlobalScopes();
    }

    public function ratePlans(): HasMany
    {
        return $this->hasMany(VehicleRatePlan::class, 'vehicle_id')->orderBy('sort_order')->orderBy('duration_hours');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(VehicleBooking::class, 'vehicle_id');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(VehicleBlock::class, 'vehicle_id');
    }

    /** Xe đang MỞ cho thuê (khách tìm và đặt được). Bảo dưỡng / ngừng cho thuê = đóng. */
    public function isRentable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }

    /** Nút bật/tắt nhanh: bật = sẵn sàng; tắt = ngừng cho thuê. Đơn đã đặt trước đó không bị ảnh hưởng, chỉ chặn đặt mới. */
    public function setRentable(bool $on): void
    {
        $this->update(['status' => $on ? self::STATUS_AVAILABLE : self::STATUS_INACTIVE]);
    }

    /** Số đơn sắp tới còn đang giữ xe (để cảnh báo khi tắt xe). */
    public function upcomingBookingsCount(): int
    {
        return $this->bookings()->whereIn('status', VehicleBooking::BLOCKING_STATUSES)->where('end_at', '>=', now())->count();
    }
}
