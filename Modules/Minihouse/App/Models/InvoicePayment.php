<?php

namespace Modules\Minihouse\App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;

class InvoicePayment extends Model
{
    use LogsMinihouseActivity;

    public const METHOD_CASH     = 'tien_mat';
    public const METHOD_TRANSFER = 'chuyen_khoan';
    public const METHOD_OTHER    = 'khac';

    // PENDING: nhân viên vừa ghi nhận (tiền mặt/chuyển khoản tay), CHƯA tính vào Invoice.amount_paid
    // — chờ "Chủ toà nhà" (quyền approve_invoice_payments) tự xác nhận là có thật. APPROVED: đã được
    // duyệt, HOẶC tự động ngay từ đầu với thanh toán qua PayOS (webhook đã tự xác nhận tiền vào tài
    // khoản thật, không cần duyệt thêm lần nữa) — xem InvoicePaymentObserver, PayOsWebhookController.
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';

    protected $table = 'minihouse_invoice_payments';

    protected $fillable = ['invoice_id', 'amount', 'paid_at', 'payment_method', 'note', 'status', 'approved_at', 'approved_by', 'created_by'];

    // float — 'decimal:2' luôn ép hiện đủ 2 số lẻ dù giá trị là số nguyên.
    protected $casts = [
        'amount'      => 'float',
        'paid_at'     => 'date',
        'approved_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
