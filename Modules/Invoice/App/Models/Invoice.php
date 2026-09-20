<?php

declare(strict_types=1);

namespace Modules\Invoice\App\Models;

use App\Models\Concerns\BelongsToPartner;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Payment\Entities\Order;

// Bản ghi hoá đơn — SƯỜN cho tích hợp MISA meInvoice, chưa gọi API thật (xem
// Modules\Invoice\App\Services\MisaInvoiceClient). Mọi bản ghi mới LUÔN ở STATUS_DRAFT; chỉ khi
// nào bước tích hợp thật (chưa làm) gọi MISA thành công mới được set STATUS_ISSUED kèm
// invoice_number/lookup_code do MISA trả về — TUYỆT ĐỐI không tự đặt STATUS_ISSUED thủ công ở đâu
// khác, để tránh tạo ra "hoá đơn giả" không có giá trị pháp lý nhưng lại hiển thị như đã phát hành.
class Invoice extends Model
{
    use HasFactory, BelongsToPartner;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_ERROR = 'error';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT     => 'Bản nháp',
        self::STATUS_ISSUED    => 'Đã phát hành',
        self::STATUS_CANCELLED => 'Đã huỷ',
        self::STATUS_ERROR     => 'Lỗi phát hành',
    ];

    public const BUYER_TYPE_INDIVIDUAL = 'individual';
    public const BUYER_TYPE_COMPANY = 'company';

    protected $fillable = [
        'order_id',
        'partner_id',
        'created_by',
        'status',
        'provider',
        'invoice_template_code',
        'invoice_series',
        'invoice_number',
        'lookup_code',
        'issued_at',
        'buyer_type',
        'buyer_name',
        'buyer_tax_code',
        'buyer_address',
        'buyer_email',
        'buyer_phone',
        'subtotal_amount',
        'vat_rate',
        'vat_amount',
        'total_amount',
        'note',
        'raw_request',
        'raw_response',
        'error_message',
    ];

    protected $casts = [
        'issued_at'    => 'datetime',
        'vat_rate'     => 'decimal:2',
        'raw_request'  => 'array',
        'raw_response' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('sort_order');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
