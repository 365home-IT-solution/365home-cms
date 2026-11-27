<?php

namespace Modules\Payment\Entities;

use Illuminate\Database\Eloquent\Model;

// Khoản thu tiền độc lập do admin tạo — xem migration create_payment_collections_table và App\Services\Payment\PaymentCollectionService.
class PaymentCollection extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::PENDING => 'Chờ thanh toán', self::PAID => 'Đã thanh toán', self::CANCELLED => 'Đã huỷ'];

    protected $table = 'payment_collections';

    protected $fillable = [
        'code', 'amount', 'description', 'customer_name', 'customer_phone', 'bank_code', 'bank_bin', 'account_number', 'account_holder',
        'status', 'expires_at', 'paid_at', 'paid_amount', 'bank_reference', 'cancelled_at', 'created_by',
    ];

    protected $casts = [
        'amount' => 'integer', 'paid_amount' => 'integer', 'expires_at' => 'datetime', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->status === self::PENDING && $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function publicUrl(): string
    {
        return route('platform-collect.show', ['kind' => 'collection', 'code' => $this->code]);
    }

    /** Dữ liệu trả API / hiển thị — không có thông tin nhạy cảm (chỉ tài khoản nhận tiền công khai trên QR). */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'amount' => $this->amount,
            'description' => $this->description,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'status' => $this->status,
            'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'expired' => $this->isExpired(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'paid_amount' => $this->paid_amount,
            'bank_reference' => $this->bank_reference,
            'checkout_url' => $this->publicUrl(),
            'transfer_content' => $this->code,
            'qr_code' => \App\Support\VietQrPayload::build((string) $this->bank_bin, (string) $this->account_number, (int) $this->amount, $this->code),
            'bank' => ['code' => $this->bank_code, 'bin' => $this->bank_bin, 'account_number' => $this->account_number, 'account_holder' => $this->account_holder],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
