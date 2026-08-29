<?php

namespace Modules\Product\App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PriceBoardItem extends Model
{
    use HasFactory, LogsAuditTrail;

    // PriceBoardSyncService::saveProductIds()/saveOverrideItems() dùng updateOrCreate()/->delete()
    // TRÊN TỪNG MODEL (không phải query builder hàng loạt) nên Eloquent event vẫn bắn bình thường —
    // khác với các chỗ "Loại 2" khác (RoomAmenityAssign...) phải tự ghi log thủ công. Không có field
    // "name" nên phải tự ghép nhãn dễ đọc từ 2 quan hệ liên quan.
    protected static function auditModuleName(): string
    {
        return 'PriceBoard';
    }

    protected function auditLabel(): string
    {
        $boardName = $this->priceBoard?->name ?? ('#' . $this->price_board_id);
        $roomName  = $this->product?->name ?? ('#' . $this->product_id);

        return "Bảng giá {$boardName} — Phòng {$roomName}";
    }

    protected $fillable = [
        'price_board_id',
        'product_id',
        'price',
        'price_unit',
        'full_booking_discount',
        'bulk_discount_rules',
        'room_config',
        'deposit_1_night',
        'deposit_multi_night',
        'deposit_min_nights',
        'default_checkin',
        'default_checkout',
    ];

    protected $casts = [
        'price'               => 'decimal:2',
        'bulk_discount_rules' => 'array',
        'room_config'         => 'array',
    ];

    public function priceBoard()
    {
        return $this->belongsTo(PriceBoard::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function timeSlots()
    {
        return $this->hasMany(PriceBoardTimeSlot::class);
    }
}
