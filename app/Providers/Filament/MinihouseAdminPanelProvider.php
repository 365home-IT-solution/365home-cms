<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Modules\Minihouse\App\Filament\MinihousePlugin;
use Modules\Minihouse\App\Filament\Pages\Dashboard;

// Panel RIÊNG cho MiniHouse (quản lý cho thuê theo tháng) — dùng chung App\Models\User/guard 'web'
// với panel Home (App\Providers\Filament\AdminPanelProvider, id='admin', path='home-admin'): KHÔNG
// tách tài khoản, chỉ tách ROUTE — user nào được cấp quyền 'access_minihouse' (trực tiếp hoặc qua
// vai trò "Quản lý MiniHouse", xem MinihousePermissionSeeder) mới đăng nhập được vào đây, xem
// App\Models\User::canAccessPanel(). Không có ->registration() vì Home cũng không cho tự đăng ký
// vào bảng users dùng chung này — tài khoản do super_admin tạo/cấp quyền.
//
// Toàn bộ Page/Resource/Widget của module nằm trong Modules/Minihouse/App/Filament/* (giống hệt
// cách BookPlugin/ProductPlugin... tổ chức cho panel Home) — tự động phát hiện qua MinihousePlugin,
// không khai báo tay từng class ở đây. Màu sắc/theme CSS dùng lại đúng theme của Home (GeneralSettings
// + viteTheme) để giao diện giống hệt, chỉ khác dữ liệu.
class MinihouseAdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('minihouse-admin')
            ->path('minihouse-admin')
            ->login()
            ->passwordReset()
            ->brandName('MiniHouse')
            ->colors(fn (\App\Settings\GeneralSettings $settings) => array_filter($settings->site_theme, fn ($c) => $c !== null))
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->navigationGroups([
                'Quản lý',
            ])
            ->pages([
                Dashboard::class,
            ])
            ->topNavigation()
            ->maxContentWidth('full')
            ->collapsibleNavigationGroups(false)
            ->plugins([
                MinihousePlugin::make(),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authGuard('web')
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
