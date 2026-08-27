<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

// Nút "Chuyển đổi tài khoản" ở topbar admin — super_admin chuyển được sang bất kỳ ai (có ô tìm
// kiếm), user thường chỉ chuyển được sang tài khoản cùng role và có chung ít nhất 1 chi nhánh (xem
// User::isEligibleSwitchTarget()). Chọn tên xong chỉ cần nhập mật khẩu của CHÍNH tài khoản đó để
// xác nhận — không phải mật khẩu của người đang thao tác.
class AccountSwitcher extends Component
{
    public bool $open = false;

    public string $step = 'list';

    public string $search = '';

    public ?string $selectedUserId = null;

    public string $password = '';

    public ?string $passwordError = null;

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function candidates(): Collection
    {
        $me = auth()->user();

        if (! $me instanceof User) {
            return collect();
        }

        if ($me->isSuperAdmin()) {
            return User::query()
                ->where('id', '!=', $me->id)
                ->when($this->search !== '', function ($query) {
                    $term = '%' . $this->search . '%';
                    $query->where(
                        fn ($q) => $q->where('fullname', 'like', $term)->orWhere('email', 'like', $term)
                    );
                })
                ->orderBy('fullname')
                ->limit(50)
                ->get();
        }

        return User::query()
            ->where('id', '!=', $me->id)
            ->where('partner_id', $me->partner_id)
            ->whereHas('roles', fn ($q) => $q->whereIn('roles.id', $me->roles->pluck('id')))
            ->get()
            ->filter(fn (User $candidate) => $me->isEligibleSwitchTarget($candidate))
            ->values();
    }

    public function selectedUser(): ?User
    {
        return $this->selectedUserId ? User::find($this->selectedUserId) : null;
    }

    public function originalUser(): ?User
    {
        $id = session('impersonate.original_id');

        return $id ? User::find($id) : null;
    }

    public function selectUser(string $userId): void
    {
        $this->selectedUserId = $userId;
        $this->step            = 'password';
        $this->password        = '';
        $this->passwordError   = null;
    }

    public function backToList(): void
    {
        $this->step           = 'list';
        $this->selectedUserId = null;
        $this->password       = '';
        $this->passwordError  = null;
    }

    public function switch(): void
    {
        $me     = auth()->user();
        $target = $this->selectedUser();

        if (! $me instanceof User || ! $target) {
            $this->passwordError = 'Tài khoản không hợp lệ.';

            return;
        }

        $key = 'filament.switch-account.' . $target->id . '|' . request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds              = RateLimiter::availableIn($key);
            $this->passwordError = "Quá nhiều lần thử. Vui lòng đợi {$seconds} giây.";

            return;
        }

        // Re-check ngay lúc switch (không chỉ lúc build danh sách) — phòng trường hợp
        // $selectedUserId bị can thiệp trỏ tới 1 id không nằm trong danh sách hợp lệ ban đầu.
        if (! $me->isEligibleSwitchTarget($target)) {
            abort(403);
        }

        if (! Hash::check($this->password, $target->password)) {
            RateLimiter::hit($key, 60);
            $this->passwordError = 'Sai mật khẩu.';

            return;
        }

        RateLimiter::clear($key);

        $panel = Filament::getCurrentPanel();

        if ($panel && ! $target->canAccessPanel($panel)) {
            abort(403);
        }

        // Chỉ lưu id gốc THẬT lần đầu tiên — nếu đang switch nối tiếp (A -> B -> C), nút "Quay lại"
        // luôn đưa về A, không phải B.
        if (! session()->has('impersonate.original_id')) {
            session(['impersonate.original_id' => $me->id]);
        }

        Auth::guard('web')->login($target);
        session()->regenerate();

        $this->redirect(Filament::getUrl(), navigate: false);
    }

    public function switchBack(): void
    {
        $originalId = session('impersonate.original_id');

        if (! $originalId) {
            return;
        }

        $original = User::find($originalId);

        session()->forget('impersonate.original_id');

        if (! $original) {
            return;
        }

        Auth::guard('web')->login($original);
        session()->regenerate();

        $this->redirect(Filament::getUrl(), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.account-switcher');
    }
}
