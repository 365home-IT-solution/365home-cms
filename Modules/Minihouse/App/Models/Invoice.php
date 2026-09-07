<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingViaContract;

class Invoice extends Model
{
    use SoftDeletes;
    use ScopedToActiveBuildingViaContract;
    use LogsMinihouseActivity;

    public const STATUS_UNPAID  = 'unpaid';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID    = 'paid';

    protected $table = 'minihouse_invoices';

    protected $fillable = [
        'contract_id', 'month', 'period_start', 'period_end', 'room_price',
        'electric_start', 'electric_end', 'electric_unit_price', 'electric_amount',
        'water_start', 'water_end', 'water_unit_price', 'water_amount',
        'service_amount', 'total_amount', 'status', 'amount_paid', 'paid_at',
    ];

    // float cho mọi cột số tiền/chỉ số — tránh 'decimal:2' luôn ép hiện đủ 2 số lẻ (VD "0.00") dù
    // giá trị là số nguyên.
    protected $casts = [
        'month'                => 'date',
        'period_start'         => 'date',
        'period_end'           => 'date',
        'paid_at'              => 'datetime',
        'room_price'           => 'float',
        'electric_start'       => 'float',
        'electric_end'         => 'float',
        'electric_unit_price'  => 'float',
        'electric_amount'      => 'float',
        'water_start'          => 'float',
        'water_end'            => 'float',
        'water_unit_price'     => 'float',
        'water_amount'         => 'float',
        'service_amount'       => 'float',
        'total_amount'         => 'float',
        'amount_paid'          => 'float',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    // Từng dòng phụ thu (rác, gửi xe, internet...) đã áp vào hoá đơn này — tổng của các dòng này
    // = service_amount (xem InvoiceForm::recalcItemsTotal()).
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    // Từng LẦN thanh toán — amount_paid/paid_at/status là cột CACHE tự đồng bộ từ đây, xem
    // InvoicePaymentObserver. Không tự tính tay ở đây để tránh 2 nơi tính ra 2 kết quả khác nhau.
    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function remainingAmount(): float
    {
        return max(0, (float) $this->total_amount - (float) $this->amount_paid);
    }

    protected function activityLabel(): string
    {
        return 'Hoá đơn tháng ' . ($this->month?->format('m/Y') ?? '#' . $this->id)
            . ($this->contract?->room?->code ? ' - phòng ' . $this->contract->room->code : '');
    }
}
