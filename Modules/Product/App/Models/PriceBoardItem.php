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
        'full_day_price',
        'bulk_discount_rules',
        'room_config',
        'deposit_1_night',
        'deposit_multi_night',
        'deposit_min_nights',
        'default_checkin',
        'default_checkout',
        'baseline_fields',
        'baseline_time_slots',
        'sheet_id',
        'weekday_prices',
    ];

    protected $casts = [
        'price'               => 'decimal:2',
        'full_day_price'      => 'decimal:2',
        'bulk_discount_rules' => 'array',
        'room_config'         => 'array',
        'baseline_fields'     => 'array',
        'baseline_time_slots' => 'array',
        'weekday_prices'      => 'array',
    ];

    public function sheet()
    {
        return $this->belongsTo(PriceBoardSheet::class, 'sheet_id');
    }

    /** Giá 1 đêm bắt đầu ngày $date: giá theo thứ (nếu có) hoặc một giá cho mọi ngày (price); null = không khai báo. */
    public function priceForDate(\Carbon\Carbon|string $date): ?float
    {
        $weekday = (array) $this->weekday_prices;

        if ($weekday !== []) {
            $day = \Carbon\Carbon::parse($date)->dayOfWeekIso;

            if (isset($weekday[$day]) && $weekday[$day] !== null && $weekday[$day] !== '') {
                return (float) $weekday[$day];
            }
        }

        return $this->price === null ? null : (float) $this->price;
    }

    public function priceBoard()
    {
        return $this->belongsTo(PriceBoard::class);
    }

    public function product()
    {
        // Lịch sử/tham chiếu tới phòng vẫn phải đọc được khi phòng mất chi nhánh (scope has_branch chỉ để ẩn khỏi danh sách).
        return $this->belongsTo(Product::class)->withoutGlobalScope('has_branch');
    }

    public function timeSlots()
    {
        return $this->hasMany(PriceBoardTimeSlot::class);
    }
}
