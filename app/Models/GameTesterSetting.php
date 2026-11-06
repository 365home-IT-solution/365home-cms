<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Công tắc trang thử mini game (1 dòng duy nhất). Tắt ⇒ /game-tester, /tien-len-tester và API /api/admin/games/dev/* trả 404.
class GameTesterSetting extends Model
{
    protected $fillable = ['is_enabled'];

    protected $casts = ['is_enabled' => 'boolean'];

    public static function current(): self
    {
        // refresh(): lấy giá trị mặc định từ DB cho dòng vừa tạo (server mới chưa có dòng nào), tránh thuộc tính null
        return static::query()->first() ?? static::create([])->refresh();
    }

    public static function enabled(): bool
    {
        return (bool) static::current()->is_enabled;
    }
}
