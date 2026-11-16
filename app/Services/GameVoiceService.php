<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CaroMatch;
use App\Models\Customer;
use App\Models\GameChatSetting;
use App\Models\TienLenRoom;
use App\Models\TienLenRoomSeat;
use App\Support\MiniGameKey;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Gọi thoại trực tiếp trong mini game — tự dựng WebRTC (mesh, mỗi người nối thẳng tới từng người còn lại) + TURN (coturn).
 * Laravel KHÔNG truyền âm thanh, chỉ:
 *  1. Kiểm tra quyền và cấp "vé" vào phòng thoại (HMAC-SHA256 bằng WS_INTERNAL_KEY, giống vé camera — App\Support\CameraWsToken).
 *     App mang vé sang WS server (websocket/server.js, sự kiện voice:join) — WS server chuyển offer / answer / ICE giữa các máy.
 *  2. Cấp ICE server: STUN + TURN với mật khẩu có hạn theo chuẩn TURN REST API của coturn (use-auth-secret):
 *     username = "{hết hạn unix}:{nhãn}", credential = base64(HMAC-SHA1(TURN_SECRET, username)).
 *  3. Đẩy người khỏi phòng thoại khi mất quyền (rời ghế / bị mời ra / phòng đóng / hết trận) — gọi WS server /internal/voice-kick.
 *
 * Phạm vi:
 *  - Tiến lên: phòng nhiều người, ai đang có ghế (phòng chưa đóng). Phòng thoại "tien-len:{room_id}", mã người "seat-{ghế}".
 *  - Caro: người chơi của trận đang đánh. Phòng thoại "caro:{match_id}", mã người "side-{x|o|t}". Hết trận ⇒ đóng phòng thoại.
 * Mã người theo ghế / quân (không lộ id khách cho người khác). Bật/tắt mic: app tự tắt track + báo voice:mute; tắt tiếng người khác: app tự làm.
 *
 * Lệnh đẩy được gom lại và gửi SAU khi trả response (app()->terminating) — WS server chậm / lỗi không làm hỏng thao tác game, chỉ ghi log.
 */
class GameVoiceService
{
    public const TICKET_TTL = 60;

    /** @var array<int, array{room: string, peer_id: ?string, reason: string}> */
    private array $pending = [];

    private bool $flushRegistered = false;

    // =========================================================
    // CẤU HÌNH
    // =========================================================

    /** Có WS server để signaling (bắt buộc). TURN không bắt buộc nhưng thiếu thì mạng 4G / NAT chặt thường không nối được. */
    public static function configured(): bool
    {
        return self::signalingUrl() !== '' && (string) config('services.websocket.internal_key') !== '';
    }

    public static function turnConfigured(): bool
    {
        return self::turnUrls() !== [] && (string) config('services.turn.secret') !== '';
    }

    public static function availableFor(string $game): bool
    {
        return self::configured() && GameChatSetting::for($game)->voice_enabled;
    }

    public static function signalingUrl(): string
    {
        return rtrim((string) config('services.websocket.public_url'), '/');
    }

    /** @return string[] */
    private static function turnUrls(): array
    {
        return self::list((string) config('services.turn.urls'));
    }

    /** @return string[] */
    private static function list(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }

    // =========================================================
    // CẤP VÉ
    // =========================================================

    public function tienLenJoin(Customer $customer, string $roomId): array
    {
        $this->assertAvailable(MiniGameKey::TIEN_LEN);

        $room = TienLenRoom::whereKey($roomId)->where('status', '!=', TienLenRoom::STATUS_CLOSED)->first();
        $seat = $room ? TienLenRoomSeat::where('room_id', $room->id)->where('customer_id', $customer->id)->first() : null;
        if (! $seat) {
            throw (new ModelNotFoundException())->setModel(TienLenRoom::class, [$roomId]);
        }

        return $this->issue(MiniGameKey::TIEN_LEN, self::tienLenRoom($room->id), "seat-{$seat->seat}", $customer->fullname ?: 'Khách',
            ['seat' => $seat->seat], $room->max_players);
    }

    public function caroJoin(Customer $customer, string $matchId, string $publicName): array
    {
        $this->assertAvailable(MiniGameKey::CARO);

        $match = CaroMatch::whereKey($matchId)->ofPlayer((string) $customer->id)->firstOrFail();
        if ($match->status !== CaroMatch::STATUS_PLAYING) {
            throw new HttpException(409, 'Trận đấu đã kết thúc, phòng thoại đã đóng.');
        }
        $side = (string) $match->sideOf((string) $customer->id);

        return $this->issue(MiniGameKey::CARO, self::caroRoom($match->id), "side-{$side}", $publicName, ['side' => $side], $match->player_count);
    }

    public static function tienLenRoom(string $roomId): string
    {
        return "tien-len:{$roomId}";
    }

    public static function caroRoom(string $matchId): string
    {
        return "caro:{$matchId}";
    }

    private function assertAvailable(string $game): void
    {
        if (! self::configured()) {
            throw new HttpException(503, 'Gọi thoại chưa được cấu hình trên máy chủ.');
        }
        if (! GameChatSetting::for($game)->voice_enabled) {
            throw new HttpException(403, 'Gọi thoại đang tạm tắt.');
        }
    }

    private function issue(string $game, string $room, string $peerId, string $name, array $meta, int $maxPeers): array
    {
        $exp = time() + self::TICKET_TTL;

        return [
            'room'       => $room,
            'peer_id'    => $peerId,
            'name'       => $name,
            'metadata'   => ['game' => $game] + $meta,
            'max_peers'  => $maxPeers,
            'signaling'  => [
                'url'        => self::signalingUrl(),
                'ticket'     => self::sign([
                    'kind' => 'voice', 'room' => $room, 'peer' => $peerId, 'name' => $name, 'meta' => ['game' => $game] + $meta,
                    'max' => $maxPeers, 'exp' => $exp,
                ]),
                'expires_at' => date(DATE_ATOM, $exp),
            ],
            'ice_servers' => self::iceServers($room . ':' . $peerId),
        ];
    }

    /** STUN + TURN (mật khẩu có hạn). Định dạng đúng RTCIceServer để app đưa thẳng vào RTCPeerConnection. */
    public static function iceServers(string $label): array
    {
        $servers = [];
        $stun    = self::list((string) config('services.turn.stun_urls'));
        if ($stun !== []) {
            $servers[] = ['urls' => $stun];
        }

        if (self::turnConfigured()) {
            $expires    = time() + max(300, (int) config('services.turn.ttl', 21600));
            $username   = $expires . ':' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $label);
            $servers[] = [
                'urls'       => self::turnUrls(),
                'username'   => $username,
                'credential' => base64_encode(hash_hmac('sha1', $username, (string) config('services.turn.secret'), true)),
            ];
        }

        return $servers;
    }

    public static function sign(array $payload): string
    {
        $b64 = base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $b64 . '.' . hash_hmac('sha256', $b64, (string) config('services.websocket.internal_key'));
    }

    // =========================================================
    // ĐẨY KHỎI PHÒNG THOẠI (gọi từ TienLenRoomService / CaroService)
    // =========================================================

    public function kickTienLenSeat(string $roomId, int $seat, string $reason = 'left'): void
    {
        $this->queue(self::tienLenRoom($roomId), "seat-{$seat}", $reason);
    }

    public function closeTienLenRoom(string $roomId): void
    {
        $this->queue(self::tienLenRoom($roomId), null, 'closed');
    }

    public function closeCaroMatch(string $matchId): void
    {
        $this->queue(self::caroRoom($matchId), null, 'finished');
    }

    private function queue(string $room, ?string $peerId, string $reason): void
    {
        if ((string) config('services.websocket.url') === '') {
            return;
        }

        $this->pending[] = ['room' => $room, 'peer_id' => $peerId, 'reason' => $reason];
        if (! $this->flushRegistered) {
            $this->flushRegistered = true;
            app()->terminating(fn () => $this->flush());
        }
    }

    public function flush(): void
    {
        $calls                 = $this->pending;
        $this->pending         = [];
        $this->flushRegistered = false;

        $url = rtrim((string) config('services.websocket.url'), '/') . '/internal/voice-kick';
        foreach ($calls as $body) {
            try {
                Http::withHeaders(['x-internal-key' => (string) config('services.websocket.internal_key')])->timeout(2)->post($url, $body);
            } catch (\Throwable $e) {
                Log::warning('Voice: không báo được WS server đẩy khỏi phòng thoại', $body + ['error' => $e->getMessage()]);
            }
        }
    }
}
