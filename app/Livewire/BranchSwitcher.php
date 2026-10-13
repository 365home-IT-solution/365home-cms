<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use App\Support\ActivePartner;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Modules\Category\Entities\Category;

// Nút "Chuyển đổi chi nhánh" ở topbar admin — chọn 1/nhiều trong số chi nhánh user được phép xem
// (User::rootProductCategoryIds()) để tạm thời thu hẹp dữ liệu hiển thị trong admin, lưu ở session
// (xem User::effectiveBranchIds(), dùng lại ở BelongsToBranch + BelongsToActiveBranchCategories +
// scope riêng của Order/Employee). Mặc định (chưa từng bấm Áp dụng) = chọn hết.
//
// super_admin có thêm ô chọn ĐỐI TÁC phía trên danh sách chi nhánh: chọn 1 đối tác = toàn bộ dữ
// liệu trong CMS chỉ còn của đối tác đó (session active_partner_id, xem App\Support\ActivePartner +
// ApplyActivePartner), danh sách chi nhánh bên dưới chỉ còn chi nhánh của đối tác đã chọn.
class BranchSwitcher extends Component
{
    public array $selected = [];

    public ?string $partnerId = null;

    public function mount(): void
    {
        if ($this->isSuperAdmin()) {
            $this->partnerId = session(ActivePartner::SESSION_KEY) ?: null;
        }

        $permitted = $this->permittedBranchIds();
        $stored    = session('active_branch_ids');

        $this->selected = ! empty($stored)
            ? array_values(array_intersect($permitted, $stored))
            : $permitted;
    }

    public function isSuperAdmin(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    protected function permittedBranchIds(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        // super_admin: theo đối tác đang chọn TRONG dropdown (chưa bấm Áp dụng) — không dùng
        // rootProductCategoryIds() vì hàm đó chỉ phản ánh đối tác đã lưu ở session.
        if ($user->isSuperAdmin()) {
            return Category::query()
                ->whereNull('parent_id')
                ->where('category_type', 'product')
                ->when($this->partnerId, fn ($q) => $q->where('partner_id', $this->partnerId))
                ->pluck('id')
                ->all();
        }

        return $user->rootProductCategoryIds();
    }

    public function partners(): array
    {
        return $this->isSuperAdmin() ? ActivePartner::options() : [];
    }

    // Đổi đối tác trong dropdown → danh sách chi nhánh đổi theo, mặc định chọn hết chi nhánh của
    // đối tác đó.
    public function updatedPartnerId(): void
    {
        $this->partnerId = $this->partnerId ?: null;
        $this->selected  = $this->permittedBranchIds();
    }

    public function branches(): Collection
    {
        $ids = $this->permittedBranchIds();

        if (empty($ids)) {
            return collect();
        }

        return Category::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function selectAll(): void
    {
        $this->selected = $this->permittedBranchIds();
    }

    public function apply(): void
    {
        if ($this->isSuperAdmin()) {
            if ($this->partnerId && array_key_exists($this->partnerId, ActivePartner::options())) {
                session([ActivePartner::SESSION_KEY => $this->partnerId]);
            } else {
                $this->partnerId = null;
                session()->forget(ActivePartner::SESSION_KEY);
            }
        }

        $permitted = $this->permittedBranchIds();
        $safe      = array_values(array_intersect($permitted, $this->selected));

        // Không cho phép tự bỏ chọn hết — coi như chưa chọn gì (mặc định = tất cả), tránh tự khoá
        // mất toàn bộ dữ liệu của chính mình do bấm nhầm.
        if (empty($safe)) {
            $safe = $permitted;
        }

        session(['active_branch_ids' => $safe]);
        $this->selected = $safe;

        $this->redirect(Filament::getUrl(), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.branch-switcher');
    }
}
