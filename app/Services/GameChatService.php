<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\TienLenRoomChat;
use App\Models\CaroMatch;
use App\Models\Customer;
use App\Models\GameChatSetting;
use App\Models\TienLenRoom;
use App\Models\TienLenRoomSeat;
use App\Support\MiniGameKey;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Chat + sticker trong trận mini game.
 *
 *  - Tiến lên: chat theo PHÒNG nhiều người (mọi người đang có ghế, phòng chưa đóng). Phát qua Reverb kênh private-tien-len.room.{id},
 *    sự kiện "chat" (App\Events\TienLenRoomChat) — người gửi cũng nhận.
 *  - Caro: chat theo TRẬN (người chơi trong trận, khi đang đánh và tới 5 phút sau khi hết trận). Phát qua WS server kênh cá nhân,
 *    sự kiện "caro.chat" — chỉ người khác trong trận nhận, người gửi dùng response.
 *  - KHÔNG lưu DB. Chỉ giữ tạm history_size tin gần nhất trong cache (CACHE_TTL_MINUTES) để vào lại / mất mạng vẫn thấy.
 *  - Từ cấm (game_chat_settings.banned_words) bị thay bằng "***". Gửi nhanh hơn cooldown ⇒ 429.
 *  - Tắt tiếng người chơi khác do app tự xử lý (ẩn tin theo seat / side), server không lưu.
 */
class GameChatService
{
    public const TYPE_TEXT    = 'text';
    public const TYPE_STICKER = 'sticker';

    public const CACHE_TTL_MINUTES = 360;

    public const CARO_AFTER_FINISH_MINUTES = 5;

    public function __construct(private readonly CaroRealtimeService $caroRealtime, private readonly CaroService $caro)
    {
    }

    // =========================================================
    // TIẾN LÊN — PHÒNG
    // =========================================================

    /** @param array{type: string, text?: ?string, sticker_id?: ?int} $input */
    public function sendToTienLenRoom(Customer $customer, string $roomId, array $input): array
    {
        $seat    = $this->tienLenSeat($customer, $roomId);
        $message = $this->compose(MiniGameKey::TIEN_LEN, $customer, $input, [
            'seat' => $seat->seat,
            'name' => $customer->fullname ?: 'Khách',
        ]);

        $this->remember(MiniGameKey::TIEN_LEN, $roomId, $message);

        try {
            event(new TienLenRoomChat($roomId, self::publicMessage($message)));
        } catch (\Throwable $e) {
            // Reverb tắt / lỗi mạng: tin vẫn có trong lịch sử, app hỏi lại được bằng GET /rooms/{id}/chat
            Log::debug('TienLen: broadcast chat thất bại', ['room_id' => $roomId, 'error' => $e->getMessage()]);
        }

        return self::publicMessage($message, (string) $customer->id);
    }

    public function tienLenHistory(Customer $customer, string $roomId): array
    {
        $this->tienLenSeat($customer, $roomId);

        return $this->history(MiniGameKey::TIEN_LEN, $roomId, (string) $customer->id);
    }

    private function tienLenSeat(Customer $customer, string $roomId): TienLenRoomSeat
    {
        $room = TienLenRoom::whereKey($roomId)->where('status', '!=', TienLenRoom::STATUS_CLOSED)->first();
        $seat = $room ? TienLenRoomSeat::where('room_id', $room->id)->where('customer_id', $customer->id)->first() : null;

        if (! $seat) {
            throw (new ModelNotFoundException())->setModel(TienLenRoom::class, [$roomId]);
        }

        return $seat;
    }

    // =========================================================
    // CARO — TRẬN
    // =========================================================

    /** @param array{type: string, text?: ?string, sticker_id?: ?int} $input */
    public function sendToCaroMatch(Customer $customer, string $matchId, array $input): array
    {
        $match = $this->caroMatch($customer, $matchId);
        if ($match->status !== CaroMatch::STATUS_PLAYING
            && (! $match->finished_at || $match->finished_at->lt(now()->subMinutes(self::CARO_AFTER_FINISH_MINUTES)))) {
            throw new HttpException(409, 'Trận đấu đã kết thúc, không gửi tin nhắn được nữa.');
        }

        $message = $this->compose(MiniGameKey::CARO, $customer, $input, [
            'side' => $match->sideOf((string) $customer->id),
            'name' => $this->caro->publicName($customer),
        ]);

        $this->remember(MiniGameKey::CARO, $match->id, $message);

        $public = self::publicMessage($message);
        foreach ($match->players() as $playerId) {
            if ($playerId !== (string) $customer->id) {
                $this->caroRealtime->push($playerId, 'caro.chat', ['match_id' => $match->id, 'message' => $public]);
            }
        }

        return self::publicMessage($message, (string) $customer->id);
    }

    public function caroHistory(Customer $customer, string $matchId): array
    {
        $match = $this->caroMatch($customer, $matchId);

        return $this->history(MiniGameKey::CARO, $match->id, (string) $customer->id);
    }

    private function caroMatch(Customer $customer, string $matchId): CaroMatch
    {
        return CaroMatch::whereKey($matchId)->ofPlayer((string) $customer->id)->firstOrFail();
    }

    // =========================================================
    // CẤU HÌNH (Filament "Mini game — Chat" + API admin)
    // =========================================================

    public static function settingsPayload(GameChatSetting $s): array
    {
        return [
            'game'                     => $s->game,
            'game_label'               => MiniGameKey::label($s->game),
            'chat_enabled'             => $s->chat_enabled,
            'sticker_enabled'          => $s->sticker_enabled,
            'voice_enabled'            => $s->voice_enabled,
            // Máy chủ đã có WS server + khoá để gọi thoại (TURN riêng ở voice_turn_configured)
            'voice_configured'         => GameVoiceService::configured(),
            'voice_turn_configured'    => GameVoiceService::turnConfigured(),
            'max_length'               => $s->max_length,
            'message_cooldown_seconds' => $s->message_cooldown_seconds,
            'sticker_cooldown_seconds' => $s->sticker_cooldown_seconds,
            'history_size'             => $s->history_size,
            'banned_words'             => array_values($s->banned_words ?? []),
            'updated_at'               => $s->updated_at?->toDateTimeString(),
        ];
    }

    /** Giới hạn từng trường cấu hình (validate ở API admin và Filament). */
    public const SETTING_LIMITS = [
        'max_length'               => [10, 1000],
        'message_cooldown_seconds' => [0, 60],
        'sticker_cooldown_seconds' => [0, 60],
        'history_size'             => [0, 100],
    ];

    public static function updateSettings(string $game, array $data): GameChatSetting
    {
        $settings = GameChatSetting::for($game);
        $values   = array_intersect_key($data, array_flip(['chat_enabled', 'sticker_enabled', 'voice_enabled', ...array_keys(self::SETTING_LIMITS)]));

        if (array_key_exists('banned_words', $data)) {
            $words = array_map(fn ($w) => trim(mb_strtolower((string) $w)), (array) ($data['banned_words'] ?? []));
            $values['banned_words'] = array_values(array_unique(array_filter($words, fn (string $w) => $w !== '' && mb_strlen($w) <= 50)));
        }

        $settings->update($values);

        return $settings->refresh();
    }

    // =========================================================
    // CHUNG
    // =========================================================

    /** Lọc từ cấm: khớp nguyên từ, không phân biệt hoa thường, thay bằng "***". */
    public static function censor(string $text, array $bannedWords): string
    {
        foreach ($bannedWords as $word) {
            $word = trim((string) $word);
            if ($word === '') {
                continue;
            }
            $text = (string) preg_replace('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/iu', '***', $text);
        }

        return $text;
    }

    /**
     * Bỏ trường nội bộ trước khi trả cho app. $viewerId có ⇒ thêm is_mine.
     */
    public static function publicMessage(array $message, ?string $viewerId = null): array
    {
        $from = $message['_from'] ?? null;
        unset($message['_from']);

        return $viewerId === null ? $message : $message + ['is_mine' => $from === $viewerId];
    }

    private function compose(string $game, Customer $customer, array $input, array $sender): array
    {
        $settings = GameChatSetting::for($game);
        $type     = $input['type'] ?? self::TYPE_TEXT;

        if ($type === self::TYPE_STICKER) {
            if (! $settings->sticker_enabled) {
                throw new HttpException(403, 'Sticker đang tạm tắt.');
            }
            $sticker = GameStickerService::usable((int) ($input['sticker_id'] ?? 0), $game);
            if (! $sticker) {
                throw ValidationException::withMessages(['sticker_id' => 'Sticker không tồn tại hoặc không dùng được trong game này.']);
            }
            $this->throttle($game, (string) $customer->id, $type, $settings->sticker_cooldown_seconds);

            $payload = ['text' => null, 'sticker' => ['id' => $sticker->id, 'url' => $sticker->url(), 'pack' => $sticker->pack->key]];
        } else {
            if (! $settings->chat_enabled) {
                throw new HttpException(403, 'Chat đang tạm tắt.');
            }
            // Bỏ ký tự điều khiển (giữ xuống dòng và ZWJ của emoji ghép), gộp xuống dòng liên tiếp
            $text = preg_replace('/[^\P{Cc}\n]+/u', '', str_replace("\r\n", "\n", (string) ($input['text'] ?? '')));
            $text = trim((string) preg_replace("/\n{3,}/", "\n\n", (string) $text));
            if ($text === '') {
                throw ValidationException::withMessages(['text' => 'Nhập nội dung tin nhắn.']);
            }
            if (mb_strlen($text) > $settings->max_length) {
                throw ValidationException::withMessages(['text' => "Tin nhắn tối đa {$settings->max_length} ký tự."]);
            }
            $this->throttle($game, (string) $customer->id, $type, $settings->message_cooldown_seconds);

            $payload = ['text' => self::censor($text, $settings->banned_words ?? []), 'sticker' => null];
        }

        return [
            'id'      => (string) Str::ulid(),
            'type'    => $type,
            ...$payload,
            'sender'  => $sender,
            'sent_at' => now()->toIso8601String(),
            '_from'   => (string) $customer->id,
        ];
    }

    private function throttle(string $game, string $customerId, string $type, int $seconds): void
    {
        if ($seconds > 0 && ! Cache::add("game-chat-cd:{$game}:{$type}:{$customerId}", 1, $seconds)) {
            throw new HttpException(429, 'Bạn gửi nhanh quá, đợi một chút rồi gửi tiếp.');
        }
    }

    private function cacheKey(string $game, string $contextId): string
    {
        return "game-chat:{$game}:{$contextId}";
    }

    private function remember(string $game, string $contextId, array $message): void
    {
        $key   = $this->cacheKey($game, $contextId);
        $limit = max(0, GameChatSetting::for($game)->history_size);
        if ($limit === 0) {
            return;
        }

        $append = function () use ($key, $message, $limit) {
            $list   = Cache::get($key, []);
            $list[] = $message;
            Cache::put($key, array_slice($list, -$limit), now()->addMinutes(self::CACHE_TTL_MINUTES));
        };

        $store = Cache::getStore();
        if ($store instanceof LockProvider) {
            try {
                Cache::lock($key . ':lock', 3)->block(2, $append);

                return;
            } catch (\Throwable $e) {
                Log::debug('Game chat: không lấy được lock lịch sử', ['key' => $key, 'error' => $e->getMessage()]);
            }
        }
        $append();
    }

    private function history(string $game, string $contextId, string $viewerId): array
    {
        $settings = GameChatSetting::for($game);

        return [
            'chat_enabled'    => $settings->chat_enabled,
            'sticker_enabled' => $settings->sticker_enabled,
            // Gọi thoại dùng được (admin bật + máy chủ đã cấu hình) — lấy vé ở POST .../voice
            'voice_enabled'   => GameVoiceService::availableFor($game),
            'max_length'      => $settings->max_length,
            'messages'        => array_map(fn (array $m) => self::publicMessage($m, $viewerId), Cache::get($this->cacheKey($game, $contextId), [])),
        ];
    }
}
