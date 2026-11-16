<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Cấu hình chat / sticker trong trận của 1 mini game (1 dòng / game). Xem App\Services\GameChatService.
class GameChatSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'game';

    protected $keyType = 'string';

    protected $fillable = ['game', 'chat_enabled', 'sticker_enabled', 'voice_enabled', 'max_length', 'message_cooldown_seconds', 'sticker_cooldown_seconds',
        'history_size', 'banned_words'];

    // Trùng mặc định của migration — dòng vừa firstOrCreate có đủ giá trị mà không phải đọc lại DB
    protected $attributes = [
        'chat_enabled'             => true,
        'sticker_enabled'          => true,
        'voice_enabled'            => true,
        'max_length'               => 200,
        'message_cooldown_seconds' => 1,
        'sticker_cooldown_seconds' => 2,
        'history_size'             => 30,
    ];

    protected $casts = [
        'chat_enabled'             => 'boolean',
        'sticker_enabled'          => 'boolean',
        'voice_enabled'            => 'boolean',
        'max_length'               => 'integer',
        'message_cooldown_seconds' => 'integer',
        'sticker_cooldown_seconds' => 'integer',
        'history_size'             => 'integer',
        'banned_words'             => 'array',
    ];

    public static function for(string $game): self
    {
        return self::firstOrCreate(['game' => $game], ['banned_words' => []]);
    }
}
