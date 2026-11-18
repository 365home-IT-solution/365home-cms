<?php

namespace Modules\Motrak\App\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Motrak\App\Models\MotrakSyncLog;
use Modules\Motrak\App\Models\MotrakVehicle;
use Modules\Motrak\App\Services\MotrakMappingService;
use Modules\Motrak\App\Services\MotrakSettingsService;
use Modules\Motrak\App\Services\MotrakStatusService;
use Modules\Motrak\App\Services\MotrakSyncService;
use Modules\Motrak\App\Services\MotrakVehicleQuery;
use Modules\Motrak\App\Support\MotrakAccess;
use Modules\Motrak\App\Support\MotrakTransformer;

/**
 * API nội bộ GPS (admin.api): CHỈ đọc dữ liệu đã lưu trong DB 365home — không bao giờ gọi Motrak khi người dùng truy cập (trừ "kiểm tra kết nối" và
 * "đồng bộ ngay" do Super Admin chủ động bấm). Không trả Token.
 *  - Xem dữ liệu: Super Admin hoặc vai trò "Quản lý GPS" (viewGuard).
 *  - Cấu hình/đồng bộ/ánh xạ: chỉ Super Admin (configGuard).
 */
class MotrakApiController extends Controller
{
    public function __construct(
        private MotrakSettingsService $settings,
        private MotrakStatusService $status,
        private MotrakSyncService $sync,
        private MotrakMappingService $mapping,
    ) {
    }

    private function viewGuard(Request $request): ?JsonResponse
    {
        return MotrakAccess::canView($request->user()) ? null : response()->json(['message' => 'Bạn không có quyền xem dữ liệu GPS.'], 403);
    }

    private function configGuard(Request $request): ?JsonResponse
    {
        return MotrakAccess::canConfigure($request->user()) ? null : response()->json(['message' => 'Chỉ Super Admin được cấu hình GPS Motrak.'], 403);
    }

    /** Tìm xe theo vehicleId của Motrak (24 ký tự hex) hoặc IMEI (14–16 chữ số). */
    private function resolve(string $id): MotrakVehicle
    {
        $q = MotrakVehicle::query();
        if (preg_match('/^\d{14,16}$/', $id)) {
            $q->where('imei', $id);
        } else {
            $q->where('vehicle_id', $id);
        }

        return $q->firstOrFail();
    }

    // ---------------- Dữ liệu GPS (chỉ đọc DB nội bộ) ----------------

    /** GET /api/admin/gps/vehicles?q&state&moving&updated_within_minutes&linked&page&per_page */
    public function vehicles(Request $request): JsonResponse
    {
        if ($denied = $this->viewGuard($request)) {
            return $denied;
        }
        $f = $request->validate([
            'q'                      => ['nullable', 'string', 'max:60'],
            'state'                  => ['nullable', 'in:connected,disconnected,stale,no_data'],
            'moving'                 => ['nullable', 'in:moving,stopped'],
            'updated_within_minutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
            'linked'                 => ['nullable', 'in:yes,no'],
            'per_page'               => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $rows = MotrakVehicleQuery::apply(MotrakVehicle::query(), $f)->orderBy('plate')->paginate((int) ($f['per_page'] ?? 20));

        return response()->json([
            'data' => $rows->getCollection()->map(fn (MotrakVehicle $v) => MotrakTransformer::vehicle($v))->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()],
        ]);
    }

    /** GET /api/admin/gps/vehicles/{vehicleId} — {vehicleId} là vehicleId của Motrak hoặc IMEI. */
    public function show(Request $request, string $vehicleId): JsonResponse
    {
        if ($denied = $this->viewGuard($request)) {
            return $denied;
        }

        return response()->json(['data' => MotrakTransformer::vehicle($this->resolve($vehicleId))]);
    }

    /** GET /api/admin/gps/vehicles/{vehicleId}/location — vị trí mới nhất đã lưu + độ tươi dữ liệu. */
    public function location(Request $request, string $vehicleId): JsonResponse
    {
        if ($denied = $this->viewGuard($request)) {
            return $denied;
        }

        return response()->json(['data' => MotrakTransformer::location($this->resolve($vehicleId))]);
    }

    /** GET /api/admin/gps/vehicles/{vehicleId}/history?from&to&order&per_page — chỉ các điểm 365Home đã lưu. */
    public function history(Request $request, string $vehicleId): JsonResponse
    {
        if ($denied = $this->viewGuard($request)) {
            return $denied;
        }
        $f = $request->validate([
            'from'     => ['nullable', 'date'],
            'to'       => ['nullable', 'date', 'after_or_equal:from'],
            'order'    => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . (int) config('motrak.history.max_page_size', 500)],
        ]);
        $vehicle = $this->resolve($vehicleId);

        $rows = $vehicle->locations()
            ->when(! empty($f['from']), fn ($q) => $q->where('gps_time', '>=', Carbon::parse($f['from'])))
            ->when(! empty($f['to']), fn ($q) => $q->where('gps_time', '<=', Carbon::parse($f['to'])))
            ->orderBy('gps_time', $f['order'] ?? 'desc')->orderBy('id', $f['order'] ?? 'desc')
            ->paginate((int) ($f['per_page'] ?? 100));

        return response()->json([
            'data' => $rows->getCollection()->map(fn ($p) => MotrakTransformer::point($p))->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()],
            'note' => MotrakTransformer::HISTORY_NOTE,
        ]);
    }

    // ---------------- Trạng thái, cấu hình, đồng bộ ----------------

    /** GET /api/admin/gps/status — trạng thái kết nối + số liệu tổng quan. */
    public function overview(Request $request): JsonResponse
    {
        if ($denied = $this->viewGuard($request)) {
            return $denied;
        }

        $q = fn (array $f) => MotrakVehicleQuery::apply(MotrakVehicle::query(), $f)->count();

        return response()->json(['data' => [
            'connection' => $this->status->status(),
            'counts'     => [
                'total'        => MotrakVehicle::query()->count(),
                'connected'    => $q(['state' => 'connected']),
                'disconnected' => $q(['state' => 'disconnected']),
                'stale'        => $q(['state' => 'stale']),
                'no_data'      => $q(['state' => 'no_data']),
                'moving'       => $q(['moving' => 'moving']),
                'linked_rental' => $q(['linked' => 'yes']),
            ],
        ]]);
    }

    /** GET /api/admin/gps/settings — cấu hình hiện tại (KHÔNG có Token; chỉ trạng thái + 4 ký tự cuối). */
    public function settingsShow(Request $request): JsonResponse
    {
        if ($denied = $this->configGuard($request)) {
            return $denied;
        }

        return response()->json(['data' => $this->settingsPayload()]);
    }

    /** PUT /api/admin/gps/settings — body: enabled (bool), sync_interval_minutes (1,2,3,5,10,15,30). */
    public function settingsUpdate(Request $request): JsonResponse
    {
        if ($denied = $this->configGuard($request)) {
            return $denied;
        }
        $data = $request->validate([
            'enabled'               => ['required', 'boolean'],
            'sync_interval_minutes' => ['required', 'integer'],
        ]);

        try {
            $this->settings->update((bool) $data['enabled'], (int) $data['sync_interval_minutes']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->settingsPayload()]);
    }

    /** PUT /api/admin/gps/settings/token — body: token. Lưu (mã hoá) rồi KIỂM TRA KẾT NỐI ngay; trả trạng thái, không trả Token. */
    public function tokenSave(Request $request): JsonResponse
    {
        if ($denied = $this->configGuard($request)) {
            return $denied;
        }
        $data = $request->validate(['token' => ['required', 'string', 'max:4200']]);

        try {
            $this->settings->saveToken($data['token']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $this->settings->refresh();
        $log = $this->sync->testConnection((string) $request->user()->id);

        return response()->json(['data' => $this->settingsPayload() + ['check' => MotrakTransformer::syncLog($log)]]);
    }

    /** DELETE /api/admin/gps/settings/token — xoá Token và tắt đồng bộ tự động; dữ liệu GPS đã đồng bộ giữ nguyên. */
    public function tokenDelete(Request $request): JsonResponse
    {
        if ($denied = $this->configGuard($request)) {
            return $denied;
        }
        $this->settings->clearToken();
        $this->settings->refresh();

        return response()->json(['data' => $this->settingsPayload()]);
    }

    /** POST /api/admin/gps/test-connection — gọi API Motrak thật bằng Token đã lưu để kiểm tra (không lưu dữ liệu xe). */
    public function testConnection(Request $request): JsonResponse
    {
        if ($denied = $this->configGuard($request)) {
            return $denied;
        }
        $log = $this->sync->testConnection((string) $request->user()->id);

        return response()->json(['data' => ['check' => MotrakTransformer::syncLog($log), 'connection' => $this->status->status()]], $log->isOk() ? 200 : 422);
    }

    /** POST /api/admin/gps/sync — "Đồng bộ ngay". */
    public function syncNow(Request $request): JsonResponse
    {
        if ($denied = $this->configGuard($request)) {
            return $denied;
        }
        $log = $this->sync->sync(MotrakSyncLog::TYPE_MANUAL, (string) $request->user()->id);

        return response()->json(['data' => MotrakTransformer::syncLog($log)], in_array($log->status, [MotrakSyncLog::STATUS_FAILED], true) ? 422 : 200);
    }

    /** GET /api/admin/gps/sync-logs?status&sync_type&per_page */
    public function syncLogs(Request $request): JsonResponse
    {
        if ($denied = $this->viewGuard($request)) {
            return $denied;
        }
        $f = $request->validate([
            'status'    => ['nullable', 'in:success,partial,failed,skipped'],
            'sync_type' => ['nullable', 'in:manual,scheduled,test'],
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $rows = MotrakSyncLog::query()
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['sync_type']), fn ($q) => $q->where('sync_type', $f['sync_type']))
            ->orderByDesc('started_at')->orderByDesc('id')->paginate((int) ($f['per_page'] ?? 20));

        return response()->json([
            'data' => $rows->getCollection()->map(fn ($l) => MotrakTransformer::syncLog($l))->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()],
        ]);
    }

    // ---------------- Ánh xạ IMEI / xe cho thuê ----------------

    /** PUT /api/admin/gps/vehicles/{vehicleId}/mapping — body: imei (14–16 chữ số, null để bỏ), rental_vehicle_id (null để bỏ). */
    public function mappingUpdate(Request $request, string $vehicleId): JsonResponse
    {
        if ($denied = $this->configGuard($request)) {
            return $denied;
        }
        $data = $request->validate([
            'imei'              => ['present', 'nullable', 'string', 'max:20'],
            'rental_vehicle_id' => ['present', 'nullable', 'integer'],
        ]);

        try {
            $vehicle = $this->mapping->update($this->resolve($vehicleId), $data['imei'], $data['rental_vehicle_id']);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => MotrakTransformer::vehicle($vehicle->fresh())]);
    }

    /** GET /api/admin/gps/rental-suggestions — gợi ý ghép theo biển số chuẩn hoá (chưa ghép gì). */
    public function suggestions(Request $request): JsonResponse
    {
        if ($denied = $this->configGuard($request)) {
            return $denied;
        }

        return response()->json(['data' => $this->mapping->suggestions()]);
    }

    /** POST /api/admin/gps/rental-suggestions/apply — body: motrak_vehicle_ids[]. Chỉ ghép các cặp vẫn còn là gợi ý hợp lệ. */
    public function suggestionsApply(Request $request): JsonResponse
    {
        if ($denied = $this->configGuard($request)) {
            return $denied;
        }
        $data = $request->validate(['motrak_vehicle_ids' => ['required', 'array', 'min:1', 'max:500'], 'motrak_vehicle_ids.*' => ['integer']]);

        return response()->json(['data' => ['applied' => $this->mapping->applySuggestions($data['motrak_vehicle_ids'])]]);
    }

    /** Cấu hình hiện tại để hiển thị — không bao giờ có Token. */
    private function settingsPayload(): array
    {
        return [
            'enabled'               => $this->settings->isEnabled(),
            'sync_interval_minutes' => $this->settings->intervalMinutes(),
            'allowed_intervals'     => array_values((array) config('motrak.sync.allowed_intervals')),
            'endpoint'              => config('motrak.endpoint'),
            'token'                 => $this->settings->tokenInfo(),
            'connection'            => $this->status->status(),
        ];
    }
}
