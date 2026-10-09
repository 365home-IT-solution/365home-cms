<?php

declare(strict_types=1);

namespace App\Services\Camera;

use App\Models\CameraSetting;
use App\Services\Camera\Cloud\CloudProviderRegistry;

// Danh mục lựa chọn dùng chung cho API (camera-options) và form Filament: gateway, loại kết nối, hãng,
// tính năng. FE dựng form động từ đây — thêm gateway/hãng mới không phải sửa lại danh sách ở nhiều nơi.
class CameraOptions
{
    public const GATEWAY_LABELS = [
        CameraSetting::GATEWAY_FRIGATE => 'Frigate (xem trực tiếp + lịch sử ghi hình)',
        CameraSetting::GATEWAY_GO2RTC => 'go2rtc (chỉ xem trực tiếp + ảnh)',
        CameraSetting::GATEWAY_CLOUD => 'Cloud của hãng (Imou, EZVIZ...)',
    ];

    public const SOURCE_TYPE_LABELS = [
        'existing' => 'Nguồn đã có sẵn trong Frigate/go2rtc',
        'rtsp' => 'RTSP',
        'onvif' => 'ONVIF',
        'hls' => 'HLS (m3u8)',
        'http' => 'HTTP video / MJPEG / FLV',
        'rtmp' => 'RTMP',
        'webrtc' => 'WebRTC / WHEP',
        'tapo' => 'Tapo native (go2rtc)',
        'tuya' => 'Tuya native (go2rtc)',
        'sdk_bridge' => 'Cầu nối SDK/cloud của hãng',
        'cloud' => 'Cloud của hãng (Imou, EZVIZ...)',
    ];

    public const PROVIDER_LABELS = [
        'generic' => 'Chuẩn chung / hãng khác',
        'imou' => 'Imou',
        'ezviz' => 'EZVIZ',
        'hikvision' => 'Hikvision / Hik-Connect',
        'tapo' => 'TP-Link Tapo',
        'tuya' => 'Tuya',
        'nvr' => 'NVR / DVR',
    ];

    public const CAPABILITY_LABELS = [
        'live' => 'Xem trực tiếp',
        'snapshot' => 'Ảnh mới nhất',
        'events' => 'Sự kiện',
        'recordings' => 'Danh sách ghi hình',
        'playback' => 'Phát lại ghi hình',
        'manual_recording' => 'Ghi hình thủ công trên server',
        'health_check' => 'Kiểm tra trạng thái',
        'source_sync' => 'Đồng bộ nguồn',
    ];

    public function __construct(private readonly CloudProviderRegistry $registry) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        return [
            'gateway_types' => $this->gatewayTypes(),
            'source_types' => $this->sourceTypes(),
            'providers' => $this->providers(),
            'capabilities' => array_map(
                fn (string $key, string $label): array => ['key' => $key, 'label' => $label],
                array_keys(self::CAPABILITY_LABELS),
                array_values(self::CAPABILITY_LABELS),
            ),
        ];
    }

    /** @return array<string, string> khoá => nhãn, dùng cho Select của Filament */
    public function providerOptions(): array
    {
        return self::PROVIDER_LABELS;
    }

    /** @return array<string, string> chỉ các hãng đã có connector cloud */
    public function cloudProviderOptions(): array
    {
        return array_intersect_key(self::PROVIDER_LABELS, array_flip($this->registry->keys()));
    }

    /** @return list<array<string, mixed>> */
    private function gatewayTypes(): array
    {
        $definitions = [
            CameraSetting::GATEWAY_FRIGATE => [
                'required_fields' => ['base_url', 'username', 'password'],
                'optional_fields' => ['go2rtc_url', 'api_key'],
                'capabilities' => ['live', 'snapshot', 'events', 'recordings', 'playback', 'manual_recording', 'health_check', 'source_sync'],
            ],
            CameraSetting::GATEWAY_GO2RTC => [
                'required_fields' => ['go2rtc_url'],
                'optional_fields' => ['api_key'],
                'capabilities' => ['live', 'snapshot', 'health_check', 'source_sync'],
            ],
            CameraSetting::GATEWAY_CLOUD => [
                'required_fields' => ['provider_credentials'],
                'optional_fields' => [],
                'capabilities' => ['live', 'snapshot', 'health_check'],
            ],
        ];

        $result = [];

        foreach ($definitions as $value => $definition) {
            $result[] = ['value' => $value, 'label' => self::GATEWAY_LABELS[$value], ...$definition];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function sourceTypes(): array
    {
        $result = [];

        foreach (self::SOURCE_TYPE_LABELS as $value => $label) {
            $result[] = [
                'value' => $value,
                'label' => $label,
                'requires_source_url' => ! in_array($value, ['existing', 'cloud'], true),
                'requires_device' => $value === 'cloud',
                'gateway_types' => $value === 'cloud'
                    ? [CameraSetting::GATEWAY_CLOUD]
                    : [CameraSetting::GATEWAY_FRIGATE, CameraSetting::GATEWAY_GO2RTC],
            ];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function providers(): array
    {
        $result = [];

        foreach (self::PROVIDER_LABELS as $value => $label) {
            $cloud = $this->registry->get($value);

            $result[] = [
                'value' => $value,
                'label' => $label,
                'cloud_supported' => $cloud !== null,
                'default_channel' => $cloud?->defaultChannel(),
                'supports_snapshot' => $cloud?->supportsSnapshot() ?? false,
                'credential_fields' => $cloud?->credentialFields() ?? [],
            ];
        }

        return $result;
    }
}
