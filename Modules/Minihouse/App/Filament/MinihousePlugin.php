<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;

// Gắn vào panel /minihouse/admin (App\Providers\Filament\MinihouseAdminPanelProvider, id vẫn giữ
// 'minihouse-admin' dù path đã đổi) — dùng đúng cơ chế tự động phát hiện Page/Resource/Widget trong
// module giống mọi module khác của panel Home (BookPlugin, ProductPlugin...), không cần khai báo
// tay từng class trong panel provider.
class MinihousePlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Minihouse';
    }

    public function getId(): string
    {
        return 'minihouse';
    }

    public function boot(\Filament\Panel $panel): void
    {
        //
    }
}
