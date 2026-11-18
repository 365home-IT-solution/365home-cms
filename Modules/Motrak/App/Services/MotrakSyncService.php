<?php

namespace Modules\Motrak\App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Motrak\App\Contracts\HistoryPriorityResolver;
use Modules\Motrak\App\Events\MotrakSynced;
use Modules\Motrak\App\Exceptions\MotrakApiException;
use Modules\Motrak\App\Models\MotrakSyncLog;
use Modules\Motrak\App\Models\MotrakVehicle;
use Modules\Motrak\App\Models\MotrakVehicleLocation;
use Modules\Motrak\App\Support\MotrakCoordinates;

/**
 * Đồng bộ GPS Motrak → DB 365home: cập nhật danh sách xe + vị trí mới nhất + lịch sử (theo chính sách), ghi nhật ký.
 *
 * Nguyên tắc an toàn dữ liệu:
 *  - API lỗi/timeout/Token sai: chỉ ghi nhật ký, KHÔNG xoá/ghi đè vị trí đã có.
 *  - Toạ độ không hợp lệ (null, 0/0, ngoài miền): không ghi đè vị trí cuối cùng hợp lệ.
 *  - Điểm cũ hơn điểm đang lưu (utcTicks nhỏ hơn) không làm lùi vị trí.
 *  - Không tạo bản ghi lịch sử trùng: duy nhất theo (xe, utc_ticks).
 *  - Khoá cache chống 2 lần đồng bộ chạy đồng thời.
 */
class MotrakSyncService
{
    public const LOCK_KEY = 'motrak:sync';

    public function __construct(private MotrakApiService $api, private MotrakSettingsService $settings)
    {
    }

    public function sync(string $type = MotrakSyncLog::TYPE_MANUAL, ?string $userId = null): MotrakSyncLog
    {
        $lock = Cache::lock(self::LOCK_KEY, (int) config('motrak.sync.lock_seconds', 120));

        if (! $lock->get()) {
            return MotrakSyncLog::create([
                'sync_type' => $type, 'status' => MotrakSyncLog::STATUS_SKIPPED, 'triggered_by' => $userId,
                'error_message' => 'Đang có một lần đồng bộ khác chạy — bỏ qua lần này.', 'started_at' => now(), 'completed_at' => now(),
            ]);
        }

        try {
            return $this->run($type, $userId);
        } finally {
            $lock->release();
        }
    }

    /** Kiểm tra kết nối: gọi API thật, kiểm tra cấu trúc, KHÔNG lưu dữ liệu xe. Ghi nhật ký loại "test". */
    public function testConnection(?string $userId = null): MotrakSyncLog
    {
        $log = $this->openLog(MotrakSyncLog::TYPE_TEST, $userId);

        try {
            $result = $this->api->fetchVehicles();
        } catch (MotrakApiException $e) {
            return $this->failLog($log, $e);
        }

        $log->update([
            'status'         => MotrakSyncLog::STATUS_SUCCESS,
            'http_status'    => $result['http_status'],
            'total_vehicles' => $result['raw_count'],
            'success_count'  => count($result['vehicles']),
            'failed_count'   => count($result['invalid']),
            'completed_at'   => now(),
        ]);

        return $log;
    }

    private function run(string $type, ?string $userId): MotrakSyncLog
    {
        $log = $this->openLog($type, $userId);

        try {
            $result = $this->api->fetchVehicles();
        } catch (MotrakApiException $e) {
            return $this->failLog($log, $e);
        }

        return $this->persistResult($log, $result);
    }

    /**
     * Nạp các phần tử "data" THÔ (cùng cấu trúc response refreshvehicles) qua ĐÚNG đường xử lý của đồng bộ (đổi toạ độ/thời gian, chính sách lịch sử,
     * nhật ký, sự kiện) nhưng không gọi API. Dùng cho dữ liệu mẫu / nhập thủ công khi phát triển; ghi nhật ký loại "manual" kèm ghi chú.
     *
     * @param  list<mixed>  $rawItems
     */
    public function importSample(array $rawItems, ?string $userId = null): MotrakSyncLog
    {
        $log = $this->openLog(MotrakSyncLog::TYPE_MANUAL, $userId);
        $vehicles = [];
        $invalid = [];
        foreach ($rawItems as $i => $row) {
            $n = is_array($row) ? $this->api->normalize($row) : null;
            $n ? $vehicles[] = $n : $invalid[] = "Phần tử #{$i}: thiếu vehicleId hoặc biển số.";
        }

        return $this->persistResult($log, ['vehicles' => $vehicles, 'invalid' => $invalid, 'raw_count' => count($rawItems), 'http_status' => null], 'Dữ liệu mẫu (không gọi API Motrak).');
    }

    /** @param  array{vehicles: list<array<string, mixed>>, invalid: list<string>, raw_count: int, http_status: ?int}  $result */
    private function persistResult(MotrakSyncLog $log, array $result, ?string $note = null): MotrakSyncLog
    {
        $now = now();
        $success = 0;
        $failed = count($result['invalid']);
        $newLocations = 0;
        $noPosition = 0;
        $changed = [];
        $notes = array_slice($result['invalid'], 0, 3);
        if ($note) {
            array_unshift($notes, $note);
        }

        foreach ($result['vehicles'] as $item) {
            try {
                $r = $this->persistVehicle($item, $now);
                $success++;
                $newLocations += $r['history'] ? 1 : 0;
                $noPosition += $r['no_position'] ? 1 : 0;
                if ($r['new_point']) {
                    $changed[] = $r['id'];
                }
            } catch (\Throwable $e) {
                $failed++;
                $notes[] = "Xe {$item['plate']}: lỗi lưu dữ liệu.";
                Log::warning('Motrak: lỗi lưu 1 phương tiện', ['plate' => $item['plate'], 'error' => $e->getMessage()]);
            }
        }

        // Xe không còn trong danh sách: giữ nguyên dữ liệu, chỉ đánh dấu không có trong lần đồng bộ này.
        MotrakVehicle::query()->whereNotIn('vehicle_id', array_column($result['vehicles'], 'vehicle_id'))->update(['in_last_sync' => false]);

        if ($noPosition > 0) {
            $notes[] = "{$noPosition} xe không có toạ độ hợp lệ (giữ nguyên vị trí cũ).";
        }

        $log->update([
            'status'         => $failed > 0 ? MotrakSyncLog::STATUS_PARTIAL : MotrakSyncLog::STATUS_SUCCESS,
            'http_status'    => $result['http_status'],
            'total_vehicles' => $result['raw_count'],
            'success_count'  => $success,
            'failed_count'   => $failed,
            'new_locations'  => $newLocations,
            'error_message'  => $notes ? implode(' ', $notes) : null,
            'completed_at'   => now(),
        ]);

        try {
            MotrakSynced::dispatch($changed, $log->id);
        } catch (\Throwable $e) {
            // Lỗi ở module lắng nghe (VD cảnh báo xe thuê) không được làm hỏng đồng bộ.
            Log::warning('Motrak: lỗi xử lý sau đồng bộ', ['error' => $e->getMessage()]);
        }

        return $log;
    }

    /** @return array{id: int, new_point: bool, history: bool, no_position: bool} */
    private function persistVehicle(array $item, Carbon $now): array
    {
        $mv = MotrakVehicle::query()->where('vehicle_id', $item['vehicle_id'])->first()
            ?? new MotrakVehicle(['vehicle_id' => $item['vehicle_id'], 'first_seen_at' => $now]);

        $prevTicks = $mv->utc_ticks;
        $prev = ['lat' => $mv->latitude, 'lng' => $mv->longitude];

        $mv->plate = $item['plate'];
        $mv->plate_normalized = MotrakVehicle::normalizePlate($item['plate']);
        $mv->in_last_sync = true;
        $mv->last_synced_at = $now;

        $hasCoords = $item['lat'] !== null && $item['lng'] !== null;
        $isOlder = $prevTicks !== null && $item['utc_ticks'] !== null && $item['utc_ticks'] < $prevTicks;
        $isNewPoint = $hasCoords && ! $isOlder && ($item['utc_ticks'] === null || $item['utc_ticks'] !== $prevTicks);

        if ($hasCoords && ! $isOlder) {
            $mv->fill([
                'latitude' => $item['lat'], 'longitude' => $item['lng'], 'speed_kmh' => $item['speed_kmh'], 'heading' => $item['heading'],
                'kmage' => $item['kmage'], 'day_km' => $item['day_km'], 'day_idle_seconds' => $item['day_idle_seconds'], 'day_run_seconds' => $item['day_run_seconds'],
                'address' => $item['address'], 'administrative_area' => $item['area'], 'device_status' => $item['device_status'],
                'is_connected' => $item['is_connected'], 'gps_time' => $item['gps_time'], 'utc_ticks' => $item['utc_ticks'],
                'device_time_raw' => $item['device_time_raw'], 'last_stopped_at' => $item['last_stopped_at'], 'raw_data' => $item['raw'],
            ]);
        } elseif (! $hasCoords) {
            // Không có toạ độ hợp lệ: giữ nguyên vị trí cuối cùng, chỉ cập nhật cờ trạng thái thô.
            $mv->fill(['device_status' => $item['device_status'], 'is_connected' => $item['is_connected']]);
        }

        $stored = false;
        if ($isNewPoint && $item['utc_ticks'] !== null && $item['gps_time'] !== null && $this->shouldStoreHistory($mv, $item, $prev)) {
            $stored = MotrakVehicleLocation::query()->insertOrIgnore([[
                'motrak_vehicle_id' => $mv->exists ? $mv->id : $this->persistNew($mv),
                'latitude' => $item['lat'], 'longitude' => $item['lng'], 'speed_kmh' => $item['speed_kmh'], 'heading' => $item['heading'],
                'kmage' => $item['kmage'], 'address' => $item['address'], 'administrative_area' => $item['area'],
                'device_status' => $item['device_status'], 'is_connected' => $item['is_connected'] === null ? null : (int) $item['is_connected'],
                'gps_time' => $item['gps_time']->toDateTimeString(), 'utc_ticks' => $item['utc_ticks'], 'created_at' => $now->toDateTimeString(),
            ]]) > 0;
            if ($stored) {
                $mv->last_history_at = $now;
            }
        }

        $mv->save();

        return ['id' => $mv->id, 'new_point' => $isNewPoint, 'history' => $stored, 'no_position' => ! $hasCoords];
    }

    /** Xe mới chưa có id nhưng cần id để ghi lịch sử: lưu trước rồi trả id. */
    private function persistNew(MotrakVehicle $mv): int
    {
        $mv->save();

        return $mv->id;
    }

    /**
     * Chính sách lưu lịch sử: điểm đầu tiên; xe được module khác ưu tiên (VD đang trong chuyến thuê) → mọi điểm mới;
     * xe đang chạy → khi đã dịch chuyển đủ min_distance_meters; còn lại → tối đa 1 điểm mỗi min_interval_minutes.
     *
     * @param  array{lat: ?float, lng: ?float}  $prev  vị trí đã thấy ở lần đồng bộ trước
     */
    private function shouldStoreHistory(MotrakVehicle $mv, array $item, array $prev): bool
    {
        if ($mv->last_history_at === null || ! $mv->exists) {
            return true;
        }

        if (app()->bound(HistoryPriorityResolver::class) && app(HistoryPriorityResolver::class)->needsFullHistory($mv)) {
            return true;
        }

        $last = MotrakVehicleLocation::query()->where('motrak_vehicle_id', $mv->id)->orderByDesc('gps_time')->first(['latitude', 'longitude', 'gps_time']);
        if (! $last) {
            return true;
        }

        $moving = ($item['speed_kmh'] ?? 0) >= (int) config('motrak.freshness.moving_speed_kmh', 3);
        $distance = MotrakCoordinates::distanceMeters($last->latitude, $last->longitude, $item['lat'], $item['lng']);
        if ($moving && $distance >= (int) config('motrak.history.min_distance_meters', 50)) {
            return true;
        }

        return $item['gps_time']->diffInMinutes($last->gps_time, true) >= (int) config('motrak.history.min_interval_minutes', 10);
    }

    private function openLog(string $type, ?string $userId): MotrakSyncLog
    {
        // Mặc định "failed" cho tới khi hoàn tất: lần chạy bị ngắt giữa chừng hiện rõ (completed_at rỗng) thay vì mất dấu.
        return MotrakSyncLog::create([
            'sync_type' => $type, 'status' => MotrakSyncLog::STATUS_FAILED, 'triggered_by' => $userId,
            'error_message' => 'Chưa hoàn tất.', 'started_at' => now(),
        ]);
    }

    private function failLog(MotrakSyncLog $log, MotrakApiException $e): MotrakSyncLog
    {
        $log->update([
            'status' => MotrakSyncLog::STATUS_FAILED, 'error_type' => $e->type, 'http_status' => $e->httpStatus,
            'error_message' => $e->getMessage(), 'completed_at' => now(),
        ]);

        return $log;
    }
}
