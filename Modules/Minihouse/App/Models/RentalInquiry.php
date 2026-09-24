<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// "Yêu cầu liên hệ thuê phòng" gửi từ trang công khai (chưa đăng nhập) — xem giải thích đầy đủ ở
// migration create_minihouse_rental_inquiries_table. CHỈ là 1 lead để nhân viên gọi lại tư vấn,
// không tự tạo Tenant/Contract nào — nhân viên tự thao tác tiếp qua panel nếu chốt thuê.
class RentalInquiry extends Model
{
    protected $table = 'minihouse_rental_inquiries';

    public const STATUS_NEW       = 'new';
    public const STATUS_CONTACTED = 'contacted';
    public const STATUS_CLOSED    = 'closed';

    public const STATUSES = [
        self::STATUS_NEW       => 'Chưa liên hệ',
        self::STATUS_CONTACTED => 'Đã liên hệ',
        self::STATUS_CLOSED    => 'Đã xử lý xong',
    ];

    protected $fillable = [
        'room_id', 'building_id', 'full_name', 'phone', 'email', 'note',
        'preferred_move_in_date', 'status', 'staff_note',
    ];

    protected $casts = [
        'preferred_move_in_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (RentalInquiry $inquiry) {
            $inquiry->status ??= self::STATUS_NEW;
        });
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class, 'building_id');
    }
}
