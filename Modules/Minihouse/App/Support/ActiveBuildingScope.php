<?php

namespace Modules\Minihouse\App\Support;

use App\Models\User;
use Filament\Facades\Filament;

// Toà nhà = "chi nhánh" của MiniHouse — cùng ý tưởng với bộ lọc chi nhánh bên Home
// (App\Livewire\BranchSwitcher, session('active_branch_ids') + User::rootProductCategoryIds()/
// effectiveBranchIds()), gộp LÀM MỘT 2 việc mà bên Home tách 2 tầng riêng:
//  - Ranh giới QUYỀN: toà nhà tài khoản được phép quản lý — User::rootBuildingIds() (dựa trên
//    minihouse_user_buildings, xem User::minihouseBuildings()).
//  - Tiện ích LỌC MÀN HÌNH: trong số toà được phép, đang chọn xem toà nào — session (bộ lọc header,
//    xem Modules\Minihouse\App\Livewire\BuildingSwitcher).
// Không cần AdminPanelContext + persistent middleware như Home (đó là để scope còn đúng cả trong
// job/console/site công khai dùng chung Product/Order) — các model MiniHouse chỉ dùng trong đúng 1
// panel này, kiểm tra thẳng Filament::getCurrentPanel() là đủ tin cậy, kể cả trên request
// /livewire/update (SetUpPanel middleware của panel này vẫn chạy).
class ActiveBuildingScope
{
    public const SESSION_KEY = 'minihouse_active_building_ids';

    public static function isPanelActive(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'minihouse-admin';
    }

    // Toà nhà tài khoản đang đăng nhập ĐƯỢC PHÉP quản lý — ranh giới quyền thật sự (xem
    // User::rootBuildingIds()). Rỗng nếu chưa đăng nhập/không phải App\Models\User.
    /** @return array<int> */
    public static function permittedBuildingIds(): array
    {
        $user = auth()->user();

        return $user instanceof User ? $user->rootBuildingIds() : [];
    }

    // Trong số toà được phép, đang thực sự xem toà nào — thu hẹp thêm theo lựa chọn ở bộ lọc header
    // nếu có, KHÔNG BAO GIỜ vượt quá permittedBuildingIds() dù session có bị can thiệp.
    //
    // Nếu lựa chọn cũ trong session không còn giao với permittedBuildingIds() nữa (VD admin vừa đổi
    // lại danh sách toà nhà được gán cho tài khoản này trong lúc họ đang có phiên đăng nhập cũ) thì
    // rơi về TOÀN BỘ permittedBuildingIds() thay vì mảng rỗng — mảng rỗng ở đây từng bị hiểu là
    // "không lọc gì" tại chỗ khác, nhưng shouldFilter() của tài khoản thường luôn trả về true nên
    // whereIn(...,[]) sẽ ẩn sạch toàn bộ dữ liệu của chính họ cho tới khi họ tự mở lại bộ lọc header
    // và bấm "Áp dụng" — tự khoá không đáng có.
    /** @return array<int> */
    public static function activeBuildingIds(): array
    {
        $permitted = self::permittedBuildingIds();
        $stored    = session(self::SESSION_KEY);

        if (empty($stored)) {
            return $permitted;
        }

        $active = array_values(array_intersect($permitted, $stored));

        return $active ?: $permitted;
    }

    // Có cần lọc query hiện tại hay không. Lọc khi: đang ở đúng panel minihouse-admin, VÀ tài
    // khoản không phải super_admin (super_admin không bị giới hạn quyền, chỉ lọc màn hình khi họ tự
    // bấm chọn ở header — session có giá trị) HOẶC tài khoản thường (luôn lọc theo đúng
    // permittedBuildingIds(), kể cả khi chưa từng đụng vào bộ lọc header — đây là ranh giới quyền,
    // không phải tuỳ chọn).
    public static function shouldFilter(): bool
    {
        if (! self::isPanelActive()) {
            return false;
        }

        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return filled(session(self::SESSION_KEY));
        }

        return true;
    }
}
