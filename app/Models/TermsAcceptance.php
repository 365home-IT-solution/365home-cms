<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

// Bản ghi khách ĐỒNG Ý Điều khoản lúc đăng ký — bất biến (chứng cứ). Chỉ cho bổ sung mã đơn/mã giao dịch khi đơn thanh toán phát sinh SAU (còn trống).
class TermsAcceptance extends Model
{
    protected $fillable = [
        'terms_version_id', 'type', 'partner_id', 'accepted', 'accepted_at', 'terms_version_label', 'terms_content_hash',
        'full_name', 'phone', 'email', 'business_name', 'plan_id', 'plan_name', 'periods', 'amount_vnd',
        'payment_id', 'order_code', 'transaction_ref', 'ip', 'user_agent', 'source', 'meta',
    ];

    protected $casts = ['accepted' => 'boolean', 'accepted_at' => 'datetime', 'meta' => 'array', 'periods' => 'integer', 'amount_vnd' => 'integer'];

    private const LATE_FIELDS = ['payment_id', 'order_code', 'transaction_ref', 'amount_vnd', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            foreach (array_keys($row->getDirty()) as $field) {
                if (! in_array($field, self::LATE_FIELDS, true) || filled($row->getOriginal($field))) {
                    throw new LogicException('Lịch sử đồng ý Điều khoản không được sửa.');
                }
            }
        });
        static::deleting(function (): void {
            throw new LogicException('Lịch sử đồng ý Điều khoản không được xoá.');
        });
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(TermsVersion::class, 'terms_version_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id, 'type' => $this->type, 'partner_id' => $this->partner_id, 'accepted' => $this->accepted,
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'terms' => ['id' => $this->terms_version_id, 'version' => $this->terms_version_label, 'content_hash' => $this->terms_content_hash],
            'customer' => ['full_name' => $this->full_name, 'phone' => $this->phone, 'email' => $this->email, 'business_name' => $this->business_name],
            'plan' => ['plan_id' => $this->plan_id, 'plan_name' => $this->plan_name, 'periods' => $this->periods, 'amount_vnd' => $this->amount_vnd],
            'order_code' => $this->order_code, 'transaction_ref' => $this->transaction_ref,
            'ip' => $this->ip, 'user_agent' => $this->user_agent, 'source' => $this->source, 'meta' => $this->meta,
        ];
    }
}
