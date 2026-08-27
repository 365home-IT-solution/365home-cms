<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

// Nút "Chuyển đổi chi nhánh" ở topbar admin — chọn 1/nhiều trong số chi nhánh user được phép xem
// (User::rootProductCategoryIds()) để tạm thời thu hẹp dữ liệu hiển thị trong admin, lưu ở session
// (xem User::effectiveBranchIds(), dùng lại ở BelongsToBranch + BelongsToActiveBranchCategories +
// scope riêng của Order/Employee). Mặc định (chưa từng bấm Áp dụng) = chọn hết.
class BranchSwitcher extends Component
{
    public array $selected = [];

    public bool $open = false;

    public function mount(): void
    {
        $permitted = $this->permittedBranchIds();
        $stored    = session('active_branch_ids');

        $this->selected = ! empty($stored)
            ? array_values(array_intersect($permitted, $stored))
            : $permitted;
    }

    protected function permittedBranchIds(): array
    {
        $user = auth()->user();

        return $user instanceof User ? $user->rootProductCategoryIds() : [];
    }

    public function branches(): Collection
    {
        $ids = $this->permittedBranchIds();

        if (empty($ids)) {
            return collect();
        }

        return \Modules\Category\Entities\Category::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function selectAll(): void
    {
        $this->selected = $this->permittedBranchIds();
    }

    public function apply(): void
    {
        $permitted = $this->permittedBranchIds();
        $safe      = array_values(array_intersect($permitted, $this->selected));

        // Không cho phép tự bỏ chọn hết — coi như chưa chọn gì (mặc định = tất cả), tránh tự khoá
        // mất toàn bộ dữ liệu của chính mình do bấm nhầm.
        if (empty($safe)) {
            $safe = $permitted;
        }

        session(['active_branch_ids' => $safe]);
        $this->selected = $safe;
        $this->open     = false;

        $this->redirect(Filament::getUrl(), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.branch-switcher');
    }
}
