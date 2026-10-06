<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

// Báo cho ĐỐI TÁC đang mở trang đăng ký hợp tác (web) rằng hồ sơ của họ vừa đổi: admin duyệt/yêu cầu bổ sung/từ chối giấy tờ, duyệt hồ sơ,
// tạo lại hợp đồng, 365 Home ký hợp đồng, kích hoạt gói MiniHouse... Kênh PUBLIC theo khoá riêng của từng hồ sơ
// ("partner-onboarding.{key}" — key lấy từ `realtime.key` trong API trạng thái hồ sơ, không đoán được và không phải mã hồ sơ).
// Payload CHỈ là tín hiệu "có thay đổi": client tự gọi lại API trạng thái để lấy dữ liệu (cùng nguyên tắc với AdminNotificationRealtimeService).
// App di động nhận cùng tín hiệu qua Socket.IO — xem App\Services\PartnerOnboardingRealtimeService.
class PartnerOnboardingStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public const EVENT = 'status-changed';

    public function __construct(public string $key) {}

    public static function channelName(string $key): string
    {
        return 'partner-onboarding.' . $key;
    }

    public function broadcastOn(): array
    {
        return [new Channel(self::channelName($this->key))];
    }

    public function broadcastAs(): string
    {
        return self::EVENT;
    }

    public function broadcastWith(): array
    {
        return ['updated_at' => now()->toIso8601String()];
    }
}
