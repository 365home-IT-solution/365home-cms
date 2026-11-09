<?php

declare(strict_types=1);

namespace App\Models;

use App\Games\TienLen\Card;
use App\Services\TienLenDeckService;
use Illuminate\Database\Eloquent\Model;

/**
 * Bộ bài (mặt bài) Tiến lên — Super Admin thêm / sửa / xoá / bật cho dùng ở Filament hoặc /api/admin/games/tien-len/card-decks.
 * Đường dẫn ảnh tương đối với web root (asset()): bộ có sẵn ở images/tien-len/decks/…, bộ upload ở storage/tien-len/decks/….
 */
class TienLenCardDeck extends Model
{
    protected $fillable = ['key', 'name', 'is_active', 'sort_order', 'back_image', 'preview_image', 'card_images'];

    protected $casts = [
        'is_active'   => 'boolean',
        'sort_order'  => 'integer',
        'card_images' => 'array',
    ];

    protected static function booted(): void
    {
        $flush = fn () => TienLenDeckService::forget();
        static::saved($flush);
        static::deleted($flush);
    }

    /** @return string[] mã lá chưa có ảnh (VD ["QS"]) */
    public function missingCodes(): array
    {
        $have = $this->card_images ?? [];

        return array_values(array_filter(array_map(fn (int $id) => Card::code($id), Card::deck()), fn (string $c) => empty($have[$c])));
    }

    public function cardCount(): int
    {
        return 52 - count($this->missingCodes());
    }

    public static function url(?string $path): ?string
    {
        return $path ? asset($path) : null;
    }
}
