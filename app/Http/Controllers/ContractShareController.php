<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ContractShareLink;
use App\Services\ContractShareLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

// Trang CÔNG KHAI tải hợp đồng qua link chia sẻ (kiểu Google Drive) — không cần đăng nhập. Link tạo ở tab Hợp đồng của đối tác
// (trang quản trị) hoặc API admin; xem App\Services\ContractShareLinkService. Có mật khẩu thì phải mở khoá (lưu trong session) trước khi xem/tải.
class ContractShareController extends Controller
{
    public function __construct(private readonly ContractShareLinkService $links) {}

    public function show(Request $request, string $token): Response
    {
        $link = $this->links->find($token);
        if (! $link || ! $link->version) {
            return $this->page(['notFound' => true], 404);
        }

        if ($reason = $link->unavailableReason()) {
            return $this->page(['link' => $link, 'reason' => $reason], 410);
        }

        $locked = $link->hasPassword() && ! $this->unlocked($request, $link);
        if (! $locked) {
            $this->links->recordAccess($link, 'view', $request);
        }

        return $this->page([
            'link' => $link,
            'token' => $token,
            'locked' => $locked,
            'signatureState' => $this->links->signatureState($link->version),
        ]);
    }

    public function unlock(Request $request, string $token): RedirectResponse
    {
        $link = $this->links->find($token);
        abort_if(! $link || $link->unavailableReason() !== null || ! $link->hasPassword(), 404);

        $password = (string) $request->validate(['password' => ['required', 'string', 'max:100']], [], ['password' => 'mật khẩu'])['password'];
        if (! $this->links->checkPassword($link, $password, $request)) {
            return back()->withErrors(['password' => 'Mật khẩu không đúng.']);
        }

        $request->session()->put($this->sessionKey($link), true);

        return redirect()->route('contract-share.show', ['token' => $token]);
    }

    public function file(Request $request, string $token, string $mode): SymfonyResponse
    {
        $link = $this->links->find($token);
        abort_if(! $link || ! $link->version, 404);
        abort_if($link->unavailableReason() !== null, 410, (string) $link->unavailableReason());
        if ($link->hasPassword() && ! $this->unlocked($request, $link)) {
            return redirect()->route('contract-share.show', ['token' => $token]);
        }

        $file = $this->links->pdf($link);
        $download = $mode === 'tai-xuong';
        $this->links->recordAccess($link, $download ? 'download' : 'preview', $request);

        return response($file['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline') . '; filename="' . $file['file_name'] . '"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function page(array $data, int $status = 200): Response
    {
        return response()->view('contract-share', $data + ['link' => null, 'token' => null, 'signatureState' => null, 'notFound' => false, 'reason' => null, 'locked' => false], $status)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'private, no-store');
    }

    private function unlocked(Request $request, ContractShareLink $link): bool
    {
        return (bool) $request->session()->get($this->sessionKey($link), false);
    }

    private function sessionKey(ContractShareLink $link): string
    {
        // Gắn với mật khẩu hiện tại (không dùng updated_at — mỗi lượt xem/tải đều làm đổi cột đó): đổi mật khẩu thì phiên đã mở khoá cũ hết hiệu lực.
        return 'contract_share_unlocked.' . $link->id . '.' . substr(hash('sha256', (string) $link->password), 0, 16);
    }
}
