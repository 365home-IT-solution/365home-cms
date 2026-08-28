<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\AccessCodeTableWidget;
use App\Filament\Widgets\ManualLockPasswordTableWidget;
use Filament\Pages\Page;

// Gộp 2 mục menu trước đây tách rời — "Pass Cổng" (AccessCodeResource) và "Khóa thủ công"
// (ManualLockPasswordResource) — vào 1 mục "Khóa cổng" duy nhất, hiển thị 2 bảng riêng biệt bên
// dưới nhau. Không có Resource nào gộp được 2 bảng, nên dùng Filament\Widgets\TableWidget (mỗi
// bảng 1 widget, tái dùng nguyên cột/action/quyền hạn của resource gốc — xem
// AccessCodeTableWidget/ManualLockPasswordTableWidget) thay vì viết lại từ đầu. 2 Resource gốc vẫn
// còn nguyên route/permission/Excel import, chỉ ẩn khỏi menu (shouldRegisterNavigation() = false).
class GateLockManagement extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Cấu hình web';

    protected static ?string $navigationLabel = 'Khóa cổng';

    protected static ?string $title = 'Khóa cổng';

    protected static string $view = 'filament.pages.gate-lock-management';

    public static function canAccess(): bool
    {
        return (auth()->user()?->can('view_any_access::code') ?? false)
            || (auth()->user()?->can('viewAny', \Modules\Product\App\Models\ManualLockPassword::class) ?? false);
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AccessCodeTableWidget::class,
            ManualLockPasswordTableWidget::class,
        ];
    }
}
