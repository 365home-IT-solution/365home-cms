<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

// Bước "commit" thật sự của tính năng "Chuyển đổi tài khoản" (topbar admin, xem
// App\Livewire\AccountSwitcher) — TÁCH RIÊNG khỏi action Livewire cố ý: Auth::login() làm
// session()->migrate(true) bên trong (xoá + đổi hẳn session id giữa chừng), và làm việc này bên
// trong 1 request AJAX /livewire/update (thay vì 1 GET/POST trang thường) gây lỗi 419 CSRF token
// mismatch/mất đăng nhập không ổn định trên trình duyệt thật — Livewire component chỉ xác thực mật
// khẩu + điều kiện rồi lưu 1 "vé" ngắn hạn vào session, redirect trình duyệt sang đây bằng
// điều hướng TRANG THƯỜNG (window.location, không phải AJAX) để Auth::login() chạy trong 1 vòng
// đời request HTTP bình thường — giống hệt cách 1 form đăng nhập chuẩn hoạt động.
class AccountSwitchController extends Controller
{
    public function commit(): RedirectResponse
    {
        $me = auth()->user();

        abort_unless($me instanceof User, 403);

        $pending = session('pending_account_switch');
        session()->forget('pending_account_switch');

        abort_unless(is_array($pending), 403);
        abort_unless(($pending['expires_at'] ?? 0) > time(), 403);

        $target = User::find($pending['target_id'] ?? null);

        abort_unless($target && $me->isEligibleSwitchTarget($target), 403);

        $panel = Filament::getCurrentPanel();

        abort_if($panel && ! $target->canAccessPanel($panel), 403);

        // Chỉ lưu id gốc THẬT lần đầu tiên — nếu đang switch nối tiếp (A -> B -> C), nút "Quay lại"
        // luôn đưa về A, không phải B.
        if (! session()->has('impersonate.original_id')) {
            session(['impersonate.original_id' => $me->id]);
        }

        Auth::guard('web')->login($target);

        return redirect(Filament::getUrl());
    }

    public function back(): RedirectResponse
    {
        abort_unless(auth()->check(), 403);

        $originalId = session('impersonate.original_id');
        session()->forget('impersonate.original_id');

        abort_unless($originalId, 403);

        $original = User::find($originalId);

        abort_unless($original, 404);

        Auth::guard('web')->login($original);

        return redirect(Filament::getUrl());
    }
}
