<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

// Nút "Chuyển đổi tài khoản" ở topbar admin — super_admin chuyển được sang bất kỳ ai (có ô tìm
// kiếm), user thường chỉ chuyển được sang tài khoản cùng role và có chung ít nhất 1 chi nhánh (xem
// User::isEligibleSwitchTarget()). Chọn tên xong chỉ cần nhập mật khẩu của CHÍNH tài khoản đó để
// xác nhận — không phải mật khẩu của người đang thao tác.
class AccountSwitcher extends Component
{
    public string $step = 'list';

    public string $search = '';

    public ?string $selectedUserId = null;

    public string $password = '';

    public ?string $passwordError = null;

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

        // KHÔNG gọi Auth::login() ngay tại đây — Auth::login() làm session()->migrate(true) bên
        // trong (đổi hẳn session id giữa chừng), và làm việc này bên trong 1 request AJAX
        // /livewire/update từng gây lỗi 419 CSRF / mất đăng nhập không ổn định trên trình duyệt
        // thật. Chỉ lưu 1 "vé" ngắn hạn (10 giây) rồi điều hướng TRANG THƯỜNG (navigate: false —
        // window.location, không phải AJAX) sang AccountSwitchController::commit(), nơi
        // Auth::login() chạy trong 1 vòng đời request HTTP bình thường, giống hệt 1 form đăng nhập
        // chuẩn.
        session(['pending_account_switch' => [
            'target_id'  => $target->id,
            'expires_at' => time() + 10,
        ]]);

        $this->redirect(route('admin.account-switch.commit'), navigate: false);
    }

    public function switchBack(): void
    {
        $this->redirect(route('admin.account-switch.back'), navigate: false);
    }

    public function render(): View
    {
        return view('livewire.account-switcher');
    }
}
