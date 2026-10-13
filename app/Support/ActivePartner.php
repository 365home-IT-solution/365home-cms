<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Partner;
use App\Models\User;

// "Đối tác đang chọn" của super_admin: CMS lưu ở session (nút "Chuyển đổi đối tác" ở topbar), API
// nhận qua header X-Partner-Id. Chỉ super_admin mới có tác dụng — mọi tài khoản khác luôn bị khoá
// theo đối tác của chính mình. Sau khi áp dụng, $user->partner_id trả về đối tác đang chọn (xem
// User::getPartnerIdAttribute) nên toàn bộ logic lọc theo đối tác tự chạy đúng phạm vi.
class ActivePartner
{
    public const SESSION_KEY = 'active_partner_id';

    public const HEADER = 'X-Partner-Id';

    // Trả về false khi super_admin gửi/lưu id không tồn tại (đối tác đã xoá) — caller tự quyết
    // định trả lỗi (API) hay bỏ qua lựa chọn (CMS).
    public static function apply(User $user, ?string $partnerId): bool
    {
        if (! $user->isSuperAdmin() || blank($partnerId)) {
            return true;
        }

        if (! Partner::query()->whereKey($partnerId)->exists()) {
            return false;
        }

        $user->actAsPartner($partnerId);

        return true;
    }

    // Đối tác (id, tên) cho nút chuyển đổi ở CMS — chỉ Homestay, khớp với panel admin.
    public static function options(): array
    {
        return Partner::query()
            ->where('partner_type', Partner::TYPE_HOMESTAY)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
