<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Minihouse;

use App\Http\Controllers\Api\Admin\Minihouse\Concerns\ScopesToMinihouseBuilding;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\TtlockSetting;
use Modules\TTLock\App\Services\TTLockService;

// Bản API của các trang khoá thông minh trong panel MiniHouse (TtlockDashboard/TtlockLockDetail/
// IssueTtlock*) — mirror Api\Admin\TtlockLockController + Api\TtlockCardAppController của Home, nhưng
// MỌI thao tác đều theo building_id (Toà nhà = nơi giữ tài khoản TTLock, xem TtlockSetting) và chỉ
// trên Toà nhà tài khoản được phân quyền. Cần quyền "page_ttlock_locks" (hoặc super_admin).
class TtlockLockController extends Controller
{
    use ScopesToMinihouseBuilding;

    // GET /api/admin/minihouse/ttlock/locks[?building_id=] — khoá của mọi Toà nhà đã cấu hình (hoặc 1 toà).
    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        $ids = $this->permittedBuildingIds($request);

        if ($request->filled('building_id')) {
            $ids = array_values(array_intersect($ids, [$request->integer('building_id')]));
        }

        $settings = TtlockSetting::query()->whereIn('building_id', $ids)->where('is_active', true)->get()
            ->filter(fn (TtlockSetting $s) => $s->isConfigured());

        $names = Building::withoutGlobalScope('activeBuilding')->whereIn('id', $settings->pluck('building_id'))->pluck('name', 'id');

        $locks = [];

        foreach ($settings as $setting) {
            foreach (TTLockService::forBuilding($setting->building_id)?->getLockList() ?? [] as $lock) {
                $locks[] = [
                    'lock_id'         => $lock['lockId'] ?? null,
                    'building_id'     => $setting->building_id,
                    'building_name'   => $names[$setting->building_id] ?? null,
                    'name'            => $lock['lockAlias'] ?? $lock['lockName'] ?? null,
                    'group_name'      => $lock['groupName'] ?? null,
                    'mac'             => $lock['lockMac'] ?? null,
                    'battery_percent' => $lock['electricQuantity'] ?? null,
                ];
            }
        }

        return response()->json(['data' => $locks]);
    }

    // GET /api/admin/minihouse/ttlock/locks/{building_id}/{lock_id} — chi tiết 1 khoá + thẻ/mã/vân tay/
    // thành viên (eKey) — mirror TtlockLockDetail::mount(). Lịch sử mở khoá có endpoint riêng (records).
    public function show(Request $request, int $buildingId, int $lockId): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        [$ttlock, $error] = $this->resolve($request, $buildingId);

        if ($error) {
            return $error;
        }

        $lock = collect($ttlock->getLockList())->firstWhere('lockId', $lockId);

        if (! $lock) {
            return response()->json(['message' => 'Không tìm thấy khoá trong tài khoản TTLock của Toà nhà này.'], 404);
        }

        $members = collect($ttlock->listEkeys($lock['lockAlias'] ?? null))
            ->filter(fn ($ekey) => (int) ($ekey['lockId'] ?? 0) === $lockId)
            ->values();

        return response()->json(['data' => [
            'lock_id'         => $lockId,
            'building_id'     => $buildingId,
            'name'            => $lock['lockAlias'] ?? $lock['lockName'] ?? null,
            'mac'             => $lock['lockMac'] ?? null,
            'battery_percent' => $lock['electricQuantity'] ?? null,
            'cards'           => $ttlock->listIcCards($lockId),
            'passcodes'       => $ttlock->listKeyboardPwds($lockId),
            'fingerprints'    => $ttlock->listFingerprints($lockId),
            'members'         => $members,
        ]]);
    }

    // POST /api/admin/minihouse/ttlock/locks/unlock  { building_id, lock_id }
    public function unlock(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        $data = $request->validate(['building_id' => 'required|integer', 'lock_id' => 'required|integer']);

        [$ttlock, $error] = $this->resolve($request, (int) $data['building_id']);

        if ($error) {
            return $error;
        }

        $success = $ttlock->remoteUnlock((int) $data['lock_id']);

        Log::info('MiniHouse TTLock API: mở khóa trực tiếp', [
            'building_id' => $data['building_id'],
            'lock_id'     => $data['lock_id'],
            'admin'       => $request->user()?->email,
            'success'     => $success,
        ]);

        if (! $success) {
            return response()->json([
                'success' => false,
                'message' => 'Không mở được khóa — kiểm tra khóa còn kết nối mạng không, hoặc tính năng "Mở khóa từ xa" đã bật trong app Sciener chưa.',
            ], 422);
        }

        return response()->json(['success' => true, 'message' => 'Đã gửi lệnh mở khóa.']);
    }

    // POST /api/admin/minihouse/ttlock/locks/lock-data  { building_id, lock_id } — chìa khoá Bluetooth cho app.
    public function lockData(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        $data = $request->validate(['building_id' => 'required|integer', 'lock_id' => 'required|integer']);

        [$ttlock, $error] = $this->resolve($request, (int) $data['building_id']);

        if ($error) {
            return $error;
        }

        $alias = collect($ttlock->getLockList())->firstWhere('lockId', (int) $data['lock_id'])['lockAlias'] ?? null;
        $ekey  = collect($ttlock->listEkeys($alias))->firstWhere('lockId', (int) $data['lock_id']);

        if (! $ekey || empty($ekey['lockData'])) {
            return response()->json(['message' => 'Không lấy được lockData cho khóa này.'], 404);
        }

        return response()->json(['data' => ['lock_data' => $ekey['lockData'], 'lock_mac' => $ekey['lockMac'] ?? null]]);
    }

    // GET /api/admin/minihouse/ttlock/records?building_id&lock_id[&page_no&page_size]
    public function records(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        $data = $request->validate([
            'building_id' => 'required|integer',
            'lock_id'     => 'required|integer',
            'page_no'     => 'nullable|integer|min:1',
            'page_size'   => 'nullable|integer|min:1|max:100',
        ]);

        [$ttlock, $error] = $this->resolve($request, (int) $data['building_id']);

        if ($error) {
            return $error;
        }

        $pageSize = (int) ($data['page_size'] ?? 20);
        $rows     = $ttlock->getLockRecords((int) $data['lock_id'], (int) ($data['page_no'] ?? 1), $pageSize + 1);

        return response()->json([
            'data' => array_slice($rows, 0, $pageSize),
            'meta' => ['page_no' => (int) ($data['page_no'] ?? 1), 'page_size' => $pageSize, 'has_more' => count($rows) > $pageSize],
        ]);
    }

    // ─── Thẻ từ ──────────────────────────────────────────────

    public function listCards(Request $request): JsonResponse
    {
        return $this->listOf($request, fn (TTLockService $t, int $lockId) => $t->listIcCards($lockId));
    }

    // POST /ttlock/cards { building_id, lock_id, card_number, name, start_date?, end_date? } (ms; 0/bỏ trống = vĩnh viễn)
    public function storeCard(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        $data = $request->validate([
            'building_id' => 'required|integer',
            'lock_id'     => 'required|integer',
            'card_number' => 'required|string|max:64',
            'name'        => 'required|string|max:255',
            'start_date'  => 'nullable|integer',
            'end_date'    => 'nullable|integer',
        ]);

        [$ttlock, $error] = $this->resolve($request, (int) $data['building_id']);

        if ($error) {
            return $error;
        }

        $result = $ttlock->addIcCard(
            lockId: (int) $data['lock_id'],
            cardNumber: (string) $data['card_number'],
            startDate: (int) ($data['start_date'] ?? 0),
            endDate: (int) ($data['end_date'] ?? 0),
            name: (string) $data['name'],
        );

        if (! $result) {
            return response()->json(['message' => 'Cấp thẻ thất bại — kiểm tra log server.'], 422);
        }

        return response()->json(['success' => true, 'data' => ['card_id' => $result['cardId'] ?? null]], 201);
    }

    // DELETE /ttlock/cards { building_id, lock_id, card_id }
    public function destroyCard(Request $request): JsonResponse
    {
        return $this->deleteOf($request, 'card_id', 'Xoá thẻ thất bại.', fn (TTLockService $t, int $lockId, int $id) => $t->deleteIcCard($lockId, $id));
    }

    // ─── Mã mở (passcode) ────────────────────────────────────

    public function listPasscodes(Request $request): JsonResponse
    {
        return $this->listOf($request, fn (TTLockService $t, int $lockId) => $t->listKeyboardPwds($lockId));
    }

    // POST /ttlock/passcodes { building_id, lock_id, name, start_date, end_date?, code? } — không có code
    // thì cloud tự sinh mã ngẫu nhiên (giống IssueTtlockPasscode). start_date/end_date là mili giây.
    public function storePasscode(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        $data = $request->validate([
            'building_id' => 'required|integer',
            'lock_id'     => 'required|integer',
            'name'        => 'required|string|max:255',
            'code'        => 'nullable|digits_between:4,9',
            'start_date'  => 'required|integer',
            'end_date'    => 'nullable|integer',
        ]);

        [$ttlock, $error] = $this->resolve($request, (int) $data['building_id']);

        if ($error) {
            return $error;
        }

        $args = [
            'lockId'    => (int) $data['lock_id'],
            'startDate' => (int) $data['start_date'],
            'endDate'   => (int) ($data['end_date'] ?? 0),
            'name'      => (string) $data['name'],
        ];

        $result = filled($data['code'] ?? null)
            ? $ttlock->addCustomPasscode(...$args, code: (string) $data['code'])
            : $ttlock->generatePasscode(...$args);

        if (! $result) {
            return response()->json(['message' => 'Cấp mã mở thất bại — kiểm tra log server.'], 422);
        }

        return response()->json([
            'success' => true,
            'data'    => ['code' => $result['code'] ?? $data['code'], 'keyboard_pwd_id' => $result['keyboardPwdId'] ?? null],
        ], 201);
    }

    // DELETE /ttlock/passcodes { building_id, lock_id, keyboard_pwd_id }
    public function destroyPasscode(Request $request): JsonResponse
    {
        return $this->deleteOf($request, 'keyboard_pwd_id', 'Xoá mã mở thất bại.', fn (TTLockService $t, int $lockId, int $id) => $t->deletePasscode($lockId, $id));
    }

    // ─── Vân tay ─────────────────────────────────────────────

    public function listFingerprints(Request $request): JsonResponse
    {
        return $this->listOf($request, fn (TTLockService $t, int $lockId) => $t->listFingerprints($lockId));
    }

    // POST /ttlock/fingerprints { building_id, lock_id, fingerprint_number, name, start_date?, end_date? }
    public function storeFingerprint(Request $request): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        $data = $request->validate([
            'building_id'        => 'required|integer',
            'lock_id'            => 'required|integer',
            'fingerprint_number' => 'required|string|max:64',
            'name'               => 'required|string|max:255',
            'start_date'         => 'nullable|integer',
            'end_date'           => 'nullable|integer',
        ]);

        [$ttlock, $error] = $this->resolve($request, (int) $data['building_id']);

        if ($error) {
            return $error;
        }

        $result = $ttlock->addFingerprint(
            lockId: (int) $data['lock_id'],
            fingerprintNumber: (string) $data['fingerprint_number'],
            startDate: (int) ($data['start_date'] ?? 0),
            endDate: (int) ($data['end_date'] ?? 0),
            name: (string) $data['name'],
        );

        if (! $result) {
            return response()->json(['message' => 'Đăng ký vân tay thất bại — kiểm tra log server.'], 422);
        }

        return response()->json(['success' => true, 'data' => ['fingerprint_id' => $result['fingerprintId'] ?? null]], 201);
    }

    // DELETE /ttlock/fingerprints { building_id, lock_id, fingerprint_id }
    public function destroyFingerprint(Request $request): JsonResponse
    {
        return $this->deleteOf($request, 'fingerprint_id', 'Xoá vân tay thất bại.', fn (TTLockService $t, int $lockId, int $id) => $t->deleteFingerprint($lockId, $id));
    }

    // ─── Dùng chung ──────────────────────────────────────────

    private function listOf(Request $request, \Closure $fetch): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        $data = $request->validate(['building_id' => 'required|integer', 'lock_id' => 'required|integer']);

        [$ttlock, $error] = $this->resolve($request, (int) $data['building_id']);

        if ($error) {
            return $error;
        }

        return response()->json(['data' => $fetch($ttlock, (int) $data['lock_id'])]);
    }

    private function deleteOf(Request $request, string $idField, string $failMessage, \Closure $delete): JsonResponse
    {
        if ($denied = $this->deny($request)) {
            return $denied;
        }

        $data = $request->validate(['building_id' => 'required|integer', 'lock_id' => 'required|integer', $idField => 'required|integer']);

        [$ttlock, $error] = $this->resolve($request, (int) $data['building_id']);

        if ($error) {
            return $error;
        }

        if (! $delete($ttlock, (int) $data['lock_id'], (int) $data[$idField])) {
            return response()->json(['message' => $failMessage], 422);
        }

        return response()->json(['success' => true]);
    }

    /** @return array{0: ?TTLockService, 1: ?JsonResponse} */
    private function resolve(Request $request, int $buildingId): array
    {
        if (! $this->isBuildingAllowed($request, $buildingId)) {
            return [null, response()->json(['message' => 'Không có quyền trên Toà nhà này.'], 403)];
        }

        $ttlock = TTLockService::forBuilding($buildingId);

        if (! $ttlock) {
            return [null, response()->json(['message' => 'Toà nhà này chưa cấu hình tài khoản TTLock (hoặc đang tắt).'], 422)];
        }

        return [$ttlock, null];
    }

    private function deny(Request $request): ?JsonResponse
    {
        return $this->hasPermission($request, 'page_ttlock_locks')
            ? null
            : response()->json(['message' => 'Không có quyền quản lý khoá thông minh.'], 403);
    }
}
