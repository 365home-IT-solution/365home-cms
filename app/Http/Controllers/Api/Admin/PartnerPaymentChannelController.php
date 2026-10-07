<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Services\Payment\PartnerPayOsChannelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kênh PayOS RIÊNG của đối tác Homestay — tiền đặt phòng online của mọi chi nhánh thuộc đối tác về thẳng tài khoản đối tác
 * (xem App\Services\Payment\PayOsAccountResolver). Đối tác chưa cấu hình hoặc đang tắt kênh thì 365home thu hộ như cũ.
 *
 * Xem: Super Admin và mọi tài khoản của chính đối tác. Lưu: Super Admin; chủ đối tác CHỈ tự nhập được khi Super Admin đã
 * bật quyền cho đối tác đó (PUT …/payment-channel/permission, mặc định tắt) — nhân viên của đối tác không bao giờ được, vì
 * đây là nơi đổi tiền chảy về tài khoản nào. Chủ đối tác đổi kênh thì Super Admin được thông báo ngay
 * (xem PartnerPayOsChannelService::save()).
 * Khoá bí mật (api_key, checksum_key) không bao giờ trả về; khi lưu, khoá gửi trống = giữ nguyên.
 */
class PartnerPaymentChannelController extends Controller
{
    public function __construct(private PartnerPayOsChannelService $channels) {}

    // GET /api/admin/partners/{partner}/payment-channel
    public function show(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeView($request, $partner);

        return response()->json(['data' => $this->data($request, $partner)]);
    }

    // PUT /api/admin/partners/{partner}/payment-channel/permission — Super Admin bật/tắt cho chủ đối tác tự nhập kênh. body: partner_setup_allowed
    public function updatePermission(Request $request, Partner $partner): JsonResponse
    {
        abort_if($partner->isSystemPartner() || $partner->isMinihouse(), 404);
        abort_unless($request->user()->isSuperAdmin(), 403, 'Chỉ Super Admin được phân quyền cấu hình kênh PayOS.');
        $data = $request->validate(['partner_setup_allowed' => ['required', 'boolean']]);

        $this->channels->setPartnerSetupAllowed($partner, (bool) $data['partner_setup_allowed'], $request->user());

        return response()->json(['message' => $data['partner_setup_allowed'] ? 'Đã cho phép chủ đối tác tự nhập kênh PayOS.' : 'Đã tắt quyền tự nhập kênh PayOS của chủ đối tác.', 'data' => $this->data($request, $partner->fresh())]);
    }

    // POST /api/admin/partners/{partner}/payment-channel — body: client_id?, api_key?, checksum_key?, is_active?, note?
    // Đổi khoá → BE gọi thử PayOS và đối chiếu chủ tài khoản với tab Tài chính; sai → 422, không lưu.
    public function store(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeManage($request, $partner);
        $data = $request->validate([
            'client_id'    => ['nullable', 'string', 'max:255'],
            'api_key'      => ['nullable', 'string', 'max:255'],
            'checksum_key' => ['nullable', 'string', 'max:255'],
            'is_active'    => ['sometimes', 'boolean'],
            'note'         => ['nullable', 'string', 'max:255'],
        ]);

        $this->channels->save($partner, $data, $request->user());

        return response()->json(['message' => 'Đã lưu kênh PayOS của đối tác.', 'data' => $this->data($request, $partner->fresh())]);
    }

    // POST /api/admin/partners/{partner}/payment-channel/confirm-webhook — đăng ký lại URL webhook của 365home cho kênh PayOS.
    public function confirmWebhook(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeManage($request, $partner);
        $account = $partner->payOsAccount;
        abort_unless($account?->isComplete(), 422, 'Đối tác chưa cấu hình kênh PayOS.');
        abort_unless($this->channels->confirmWebhook($account), 422, 'Chưa đăng ký được webhook — kiểm tra lại khoá, và URL webhook phải truy cập được công khai.');

        return response()->json(['message' => 'Đã đăng ký webhook cho kênh PayOS.', 'data' => $this->data($request, $partner->fresh())]);
    }

    // Kèm can_manage: tài khoản đang gọi có được lưu kênh không — app dựa vào đây để hiện form nhập hay chỉ hiện trạng thái.
    private function data(Request $request, Partner $partner): array
    {
        $user = $request->user();

        return [...$this->channels->data($partner), 'can_manage' => $user->isSuperAdmin() || $this->channels->partnerOwnerCanManage($partner, $user)];
    }

    private function authorizeView(Request $request, Partner $partner): void
    {
        abort_if($partner->isSystemPartner() || $partner->isMinihouse(), 404);
        abort_unless($request->user()->isSuperAdmin() || $request->user()->partner_id === $partner->id, 403);
    }

    private function authorizeManage(Request $request, Partner $partner): void
    {
        abort_if($partner->isSystemPartner() || $partner->isMinihouse(), 404);
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || $this->channels->partnerOwnerCanManage($partner, $user),
            403, $partner->payos_self_setup_enabled ? 'Chỉ chủ đối tác hoặc Super Admin được cấu hình kênh PayOS.' : 'Kênh PayOS của đối tác do 365home cấu hình — liên hệ 365home để được hỗ trợ hoặc được cấp quyền tự nhập.');
    }
}
