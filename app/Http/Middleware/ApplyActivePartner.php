<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ActivePartner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Áp "đối tác đang chọn" (session, nút "Chuyển đổi đối tác" ở topbar) lên User của request trong
// admin panel. Đăng ký persistent cùng MarkAdminPanelContext để Livewire replay trên /livewire/update.
class ApplyActivePartner
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user instanceof User && $user->isSuperAdmin()) {
            $partnerId = session(ActivePartner::SESSION_KEY);

            if (filled($partnerId) && ! ActivePartner::apply($user, (string) $partnerId)) {
                // Đối tác đã bị xoá — bỏ lựa chọn cũ thay vì khoá super_admin ở một phạm vi rỗng.
                session()->forget([ActivePartner::SESSION_KEY, 'active_branch_ids']);
            }
        }

        return $next($request);
    }
}
