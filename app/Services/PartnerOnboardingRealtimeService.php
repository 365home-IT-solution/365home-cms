<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\PartnerOnboardingStatusChanged;
use App\Models\Partner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// REALTIME cho đối tác đang theo dõi hồ sơ đăng ký hợp tác: mỗi khi hồ sơ / giấy tờ / hợp đồng / gói của đối tác đổi (xem các model hook đăng ký ở
// AppServiceProvider::registerPartnerOnboardingRealtime), đẩy MỘT tín hiệu "có thay đổi" qua 2 kênh:
//   - Reverb (web): sự kiện App\Events\PartnerOnboardingStatusChanged trên kênh public "partner-onboarding.{key}";
//   - Socket.IO (app): phòng "partner-onboarding:{key}", sự kiện "partner_onboarding.updated" (websocket/server.js).
// Chỉ là tín hiệu — client gọi lại API trạng thái hồ sơ để lấy dữ liệu. Gửi lỗi (Reverb/WS không chạy) không được làm hỏng luồng chính.
// Các thay đổi trong cùng một request được GOM lại và gửi một lần khi request kết thúc (sau khi dữ liệu đã lưu xong).
class PartnerOnboardingRealtimeService
{
    public const SOCKET_SUBSCRIBE = 'subscribe:partner-onboarding';

    public const SOCKET_EVENT = 'partner_onboarding.updated';

    /** @var array<string, true> id đối tác chờ gửi tín hiệu */
    private array $pending = [];

    private bool $registered = false;

    /** Khoá kênh realtime của một hồ sơ: suy từ mã hồ sơ đã băm, không đoán được, KHÔNG dùng để gọi API. Hồ sơ không có mã → null. */
    public static function keyFor(Partner $partner): ?string
    {
        return filled($partner->onboarding_token) ? substr(hash('sha256', 'partner-onboarding-realtime|' . $partner->onboarding_token), 0, 40) : null;
    }

    /** Thông tin kết nối trả trong API trạng thái hồ sơ (`realtime`) để FE tự subscribe; null khi hồ sơ không có mã. */
    public static function descriptor(Partner $partner): ?array
    {
        $key = self::keyFor($partner);

        return $key === null ? null : [
            'key'    => $key,
            'reverb' => ['channel' => PartnerOnboardingStatusChanged::channelName($key), 'event' => PartnerOnboardingStatusChanged::EVENT],
            'socket' => ['subscribe' => self::SOCKET_SUBSCRIBE, 'unsubscribe' => 'unsubscribe:partner-onboarding', 'event' => self::SOCKET_EVENT],
        ];
    }

    public function queue(Partner|string|null $partner): void
    {
        $id = $partner instanceof Partner ? $partner->getKey() : $partner;
        if (blank($id)) {
            return;
        }
        $this->pending[(string) $id] = true;

        // Lệnh artisan / hàng đợi: không có "kết thúc request" → gửi ngay.
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            $this->flush();

            return;
        }
        if (! $this->registered) {
            $this->registered = true;
            app()->terminating(fn () => $this->flush());
        }
    }

    /** Gửi tín hiệu cho mọi đối tác đang chờ. Trả về số hồ sơ đã gửi. */
    public function flush(): int
    {
        $ids = array_keys($this->pending);
        $this->pending = [];
        if ($ids === []) {
            return 0;
        }

        $sent = 0;
        foreach (Partner::withTrashed()->whereIn('id', $ids)->whereNotNull('onboarding_token')->get() as $partner) {
            $key = self::keyFor($partner);
            if ($key === null) {
                continue;
            }
            $this->broadcast($key);
            $sent++;
        }

        return $sent;
    }

    private function broadcast(string $key): void
    {
        // Test tự động: chỉ phát sự kiện khi test đã Event::fake() (để kiểm tra), không gọi Reverb thật.
        if (app()->runningUnitTests() && ! (\Illuminate\Support\Facades\Event::getFacadeRoot() instanceof \Illuminate\Support\Testing\Fakes\EventFake)) {
            return;
        }
        try {
            event(new PartnerOnboardingStatusChanged($key));
        } catch (\Throwable $e) {
            Log::warning('Partner onboarding realtime: Reverb broadcast failed', ['error' => $e->getMessage()]);
        }

        $url = rtrim((string) config('services.websocket.url', ''), '/');
        if ($url === '' || app()->runningUnitTests()) {
            return;
        }
        try {
            Http::withHeaders(['x-internal-key' => (string) config('services.websocket.internal_key', '')])
                ->timeout(2)
                ->post("{$url}/internal/partner-onboarding", ['key' => $key]);
        } catch (\Throwable $e) {
            Log::warning('Partner onboarding realtime: WS push failed', ['error' => $e->getMessage()]);
        }
    }
}
