<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FcmToken extends Model
{
    protected $fillable = ['user_id', 'token', 'token_hash', 'last_seen_at'];

    protected $keyType = 'int';
    public $incrementing = true;

    protected $casts = ['last_seen_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Lưu token cho user — upsert theo hash để tránh duplicate.
     * Giữ tối đa 10 token mỗi user (xoá cái cũ nhất).
     */
    public static function upsertForUser(string $userId, string $token): void
    {
        $hash = hash('sha256', $token);

        static::updateOrCreate(
            ['token_hash' => $hash],
            ['user_id' => $userId, 'token' => $token, 'last_seen_at' => now()]
        );

        // Giữ tối đa 10 token / user
        $keep = static::where('user_id', $userId)
            ->orderByDesc('last_seen_at')
            ->limit(10)
            ->pluck('id');

        static::where('user_id', $userId)
            ->whereNotIn('id', $keep)
            ->delete();
    }
}
