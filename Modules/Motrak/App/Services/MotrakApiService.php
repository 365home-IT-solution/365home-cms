<?php

namespace Modules\Motrak\App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Motrak\App\Exceptions\MotrakApiException;
use Modules\Motrak\App\Support\MotrakCoordinates;
use Modules\Motrak\App\Support\MotrakTime;

/**
 * Gọi API Motrak ĐÃ KIỂM CHỨNG: POST {endpoint} với "Authorization: Bearer {token}" + "Accept: application/json", KHÔNG có body.
 * Không đổi phương thức/xác thực, không suy đoán endpoint khác.
 *
 * - Timeout, thử lại có giới hạn (chỉ lỗi mạng tạm thời, 5xx, 429), không thử lại 401/403/404.
 * - Phân loại lỗi thành MotrakApiException::type; message luôn được làm sạch (không chứa Token).
 * - Kiểm tra cấu trúc: gốc là JSON object có "data" là mảng (rỗng vẫn hợp lệ). Từng phần tử thiếu vehicleId/biển số bị loại và đếm riêng.
 * - Không ghi DB: việc lưu nằm ở MotrakSyncService, nên lỗi API không bao giờ làm mất dữ liệu cũ.
 */
class MotrakApiService
{
    public function __construct(private MotrakSettingsService $settings)
    {
    }

    public function endpoint(): string
    {
        return (string) config('motrak.endpoint');
    }

    /**
     * @return array{vehicles: list<array<string, mixed>>, invalid: list<string>, raw_count: int, http_status: int}
     *
     * @throws MotrakApiException
     */
    public function fetchVehicles(): array
    {
        $token = $this->settings->token();
        $response = $this->send($token);

        $json = $response->json();
        if (! is_array($json) || ! array_key_exists('data', $json) || ! is_array($json['data'])) {
            throw new MotrakApiException(
                MotrakApiException::INVALID_RESPONSE,
                'Phản hồi của Motrak không đúng cấu trúc mong đợi (thiếu danh sách "data").',
                $response->status(),
            );
        }

        $vehicles = [];
        $invalid = [];
        foreach ($json['data'] as $i => $row) {
            $normalized = is_array($row) ? $this->normalize($row) : null;
            if ($normalized === null) {
                $invalid[] = "Phần tử #{$i}: thiếu vehicleId hoặc biển số.";

                continue;
            }
            $vehicles[] = $normalized;
        }

        return ['vehicles' => $vehicles, 'invalid' => $invalid, 'raw_count' => count($json['data']), 'http_status' => $response->status()];
    }

    /** Chuẩn hoá 1 phần tử của "data"; null nếu thiếu vehicleId hoặc plate. Toạ độ không hợp lệ KHÔNG làm phần tử bị loại (lat/lng = null). */
    public function normalize(array $row): ?array
    {
        $vehicleId = isset($row['vehicleId']) && (is_string($row['vehicleId']) || is_int($row['vehicleId'])) ? trim((string) $row['vehicleId']) : '';
        $plate = isset($row['plate']) && is_string($row['plate']) ? trim($row['plate']) : '';
        if ($vehicleId === '' || $plate === '') {
            return null;
        }

        $coords = MotrakCoordinates::fromRaw($row['x'] ?? null, $row['y'] ?? null);
        $time = MotrakTime::resolve($row['utcTicks'] ?? null, $row['time'] ?? null);
        $day = is_array($row['dayState'] ?? null) ? $row['dayState'] : [];
        $data = is_array($row['data'] ?? null) ? $row['data'] : [];

        return [
            'vehicle_id'      => mb_substr($vehicleId, 0, 40),
            'plate'           => mb_substr($plate, 0, 30),
            'lat'             => $coords['lat'] ?? null,
            'lng'             => $coords['lng'] ?? null,
            'speed_kmh'       => isset($row['kmh']) && is_numeric($row['kmh']) ? max(0, (int) round((float) $row['kmh'])) : null,
            'heading'         => isset($row['heading']) && is_numeric($row['heading']) ? (int) $row['heading'] : null,
            'kmage'           => isset($row['kmage']) && is_numeric($row['kmage']) ? round((float) $row['kmage'], 3) : null,
            'device_status'   => isset($row['status']) && is_numeric($row['status']) ? (int) $row['status'] : null,
            'is_connected'    => array_key_exists('isConnected', $row) && $row['isConnected'] !== null ? (bool) $row['isConnected'] : null,
            'address'         => isset($data['addr']) && is_string($data['addr']) ? mb_substr(trim($data['addr']), 0, 255) : null,
            'area'            => isset($data['admin']) && is_string($data['admin']) ? mb_substr(trim($data['admin']), 0, 100) : null,
            'gps_time'        => $time['time'],
            'utc_ticks'       => $time['ticks'],
            'time_source'     => $time['source'],
            'device_time_raw' => isset($row['time']) && is_numeric($row['time']) ? (int) $row['time'] : null,
            'last_stopped_at' => MotrakTime::fromDeviceSeconds($row['lastStopped'] ?? null),
            'day_km'          => isset($day['km']) && is_numeric($day['km']) ? round((float) $day['km'], 3) : null,
            'day_idle_seconds' => isset($day['idleSeconds']) && is_numeric($day['idleSeconds']) ? max(0, (int) $day['idleSeconds']) : null,
            'day_run_seconds' => isset($day['runSeconds']) && is_numeric($day['runSeconds']) ? max(0, (int) $day['runSeconds']) : null,
            'raw'             => $row,
        ];
    }

    private function send(string $token): Response
    {
        $cfg = (array) config('motrak.http');
        $retries = max(0, (int) ($cfg['retries'] ?? 2));
        $backoff = (array) ($cfg['backoff_ms'] ?? [500, 1500]);
        $attempt = 0;

        while (true) {
            try {
                $response = $this->request($token, $cfg)->send('POST', $this->endpoint());
            } catch (ConnectionException $e) {
                if ($attempt < $retries) {
                    $this->pause($backoff, $attempt++);

                    continue;
                }

                throw $this->connectionError($e, $token);
            } catch (\Throwable $e) {
                throw new MotrakApiException(MotrakApiException::CONNECTION, 'Lỗi kết nối tới Motrak: ' . $this->clean($e->getMessage(), $token));
            }

            $status = $response->status();
            if ($response->successful()) {
                return $response;
            }

            // Thử lại có giới hạn: chỉ 429 và 5xx (tạm thời). 401/403/404/4xx khác không thử lại.
            if (($status === 429 || $status >= 500) && $attempt < $retries) {
                $this->pause($backoff, $attempt++);

                continue;
            }

            throw $this->statusError($status);
        }
    }

    /** Request đúng như bản đã kiểm chứng: chỉ Authorization + Accept, không body. */
    private function request(string $token, array $cfg): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout((int) ($cfg['timeout'] ?? 15))
            ->connectTimeout((int) ($cfg['connect_timeout'] ?? 5));
    }

    private function statusError(int $status): MotrakApiException
    {
        return match (true) {
            in_array($status, [401, 403], true) => new MotrakApiException(MotrakApiException::UNAUTHORIZED, "Motrak từ chối Token (HTTP {$status}): Token sai, hết hạn hoặc không đủ quyền.", $status),
            $status === 404 => new MotrakApiException(MotrakApiException::NOT_FOUND, 'Motrak không tìm thấy endpoint (HTTP 404).', $status),
            $status === 429 => new MotrakApiException(MotrakApiException::RATE_LIMITED, 'Motrak giới hạn tốc độ gọi (HTTP 429). Hãy tăng chu kỳ đồng bộ.', $status),
            $status >= 500 => new MotrakApiException(MotrakApiException::SERVER_ERROR, "Máy chủ Motrak báo lỗi (HTTP {$status}).", $status),
            default => new MotrakApiException(MotrakApiException::CONNECTION, "Motrak trả mã HTTP {$status} không mong đợi.", $status),
        };
    }

    private function connectionError(ConnectionException $e, string $token): MotrakApiException
    {
        $message = strtolower($e->getMessage());
        $timeout = str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'error 28');

        return $timeout
            ? new MotrakApiException(MotrakApiException::NO_RESPONSE, 'Motrak không phản hồi kịp thời (quá thời gian chờ).')
            : new MotrakApiException(MotrakApiException::CONNECTION, 'Không kết nối được tới Motrak: ' . $this->clean($e->getMessage(), $token));
    }

    /** Xoá Token (và biến thể có tiền tố Bearer) khỏi một chuỗi trước khi hiển thị/ghi log. */
    private function clean(string $message, string $token): string
    {
        $message = str_replace([$token, 'Bearer ' . $token], '***', $message);

        return mb_substr($message, 0, 300);
    }

    private function pause(array $backoff, int $attempt): void
    {
        $ms = (int) ($backoff[$attempt] ?? end($backoff) ?: 0);
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
