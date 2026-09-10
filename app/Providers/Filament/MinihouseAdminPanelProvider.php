<?php

namespace App\Providers\Filament;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
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
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Modules\Minihouse\App\Filament\MinihousePlugin;
use Modules\Minihouse\App\Filament\Pages\Dashboard;
use Modules\Minihouse\App\Livewire\BuildingSwitcher;
use Modules\Minihouse\App\Support\ActiveBuildingScope;

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
    // Nút "Chuyển đổi toà nhà" ở topbar — xem Modules\Minihouse\App\Livewire\BuildingSwitcher +
    // ActiveBuildingScope. registerRenderHook() KHÔNG tự lọc theo panel dù gọi từ đây (đã xác nhận
    // với USER_MENU_BEFORE/HEAD_END/BODY_END khi sửa 6 hook tương tự của Home) — phải tự kiểm tra
    // Filament::getCurrentPanel() trong closure. Ẩn luôn nếu tài khoản chỉ được phép quản lý ≤ 1
    // toà nhà (không có gì để chọn).
    public function register(): void
    {
        parent::register();

        FilamentView::registerRenderHook(
            PanelsRenderHook::USER_MENU_BEFORE,
            function (): string {
                if (Filament::getCurrentPanel()?->getId() !== 'minihouse-admin') {
                    return '';
                }

                if (count(ActiveBuildingScope::permittedBuildingIds()) <= 1) {
                    return '';
                }

                return \Livewire\Livewire::mount(BuildingSwitcher::class);
            },
        );
    }

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
                'Phân quyền',
            ])
            ->pages([
                Dashboard::class,
            ])
            ->topNavigation()
            ->maxContentWidth('full')
            ->collapsibleNavigationGroups(false)
            // Chuông thông báo — dùng lại NGUYÊN VẸN bảng "notifications" + REST /api/admin/
            // notifications đã có sẵn cho Home (App\Http\Controllers\Api\Admin\NotificationController
            // lọc theo user, không lọc theo panel) — chỉ cần bật tính năng này ở panel, không cần
            // Livewire component riêng như BuildingSwitcher. Xem ReminderNotificationService.
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->plugins([
                MinihousePlugin::make(),
                // Cùng giao diện Vai trò/Permission dạng lưới theo từng Resource như panel Home
                // (App\Providers\Filament\AdminPanelProvider) — chain cấu hình giống hệt. Mỗi panel
                // tự resolve 1 instance FilamentShieldPlugin riêng (không phải singleton dùng
                // chung), nên không đụng cấu hình của Home dù cùng 1 class. RoleResource thật sự
                // hiển thị là Modules\Minihouse\App\Filament\Resources\RoleResource (tự phát hiện
                // qua MinihousePlugin) — plugin này chỉ cấp cấu hình cột/lưới, KHÔNG tự đăng ký
                // resource nào cho panel này (Utils::isResourcePublished() đã cố định = true toàn
                // app do Home đã "publish" bản của họ ở app/Filament/Resources/Shield/RoleResource.php).
                FilamentShieldPlugin::make()
                    ->gridColumns(['default' => 2, 'sm' => 1])
                    ->sectionColumnSpan(1)
                    ->checkboxListColumns(['default' => 1, 'sm' => 2, 'lg' => 3])
                    ->resourceCheckboxListColumns(['default' => 1, 'sm' => 2]),
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
