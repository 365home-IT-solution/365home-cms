<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingId;

class Transaction extends Model
{
    use SoftDeletes;
    use ScopedToActiveBuildingId;
    use LogsMinihouseActivity;

    public const TYPE_IN  = 'thu';
    public const TYPE_OUT = 'chi';

    public const CATEGORY_REPAIR    = 'sua_chua';
    public const CATEGORY_OPERATION = 'van_hanh';
    public const CATEGORY_OTHER     = 'khac';

    protected $table = 'minihouse_transactions';

    protected $fillable = ['contract_id', 'building_id', 'invoice_payment_id', 'type', 'category', 'amount', 'transaction_date', 'note', 'receipt_image'];

    protected $casts = [
        'transaction_date' => 'date',
        'amount'           => 'float',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    // Khác NULL nếu dòng "Thu" này được TỰ ĐỘNG sinh ra từ 1 lần thanh toán hoá đơn — xem
    // InvoicePaymentObserver. Giao dịch nhập tay bình thường (sửa chữa, vận hành...) luôn NULL.
    public function invoicePayment(): BelongsTo
    {
        return $this->belongsTo(InvoicePayment::class);
    }
}
