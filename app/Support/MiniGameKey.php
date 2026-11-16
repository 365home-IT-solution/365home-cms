<?php

declare(strict_types=1);

namespace App\Support;

// Mã các mini game có chat / sticker / bộ âm thanh (cột `game` ở game_sound_packs, game_player_preferences, game_chat_settings…).
final class MiniGameKey
{
    public const TIEN_LEN = 'tien_len';
    public const CARO     = 'caro';

    public const LABELS = [
        self::TIEN_LEN => 'Tiến lên',
        self::CARO     => 'Caro',
    ];

    /** @return string[] */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function label(string $game): string
    {
        return self::LABELS[$game] ?? $game;
    }
}
