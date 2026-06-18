<?php

namespace Modules\Payment\Entities;

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
    use HasFactory;

    protected $fillable = [
        'order_code',
        'amount',
        'full_amount',
        'deposit_percent',
        'coupon_code',
        'coupon_codes',
        'deposit_paid_at',
        'remaining_paid_at',
        'remaining_payment_method',
        'remaining_payos_code',
        'remaining_checkout_url',
        'current_payos_code',
        'description',
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
        'cccd_data',
        'guest_count',
        'category_id',
        'user_id',
        'money_deposit',
        'exclude_from_stats',
        'unlock_anytime',
        'customer_id',
        'qr_code',
        'remaining_qr_code',
        'device_token',
    ];

    protected $casts = [
        'cccd_data'            => 'array',
        'coupon_codes'         => 'array',
        'deposit_paid_at'      => 'datetime',
        'remaining_paid_at'    => 'datetime',
        'exclude_from_stats'   => 'boolean',
        'unlock_anytime'       => 'boolean',
    ];
    protected $with = ['items'];

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
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
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
