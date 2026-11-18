<?php

namespace Modules\VehicleRental\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phụ phí phát sinh sau chuyến (trễ giờ, km vượt, thiếu xăng, vệ sinh, hư hỏng...). Trừ vào cọc thế chấp khi tất toán. */
class VehicleCharge extends Model
{
    protected $table = 'rental_vehicle_charges';

    public const TYPES = [
        'late'     => 'Trả trễ giờ',
        'km'       => 'Vượt km',
        'fuel'     => 'Thiếu xăng/pin',
        'cleaning' => 'Vệ sinh',
        'damage'   => 'Hư hỏng',
        'other'    => 'Khác',
    ];

    protected $fillable = ['booking_id', 'type', 'label', 'amount', 'note', 'photos', 'created_by'];

    protected $casts = [
        'photos' => 'array',
        'amount' => 'integer',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(VehicleBooking::class, 'booking_id');
    }
}
