<?php

declare(strict_types=1);

namespace Modules\Promotion\App\Models;

use App\Models\Concerns\BelongsToPartner;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;

// Nhật ký "mã giảm giá được dùng" phục vụ trang admin + xuất Excel — ghi 1 lần duy nhất khi đơn
// thanh toán thành công lần đầu (CouponUsageLedger::confirm(), gọi từ OrderObserver), KHÔNG BAO GIỜ
// xóa dòng: nếu lượt dùng bị hoàn (đơn chuyển từ paid/deposit sang cancelled/refunded/failed) chỉ
// set reversed_at. Tách biệt với coupon_usages (bảng nghiệp vụ nội bộ, có thể bị xóa khi coupon
// trên đơn thay đổi — xem CouponUsageLedger::release()).
class CouponUsageLog extends Model
{
    use BelongsToPartner;

    protected $fillable = [
        'partner_id',
        'coupon_id',
        'code',
        'coupon_name',
        'order_id',
        'order_code',
        'customer_id',
        'customer_name',
        'customer_phone',
        'discount_amount',
        'order_amount',
        'payment_method',
        'category_id',
        'used_at',
        'reversed_at',
    ];

    protected $casts = [
        'used_at'         => 'datetime',
        'reversed_at'     => 'datetime',
        'discount_amount' => 'integer',
        'order_amount'    => 'integer',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
