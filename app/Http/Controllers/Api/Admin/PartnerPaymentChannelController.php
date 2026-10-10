<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Services\PartnerChannelOtpService;
use App\Services\Payment\PartnerPayOsChannelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\BranchPayOsAccount;

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
    public function __construct(private PartnerPayOsChannelService $channels, private PartnerChannelOtpService $otp) {}

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
            'otp'          => ['nullable', 'string', 'size:6'],
        ]);

        // Chủ đối tác tự đổi nơi nhận tiền (đổi khoá hoặc bật/tắt kênh) phải xác nhận bằng OTP gửi về email của chính họ; Super Admin nhập hộ thì không.
        $user = $request->user();
        if (! $user->isSuperAdmin() && $this->changesDestination($partner, $data)) {
            if (blank($data['otp'] ?? null)) {
                return response()->json(['message' => 'Đổi kênh PayOS cần mã OTP gửi về email của bạn — gọi POST …/payment-channel/otp trước.', 'code' => 'OTP_REQUIRED'], 422);
            }
            if (! $this->otp->verify($user, $partner, (string) $data['otp'])) {
                return response()->json(['message' => 'Mã OTP không đúng hoặc đã hết hạn.', 'code' => 'OTP_INVALID'], 422);
            }
        }
        unset($data['otp']);

        $this->channels->save($partner, $data, $user);

        return response()->json(['message' => 'Đã lưu kênh PayOS của đối tác.', 'data' => $this->data($request, $partner->fresh())]);
    }

    // PUT /api/admin/partners/{partner}/payment-channel/branches/{category} — lưu kênh PayOS RIÊNG của một chi nhánh (ghi đè kênh đối tác).
    // body như POST payment-channel: client_id?, api_key?, checksum_key?, is_active?, note?, otp? (tạo mới phải đủ 3 khoá).
    public function storeBranch(Request $request, Partner $partner, int $category): JsonResponse
    {
        $this->authorizeManage($request, $partner);
        $branch = $this->branchOf($partner, $category);
        $data = $request->validate([
            'client_id'    => ['nullable', 'string', 'max:255'],
            'api_key'      => ['nullable', 'string', 'max:255'],
            'checksum_key' => ['nullable', 'string', 'max:255'],
            'is_active'    => ['sometimes', 'boolean'],
            'note'         => ['nullable', 'string', 'max:255'],
            'otp'          => ['nullable', 'string', 'size:6'],
        ]);

        $existing = BranchPayOsAccount::query()->where('category_id', $branch->id)->first();
        $changes = filled($data['client_id'] ?? null) || filled($data['api_key'] ?? null) || filled($data['checksum_key'] ?? null)
            || (array_key_exists('is_active', $data) && (bool) $data['is_active'] !== (bool) $existing?->is_active);

        if ($response = $this->requireOtp($request, $partner, $changes, $data['otp'] ?? null)) {
            return $response;
        }

        $account = $this->channels->saveBranch($partner, $branch, $data, $request->user());

        return response()->json(['message' => 'Đã lưu kênh PayOS riêng của chi nhánh.', 'data' => $this->channels->branchData($partner, $account)]);
    }

    // DELETE /api/admin/partners/{partner}/payment-channel/branches/{category} — xoá kênh riêng của chi nhánh (quay về kênh đối tác). Chủ đối tác kèm otp.
    public function destroyBranch(Request $request, Partner $partner, int $category): JsonResponse
    {
        $this->authorizeManage($request, $partner);
        $branch = $this->branchOf($partner, $category);
        $data = $request->validate(['otp' => ['nullable', 'string', 'size:6']]);

        abort_unless(BranchPayOsAccount::query()->where('category_id', $branch->id)->exists(), 404, 'Chi nhánh này chưa có kênh PayOS riêng.');

        if ($response = $this->requireOtp($request, $partner, true, $data['otp'] ?? null)) {
            return $response;
        }

        $this->channels->removeBranch($partner, $branch, $request->user());

        return response()->json(['message' => 'Đã xoá kênh PayOS riêng của chi nhánh — chi nhánh dùng kênh của đối tác.', 'data' => $this->data($request, $partner->fresh())]);
    }

    private function branchOf(Partner $partner, int $categoryId): Category
    {
        abort_unless($this->channels->ownsBranch($partner, $categoryId), 404, 'Chi nhánh không thuộc đối tác này.');

        return Category::query()->findOrFail($categoryId);
    }

    /** Chủ đối tác tự đổi nơi nhận tiền phải xác nhận OTP; Super Admin thì không. Trả response lỗi nếu thiếu/sai OTP, null nếu hợp lệ. */
    private function requireOtp(Request $request, Partner $partner, bool $changesDestination, ?string $otp): ?JsonResponse
    {
        $user = $request->user();

        if ($user->isSuperAdmin() || ! $changesDestination) {
            return null;
        }
        if (blank($otp)) {
            return response()->json(['message' => 'Đổi kênh PayOS cần mã OTP gửi về email của bạn — gọi POST …/payment-channel/otp trước.', 'code' => 'OTP_REQUIRED'], 422);
        }
        if (! $this->otp->verify($user, $partner, $otp)) {
            return response()->json(['message' => 'Mã OTP không đúng hoặc đã hết hạn.', 'code' => 'OTP_INVALID'], 422);
        }

        return null;
    }

    // POST /api/admin/partners/{partner}/payment-channel/otp — chủ đối tác xin mã OTP (email) để xác nhận đổi kênh PayOS.
    public function sendOtp(Request $request, Partner $partner): JsonResponse
    {
        $this->authorizeManage($request, $partner);
        $user = $request->user();
        abort_if($user->isSuperAdmin(), 422, 'Super Admin không cần OTP.');
        abort_if($this->otp->hasCooldown($user, $partner), 429, 'Vui lòng chờ 1 phút trước khi xin mã mới.');
        abort_unless(filled($user->email), 422, 'Tài khoản chưa có email để nhận mã OTP.');
        abort_unless($this->otp->send($user, $partner), 500, 'Không gửi được email OTP — thử lại sau.');

        return response()->json(['message' => 'Đã gửi mã OTP tới email ' . $this->maskEmail((string) $user->email) . ' (hiệu lực 5 phút).']);
    }

    /** Có đổi nơi nhận tiền không: gửi khoá mới, hoặc bật/tắt kênh khác trạng thái hiện tại. */
    private function changesDestination(Partner $partner, array $data): bool
    {
        if (filled($data['client_id'] ?? null) || filled($data['api_key'] ?? null) || filled($data['checksum_key'] ?? null)) {
            return true;
        }

        return array_key_exists('is_active', $data) && (bool) $data['is_active'] !== (bool) $partner->payOsAccount?->is_active;
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 2) . '***@' . $domain;
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
