<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Lưu token OAuth2 (access/refresh) của provider ký số thật (VNPT SmartCA, API chuẩn CSC) — chỉ 1
// dòng/provider vì công ty chỉ có 1 chứng thư số Remote dùng chung.
class ContractSigningToken extends Model
{
    protected $fillable = [
        'provider',
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public static function current(string $provider): ?self
    {
        return static::where('provider', $provider)->first();
    }

    public function isExpired(): bool
    {
        // Trừ hao 60 giây để tránh vừa dùng xong access_token thì hết hạn ngay giữa lúc gọi API.
        return ! $this->expires_at || now()->addSeconds(60)->gte($this->expires_at);
    }
}
