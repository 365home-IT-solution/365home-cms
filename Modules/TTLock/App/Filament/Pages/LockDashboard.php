<?php

declare(strict_types=1);

namespace Modules\TTLock\App\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Modules\TTLock\App\Services\TTLockService;
use Modules\TTLock\Entities\TtlockAccount;

// Danh sách TOÀN BỘ khóa TTLock (mọi chi nhánh đang có tài khoản hoạt động, không lọc riêng theo
// chi nhánh nữa theo yêu cầu) — bấm vào 1 khóa để SANG TRANG RIÊNG xem chi tiết (xem LockDetail.php),
// không hiện lồng ngay trên trang danh sách này nữa.
class LockDashboard extends Page
{
    protected static string $view = 'ttlock::filament.pages.lock-dashboard';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    // Gộp chung nhóm "Quản lý API" có sẵn (dùng cho các trang tích hợp bên thứ 3/API khác trong hệ
    // thống, xem GuestCustomerResource/AskUserResource/ManagePopups) thay vì tạo riêng nhóm "TTLock".
    protected static ?string $navigationGroup = 'Quản lý API';

    protected static ?string $navigationLabel = 'Thông tin TTLock';

    protected static ?string $title = 'Thông tin TTLock';

    protected static ?string $slug = 'ttlock/dashboard';

    /** @var array<int, array> */
    public array $locks = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('page_LockDashboard') ?? false;
    }

    public function mount(): void
    {
        $this->locks = $this->loadAllLocks();
    }

    // Mở khóa NGAY từ trang danh sách tổng — KHÔNG gắn với đơn hàng/booking nào (khác
    // OpenGateAction bên Modules\Payment, chỉ mở được khóa gắn với 1 đơn ĐANG paid/deposit). Trang
    // này liệt kê TOÀN BỘ khóa mọi chi nhánh nên cần mở được bất kỳ khóa nào, bất kỳ lúc nào, phục
    // vụ tình huống khẩn/kỹ thuật (không phải luồng khách thuê phòng thông thường) — cùng quyền
    // page_LockDashboard đang gác cả trang, KHÔNG thêm quyền riêng vì đây vẫn là 1 hành động trên
    // đúng trang đó.
    public function unlockNow(int $categoryId, int $lockId, ?string $lockName = null): void
    {
        $ttlock = TTLockService::forCategory($categoryId);

        if (! $ttlock) {
            Notification::make()->title('Chi nhánh này chưa kết nối tài khoản TTLock.')->danger()->send();

            return;
        }

        $success = $ttlock->remoteUnlock($lockId);

        Log::info('TTLock LockDashboard: mở khóa trực tiếp từ danh sách', [
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
     * Gộp danh sách khóa của TẤT CẢ tài khoản TTLock đang hoạt động (mọi chi nhánh) — mỗi khóa kèm
     * theo categoryId của chi nhánh sở hữu nó, để LockDetail.php biết dùng đúng tài khoản nào gọi
     * API tiếp (1 tài khoản TTLock có thể gắn nhiều chi nhánh, xem TtlockAccount::categories()).
     *
     * @return array<int, array>
     */
    private function loadAllLocks(): array
    {
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
                $lock['categoryId'] = $categoryId;
                $lock['branchName'] = $account->categories->first()?->name;
                $locks[] = $lock;
            }
        }

        return $locks;
    }
}
