<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Support;

use App\Models\User;
use Illuminate\Support\Str;

// Tên hiển thị cho "Người nhập/xuất/kiểm kê kho" — dùng ở 2 nơi:
//  - Placeholder tự động = tài khoản đang đăng nhập lúc tạo phiếu (KHÔNG phải chọn Employee, xem
//    WarehouseStock{In,Out,Check}Form) → name().
//  - Cột "Người nhập/xuất/kiểm kê kho" trong bảng danh sách, đọc lại creator (created_by) của MỘT
//    phiếu bất kỳ, không nhất thiết là người đang xem → forUser().
// Ưu tiên fullname; nếu tài khoản chưa có fullname thì lấy phần TRƯỚC @ của email làm tên gọi tắt —
// không hiển thị nguyên email đăng nhập đầy đủ ra màn hình.
class CurrentUserDisplay
{
    public static function name(): string
    {
        return static::forUser(auth()->user());
    }

    public static function forUser(?User $user): string
    {
        if (! $user) {
            return '—';
        }

        if (filled($user->name)) {
            return $user->name;
        }

        return $user->email ? Str::before($user->email, '@') : '—';
    }
}
