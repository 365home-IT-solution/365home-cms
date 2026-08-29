<?php

declare(strict_types=1);

namespace App\Support;

// Dùng chung cho các Observer viết tay (app/Observers/*.php) — lọc bỏ field noise trước khi đưa
// vào old/new_values của audit log, CÙNG danh sách loại trừ với App\Models\Concerns\LogsAuditTrail
// (trait dùng cho ~50 model còn lại). Trước đây mỗi Observer viết tay tự khai 1 danh sách
// TRACKED_FIELDS riêng (whitelist) — bỏ sót field nào thì field đó đổi mà KHÔNG có log (vd sửa mô
// tả phòng, thay đổi tiêu đề bài viết không nằm trong whitelist). Bỏ hẳn cách whitelist, log TOÀN
// BỘ field thực sự thay đổi, chỉ loại các field kỹ thuật không có ý nghĩa audit.
class AuditFieldFilter
{
    private const DEFAULT_EXCLUDED = ['id', 'created_at', 'updated_at', 'deleted_at', 'password', 'remember_token'];

    public static function filter(array $attributes, array $extraExcluded = []): array
    {
        return array_diff_key($attributes, array_flip(array_merge(self::DEFAULT_EXCLUDED, $extraExcluded)));
    }
}
