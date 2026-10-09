<?php

declare(strict_types=1);

namespace App\Services\Camera;

use App\Models\Camera;

// Một "cách lấy luồng camera" — chọn theo từng đối tác/toà nhà (CameraSetting::gatewayType()) và theo
// từng camera (source_type = cloud). Mọi nơi cần live/ảnh/kiểm tra/đồng bộ đều đi qua đây thay vì
// giả định ai cũng dùng Frigate; tính năng không có thì capabilities() báo false để API/giao diện ẩn.
interface CameraGateway
{
    public const CAP_LIVE = 'live';

    public const CAP_SNAPSHOT = 'snapshot';

    public const CAP_EVENTS = 'events';

    public const CAP_RECORDINGS = 'recordings';

    public const CAP_PLAYBACK = 'playback';

    public const CAP_MANUAL_RECORDING = 'manual_recording';

    public const CAP_HEALTH_CHECK = 'health_check';

    public const CAP_SOURCE_SYNC = 'source_sync';

    public const CAPABILITIES = [
        self::CAP_LIVE,
        self::CAP_SNAPSHOT,
        self::CAP_EVENTS,
        self::CAP_RECORDINGS,
        self::CAP_PLAYBACK,
        self::CAP_MANUAL_RECORDING,
        self::CAP_HEALTH_CHECK,
        self::CAP_SOURCE_SYNC,
    ];

    public function type(): string;

    /** @return array<string, bool> đủ mọi khoá trong CAPABILITIES */
    public function capabilities(Camera $camera): array;

    /**
     * Hợp đồng chung cho app: luôn đủ khoá, giá trị không áp dụng = null.
     *
     * @return array{gateway_type: string, preferred: ?string, capabilities: array<string, bool>, mse_ws_url: ?string, webview_url: ?string, hls_url: ?string, mjpeg_url: ?string, rtsp_url: ?string, latest_image_url: ?string, expires_in: ?int, message: ?string}
     */
    public function liveOptions(Camera $camera): array;

    /** URL ảnh mới nhất dùng được ngay, hoặc null nếu không lấy được/không hỗ trợ. */
    public function latestImageUrl(Camera $camera, ?string &$error = null): ?string;

    /** @return array{online: bool, message: ?string, details?: mixed} */
    public function health(Camera $camera): array;

    /** Trả null nếu thành công hoặc không có gì để đồng bộ, hoặc chuỗi lỗi tiếng Việt. */
    public function sync(Camera $camera, ?string $oldStreamKey = null, ?Camera $oldCamera = null): ?string;
}
