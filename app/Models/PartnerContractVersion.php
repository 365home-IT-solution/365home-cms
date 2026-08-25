<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

// Lịch sử phiên bản hợp đồng của 1 đối tác — mỗi lần cập nhật điều khoản/gia hạn hợp đồng thì
// tạo 1 dòng mới ở đây, hiển thị ở tab "Hợp đồng" (PartnerResource).
//
// Ký điện tử: mỗi phiên bản mang theo 1 bản nội dung CỐ ĐỊNH tại thời điểm tạo ('content' +
// 'content_hash' sha256 để đối chiếu toàn vẹn — nếu nội dung hợp đồng bị đổi sau khi tạo thì
// hash sẽ lệch, phát hiện được ngay). Đối tác ký qua link công khai (signing_token, không cần
// tài khoản CMS — xem ContractSignController); nền tảng (super_admin) ký ngay trong panel. Hợp
// đồng chỉ coi là ký xong khi CẢ 2 BÊN đã ký (xem isFullySigned()).
class PartnerContractVersion extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'partner_id',
        'version_label',
        'change_note',
        'changed_by',
        'content',
        'content_hash',
        'signing_token',
        'partner_confirmed_at',
        'partner_signing_provider',
        'partner_signed_at',
        'partner_signed_by_name',
        'partner_signed_ip',
        'partner_signed_user_agent',
        'partner_signature',
        'partner_signature_certificate',
        'platform_signing_provider',
        'platform_signed_at',
        'platform_signed_by',
        'platform_signed_ip',
        'platform_signed_user_agent',
        'platform_signature',
        'platform_signature_certificate',
    ];

    protected $casts = [
        'partner_confirmed_at'          => 'datetime',
        'partner_signed_at'             => 'datetime',
        'platform_signed_at'            => 'datetime',
        'partner_signature_certificate'  => 'array',
        'platform_signature_certificate' => 'array',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('document')->singleFile();
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    public function platformSignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'platform_signed_by');
    }

    // Đối tác đã XÁC NHẬN ĐỒNG Ý qua OTP email (ContractSignController::sign()) — CHƯA chắc đã có
    // chữ ký số thật áp lên (xem isPartnerSigned()). Tách riêng vì bước xác nhận này xảy ra công
    // khai, bất kỳ lúc nào, không có nhân viên đứng cạnh để nhập OTP VNPT SmartCA ngay lúc đó.
    public function isPartnerConfirmed(): bool
    {
        return ! is_null($this->partner_confirmed_at);
    }

    // LỊCH SỬ: trước đây có bước "ký số thay mặt đối tác" bằng CHÍNH chứng thư của nền tảng — đã bỏ
    // (không hợp lý: không phải "chữ ký của đối tác" thật, chỉ là nền tảng tự ký rồi gắn nhãn). Giữ
    // lại hàm/cột này chỉ để đọc dữ liệu CŨ đã ký theo kiểu đó — KHÔNG còn được set mới nữa. Phần
    // "đối tác" giờ chỉ cần isPartnerConfirmed() (xác nhận qua OTP) là đủ đại diện cho ý chí đồng ý.
    public function isPartnerSigned(): bool
    {
        return ! is_null($this->partner_signed_at);
    }

    // Nền tảng đã ký số THẬT (VNPT SmartCA) niêm phong hợp đồng SAU KHI đối tác đã xác nhận —
    // đây là chữ ký số PKI DUY NHẤT của toàn bộ hợp đồng (xem ContractPdfSigningService).
    public function isPlatformSigned(): bool
    {
        return ! is_null($this->platform_signed_at);
    }

    // Hợp đồng coi là hoàn tất khi: đối tác đã xác nhận (OTP) VÀ nền tảng đã ký số thật niêm phong.
    public function isFullySigned(): bool
    {
        return $this->isPartnerConfirmed() && $this->isPlatformSigned();
    }

    // Nội dung hợp đồng đã bị đổi so với lúc tạo phiên bản này chưa (không nên xảy ra vì content
    // không có form nào cho sửa sau khi tạo, đây là lớp kiểm tra toàn vẹn thêm — phòng trường
    // hợp sửa DB trực tiếp).
    public function contentIntegrityValid(): bool
    {
        if (blank($this->content) || blank($this->content_hash)) {
            return false;
        }

        return hash('sha256', $this->content) === $this->content_hash;
    }

    // Xác minh lại chữ ký số ĐÃ KÝ (không phải chỉ tin theo có/không giá trị partner_signed_at) —
    // dùng ĐÚNG provider đã ký lúc đó (partner_signing_provider/platform_signing_provider), KHÔNG
    // phải provider đang cấu hình mặc định — vì 2 bên có thể ký ở 2 thời điểm khác nhau, lỡ đổi
    // CONTRACT_SIGNING_PROVIDER giữa chừng (vd từ 'local' sang 'vnpt_smartca' thật) thì mỗi chữ ký
    // vẫn phải verify bằng đúng provider đã tạo ra nó.
    public function verifyPartnerSignature(): bool
    {
        if (blank($this->partner_signature) || blank($this->partner_signature_certificate)) {
            return false;
        }

        // verify() có thể ném lỗi (vd provider chưa hoàn thiện, lỗi mạng khi cần tra CA...) — đây
        // chỉ là 1 badge hiển thị PHỤ trên trang, KHÔNG được để crash cả trang chỉ vì badge này.
        try {
            return app(\App\Services\ContractSigning\ContractSigningManager::class)
                ->driver($this->partner_signing_provider)
                ->verify($this->content_hash, $this->partner_signature, $this->partner_signature_certificate);
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }

    public function verifyPlatformSignature(): bool
    {
        if (blank($this->platform_signature) || blank($this->platform_signature_certificate)) {
            return false;
        }

        try {
            return app(\App\Services\ContractSigning\ContractSigningManager::class)
                ->driver($this->platform_signing_provider)
                ->verify($this->content_hash, $this->platform_signature, $this->platform_signature_certificate);
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }
}
