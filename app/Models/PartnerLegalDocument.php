<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class PartnerLegalDocument extends Model implements HasMedia
{
    use HasUuids, InteractsWithMedia, SoftDeletes;

    public const TYPES = [
        'business_license' => 'Giấy phép kinh doanh',
        'security_order' => 'Giấy chứng nhận an ninh, trật tự',
        'fire_safety' => 'Hồ sơ phòng cháy chữa cháy',
        'tax_registration' => 'Giấy đăng ký thuế',
        'accommodation_license' => 'Giấy phép/công nhận cơ sở lưu trú',
        'signing_authorization' => 'Giấy ủy quyền người ký',
        'property_ownership_or_use' => 'Giấy tờ sở hữu hoặc quyền khai thác tòa nhà',
        'other' => 'Giấy tờ khác',
    ];

    public const STATUSES = [
        'draft' => 'Bản nháp',
        'pending_review' => 'Chờ duyệt',
        'approved' => 'Đã duyệt',
        'changes_requested' => 'Cần bổ sung',
        'rejected' => 'Từ chối',
    ];

    protected $fillable = [
        'partner_id', 'type', 'name', 'document_number', 'issuer', 'issued_at', 'expires_at',
        'is_required', 'status', 'review_note', 'submitted_at', 'reviewed_at', 'reviewed_by', 'created_by',
    ];

    protected $casts = [
        'issued_at' => 'date',
        'expires_at' => 'date',
        'is_required' => 'boolean',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $document): void {
            if ($document->type === 'business_license' || ($document->partner?->partner_type === Partner::TYPE_MINIHOUSE && in_array($document->type, ['fire_safety', 'property_ownership_or_use'], true))) {
                $document->is_required = true;
            }
        });
    }

    public function registerMediaCollections(): void
    {
        // Hồ sơ pháp lý chứa dữ liệu nhạy cảm: không lưu trên disk public và chỉ tải qua API có quyền.
        $this->addMediaCollection('file')->useDisk('local')->singleFile();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at?->isBefore(today()) ?? false;
    }
}
