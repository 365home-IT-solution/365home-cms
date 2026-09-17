<?php

declare(strict_types=1);

namespace Modules\Metering\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;

// Gắn vào panel /minihouse-admin (App\Providers\Filament\MinihouseAdminPanelProvider) — cùng cơ chế
// tự động phát hiện Resource trong module như MinihousePlugin, để module Metering (tách riêng khỏi
// Minihouse) vẫn hiện resource của nó trong đúng 1 panel quản trị dùng chung.
class MeteringPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Metering';
    }

    public function getId(): string
    {
        return 'metering';
    }

    public function boot(\Filament\Panel $panel): void
    {
        //
    }
}
