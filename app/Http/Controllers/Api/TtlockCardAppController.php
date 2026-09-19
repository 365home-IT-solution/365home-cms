<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\TTLock\App\Services\TTLockService;
use Modules\TTLock\Entities\TtlockAccount;

// API RIÊNG cho app Flutter "Đọc thẻ TTLock" (đọc thẻ IC/vân tay mới qua Bluetooth ngay tại cửa, xem
// Modules/TTLock — app này chạy độc lập trên điện thoại, không qua panel Filament). Được bảo vệ bởi
// middleware 'ttlock.card-app' (token cố định, xem AuthorizeTtlockCardApp) — KHÔNG dùng phiên đăng
// nhập panel vì app không có UI đăng nhập.
//
// Luồng thẻ/vân tay: app gọi listLocks() cho nhân viên chọn khóa -> gọi getLockData() lấy "lockData"
// (chìa khoá Bluetooth từ cloud TTLock) -> app tự đọc thẻ/vân tay qua Bluetooth bằng gói
// ttlock_flutter (KHÔNG qua server) -> gọi registerCard()/registerFingerprint() để đăng ký + đồng bộ
// số vừa đọc lên cloud (dùng addType=2 qua Gateway, không cần app đứng cạnh khóa nữa ở bước này).
//
// Luồng mã mở: KHÔNG cần đọc Bluetooth trước — issuePasscode() tự sinh mã ngẫu nhiên qua cloud (hoặc
// dùng mã tự chọn nếu app truyền lên), y hệt cơ chế IssuePasscode.php bên panel.
//
// Toàn bộ endpoint list/delete mirror đúng các thao tác đã có trên panel Filament (LockDetail.php),
// để app có đầy đủ chức năng thay thế app/web thật của TTLock cho việc quản lý thẻ/mã/vân tay tại cửa.
class TtlockCardAppController extends Controller
{
    private function resolveTtlock(int $categoryId): ?TTLockService
    {
        return TTLockService::forCategory($categoryId);
    }

    public function listLocks(): JsonResponse
    {
        $locks = [];

        $accounts = TtlockAccount::query()->where('is_active', true)->with('categories:id,name')->get();

        foreach ($accounts as $account) {
            $categoryId = $account->categories->first()?->id;

            if (! $categoryId) {
                continue;
            }

            $ttlock = $this->resolveTtlock($categoryId);

            if (! $ttlock) {
                continue;
            }

            foreach ($ttlock->getLockList() as $lock) {
                $locks[] = [
                    'lock_id'     => (int) $lock['lockId'],
                    'name'        => $lock['lockAlias'] ?? $lock['lockName'] ?? "Lock #{$lock['lockId']}",
                    'branch'      => $account->categories->first()?->name,
                    'category_id' => $categoryId,
                    'battery'     => $lock['electricQuantity'] ?? null,
                    'mac'         => $lock['lockMac'] ?? null,
                ];
            }
        }

        return response()->json(['locks' => $locks]);
    }

    public function getLockData(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer'],
            'lock_id'     => ['required', 'integer'],
        ]);

        $ttlock = $this->resolveTtlock((int) $data['category_id']);

        if (! $ttlock) {
            return response()->json(['message' => 'Không tìm thấy tài khoản TTLock cho chi nhánh này.'], 404);
        }

        // getLockList() gọi /v3/lock/list — KHÔNG có lockData. Cần gọi thêm /v3/key/list (đã có sẵn
        // qua listEkeys()) để lấy đúng field "lockData" (chìa khoá Bluetooth) — field này chỉ xuất
        // hiện ở /v3/key/list, không có ở /v3/lock/list.
        $lockAlias = collect($ttlock->getLockList())
            ->firstWhere('lockId', (int) $data['lock_id'])['lockAlias'] ?? null;

        $ekey = collect($ttlock->listEkeys($lockAlias))
            ->firstWhere('lockId', (int) $data['lock_id']);

        if (! $ekey || empty($ekey['lockData'])) {
            return response()->json(['message' => 'Không lấy được lockData cho khóa này.'], 404);
        }

        return response()->json([
            'lock_data' => $ekey['lockData'],
            'lock_mac'  => $ekey['lockMac'] ?? null,
        ]);
    }

    public function remoteUnlock(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer'],
            'lock_id'     => ['required', 'integer'],
        ]);

        $ttlock = $this->resolveTtlock((int) $data['category_id']);

        if (! $ttlock) {
            return response()->json(['message' => 'Không tìm thấy tài khoản TTLock cho chi nhánh này.'], 404);
        }

        if (! $ttlock->remoteUnlock((int) $data['lock_id'])) {
            return response()->json(['message' => 'Mở khóa từ xa thất bại — khóa có thể đang offline (không có Gateway).'], 422);
        }

        return response()->json(['success' => true]);
    }

    public function listRecords(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer'],
            'lock_id'     => ['required', 'integer'],
            'page_no'     => ['nullable', 'integer', 'min:1'],
            'page_size'   => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $ttlock = $this->resolveTtlock((int) $data['category_id']);

        if (! $ttlock) {
            return response()->json(['message' => 'Không tìm thấy tài khoản TTLock cho chi nhánh này.'], 404);
        }

        $records = $ttlock->getLockRecords(
            lockId:   (int) $data['lock_id'],
            pageNo:   (int) ($data['page_no'] ?? 1),
            pageSize: (int) ($data['page_size'] ?? 20),
        );

        return response()->json(['records' => $records]);
    }

    // ---------------------------------------------------------------
    // Thẻ từ
    // ---------------------------------------------------------------

    public function listCards(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer'],
            'lock_id'     => ['required', 'integer'],
        ]);

        $ttlock = $this->resolveTtlock((int) $data['category_id']);

        if (! $ttlock) {
            return response()->json(['message' => 'Không tìm thấy tài khoản TTLock cho chi nhánh này.'], 404);
        }

        return response()->json(['cards' => $ttlock->listIcCards((int) $data['lock_id'])]);
    }

    public function registerCard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer'],
            'lock_id'     => ['required', 'integer'],
            'card_number' => ['required', 'string', 'max:64'],
            'name'        => ['required', 'string', 'max:255'],
            'start_date'  => ['nullable', 'integer'],
            'end_date'    => ['nullable', 'integer'],
        ]);

        $ttlock = $this->resolveTtlock((int) $data['category_id']);

        if (! $ttlock) {
            return response()->json(['message' => 'Không tìm thấy tài khoản TTLock cho chi nhánh này.'], 404);
        }

        $result = $ttlock->addIcCard(
            lockId: (int) $data['lock_id'],
            cardNumber: (string) $data['card_number'],
            startDate: (int) ($data['start_date'] ?? 0),
            endDate: (int) ($data['end_date'] ?? 0),
            name: (string) $data['name'],
            addType: 2,
        );

        if (! $result) {
            return response()->json(['message' => 'Đăng ký thẻ thất bại — kiểm tra log server.'], 422);
        }

        return response()->json(['success' => true, 'card_id' => $result['cardId']]);
    }

    public function deleteCard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer'],
            'lock_id'     => ['required', 'integer'],
            'card_id'     => ['required', 'integer'],
        ]);

        $ttlock = $this->resolveTtlock((int) $data['category_id']);

        if (! $ttlock) {
            return response()->json(['message' => 'Không tìm thấy tài khoản TTLock cho chi nhánh này.'], 404);
        }

        if (! $ttlock->deleteIcCard((int) $data['lock_id'], (int) $data['card_id'])) {
            return response()->json(['message' => 'Xoá thẻ thất bại.'], 422);
        }

        return response()->json(['success' => true]);
    }

    // ---------------------------------------------------------------
    // Mã mở (passcode) — KHÔNG cần đọc Bluetooth trước, sinh mã qua cloud
    // ---------------------------------------------------------------

    public function listPasscodes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer'],
            'lock_id'     => ['required', 'integer'],
        ]);

        $ttlock = $this->resolveTtlock((int) $data['category_id']);

        if (! $ttlock) {
            return response()->json(['message' => 'Không tìm thấy tài khoản TTLock cho chi nhánh này.'], 404);
        }

        return response()->json(['passcodes' => $ttlock->listKeyboardPwds((int) $data['lock_id'])]);
    }

    public function issuePasscode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer'],
            'lock_id'     => ['required', 'integer'],
            'name'        => ['required', 'string', 'max:255'],
            'code'        => ['nullable', 'string', 'max:32'],
            'start_date'  => ['required', 'integer'],
            'end_date'    => ['nullable', 'integer'],
        ]);

        $ttlock = $this->resolveTtlock((int) $data['category_id']);

        if (! $ttlock) {
            return response()->json(['message' => 'Không tìm thấy tài khoản TTLock cho chi nhánh này.'], 404);
        }

        $lockId    = (int) $data['lock_id'];
        $startDate = (int) $data['start_date'];
        $endDate   = (int) ($data['end_date'] ?? 0);
        $name      = (string) $data['name'];

        // Có sẵn mã (app đọc lại mã đã sinh cho khóa khác trong cùng lượt cấp nhiều khóa) -> đăng ký
        // mã đó lên khóa này. Không có mã -> để cloud tự sinh mã ngẫu nhiên (giống IssuePasscode.php).
        if (! empty($data['code'])) {
            $result = $ttlock->addCustomPasscode(
                lockId: $lockId,
                code: (string) $data['code'],
                startDate: $startDate,
                endDate: $endDate,
                name: $name,
            );

            if (! $result) {
                return response()->json(['message' => 'Cấp mã mở thất bại — kiểm tra log server.'], 422);
            }

            return response()->json(['success' => true, 'code' => $data['code'], 'keyboard_pwd_id' => $result['keyboardPwdId']]);
        }

        $result = $ttlock->generatePasscode(
            lockId: $lockId,
            startDate: $startDate,
            endDate: $endDate,
            name: $name,
        );

        if (! $result) {
            return response()->json(['message' => 'Cấp mã mở thất bại — kiểm tra log server.'], 422);
        }

        return response()->json(['success' => true, 'code' => $result['code'], 'keyboard_pwd_id' => $result['keyboardPwdId']]);
    }

    public function deletePasscode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id'      => ['required', 'integer'],
            'lock_id'          => ['required', 'integer'],
            'keyboard_pwd_id'  => ['required', 'integer'],
        ]);

        $ttlock = $this->resolveTtlock((int) $data['category_id']);

        if (! $ttlock) {
            return response()->json(['message' => 'Không tìm thấy tài khoản TTLock cho chi nhánh này.'], 404);
        }

        if (! $ttlock->deletePasscode((int) $data['lock_id'], (int) $data['keyboard_pwd_id'])) {
            return response()->json(['message' => 'Xoá mã mở thất bại.'], 422);
        }

        return response()->json(['success' => true]);
    }

    // ---------------------------------------------------------------
    // Vân tay — cùng cơ chế thẻ từ: phải đọc qua Bluetooth SDK trước, server chỉ đăng ký + đồng bộ
    // ---------------------------------------------------------------

    public function listFingerprints(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer'],
            'lock_id'     => ['required', 'integer'],
        ]);

        $ttlock = $this->resolveTtlock((int) $data['category_id']);

        if (! $ttlock) {
            return response()->json(['message' => 'Không tìm thấy tài khoản TTLock cho chi nhánh này.'], 404);
        }

        return response()->json(['fingerprints' => $ttlock->listFingerprints((int) $data['lock_id'])]);
    }

    public function registerFingerprint(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id'        => ['required', 'integer'],
            'lock_id'            => ['required', 'integer'],
            'fingerprint_number' => ['required', 'string', 'max:64'],
            'name'               => ['required', 'string', 'max:255'],
            'start_date'         => ['nullable', 'integer'],
            'end_date'           => ['nullable', 'integer'],
        ]);

        $ttlock = $this->resolveTtlock((int) $data['category_id']);

        if (! $ttlock) {
            return response()->json(['message' => 'Không tìm thấy tài khoản TTLock cho chi nhánh này.'], 404);
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

        return response()->json(['success' => true, 'fingerprint_id' => $result['fingerprintId']]);
    }

    public function deleteFingerprint(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id'     => ['required', 'integer'],
            'lock_id'         => ['required', 'integer'],
            'fingerprint_id'  => ['required', 'integer'],
        ]);

        $ttlock = $this->resolveTtlock((int) $data['category_id']);

        if (! $ttlock) {
            return response()->json(['message' => 'Không tìm thấy tài khoản TTLock cho chi nhánh này.'], 404);
        }

        if (! $ttlock->deleteFingerprint((int) $data['lock_id'], (int) $data['fingerprint_id'])) {
            return response()->json(['message' => 'Xoá vân tay thất bại.'], 422);
        }

        return response()->json(['success' => true]);
    }
}
