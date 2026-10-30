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
        'holiday_prices',
        'weekday_adjustments',
    ];

    protected $casts = [
        'price'               => 'decimal:2',
        'full_day_price'      => 'decimal:2',
        'bulk_discount_rules' => 'array',
        'room_config'         => 'array',
        'baseline_fields'     => 'array',
        'baseline_time_slots' => 'array',
        'weekday_prices'      => 'array',
        'holiday_prices'      => 'array',
        'weekday_adjustments' => 'array',
    ];

    public function sheet()
    {
        return $this->belongsTo(PriceBoardSheet::class, 'sheet_id');
    }

    /**
     * Giá 1 đêm bắt đầu ngày $date, tính tuần tự:
     *  1. giá nền: giá riêng của thứ đó (weekday_prices) hoặc một giá cho mọi ngày (price) + tăng/giảm theo thứ (weekday_adjustments);
     *  2. ngày thuộc 1 dòng lễ: cố định thì thay hẳn, % / số tiền thì tính TRÊN giá nền của đúng ngày đó (lễ rơi vào cuối tuần → tính trên giá cuối tuần).
     * null = không khai báo giá.
     */
    public function priceForDate(\Carbon\Carbon|string $date): ?float
    {
        $holiday = \App\Support\HolidayPrices::match($this->holiday_prices, $date);

        if ($holiday !== null && $holiday['mode'] === 'price') {
            return \App\Support\HolidayPrices::apply($holiday, 0.0);
        }

        $base    = null;
        $weekday = (array) $this->weekday_prices;

        if ($weekday !== []) {
            $day = \Carbon\Carbon::parse($date)->dayOfWeekIso;

            if (isset($weekday[$day]) && $weekday[$day] !== null && $weekday[$day] !== '') {
                $base = (float) $weekday[$day];
            }
        }

        if ($base === null && $this->price !== null) {
            $base = \App\Support\WeekdayAdjustments::apply($this->weekday_adjustments, $date, (float) $this->price);
        }

        if ($base === null) {
            return null;
        }

        return $holiday !== null ? \App\Support\HolidayPrices::apply($holiday, $base) : $base;
    }

    /**
     * Giá 1 khung giờ (phòng theo giờ) sau khi tăng/giảm theo thứ rồi theo ngày lễ — cùng thứ tự với priceForDate().
     * Dòng lễ "giá cố định" không có nghĩa với khung giờ nên bị bỏ qua.
     */
    public function adjustSlotPrice(float $base, \Carbon\Carbon|string $date): float
    {
        $price   = \App\Support\WeekdayAdjustments::apply($this->weekday_adjustments, $date, $base);
        $holiday = \App\Support\HolidayPrices::match($this->holiday_prices, $date);

        return $holiday !== null && $holiday['mode'] !== 'price' ? \App\Support\HolidayPrices::apply($holiday, $price) : $price;
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
