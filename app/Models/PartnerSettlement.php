<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Payment\Entities\Order;

// Bảng ĐỐI SOÁT hoa hồng của 1 đối tác Homestay trong 1 kỳ (xem App\Services\SettlementService).
// net_amount có dấu: dương = đối tác phải nộp 365home; âm = 365home phải chi cho đối tác.
class PartnerSettlement extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_PAID = 'paid';
    public const STATUS_DEDUCTED = 'deducted';
    public const STATUS_PAID_OUT = 'paid_out';

    public const STATUSES = [
        self::STATUS_DRAFT    => 'Nháp',
        self::STATUS_SENT     => 'Đã gửi đối tác',
        self::STATUS_DISPUTED => 'Đang khiếu nại',
        self::STATUS_PAID     => 'Đối tác đã nộp',
        self::STATUS_DEDUCTED => 'Đã trừ ký quỹ',
        self::STATUS_PAID_OUT => '365home đã chi',
    ];

    /** Trạng thái đã chốt xong — không sửa số liệu nữa. */
    public const FINAL_STATUSES = [self::STATUS_PAID, self::STATUS_DEDUCTED, self::STATUS_PAID_OUT];

    protected $fillable = [
        'code', 'partner_id', 'period_start', 'period_end', 'cycle', 'status', 'orders_count', 'revenue_total',
        'commission_total', 'subsidy_total', 'platform_collected_total', 'net_amount', 'sent_at', 'due_at', 'reminded_at',
        'paid_at', 'deducted_at', 'deducted_entry_id', 'paid_out_at', 'paid_out_reference', 'paid_out_by',
        'payos_order_code', 'payos_payment_link_id', 'payos_checkout_url', 'payos_qr_code', 'payos_bank_bin',
        'payos_account_number', 'payos_account_name', 'payos_expired_at', 'note', 'created_by', 'invoice_no', 'invoiced_at', 'invoiced_by',
    ];

    protected $casts = [
        'period_start'             => 'date',
        'period_end'               => 'date',
        'orders_count'             => 'integer',
        'revenue_total'            => 'integer',
        'commission_total'         => 'integer',
        'subsidy_total'            => 'integer',
        'platform_collected_total' => 'integer',
        'net_amount'               => 'integer',
        'sent_at'                  => 'datetime',
        'due_at'                   => 'datetime',
        'reminded_at'              => 'datetime',
        'paid_at'                  => 'datetime',
        'deducted_at'              => 'datetime',
        'paid_out_at'              => 'datetime',
        'payos_expired_at'         => 'datetime',
        'invoiced_at'              => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class)->withTrashed();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'settlement_id')->withoutGlobalScopes();
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(PartnerSettlementDispute::class, 'settlement_id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATUSES, true);
    }

    /** Đối tác phải nộp 365home (net > 0), hay 365home phải chi (net < 0), hay hoà (0). */
    public function direction(): string
    {
        return $this->net_amount > 0 ? 'partner_pays' : ($this->net_amount < 0 ? 'platform_pays' : 'even');
    }
}
