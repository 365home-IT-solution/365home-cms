<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\TTLock\App\Services\TTLockService;
use Modules\TTLock\Entities\TtlockAccount;

// Bản API của Modules\TTLock\App\Filament\Pages\LockDashboard (giao diện CMS "Thông tin TTLock" —
// nút "Mở khóa ngay") — cho app di động/bên thứ 3 liệt kê + mở BẤT KỲ khóa TTLock nào (mọi chi
// nhánh đang có tài khoản hoạt động), KHÔNG cần gắn với đơn hàng/phòng nào cả. Khác hẳn:
//   - POST /api/admin/rooms/{id}/unlock (Api\Admin\ProductController::unlock()) — CHỈ mở được
//     khóa đã gán vào 1 phòng (Product::lock_id) cụ thể.
//   - POST /api/admin/orders/{order_code}/unlock — CHỈ mở được khóa của 1 đơn đang paid/deposit.
// Dùng cho tình huống khẩn/kỹ thuật (kỹ thuật viên cần mở 1 khóa bất kỳ để kiểm tra/bảo trì), cùng
// quyền page_LockDashboard đang gác trang Filament tương ứng — không mở rộng phạm vi truy cập.
class TtlockLockController extends Controller
{
    // GET /api/admin/ttlock/locks — liệt kê TOÀN BỘ khóa của mọi chi nhánh đang có tài khoản TTLock
    // hoạt động, mirror Modules\TTLock\App\Filament\Pages\LockDashboard::loadAllLocks().
    public function index(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Không có quyền xem danh sách khóa TTLock.'], 403);
        }

        $locks = [];

        $accounts = TtlockAccount::query()->where('is_active', true)->with('categories:id,name')->get();

        foreach ($accounts as $account) {
            $categoryId = $account->categories->first()?->id;

            if (! $categoryId) {
                continue;
            }

            $ttlock = TTLockService::forCategory($categoryId);

            if (! $ttlock) {
                continue;
            }

            foreach ($ttlock->getLockList() as $lock) {
                $locks[] = [
                    'lock_id'          => $lock['lockId'] ?? null,
                    'category_id'      => $categoryId,
                    'name'             => $lock['lockAlias'] ?? $lock['lockName'] ?? null,
                    'branch_name'      => $account->categories->first()?->name,
                    'group_name'       => $lock['groupName'] ?? null,
                    'mac'              => $lock['lockMac'] ?? null,
                    'battery_percent'  => $lock['electricQuantity'] ?? null,
                ];
            }
        }

        return response()->json(['data' => $locks]);
    }

    // POST /api/admin/ttlock/locks/unlock  { category_id, lock_id }
    // Mở NGAY khóa được chỉ định — KHÔNG kiểm tra thời gian check-in/out hay trạng thái đơn hàng
    // nào (khác hẳn Api\UnlockController), vì khóa này có thể còn chưa gắn với phòng/đơn nào cả.
    public function unlock(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Không có quyền mở khóa TTLock.'], 403);
        }

        $data = $request->validate([
            'category_id' => 'required|integer',
            'lock_id'     => 'required|integer',
        ]);

        $ttlock = TTLockService::forCategory((int) $data['category_id']);

        if (! $ttlock) {
            return response()->json(['success' => false, 'message' => 'Chi nhánh này chưa kết nối tài khoản TTLock.'], 422);
        }

        $success = $ttlock->remoteUnlock((int) $data['lock_id']);

        Log::info('TTLock API: mở khóa trực tiếp (không gắn đơn hàng/phòng)', [
            'category_id' => $data['category_id'],
            'lock_id'     => $data['lock_id'],
            'admin'       => $request->user()?->email,
            'success'     => $success,
        ]);

        if (! $success) {
            return response()->json([
                'success' => false,
                'message' => 'Không mở được khóa — kiểm tra khóa còn kết nối mạng không, hoặc tính năng "Mở khóa từ xa" đã bật trong app Sciener chưa (cài đặt khóa > Remote Unlock).',
            ], 422);
        }

        return response()->json(['success' => true, 'message' => 'Đã gửi lệnh mở khóa.']);
    }

    // super_admin bypass; tài khoản thường phải có đúng quyền trang Filament tương ứng
    // (page_LockDashboard, gác chung cả LockDashboard/LockDetail bên Filament) — dùng $user->can()
    // (không dùng hasPermissionTo() thuần Spatie) để super_admin không bị chặn nhầm.
    private function authorized(Request $request): bool
    {
        $user = $request->user();

        return ($user instanceof User) && ($user->isSuperAdmin() || $user->can('page_LockDashboard'));
    }
}
