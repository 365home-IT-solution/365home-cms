<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Pages\Ttlock;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Modules\Minihouse\App\Support\TtlockLocks;
use Modules\TTLock\App\Services\TTLockService;

// Danh sách TOÀN BỘ khóa TTLock (mọi toà nhà đang có tài khoản hoạt động, không lọc riêng theo
// toà nhà nữa theo yêu cầu) — bấm vào 1 khóa để SANG TRANG RIÊNG xem chi tiết (xem TtlockLockDetail.php),
// không hiện lồng ngay trên trang danh sách này nữa.
class TtlockDashboard extends Page
{
    protected static string $view = 'minihouse::filament.pages.ttlock.lock-dashboard';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    // Gộp chung nhóm "Quản lý API" có sẵn (dùng cho các trang tích hợp bên thứ 3/API khác trong hệ
    // thống, xem GuestCustomerResource/AskUserResource/ManagePopups) thay vì tạo riêng nhóm "TTLock".
    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Khoá thông minh';

    protected static ?int $navigationSort = 90;

    protected static ?string $title = 'Khoá thông minh (TTLock)';

    protected static ?string $slug = 'ttlock/dashboard';

    /** @var array<int, array> */
    public array $locks = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('page_ttlock_locks') ?? false);
    }

    public function mount(): void
    {
        $this->locks = $this->loadAllLocks();
    }

    // Mở khóa NGAY từ trang danh sách tổng — KHÔNG gắn với đơn hàng/booking nào (khác
    // OpenGateAction bên Modules\Payment, chỉ mở được khóa gắn với 1 đơn ĐANG paid/deposit). Trang
    // này liệt kê TOÀN BỘ khóa mọi toà nhà nên cần mở được bất kỳ khóa nào, bất kỳ lúc nào, phục
    // vụ tình huống khẩn/kỹ thuật (không phải luồng khách thuê phòng thông thường) — cùng quyền
    // page_ttlock_locks đang gác cả trang, KHÔNG thêm quyền riêng vì đây vẫn là 1 hành động trên
    // đúng trang đó.
    public function unlockNow(int $categoryId, int $lockId, ?string $lockName = null): void
    {
        $ttlock = TtlockLocks::service($categoryId);

        if (! $ttlock) {
            Notification::make()->title('Toà nhà này chưa kết nối tài khoản TTLock.')->danger()->send();

            return;
        }

        $success = $ttlock->remoteUnlock($lockId);

        Log::info('TTLock TtlockDashboard: mở khóa trực tiếp từ danh sách', [
            'category_id' => $categoryId,
            'lock_id'     => $lockId,
            'admin'       => auth()->user()?->email,
            'success'     => $success,
        ]);

        if ($success) {
            Notification::make()->title('Đã gửi lệnh mở khóa — "' . ($lockName ?? "Lock #{$lockId}") . '"')->success()->send();

            return;
        }

        Notification::make()
            ->title('Không mở được khóa')
            ->body('Kiểm tra khóa còn kết nối mạng không, hoặc tính năng "Mở khóa từ xa" đã bật trong app Sciener chưa (cài đặt khóa > Remote Unlock).')
            ->danger()
            ->send();
    }

    /**
     * Gộp danh sách khóa của TẤT CẢ tài khoản TTLock đang hoạt động (mọi toà nhà) — mỗi khóa kèm
     * theo categoryId của toà nhà sở hữu nó, để TtlockLockDetail.php biết dùng đúng tài khoản nào gọi
     * API tiếp (1 tài khoản TTLock có thể gắn nhiều toà nhà, xem TtlockSetting).
     *
     * @return array<int, array>
     */
    private function loadAllLocks(): array
    {
        $locks = [];

        $accounts = \Modules\Minihouse\App\Support\TtlockLocks::buildings();

        foreach ($accounts as $account) {
            $categoryId = $account->id;

            if (! $categoryId) {
                continue;
            }

            $ttlock = TtlockLocks::service($categoryId);

            if (! $ttlock) {
                continue;
            }

            foreach ($ttlock->getLockList() as $lock) {
                $lock['categoryId'] = $categoryId;
                $lock['branchName'] = $account->name;
                $locks[] = $lock;
            }
        }

        return $locks;
    }
}
