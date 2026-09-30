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
        'partner_id', 'building_id', 'type', 'name', 'document_number', 'issuer', 'issued_at', 'expires_at',
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
            $buildingTypes = ['fire_safety', 'security_order', 'property_ownership_or_use'];
            $isMinihouseBuildingDocument = $document->partner?->isMinihouse()
                && in_array($document->type, $buildingTypes, true);

            if ($isMinihouseBuildingDocument) {
                if (! $document->building_id || ! $document->partner->categories()
                    ->whereKey($document->building_id)->where('category_type', 'product')->whereNull('parent_id')->exists()) {
                    throw new \DomainException('Giấy tờ tòa nhà phải thuộc đúng Partner MiniHouse.');
                }
            } else {
                $document->building_id = null;
            }

            if ($document->type === 'business_license' || $isMinihouseBuildingDocument) {
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

    public function building(): BelongsTo
    {
        return $this->belongsTo(\Modules\Minihouse\App\Models\Building::class, 'building_id');
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
