<?php

namespace Modules\Coupon\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class CouponPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Coupon';
    }

    public function getId(): string
    {
        return 'coupon';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}
