<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\TolerantEncrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Link chia sẻ tải 1 phiên bản hợp đồng đối tác (kiểu "chia sẻ link" của Google Drive) — tạo/thu hồi qua App\Services\ContractShareLinkService.
// Trang công khai: route('contract-share.show', token). Token chỉ tra cứu qua token_hash; cột token lưu mã hoá để admin sao chép lại link.
class ContractShareLink extends Model
{
    protected $fillable = [
        'partner_id',
        'partner_contract_version_id',
        'token_hash',
        'token',
        'password',
        'expires_at',
        'max_downloads',
        'created_by',
    ];

    protected $hidden = ['token', 'token_hash', 'password'];

    // Giá trị mặc định ngay trên model vừa tạo (cột DB mặc định 0 nhưng model mới create() chưa đọc lại từ DB).
    protected $attributes = [
        'view_count' => 0,
        'download_count' => 0,
    ];

    protected $casts = [
        'token' => TolerantEncrypted::class,
        'password' => 'hashed',
        'expires_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'max_downloads' => 'integer',
        'view_count' => 'integer',
        'download_count' => 'integer',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PartnerContractVersion::class, 'partner_contract_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function accesses(): HasMany
    {
        return $this->hasMany(ContractShareLinkAccess::class)->latest('id');
    }

    public function hasPassword(): bool
    {
        return filled($this->password);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isDownloadLimitReached(): bool
    {
        return $this->max_downloads !== null && $this->download_count >= $this->max_downloads;
    }

    /** null = link dùng được; còn lại = lý do không mở được (hiển thị cho người mở link). */
    public function unavailableReason(): ?string
    {
        return match (true) {
            $this->revoked_at !== null => 'Link chia sẻ đã bị thu hồi.',
            $this->isExpired() => 'Link chia sẻ đã hết hạn.',
            $this->isDownloadLimitReached() => 'Link chia sẻ đã hết lượt tải.',
            default => null,
        };
    }

    /** Trạng thái hiển thị ở trang quản trị/API: active | revoked | expired | exhausted. */
    public function state(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->isExpired() => 'expired',
            $this->isDownloadLimitReached() => 'exhausted',
            default => 'active',
        };
    }

    public const STATES = [
        'active' => 'Đang mở',
        'revoked' => 'Đã thu hồi',
        'expired' => 'Hết hạn',
        'exhausted' => 'Hết lượt tải',
    ];

    /** Link đầy đủ để gửi đi; null khi token không giải mã được (DB chép từ máy khác, APP_KEY khác) — tạo link mới. */
    public function url(): ?string
    {
        return filled($this->token) ? route('contract-share.show', ['token' => $this->token]) : null;
    }
}
