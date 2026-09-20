<?php

declare(strict_types=1);

namespace Modules\TTLock\App\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Livewire\WithPagination;
use Modules\TTLock\App\Services\TTLockService;

// Trang chi tiết 1 khóa — tách riêng khỏi LockDashboard.php (danh sách) theo yêu cầu, truy cập qua
// URL /ttlock/locks/{categoryId}/{lockId} (categoryId để biết dùng đúng tài khoản TTLock nào, vì 1
// tài khoản có thể quản lý nhiều chi nhánh — xem cách LockDashboard.php gắn categoryId vào mỗi khóa).
//
// Giao diện dạng tab (giống trang quản trị thật của TTLock: eKeys | Passcodes | Cards | Fingerprints
// | Records) kèm ô tìm kiếm + phân trang cho từng tab. Dữ liệu 4 tab đầu là mảng PHP thuần lấy từ
// REST API (không phải Eloquent) nên KHÔNG dùng được Filament Table Builder ở bản filament/tables
// 3.3.54 đang cài (getTableQuery() bắt buộc trả về Eloquent Builder|Relation|null) — tự dựng tìm
// kiếm + phân trang bằng LengthAwarePaginator thủ công trên mảng đã lọc, giữ đúng style bảng hiện có.
class LockDetail extends Page
{
    use WithPagination;

    protected static string $view = 'ttlock::filament.pages.lock-detail';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'ttlock/locks/{categoryId}/{lockId}';

    protected static ?string $title = 'Chi tiết khóa';

    protected string $paginationTheme = 'tailwind';

    public int $categoryId;

    public int $lockId;

    public ?array $lock = null;

    public string $activeTab = 'cards';

    public string $search = '';

    protected int $perPage = 15;

    /** @var array<int, array> */
    public array $cards = [];

    /** @var array<int, array> */
    public array $passcodes = [];

    /** @var array<int, array> */
    public array $fingerprints = [];

    /** @var array<int, array> */
    public array $members = [];

    /** @var array<int, array> */
    public array $records = [];

    public int $recordsPageNo = 1;

    public bool $recordsHasMore = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('page_LockDashboard') ?? false;
    }

    public function mount(int $categoryId, int $lockId): void
    {
        $this->categoryId = $categoryId;
        $this->lockId = $lockId;

        $ttlock = TTLockService::forCategory($categoryId);

        if (! $ttlock) {
            return;
        }

        $this->lock = collect($ttlock->getLockList())->firstWhere('lockId', $lockId);
        $this->cards = $ttlock->listIcCards($lockId);
        $this->passcodes = $ttlock->listKeyboardPwds($lockId);
        $this->fingerprints = $ttlock->listFingerprints($lockId);

        $alias = $this->lock['lockAlias'] ?? null;

        // /v3/key/list chỉ lọc được theo lockAlias (không có lockId trực tiếp) — lọc lại chính xác
        // theo lockId ngay sau khi có kết quả, đề phòng nhiều khóa trùng alias.
        $this->members = collect($ttlock->listEkeys($alias))
            ->filter(fn ($ekey) => (int) ($ekey['lockId'] ?? 0) === $lockId)
            ->values()
            ->all();

        $this->loadRecords();
    }

    private function loadRecords(): void
    {
        $ttlock = TTLockService::forCategory($this->categoryId);

        if (! $ttlock) {
            return;
        }

        // /v3/lockRecord/list đã hỗ trợ phân trang server-side nên KHÔNG cần paginateAll() ở đây —
        // lấy dư 1 bản ghi để biết còn trang sau hay không, rồi cắt lại đúng $perPage.
        $rows = $ttlock->getLockRecords($this->lockId, $this->recordsPageNo, $this->perPage + 1);
        $this->recordsHasMore = count($rows) > $this->perPage;
        $this->records = array_slice($rows, 0, $this->perPage);
    }

    public function goToRecordsPage(int $pageNo): void
    {
        $this->recordsPageNo = max(1, $pageNo);
        $this->loadRecords();
    }

    public function updatedSearch(): void
    {
        $this->resetPage('cardsPage');
        $this->resetPage('passcodesPage');
        $this->resetPage('fingerprintsPage');
        $this->resetPage('membersPage');
    }

    public function updatedActiveTab(): void
    {
        $this->search = '';
    }

    private function filterBySearch(array $items, array $searchableKeys): array
    {
        $search = trim($this->search);

        if ($search === '') {
            return $items;
        }

        return array_values(array_filter($items, function (array $item) use ($searchableKeys, $search) {
            foreach ($searchableKeys as $key) {
                if (isset($item[$key]) && str_contains(mb_strtolower((string) $item[$key]), mb_strtolower($search))) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function paginateArray(array $items, string $pageName): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage($pageName);
        $items = array_values($items);

        return new LengthAwarePaginator(
            array_slice($items, ($page - 1) * $this->perPage, $this->perPage),
            count($items),
            $this->perPage,
            $page,
            [
                'path'     => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ]
        );
    }

    public function getFilteredCardsProperty(): LengthAwarePaginator
    {
        return $this->paginateArray($this->filterBySearch($this->cards, ['cardName', 'cardNumber']), 'cardsPage');
    }

    public function getFilteredPasscodesProperty(): LengthAwarePaginator
    {
        return $this->paginateArray($this->filterBySearch($this->passcodes, ['keyboardPwdName', 'keyboardPwd']), 'passcodesPage');
    }

    public function getFilteredFingerprintsProperty(): LengthAwarePaginator
    {
        return $this->paginateArray($this->filterBySearch($this->fingerprints, ['fingerprintName', 'fingerprintNumber']), 'fingerprintsPage');
    }

    public function getFilteredMembersProperty(): LengthAwarePaginator
    {
        return $this->paginateArray($this->filterBySearch($this->members, ['keyName', 'remarks']), 'membersPage');
    }

    public function getTitle(): string
    {
        return 'Khóa: ' . ($this->lock['lockAlias'] ?? $this->lock['lockName'] ?? "#{$this->lockId}");
    }

    // 3 action xoá dùng chung qua wire:click ngay trên bảng (xem lock-detail.blade.php) — reload lại
    // đúng danh sách tương ứng sau khi xoá thành công, không cần load lại cả trang.
    public function deleteCard(int $cardId): void
    {
        $ttlock = TTLockService::forCategory($this->categoryId);

        if ($ttlock && $ttlock->deleteIcCard($this->lockId, $cardId)) {
            $this->cards = $ttlock->listIcCards($this->lockId);
            Notification::make()->title('Đã xoá thẻ')->success()->send();
        } else {
            Notification::make()->title('Xoá thẻ thất bại')->danger()->send();
        }
    }

    public function deletePasscode(int $keyboardPwdId): void
    {
        $ttlock = TTLockService::forCategory($this->categoryId);

        if ($ttlock && $ttlock->deletePasscode($this->lockId, $keyboardPwdId)) {
            $this->passcodes = $ttlock->listKeyboardPwds($this->lockId);
            Notification::make()->title('Đã xoá mã mở')->success()->send();
        } else {
            Notification::make()->title('Xoá mã mở thất bại')->danger()->send();
        }
    }

    public function deleteFingerprint(int $fingerprintId): void
    {
        $ttlock = TTLockService::forCategory($this->categoryId);

        if ($ttlock && $ttlock->deleteFingerprint($this->lockId, $fingerprintId)) {
            $this->fingerprints = $ttlock->listFingerprints($this->lockId);
            Notification::make()->title('Đã xoá vân tay')->success()->send();
        } else {
            Notification::make()->title('Xoá vân tay thất bại')->danger()->send();
        }
    }

    public static function msToDate(int $ms): string
    {
        return $ms > 0 ? \Carbon\Carbon::createFromTimestampMs($ms)->format('d/m/Y H:i') : '—';
    }
}
