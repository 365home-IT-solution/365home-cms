<?php

declare(strict_types=1);

namespace Modules\TTLock\App\Filament\Pages;

use Filament\Pages\Page;
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
