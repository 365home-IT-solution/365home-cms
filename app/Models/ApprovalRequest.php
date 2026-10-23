<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Yêu cầu DUYỆT của đối tác gửi 365home (bảng giá, phòng). Xem App\Services\ApprovalService.
 *
 * Bản ghi gốc (price_boards / products) mang approval_status của chính nó (đăng mới); bản SỬA của bản ghi đã duyệt nằm ở payload của yêu cầu này
 * và chỉ áp xuống khi được duyệt.
 */
class ApprovalRequest extends Model
{
    public const KIND_PRICE_BOARD_CREATE = 'price_board_create';
    public const KIND_PRICE_BOARD_UPDATE = 'price_board_update';
    public const KIND_ROOM_CREATE        = 'room_create';
    public const KIND_ROOM_UPDATE        = 'room_update';

    public const KINDS = [
        self::KIND_PRICE_BOARD_CREATE => 'Bảng giá mới',
        self::KIND_PRICE_BOARD_UPDATE => 'Sửa bảng giá',
        self::KIND_ROOM_CREATE        => 'Đăng phòng mới',
        self::KIND_ROOM_UPDATE        => 'Sửa phòng',
    ];

    public const SUBJECT_PRICE_BOARD = 'price_board';
    public const SUBJECT_PRODUCT     = 'product';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING   => 'Chờ duyệt',
        self::STATUS_APPROVED  => 'Đã duyệt',
        self::STATUS_REJECTED  => 'Bị từ chối',
        self::STATUS_CANCELLED => 'Đã rút lại',
    ];

    protected $fillable = [
        'partner_id', 'kind', 'subject_type', 'subject_id', 'subject_label', 'payload', 'status',
        'requested_by', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $casts = [
        'payload'     => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Yêu cầu "đăng mới" (bản ghi gốc còn chờ duyệt) — khác với yêu cầu sửa bản ghi đã duyệt. */
    public function isCreate(): bool
    {
        return in_array($this->kind, [self::KIND_PRICE_BOARD_CREATE, self::KIND_ROOM_CREATE], true);
    }
}
