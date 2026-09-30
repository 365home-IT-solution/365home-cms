<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Services\ContractTtlockService;
use Modules\TTLock\App\Services\TTLockService;
use App\Models\User;
use App\Services\RoomEmergencyAccessService;

// Gán khoá TTLock cho Phòng + mở khoá theo Phòng — bản API của RoomLockActions (Filament) và của
// Api\Admin\ProductController::unlock() bên Home. Tài khoản TTLock lấy theo Toà nhà của phòng.
// Cần quyền "page_ttlock_locks" (hoặc super_admin) VÀ Toà nhà của phòng nằm trong phạm vi được phân quyền.
class RoomLockController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/rooms/{id}/lock
    public function show(Request $request, string $id): JsonResponse
    {
        [$room, $error] = $this->room($request, $id);

        return $error ?? response()->json(['data' => $this->transform($room)]);
    }

    // PUT/PATCH /api/admin/minihouse/rooms/{id}/lock
    // { lock_id?, lock_id_checkout?, unlock_both_locks? } — gửi null để gỡ khoá; bỏ field = giữ nguyên.
    public function update(Request $request, string $id): JsonResponse
    {
        [$room, $error] = $this->room($request, $id);

        if ($error) {
            return $error;
        }

        $data = $request->validate([
            'lock_id'           => 'nullable|integer',
            'lock_id_checkout'  => 'nullable|integer',
            'unlock_both_locks' => 'nullable|boolean',
        ]);

        $ttlock = TTLockService::forBuilding((int) $room->building_id);

        if (! $ttlock) {
            return response()->json(['message' => 'Toà nhà của phòng này chưa cấu hình tài khoản TTLock (hoặc đang tắt).'], 422);
        }

        // Chỉ cho gán khoá THUỘC tài khoản TTLock của Toà nhà (chặn gán lockId bất kỳ).
        $owned = collect($ttlock->getLockList())->pluck('lockId')->map(fn ($v) => (int) $v)->all();

        foreach (['lock_id', 'lock_id_checkout'] as $field) {
            if (filled($data[$field] ?? null) && ! in_array((int) $data[$field], $owned, true)) {
                return response()->json(['message' => "Khoá {$field} không thuộc tài khoản TTLock của Toà nhà này."], 422);
            }
        }

        $update = [];
        foreach (['lock_id', 'lock_id_checkout'] as $field) {
            if ($request->has($field)) {
                $update[$field] = $data[$field] ?? null;
            }
        }
        if ($request->has('unlock_both_locks')) {
            $update['unlock_both_locks'] = (bool) $data['unlock_both_locks'];
        }

        $room->update($update);

        // Phòng đang có hợp đồng hiệu lực -> cấp/thu hồi mã mở TTLock ngay theo đúng khoá vừa gán.
        ContractTtlockService::syncForRoom($room->id);

        return response()->json(['data' => $this->transform($room->fresh())]);
    }

    // POST /api/admin/minihouse/rooms/{id}/unlock — mở khoá phòng từ xa, mirror ProductController::unlock() (Home).
    public function unlock(Request $request, string $id): JsonResponse
    {
        [$room, $error] = $this->room($request, $id);

        if ($error) {
            return $error;
        }

        $ttlock = TTLockService::forBuilding((int) $room->building_id);

        if (! $ttlock) {
            return response()->json(['success' => false, 'message' => 'Toà nhà của phòng này chưa cấu hình tài khoản TTLock.'], 422);
        }

        if (! $room->lock_id) {
            return response()->json(['success' => false, 'message' => 'Phòng này chưa gán khóa TTLock.'], 422);
        }

        $both   = $room->unlock_both_locks && $room->lock_id && $room->lock_id_checkout;
        $opened = $both
            ? $ttlock->remoteUnlockBoth((int) $room->lock_id, (int) $room->lock_id_checkout)['success']
            : $ttlock->remoteUnlock((int) $room->lock_id);

        Log::info('MiniHouse admin remote unlock room', [
            'room_id' => $room->id, 'lock_id' => $room->lock_id, 'admin' => $request->user()?->email, 'success' => $opened,
        ]);

        if (! $opened) {
            return response()->json([
                'success' => false,
                'message' => 'Không mở được khóa — kiểm tra khóa còn kết nối mạng và đã bật "Mở khóa từ xa" trong app Sciener chưa.',
            ], 422);
        }

        return response()->json(['success' => true, 'message' => 'Đã gửi lệnh mở khóa.']);
    }

    // POST /api/admin/minihouse/rooms/{id}/emergency-lock
    public function emergencyLock(Request $request, string $id, RoomEmergencyAccessService $service): JsonResponse
    {
        [$room, $error] = $this->emergencyRoom($request, $id);
        if ($error) {
            return $error;
        }

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        /** @var User $actor */
        $actor = $request->user();
        $room = $service->lock($room, $actor, $data['reason']);

        return response()->json(['data' => $this->transform($room), 'message' => 'Đã khóa quyền mở phòng qua ứng dụng.']);
    }

    /**
     * POST /api/admin/minihouse/rooms/emergency-lock/bulk
     * Khóa/gỡ khóa quyền mở qua ứng dụng cho nhiều phòng MiniHouse, trả kết quả từng phòng.
     */
    public function bulkEmergencyLock(Request $request, RoomEmergencyAccessService $service): JsonResponse
    {
        if (! $this->hasPermission($request, 'page_emergency_room_lock')) {
            return response()->json(['message' => 'Không có quyền khóa phòng khẩn cấp.'], 403);
        }

        $data = $request->validate([
            'room_ids' => ['required', 'array', 'min:1', 'max:100'],
            'room_ids.*' => ['required', 'string', 'distinct'],
            'action' => ['required', \Illuminate\Validation\Rule::in(['lock', 'release'])],
            'reason' => ['nullable', 'required_if:action,lock', 'string', 'min:5', 'max:1000'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $rooms = Room::withoutGlobalScope('activeBuilding')->whereIn('id', $data['room_ids'])->get()
            ->keyBy(fn (Room $room) => (string) $room->getKey());

        $results = collect($data['room_ids'])->map(function (string $roomId) use ($rooms, $request, $data, $service, $actor): array {
            /** @var Room|null $room */
            $room = $rooms->get($roomId);

            if (! $room || ! $this->isBuildingAllowed($request, $room->building_id)) {
                return $this->bulkResult($roomId, false, 'Không tìm thấy phòng hoặc phòng nằm ngoài phạm vi quản lý.', 404);
            }
            if ($data['action'] === 'lock' && ! $room->lock_id) {
                return $this->bulkResult($roomId, false, 'Phòng chưa gán khóa TTLock.', 422, $room->name);
            }

            try {
                $room = DB::transaction(fn () => $data['action'] === 'lock'
                    ? $service->lock($room, $actor, $data['reason'])
                    : $service->release($room, $actor));

                return $this->bulkResult(
                    $roomId,
                    true,
                    $data['action'] === 'lock' ? 'Đã khóa quyền mở qua ứng dụng.' : 'Đã gỡ khóa truy cập khẩn cấp.',
                    200,
                    $room->name,
                    $this->transform($room),
                );
            } catch (\Throwable $exception) {
                report($exception);

                return $this->bulkResult($roomId, false, 'Không thể cập nhật trạng thái phòng.', 500, $room->name);
            }
        })->values();

        $succeeded = $results->where('success', true)->count();

        return response()->json([
            'success' => $succeeded === $results->count(),
            'message' => "Đã xử lý {$succeeded}/{$results->count()} phòng.",
            'summary' => ['total' => $results->count(), 'succeeded' => $succeeded, 'failed' => $results->count() - $succeeded],
            'results' => $results,
        ]);
    }

    // DELETE /api/admin/minihouse/rooms/{id}/emergency-lock
    public function releaseEmergencyLock(Request $request, string $id, RoomEmergencyAccessService $service): JsonResponse
    {
        [$room, $error] = $this->emergencyRoom($request, $id);
        if ($error) {
            return $error;
        }

        /** @var User $actor */
        $actor = $request->user();
        $room = $service->release($room, $actor);

        return response()->json(['data' => $this->transform($room), 'message' => 'Đã gỡ khóa truy cập khẩn cấp.']);
    }

    /** @return array{0: ?Room, 1: ?JsonResponse} */
    private function emergencyRoom(Request $request, string $id): array
    {
        if (! $this->hasPermission($request, 'page_emergency_room_lock')) {
            return [null, response()->json(['message' => 'Không có quyền khóa phòng khẩn cấp.'], 403)];
        }

        $room = Room::withoutGlobalScope('activeBuilding')->find($id);
        if (! $room || ! $this->isBuildingAllowed($request, $room->building_id)) {
            return [null, response()->json(['message' => 'Không tìm thấy phòng.'], 404)];
        }
        if (! $room->lock_id) {
            return [null, response()->json(['message' => 'Phòng này chưa gán khóa TTLock.'], 422)];
        }

        return [$room, null];
    }

    /** @return array{0: ?Room, 1: ?JsonResponse} */
    private function room(Request $request, string $id): array
    {
        if (! $this->hasPermission($request, 'page_ttlock_locks')) {
            return [null, response()->json(['message' => 'Không có quyền quản lý khoá thông minh.'], 403)];
        }

        $room = Room::withoutGlobalScope('activeBuilding')->find($id);

        if (! $room || ! $this->isBuildingAllowed($request, $room->building_id)) {
            return [null, response()->json(['message' => 'Không tìm thấy phòng.'], 404)];
        }

        return [$room, null];
    }

    /** @return array<string, mixed> */
    private function transform(Room $room): array
    {
        return [
            'room_id'           => $room->id,
            'building_id'       => $room->building_id,
            'lock_id'           => $room->lock_id,
            'lock_id_checkout'  => $room->lock_id_checkout,
            'unlock_both_locks' => (bool) $room->unlock_both_locks,
            'is_emergency_locked' => $room->emergency_locked_at !== null,
            'emergency_locked_at' => $room->emergency_locked_at?->toIso8601String(),
            'emergency_lock_reason' => $room->emergency_lock_reason,
        ];
    }

    /** @param array<string, mixed>|null $state */
    private function bulkResult(string $roomId, bool $success, string $message, int $status, ?string $roomName = null, ?array $state = null): array
    {
        return array_filter([
            'room_id' => $roomId,
            'room_name' => $roomName,
            'success' => $success,
            'status' => $status,
            'message' => $message,
            'data' => $state,
        ], static fn ($value) => $value !== null);
    }
}
