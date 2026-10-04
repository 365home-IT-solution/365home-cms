<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

// Một phiên bản Điều khoản dịch vụ — BẤT BIẾN: không sửa, không xoá. Cập nhật Điều khoản = tạo phiên bản MỚI (TermsService::createVersion).
class TermsVersion extends Model
{
    public const TYPE_HOMESTAY = 'partner_homestay';

    public const TYPE_MINIHOUSE = 'partner_minihouse';

    public const TYPES = [
        self::TYPE_HOMESTAY => 'Đối tác Homestay',
        self::TYPE_MINIHOUSE => 'MiniHouse (mua gói)',
    ];

    protected $fillable = ['type', 'version', 'title', 'content', 'content_hash', 'effective_at', 'created_by'];

    protected $casts = ['effective_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Phiên bản Điều khoản không được sửa — hãy tạo phiên bản mới.');
        });
        static::deleting(function (): void {
            throw new LogicException('Phiên bản Điều khoản không được xoá — cần lưu để đối chiếu.');
        });
    }

    // Mỗi panel quản lý Điều khoản RIÊNG: panel MiniHouse → partner_minihouse, panel Homestay → partner_homestay.
    public static function typeForCurrentPanel(): string
    {
        return \Filament\Facades\Filament::getCurrentPanel()?->getId() === 'minihouse-admin' ? self::TYPE_MINIHOUSE : self::TYPE_HOMESTAY;
    }

    public static function panelLabel(): string
    {
        return self::typeForCurrentPanel() === self::TYPE_MINIHOUSE ? 'MiniHouse' : 'Homestay';
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(TermsAcceptance::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function contentIntegrityValid(): bool
    {
        return hash('sha256', (string) $this->content) === $this->content_hash;
    }

    public function toApi(bool $withContent = true): array
    {
        return [
            'id' => $this->id, 'type' => $this->type, 'type_label' => $this->typeLabel(), 'version' => $this->version, 'title' => $this->title,
            'content' => $withContent ? $this->content : null, 'content_hash' => $this->content_hash,
            'effective_at' => $this->effective_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
