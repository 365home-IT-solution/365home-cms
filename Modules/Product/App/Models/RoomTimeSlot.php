<?php

namespace Modules\Product\App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Promotion\App\Models\Promotion;

class RoomTimeSlot extends Model
{
    use HasFactory, LogsAuditTrail;

    protected $fillable = [
        'room_id',
        'timeslot_id',
        'price',
        'checkin',
        'checkout',
        'status',
        'over_night',
        'settings',
        'synced_from_price_board_id',
    ];

    protected $casts = [
        'date'     => 'date',
        'settings' => 'array', // ← Fix: đổi từ 'setting' → 'settings', json → array
    ];

    // Đối tác sửa giá / giờ của khung giờ thuộc phòng ĐÃ duyệt: giữ bản đang chạy, bản sửa vào yêu cầu "Sửa phòng" chờ duyệt (xem App\Services\ApprovalService).
    protected static function booted(): void
    {
        static::updating(function (RoomTimeSlot $slot) {
            $gate = app(\App\Services\ApprovalService::class);

            if (! $gate->guards()) {
                return;
            }

            $room = Product::withoutGlobalScopes()->find($slot->room_id);

            if (! $room || ($room->approval_status ?? 'approved') !== 'approved') {
                return;
            }

            $changes = [];

            foreach (array_keys(\App\Services\ApprovalService::SLOT_FIELDS) as $field) {
                if ($slot->isDirty($field)) {
                    $changes[$field] = ['old' => $slot->getOriginal($field), 'new' => $slot->getAttribute($field)];
                    $slot->setAttribute($field, $slot->getOriginal($field));
                }
            }

            if ($changes) {
                $gate->mergeRoomChanges($room, [], [$slot->id => $changes], auth()->user());
            }
        });
    }

    public function timeSlot()
    {
        return $this->belongsTo(TimeSlot::class, 'timeslot_id');
    }

    public function promotions()
    {
        return $this->belongsToMany(
            Promotion::class,
            'promotion_room_time_slot',
            'room_time_slot_id',
            'promotion_id'
        )->withPivot(['min_quantity', 'custom_value'])->withTimestamps();
    }

    public function room()
    {
        return $this->belongsTo(Product::class, 'room_id');
    }

    // RoomTimeSlot không có field "name" nên auditLabel() mặc định của LogsAuditTrail chỉ ra được
    // "#<id>" — không đủ để biết đang nói về khung giờ của phòng nào. Ghép rõ tên phòng + nhãn
    // khung giờ (TimeSlot::label, vd "7h-9h") để trang "Lịch sử thao tác" đọc hiểu ngay không cần
    // tra thêm.
    protected function auditLabel(): string
    {
        $roomName  = $this->room?->name ?? ('#' . $this->room_id);
        $slotLabel = $this->timeSlot?->label ?? ('#' . $this->timeslot_id);

        return "Phòng {$roomName} — Khung giờ {$slotLabel}";
    }

    public function coupons()
    {
        return $this->belongsToMany(
            \Modules\Promotion\App\Models\Coupon::class,
            'coupon_room_time_slot',
            'room_time_slot_id',
            'coupon_id'
        );
    }

    /**
     * Giá của khung này vào ngày ở $date. Giai đoạn 2 (config price_boards.phase2): chọn bảng giá thắng
     * của ĐÚNG ngày đó (App\Services\PriceBoards\StayPriceResolver) thay vì giá hiện tại; tắt cờ hoặc
     * khung "ngày đặc biệt" (date != null, giá đã chốt theo ngày) thì trả đúng cột price như trước.
     */
    public function priceOn(\Carbon\Carbon|string|null $date): ?int
    {
        if ($this->price === null) {
            return null;
        }

        if ($date === null || $date === '' || $this->date !== null || ! PriceBoard::phase2Enabled()) {
            return (int) $this->price;
        }

        return app(\App\Services\PriceBoards\StayPriceResolver::class)->slotPrice($this, $date);
    }

    /**
     * Kiểm tra khung giờ có bị block vào 1 ngày cụ thể không
     */
    public function isBlockedOn(string $date): bool
    {
        $blocked = $this->settings['blocked_dates'] ?? [];
        return in_array($date, $blocked);
    }

    /**
     * Lấy tất cả ngày bị block
     */
    public function getBlockedDates(): array
    {
        return $this->settings['blocked_dates'] ?? [];
    }
}