<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Partner;
use App\Models\User;

/**
 * Loại đối tác đã TẮT "Tính vào thống kê hệ thống" (partners.count_in_platform_stats = false — đối tác
 * dùng thử, dữ liệu test...) khỏi số liệu tổng hợp mà SUPER ADMIN xem: dashboard, báo cáo, API doanh thu.
 *
 * CHỈ áp dụng khi người xem chắc chắn là super_admin. Chủ đối tác/nhân viên luôn thấy đủ số liệu của
 * chính mình, và ngữ cảnh không có người đăng nhập (queue, console, export chạy nền) cũng không bị lọc —
 * tránh làm rỗng số liệu của chính đối tác đó.
 */
class PlatformStats
{
    /** @return array<int, string> id các đối tác bị loại khỏi thống kê với $viewer (mặc định: user đang đăng nhập). */
    public static function excludedPartnerIds($viewer = null): array
    {
        $viewer ??= auth()->user();

        if (! $viewer instanceof User || ! $viewer->isSuperAdmin()) {
            return [];
        }

        return Partner::withTrashed()->where('count_in_platform_stats', false)->pluck('id')->all();
    }

    /**
     * Thêm điều kiện loại các đối tác trên vào $query (Eloquent hoặc DB::table) theo cột partner_id chỉ định
     * (vd 'o.partner_id' với query join thô). Bản ghi chưa gắn đối tác (partner_id NULL) vẫn được giữ.
     */
    public static function apply($query, string $partnerColumn = 'partner_id', $viewer = null)
    {
        $excluded = static::excludedPartnerIds($viewer);

        if ($excluded === []) {
            return $query;
        }

        return $query->where(fn ($q) => $q->whereNull($partnerColumn)->orWhereNotIn($partnerColumn, $excluded));
    }
}
