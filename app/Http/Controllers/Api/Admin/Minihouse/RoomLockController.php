<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Services\ContractTtlockService;
use Modules\TTLock\App\Services\TTLockService;

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
        ];
    }
}
