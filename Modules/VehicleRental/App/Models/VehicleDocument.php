<?php

namespace Modules\VehicleRental\App\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Giấy tờ (KYC) của khách thuê xe: CCCD + GPLX + ảnh chân dung. Mỗi khách 1 bộ, dùng lại cho mọi đơn khi còn hiệu lực. */
class VehicleDocument extends Model
{
    protected $table = 'rental_vehicle_documents';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const IMAGE_FIELDS = ['id_front', 'id_back', 'license_front', 'license_back', 'selfie'];

    protected $fillable = [
        'customer_id', 'full_name', 'id_number', 'license_number', 'license_class', 'license_expires_on',
        'id_front', 'id_back', 'license_front', 'license_back', 'selfie',
        'status', 'reject_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'license_expires_on' => 'date',
        'reviewed_at'        => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
