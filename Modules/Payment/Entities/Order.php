<?php

namespace Modules\Payment\Entities;

use App\Models\Concerns\BelongsToPartner;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\AccessCode\Entities\AccessCode;
use Modules\Category\Entities\Category;
use Modules\Zns\App\Models\ZnsNotification;
use Guava\Calendar\Contracts\Eventable;
use Guava\Calendar\ValueObjects\CalendarEvent;
use Modules\Payment\App\Models\OrderService;
class Order extends Model implements Eventable
{
    use HasFactory, BelongsToPartner;

    protected $fillable = [
        'partner_id',
        'created_by',
        'order_code',
        'amount',
        'surcharge',
        'full_amount',
        'deposit_percent',
        'deposit_paid_amount',
        'coupon_code',
        'coupon_codes',
        'deposit_paid_at',
        'paid_at',
        'remaining_paid_at',
        'remaining_payment_method',
        'remaining_payos_code',
        'remaining_checkout_url',
        'current_payos_code',
        'description',
        'short_description',
        'buyer_name',
        'buyer_email',
        'buyer_phone',
        'buyer_address',
        'ward_name',
        'district_name',
        'province_name',
        'payment_method',
        'shipping_method',
        'is_freeship',
        'expired_at',
        'checkout_url',
        'status',
        'note_for_admin',
        'deposit_room',
        'cccd_front',
        'cccd_back',
        'cccd_front_2',
        'cccd_back_2',
        'cccd_data',
        'cccd_data_2',
        'buyer_phone_2',
        // Khách thứ 2 cùng lưu trú qua đêm (Luật Cư trú yêu cầu khai báo đủ từng người khi ở qua
        // đêm 2 khách) — xem CccdDeclarationService.
        'cccd_front_2',
        'cccd_back_2',
        'cccd_data_2',
        'buyer_phone_2',
        'guest_count',
        'category_id',
        'user_id',
        'money_deposit',
        'exclude_from_stats',
        'unlock_anytime',
        'checked_in_at',
        'checked_out_at',
        'order_status',
        'customer_id',
        'qr_code',
        'remaining_qr_code',
        'device_token',
        'extra_charge_amount',
        'extra_charge_payos_code',
        'extra_charge_checkout_url',
        'extra_charge_qr_code',
        'extra_charge_payment_method',
        'extra_charge_paid_at',
        'extra_charge_expired_at',
        'extra_refund_amount',
        'extra_refund_method',
        'extra_refund_paid_at',
        'settled_adjustment_total',
    ];

    protected $casts = [
        'cccd_data'              => 'array',
        'cccd_data_2'            => 'array',
        'coupon_codes'           => 'array',
        'deposit_paid_at'        => 'datetime',
        'paid_at'                => 'datetime',
        'remaining_paid_at'      => 'datetime',
        'extra_charge_paid_at'    => 'datetime',
        'extra_charge_expired_at' => 'datetime',
        'extra_refund_paid_at'    => 'datetime',
        'exclude_from_stats'     => 'boolean',
        'unlock_anytime'         => 'boolean',
        'checked_in_at'          => 'datetime',
        'checked_out_at'         => 'datetime',
    ];
    protected $with = ['items'];
    protected $appends = ['payment_status'];

    // payment_status: trạng thái thanh toán (chưa trả/cọc/trả đủ/hoàn tiền), suy ra từ cột `status`.
    public const PAYMENT_STATUS_LABELS = [
        'unpaid'   => 'Chưa trả',
        'deposit'  => 'Đã đặt cọc',
        'paid'     => 'Đã trả đủ',
        'refunded' => 'Đã hoàn tiền',
    ];

    // order_status: vòng đời đơn (chờ → nhận phòng → đang ở → trả phòng).
    public const ORDER_STATUS_LABELS = [
        'pending'     => 'Chờ nhận phòng',
        'checked_in'  => 'Đã nhận phòng',
        'staying'     => 'Đang ở',
        'checked_out' => 'Đã trả phòng',
    ];

    private const PAYMENT_STATUS_MAP = [
        'pending'           => 'unpaid',
        'failed'            => 'unpaid',
        'cancelled_payment' => 'unpaid',
        'deposit'           => 'deposit',
        'paid'              => 'paid',
        'refunded'          => 'refunded',
    ];

    public function getPaymentStatusAttribute(): string
    {
        return self::PAYMENT_STATUS_MAP[$this->attributes['status'] ?? 'pending'] ?? 'unpaid';
    }

    public function getPaymentStatusLabelAttribute(): string
    {
        return self::PAYMENT_STATUS_LABELS[$this->payment_status] ?? self::PAYMENT_STATUS_LABELS['unpaid'];
    }

    public function getOrderStatusLabelAttribute(): string
    {
        return self::ORDER_STATUS_LABELS[$this->order_status ?? 'pending'] ?? self::ORDER_STATUS_LABELS['pending'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            if (empty($order->order_code)) {
                do {
                    $code = intval(substr(strval(microtime(true) * 10000), -6));
                } while (self::where('order_code', $code)->exists());
                $order->order_code = (string) $code;
            }

            if (empty($order->user_id) && auth()->check()) {
                $order->user_id = (string) auth()->id();
            }
        });

        // order_items KHÔNG có FK cascade (chỉ unsignedBigInteger + index, không ràng buộc khoá
        // ngoại) — xoá Order trước đây chỉ xoá đúng 1 dòng orders, để lại order_items MỒ CÔI
        // trong DB. Vì khung giờ/ngày đã đặt của phòng được TÍNH TỪ order_items còn tồn tại (không
        // phải từ 1 bảng "slot" riêng), các order_item mồ côi này vẫn khiến phòng hiện "đã đặt",
        // không thể tạo lại đúng khung giờ đó dù đơn đã bị xoá. Phải tự tay dọn khi Order bị xoá.
        static::deleting(function (self $order): void {
            // Chụp lại (ngày, timeslot_id)/khoảng ngày của items TRƯỚC khi xoá — dùng để bắn
            // realtime "giải phóng" NGAY sau khi xoá xong, để khách đang mở book.blade.php/
            // product-detail.blade.php của đúng phòng này thấy khung giờ trống lại tức thì thay
            // vì phải đợi tải lại trang (trước đây xoá đơn không bắn gì cả — ĐÚNG cách
            // App\Http\Controllers\Api\Admin\OrderController::updateOrder() đã làm cho luồng SỬA
            // đơn, áp dụng lại cho luồng XOÁ ở đây).
            $itemsByProduct = $order->items()->whereNotNull('product_id')->get()->groupBy('product_id');

            $order->items()->delete();
            $order->services()->delete();

            if ($itemsByProduct->isEmpty()) {
                return;
            }

            $realtimeService = app(\App\Services\SlotRealtimeService::class);

            foreach ($itemsByProduct as $productId => $items) {
                $product = \Modules\Product\App\Models\Product::find($productId);
                if (! $product) {
                    continue;
                }

                if ((int) $product->styles === 1) {
                    // Phòng "khung giờ" — order_items không lưu timeslot_id trực tiếp nên suy
                    // ngược qua giờ bắt đầu (checkin_date) khớp với RoomTimeSlot lặp lại (date IS
                    // NULL) của phòng, best-effort cho mục đích hiển thị realtime.
                    $recurringSlotsByStartTime = $product->roomTimeSlots()
                        ->whereNull('date')
                        ->with('timeSlot')
                        ->get()
                        ->filter(fn ($rts) => $rts->timeSlot)
                        ->keyBy(fn ($rts) => $rts->timeSlot->start_time);

                    $items
                        ->filter(fn ($item) => $item->checkin_date)
                        ->map(function ($item) use ($recurringSlotsByStartTime) {
                            $rts = $recurringSlotsByStartTime->get($item->checkin_date->format('H:i:s'));

                            return $rts ? ['date' => $item->checkin_date->toDateString(), 'timeslot_id' => $rts->timeslot_id] : null;
                        })
                        ->filter()
                        ->groupBy('date')
                        ->each(fn ($slots, $date) => $realtimeService->broadcastSlotsAvailable(
                            (string) $productId,
                            $date,
                            collect($slots)->pluck('timeslot_id')->values()->toArray()
                        ));
                } else {
                    // Phòng "theo ngày" — giải phóng khoảng ngày rộng nhất (min checkin - max
                    // checkout) trong số các item vừa xoá của phòng này.
                    $checkin  = $items->pluck('checkin_date')->filter()->min();
                    $checkout = $items->pluck('checkout_date')->filter()->max();
                    if ($checkin && $checkout) {
                        $realtimeService->broadcastDailyReleased((string) $productId, $checkin->toDateString(), $checkout->toDateString());
                    }
                }
            }
        });
    }

    // Số tiền THỰC TẾ cần thu ngay bây giờ (qua PayOS...) — 'full_amount' luôn là TỔNG GIÁ CỐ ĐỊNH
    // của đơn (không phải tiền cọc), nên số tiền cọc cần trả phải TÍNH LẠI từ full_amount *
    // deposit_percent mỗi lần cần, không đọc trực tiếp từ cột nào cả (tránh 2 cột amount/full_amount
    // bị dùng lẫn lộn giữa "tổng giá" và "tiền cần thu ngay" như trước đây).
    public function depositDueAmount(): int
    {
        $total = (int) ($this->full_amount ?? 0);

        if ($this->deposit_percent !== null && (int) $this->deposit_percent < 100) {
            return (int) ceil($total * (int) $this->deposit_percent / 100);
        }

        return $total;
    }

    // Số tiền cọc THỰC TẾ đã thu — mặc định bằng depositDueAmount() (đúng % đã áp dụng lúc đặt),
    // trừ khi admin đã tự điều chỉnh tay qua 'deposit_paid_amount' (form sửa đơn ở admin).
    public function depositPaidAmount(): int
    {
        return $this->deposit_paid_amount !== null
            ? (int) $this->deposit_paid_amount
            : $this->depositDueAmount();
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    // Khách thứ 2 trở đi (đặt qua đêm) — xem migration 2026_07_20_000001_create_order_guest_cccds_table.
    public function guestCccds()
    {
        return $this->hasMany(OrderGuestCccd::class)->orderBy('guest_index');
    }
    // Thêm accessor để đảm bảo items luôn trả về Collection
    public function getItemsAttribute($value)
    {
        if ($this->relationLoaded('items')) {
            return $this->getRelation('items');
        }

        // Nếu chưa load relation, load ngay
        return $this->items()->get();
    }

    // Thêm method helper để kiểm tra có items không
    public function hasItems()
    {
        return $this->items && $this->items->count() > 0;
    }

    // Method để get items với fallback
    public function getItemsSafe()
    {
        if (!$this->relationLoaded('items')) {
            $this->load('items');
        }

        return $this->items ?? collect();
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    // Nhân viên/tài khoản đã TẠO đơn này — dùng cho nhân viên đối tác nền tảng (vd 365home) xem
    // lại đúng những đơn CHÍNH HỌ đặt hộ cho đối tác khác (xem OrderResource::getEloquentQuery()).
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Order có thể dùng nhiều access codes
     */
    public function accessCodes()
    {
        return $this->belongsToMany(
            AccessCode::class,
            'access_code_order',
            'order_id',
            'access_code_id'
        )->withTimestamps()->withPivot('assigned_at');
    }

    /**
     * Lấy access code chính (code đầu tiên)
     */
    public function accessCode()
    {
        return $this->accessCodes()->first();
    }

    public function hasAccessCode()
    {
        return $this->accessCodes()->exists();
    }

    // Lấy chi nhánh từ order items (nếu chưa có trong order)
    public function getAccessCodeAttribute()
    {
        return $this->getPrimaryAccessCode();
    }

    public function znsNotifications()
    {
        return $this->hasMany(ZnsNotification::class, 'order_id');
    }

        public function toCalendarEvent(): CalendarEvent
    {
        return CalendarEvent::make($this)
            ->title($this->name);
    }

    /**
     * Lấy access code đầu tiên (primary code)
     * Return: AccessCode model instance hoặc null
     */
    public function getPrimaryAccessCode()
    {
        if (!$this->relationLoaded('accessCodes')) {
            $this->load('accessCodes');
        }
        return $this->accessCodes->first();
    }

    public function services()
    {
        return $this->hasMany(OrderService::class);
    }
}
