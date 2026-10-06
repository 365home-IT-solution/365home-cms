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
        'citizen_id' => 'Căn cước công dân (CCCD)',
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

    // Các loại giấy tờ cấp đối tác CÓ THỂ bắt buộc khi đăng ký trên website: Giấy phép kinh doanh, CCCD người đại diện (phải đọc được mã QR),
    // An toàn an ninh (ANTT), Phòng cháy chữa cháy (PCCC).
    // Loại nào THỰC SỰ bắt buộc với từng loại đối tác được bật/tắt ở config partner_flow.registration_required_documents — xem registrationRequiredFor().
    public const REGISTRATION_REQUIRED = ['business_license', 'citizen_id', 'security_order', 'fire_safety'];

    public const HOMESTAY_REGISTRATION_REQUIRED = self::REGISTRATION_REQUIRED;

    /**
     * Giấy tờ BẮT BUỘC khi đăng ký trên website theo loại đối tác (Homestay / MiniHouse đăng ký dùng thử), đọc từ config.
     * Giấy phép kinh doanh luôn bắt buộc; loại lạ trong config bị bỏ qua; thứ tự theo REGISTRATION_REQUIRED.
     *
     * @return array<int, string>
     */
    public static function registrationRequiredFor(Partner|string|null $partner): array
    {
        $partnerType = $partner instanceof Partner ? $partner->partner_type : $partner;
        $configured = (array) config('partner_flow.registration_required_documents.'.($partnerType === Partner::TYPE_MINIHOUSE ? 'minihouse' : 'homestay'), self::REGISTRATION_REQUIRED);

        return array_values(array_filter(self::REGISTRATION_REQUIRED, fn (string $type) => $type === 'business_license' || in_array($type, $configured, true)));
    }

    // ĐKKD / ANTT / PCCC có BỘ CỘT RIÊNG theo loại (dkkd_* / antt_* / pccc_* — App\Support\LegalDocumentFields): cho phép gán hàng loạt và cast cột ngày.
    public function getFillable()
    {
        return array_merge(parent::getFillable(), \App\Support\LegalDocumentFields::allKeys());
    }

    public function getCasts()
    {
        return parent::getCasts() + array_fill_keys(\App\Support\LegalDocumentFields::dateKeys(), 'date');
    }

    protected static function booted(): void
    {
        static::saving(function (self $document): void {
            // Mỗi loại một bộ cột riêng: xoá cột của loại khác, chép số/ngày cấp/nơi cấp sang 3 cột tóm tắt chung.
            \App\Support\LegalDocumentFields::sync($document);

            $buildingTypes = ['fire_safety', 'security_order', 'property_ownership_or_use'];
            // MiniHouse đăng ký dùng thử: PCCC/ANTT nộp ở CẤP ĐỐI TÁC (không gắn toà nhà) như Homestay.
            $isMinihouseBuildingDocument = $document->partner?->isMinihouse()
                && in_array($document->type, $buildingTypes, true)
                && ! ($document->partner->minihouseDocumentsFlow() && ! $document->building_id);

            if ($isMinihouseBuildingDocument) {
                if (! $document->building_id || ! $document->partner->categories()
                    ->whereKey($document->building_id)->where('category_type', 'product')->whereNull('parent_id')->exists()) {
                    throw new \DomainException('Giấy tờ tòa nhà phải thuộc đúng Partner MiniHouse.');
                }
            } else {
                $document->building_id = null;
            }

            $isRegistrationRequired = $document->partner?->requiresRegistrationDocuments() && ! $document->building_id
                && in_array($document->type, self::registrationRequiredFor($document->partner), true);

            if ($document->type === 'business_license' || $isMinihouseBuildingDocument || $isRegistrationRequired) {
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
