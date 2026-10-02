<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Partner;
use App\Models\PartnerSubscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Minihouse\App\Support\HomestayBridge;

// Cổng kiểm soát GÓI DỊCH VỤ — CHỈ ÁP DỤNG CHO MINIHOUSE: đối tác MiniHouse dùng đầy đủ chức năng khi gói còn hạn; hết hạn/bị huỷ thì bị khoá
// (API trả 402, trang quản trị chuyển về trang Gói dịch vụ). Homestay KHÔNG dùng gói: đăng nhập là dùng.
// Miễn trừ: super admin, tài khoản không thuộc đối tác, đối tác hệ thống, đối tác Homestay, đối tác MiniHouse CHƯA có đăng ký gói.
class SubscriptionGate
{
    /** @var array<string, array{exempt: bool, sub: ?PartnerSubscription, locked: bool, type: ?string}> */
    private static array $cache = [];

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** @return array{exempt: bool, sub: ?PartnerSubscription, locked: bool, type: ?string} */
    public static function stateFor(User $user): array
    {
        $key = (string) $user->getKey() . '|' . (string) $user->partner_id;

        return self::$cache[$key] ??= self::compute($user);
    }

    private static function compute(User $user): array
    {
        $exempt = ['exempt' => true, 'sub' => null, 'locked' => false, 'type' => null];

        if (! $user->partner_id || $user->isSuperAdmin() || $user->partner_id === HomestayBridge::PARTNER_ID) {
            return $exempt;
        }

        $partner = Partner::withTrashed()->find($user->partner_id);
        $type = $partner?->partner_type;

        // Homestay không dùng gói; đối tác không tồn tại thì không giới hạn.
        if (! $partner || $type !== Partner::TYPE_MINIHOUSE) {
            return ['exempt' => true, 'sub' => null, 'locked' => false, 'type' => $type];
        }

        $sub = $partner->subscription()->with('plan')->first();

        if (! $sub) {
            return ['exempt' => true, 'sub' => null, 'locked' => false, 'type' => $type];
        }

        return ['exempt' => false, 'sub' => $sub, 'locked' => ! $sub->isUsable(), 'type' => $type];
    }

    /** Tài khoản đang đăng nhập có đang bị khoá do gói hết hạn/huỷ không (dùng trong view/Livewire). */
    public static function lockedCurrent(): bool
    {
        $user = auth()->user();

        return $user instanceof User && self::stateFor($user)['locked'];
    }

    /**
     * Chặn request API admin. Trả response lỗi, hoặc null nếu được phép.
     *  - 402 SUBSCRIPTION_EXPIRED: đối tác MiniHouse hết hạn/bị khoá (trừ các nhóm luôn cho phép).
     *  - 403 FORBIDDEN_PARTNER_TYPE: gọi API của loại đối tác khác (Homestay gọi /minihouse/*, MiniHouse gọi API Homestay).
     */
    public static function checkRequest(Request $request, User $user): ?JsonResponse
    {
        $state = self::stateFor($user);

        $path = trim(substr($request->path(), strlen('api/admin/')), '/');
        $segments = explode('/', $path);
        $isMinihouseApi = ($segments[0] ?? '') === 'minihouse';
        $key = $isMinihouseApi ? 'minihouse/' . ($segments[1] ?? '') : ($segments[0] ?? '');
        $alwaysAllowed = in_array($key, config('subscription.always_allowed_api'), true);

        // Tách loại đối tác ở API.
        if ($state['type'] && ! $alwaysAllowed) {
            $isMinihousePartner = $state['type'] === Partner::TYPE_MINIHOUSE;

            if ($isMinihouseApi !== $isMinihousePartner) {
                return response()->json([
                    'code'    => 'FORBIDDEN_PARTNER_TYPE',
                    'message' => 'Tài khoản không có quyền dùng chức năng này.',
                ], 403);
            }
        }

        if ($state['exempt'] || ! $state['locked'] || $alwaysAllowed) {
            return null;
        }

        return response()->json([
            'code'       => 'SUBSCRIPTION_EXPIRED',
            'message'    => 'Gói dịch vụ đã hết hạn. Vui lòng thanh toán phí để tiếp tục sử dụng.',
            'expires_at' => $state['sub']?->expires_at?->toIso8601String(),
        ], 402);
    }
}
