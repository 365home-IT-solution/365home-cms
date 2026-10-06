<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PartnerContractVersion;
use App\Services\ContractOtpService;
use App\Services\PartnerLegalDocumentService;
use App\Support\PartnerContractRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

// Trang KÝ HỢP ĐỒNG ĐIỆN TỬ công khai cho đối tác — KHÔNG cần đăng nhập CMS (đối tác nhận link
// này qua email từ super_admin, xem PartnerForm::contractTab()). Xác thực danh tính bằng OTP gửi
// tới email đối tác.
//
// QUAN TRỌNG: route này KHÔNG gọi DigitalSignatureProvider::sign() trực tiếp nữa — chỉ GHI NHẬN
// ĐỒNG Ý của đối tác (partner_confirmed_at). Lý do: route công khai này có thể được đối tác bấm ký
// BẤT KỲ lúc nào (11h đêm, cuối tuần...), trong khi chữ ký số thật (VNPT SmartCA) cần OTP hợp lệ
// tại đúng thời điểm gọi API — không có nhân viên nào đứng cạnh để nhập OTP lúc đó. Việc ÁP CHỮ KÝ
// SỐ THẬT (partner_signed_at) được dời sang 1 hành động riêng của nhân viên trong PartnerForm
// ("Hoàn tất ký số (Đối tác)"), thực hiện sau đó khi thuận tiện, tự nhập OTP đọc từ app SmartCA.
class ContractSignController extends Controller
{
    public function show(string $token, PartnerLegalDocumentService $documents): View
    {
        $version = $this->findVersion($token);
        $eligible = $documents->canPartnerSign($version->partner);

        return view('contract-sign', [
            'version' => $version,
            'partner' => $version->partner,
            'canSign' => $eligible && ! $version->isPartnerConfirmed(),
            'legalDocumentsEligible' => $eligible,
            'framedContent' => PartnerContractRenderer::renderFramed($version->content, $version->partner, $version),
        ]);
    }

    public function sendOtp(string $token, ContractOtpService $otp, PartnerLegalDocumentService $documents): RedirectResponse
    {
        $version = $this->findVersion($token);

        if (! $documents->canPartnerSign($version->partner)) {
            return back()->with('error', 'Hồ sơ pháp lý của đối tác chưa được phê duyệt hoặc cần xác minh lại.');
        }

        if ($version->isPartnerConfirmed()) {
            return back()->with('error', 'Hợp đồng này đã được xác nhận trước đó.');
        }

        if ($otp->hasCooldown($version)) {
            return back()->with('error', 'Bạn vừa yêu cầu gửi mã — vui lòng đợi ít phút rồi thử lại.');
        }

        // Ưu tiên email LIÊN HỆ của đối tác (partner.email) — khớp đúng nơi đã gửi link ký ban
        // đầu (xem PartnerForm::createAndSendContract()), chỉ rơi về email đăng nhập hệ thống
        // nếu đối tác chưa khai báo email liên hệ riêng.
        $email = $version->partner->email ?? $version->partner->owner()?->email;

        if (blank($email)) {
            return back()->with('error', 'Đối tác chưa có email liên hệ để gửi mã xác nhận.');
        }

        if (! $otp->send($version, $email)) {
            return back()->with('error', 'Không gửi được mã xác nhận qua email. Vui lòng liên hệ quản trị viên hoặc thử lại sau.');
        }

        $maskedEmail = preg_replace('/^(.{2}).*(@.*)$/', '$1***$2', $email);

        return back()->with('success', "Đã gửi mã xác nhận đến email {$maskedEmail}.");
    }

    public function sign(Request $request, string $token, ContractOtpService $otp, PartnerLegalDocumentService $documents): RedirectResponse
    {
        $version = $this->findVersion($token);

        if (! $documents->canPartnerSign($version->partner)) {
            return back()->with('error', 'Hồ sơ pháp lý của đối tác chưa được phê duyệt hoặc cần xác minh lại.');
        }

        if ($version->isPartnerConfirmed()) {
            return back()->with('error', 'Hợp đồng này đã được xác nhận trước đó.');
        }

        $data = $request->validate([
            'otp' => ['required', 'string', 'size:6'],
            'signer_name' => ['required', 'string', 'max:255'],
            'agree' => ['required', 'accepted'],
        ], [], [
            'otp' => 'mã xác nhận',
            'signer_name' => 'họ tên người ký',
            'agree' => 'đồng ý điều khoản',
        ]);

        if (! $otp->verify($version, $data['otp'])) {
            return back()->with('error', 'Mã xác nhận không đúng hoặc đã hết hạn.')->withInput();
        }

        // OTP xác thực danh tính xong — CHỈ ghi nhận đồng ý ở đây (xem comment đầu file lý do
        // không ký số thật ngay tại bước này). Nhân viên sẽ hoàn tất ký số thật sau đó.
        $version->update([
            'partner_confirmed_at' => now(),
            'partner_signed_by_name' => $data['signer_name'],
            'partner_signed_ip' => $request->ip(),
            'partner_signed_user_agent' => (string) $request->userAgent(),
        ]);

        app(\App\Services\PartnerOnboardingService::class)->notifyPartnerSigned($version->partner);

        return back()->with('success', $version->partner->fresh()->verification_status === 'approved'
            ? 'Xác nhận thành công! Hợp đồng sẽ được hoàn tất chữ ký số bởi nền tảng trong ít phút. Cảm ơn bạn đã hợp tác.'
            : 'Xác nhận thành công! Hồ sơ đã được gửi cho 365 Home duyệt; hợp đồng có hiệu lực sau khi 365 Home ký xác nhận.');
    }

    private function findVersion(string $token): PartnerContractVersion
    {
        $version = PartnerContractVersion::where('signing_token', $token)
            ->with('partner')
            ->firstOrFail();

        // Chỉ phiên bản mới nhất của đối tác mới ký được (phiên bản cũ bị thay thế → link hết hiệu lực).
        abort_unless($version->partner->contractVersions()->first()?->id === $version->id, 404);
        abort_unless($version->partner->usesContract(), 404);

        return $version;
    }
}
