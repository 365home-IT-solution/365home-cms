<?php

declare(strict_types=1);

namespace BezhanSalleh\FilamentShield\Traits;

use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Panel;

trait HasPanelShield
{
    public static function bootHasPanelShield()
    {
        try {
            if (Utils::isPanelUserRoleEnabled()) {

                Utils::createPanelUserRole();

                static::created(fn ($user) => $user->assignRole(Utils::getPanelUserRoleName()));

                static::deleting(fn ($user) => $user->removeRole(Utils::getPanelUserRoleName()));
            }
        } catch (\Exception $e) {
            //
        }
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasRole(Utils::getSuperAdminName()) || $this->hasRole(Utils::getPanelUserRoleName());
    }
}
