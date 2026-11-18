<?php

namespace Modules\VehicleRental\App\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleBooking extends Model
{
    protected $table = 'rental_vehicle_bookings';

    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PICKED_UP = 'picked_up';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';

    /** Trạng thái đang giữ xe (chặn đặt trùng). returned/settled/cancelled/no_show đã nhả xe. */
    public const BLOCKING_STATUSES = [
        self::STATUS_PENDING_PAYMENT,
        self::STATUS_CONFIRMED,
        self::STATUS_PICKED_UP,
    ];

    protected $fillable = [
        'code', 'vehicle_id', 'customer_id', 'room_order_id', 'pickup_branch_id', 'return_branch_id',
        'pickup_source', 'pickup_address', 'pickup_latitude', 'pickup_longitude', 'pickup_located_at',
        'start_at', 'end_at', 'actual_start_at', 'actual_end_at', 'status', 'hold_expires_at',
        'rental_amount', 'discount_amount', 'extra_amount', 'deposit_amount', 'total_amount', 'paid_amount',
        'pricing_snapshot', 'cancelled_at', 'cancel_reason',
        'payos_order_code', 'payos_checkout_url', 'payos_qr_code', 'payos_expires_at', 'paid_at', 'refund_amount', 'refunded_at',
        'deposit_collected_amount', 'deposit_collected_at', 'deposit_deducted_amount', 'deposit_refund_amount',
        'extra_due_amount', 'km_driven', 'settled_at',
        'pickup_reminded_at', 'return_reminded_at', 'overdue_notified_at',
        'gps_km_driven', 'gps_km_discrepancy_pct',
    ];

    /** cancel_reason của đơn tự huỷ do hết hạn giữ chỗ — chỉ loại này mới được "sống lại" khi tiền về muộn. */
    public const CANCEL_HOLD_EXPIRED = 'hold_expired';

    protected $casts = [
        'start_at'         => 'datetime',
        'end_at'           => 'datetime',
        'actual_start_at'  => 'datetime',
        'actual_end_at'    => 'datetime',
        'hold_expires_at'  => 'datetime',
        'pickup_located_at' => 'datetime',
        'pickup_latitude'  => 'float',
        'pickup_longitude' => 'float',
        'cancelled_at'     => 'datetime',
        'pricing_snapshot' => 'array',
        'rental_amount'    => 'integer',
        'discount_amount'  => 'integer',
        'extra_amount'     => 'integer',
        'deposit_amount'   => 'integer',
        'total_amount'     => 'integer',
        'paid_amount'      => 'integer',
        'refund_amount'    => 'integer',
        'payos_expires_at' => 'datetime',
        'paid_at'          => 'datetime',
        'refunded_at'      => 'datetime',
        'deposit_collected_amount' => 'integer',
        'deposit_collected_at'     => 'datetime',
        'deposit_deducted_amount'  => 'integer',
        'deposit_refund_amount'    => 'integer',
        'extra_due_amount'         => 'integer',
        'km_driven'                => 'integer',
        'settled_at'               => 'datetime',
        'pickup_reminded_at'       => 'datetime',
        'return_reminded_at'       => 'datetime',
        'overdue_notified_at'      => 'datetime',
        'gps_km_driven'            => 'float',
        'gps_km_discrepancy_pct'   => 'float',
    ];

    /** Lý do huỷ để HIỂN THỊ (mã nội bộ hold_expired không được lộ ra cho khách/nhân viên). */
    public function cancelReasonLabel(): ?string
    {
        return $this->cancel_reason === self::CANCEL_HOLD_EXPIRED ? 'Hết hạn giữ chỗ (chưa thanh toán)' : $this->cancel_reason;
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id')->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    /** Đơn phòng kèm theo (nếu thuê xe kèm phòng). */
    public function roomOrder(): BelongsTo
    {
        return $this->belongsTo(\Modules\Payment\Entities\Order::class, 'room_order_id')->withoutGlobalScopes();
    }

    public function handovers():\Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VehicleHandover::class, 'booking_id');
    }

    public function charges(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VehicleCharge::class, 'booking_id')->orderBy('id');
    }

    public function handover(string $type): ?VehicleHandover
    {
        return $this->handovers()->where('type', $type)->first();
    }
}
