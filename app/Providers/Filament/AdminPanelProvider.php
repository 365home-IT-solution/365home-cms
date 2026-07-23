<?php

namespace App\Providers\Filament;

use App\Http\Middleware\RedirectUnauthorizedDashboard;
use App\Livewire\MyProfileExtended;
use App\Settings\GeneralSettings;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use JulioMotol\FilamentPasswordConfirmation\FilamentPasswordConfirmationPlugin;
use Modules\Book\App\Filament\BookPlugin;
use Modules\Comment\App\Filament\CommentPlugin;
use Modules\Coupon\App\Filament\CouponPlugin;
use Modules\AppPage\App\Filament\AppPagePlugin;
use Modules\AuditLog\App\Filament\AuditLogPlugin;
use Modules\DataPermission\App\Filament\DataPermissionPlugin;
use Modules\Form\App\Filament\FormPlugin;
use Modules\Payment\App\Filament\PaymentPlugin;
use Modules\Promotion\App\Filament\PromotionPlugin;
use Modules\SettingCompany\App\Filament\SettingCompanyPlugin;
use Modules\ThemeStudio\App\Filament\ThemeStudioPlugin;
use Filament\Support\Enums\Platform;
use Jeffgreco13\FilamentBreezy\BreezyCore;
use Modules\Category\App\Filament\CategoryPlugin;
use Modules\Component\App\Filament\ComponentPlugin;
use Modules\Dashboard\App\Filament\DashboardPlugin;
use Modules\Menu\App\Filament\FilamentMenuBuilderPlugin;
use Modules\Page\App\Filament\PagePlugin;
use Modules\Post\App\Filament\PostPlugin;
use Modules\Product\App\Filament\ProductPlugin;
use Modules\Tag\App\Filament\TagPlugin;
use Modules\ThemeSetting\App\Filament\ThemePlugin;
use Modules\User\App\Filament\UserPlugin;
use TomatoPHP\FilamentMediaManager\FilamentMediaManagerPlugin;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\RequestPasswordReset;
use Modules\AccessCode\App\Filament\AccessCodePlugin;
use Modules\TTLock\App\Filament\TTLockPlugin;
use Modules\Zns\App\Filament\ZnsPlugin;
use Modules\Payment\App\Filament\Resources\OrderResource\Widgets\OrderCalendarWidget;

class AdminPanelProvider extends PanelProvider
{
    /**
     * @throws \Exception
     */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->passwordReset(RequestPasswordReset::class)
            ->emailVerification()
            ->favicon(fn(GeneralSettings $settings) => Storage::url($settings->site_favicon))
            ->brandName(fn(GeneralSettings $settings) => $settings->brand_name)
            ->brandLogo(fn(GeneralSettings $settings) => Storage::url($settings->brand_logo_light_version))
            ->brandLogoHeight(fn(GeneralSettings $settings) => $settings->brand_logoHeight . 'px')
            ->colors(fn(GeneralSettings $settings) => array_filter($settings->site_theme, fn($c) => $c !== null))
            ->databaseNotifications()->databaseNotificationsPolling('10s')
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->topNavigation()
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->navigationGroups([
                'Quản lý',
                'Cấu hình web',
                'Phân quyền',
            ])
            ->pages([
                \Modules\Dashboard\App\Filament\Pages\Dashboard::class,
            ])
            ->widgets([
                OrderCalendarWidget::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
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
                RedirectUnauthorizedDashboard::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make()
                    ->gridColumns([
                        'default' => 2,
                        'sm' => 1
                    ])
                    ->sectionColumnSpan(1)
                    ->checkboxListColumns([
                        'default' => 1,
                        'sm' => 2,
                        'lg' => 3,
                    ])
                    ->resourceCheckboxListColumns([
                        'default' => 1,
                        'sm' => 2,
                    ]),
                BreezyCore::make()
                    ->myProfile(
                        shouldRegisterUserMenu: true,
                        shouldRegisterNavigation: false,
                        hasAvatars: true,
                        slug: 'my-profile',
                        navigationGroup: 'Settings'
                    )
                    ->myProfileComponents([
                        'personal_info' => MyProfileExtended::class,
                    ]),
                FilamentMediaManagerPlugin::make(),
                FilamentPasswordConfirmationPlugin::make(),
                CategoryPlugin::make(),
                ComponentPlugin::make(),
                DashboardPlugin::make(),
                FilamentMenuBuilderPlugin::make(),
                PagePlugin::make(),
                PostPlugin::make(),
                ProductPlugin::make(),
                PromotionPlugin::make(),
                TagPlugin::make(),
                UserPlugin::make(),
                ThemePlugin::make(),
                CommentPlugin::make(),
                FormPlugin::make(),
//                ThemeStudioPlugin::make(),
                PaymentPlugin::make(),
                SettingCompanyPlugin::make(),
                AccessCodePlugin::make(),
                ZnsPlugin::make(),
                BookPlugin::make(),
                CouponPlugin::make(),
                DataPermissionPlugin::make(),
                AppPagePlugin::make(),
                AuditLogPlugin::make(),
                TTLockPlugin::make(),
            ])
            // ->spa()
            ->maxContentWidth('full')
            ->collapsibleNavigationGroups(false)
            ->maxContentWidth('full')
            ->globalSearchFieldSuffix(fn(): ?string => match (Platform::detect()) {
                Platform::Windows, Platform::Linux => 'CTRL+K',
                Platform::Mac => '⌘K',
                default => null,
            });
    }

    public function register(): void
    {
        parent::register();

        // Nạp Echo/Reverb CHỈ trong panel admin (xem resources/js/echo-admin.js) — cần có TRƯỚC
        // khi Livewire boot để cơ chế lắng nghe "echo-private:kênh,.event" (dùng ở
        // CreateOrder/EditOrder cho hold khung giờ real-time, xem TimeslotHoldService) nhận đúng
        // window.Echo, nên đặt ở HEAD_END thay vì BODY_END như script polling bên dưới.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => (string) app(\Illuminate\Foundation\Vite::class)(['resources/js/echo-admin.js']),
        );

        // Inject polling script: tab title + notification sound + instant bell refresh
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): string => <<<'BLADE'
<script>
(function () {
    var _baseTitle = null;
    var _prevCount = null;  // null = chưa có baseline

    /* ── 1. AUDIO ─────────────────────────────────────────── */

    var _audio  = null;
    var _primed = false;

    function initAudio() {
        if (_audio) return;
        try {
            _audio = new Audio('/sounds/order-notification.mp3');
            _audio.volume = 0.8;
            _audio.preload = 'auto';
        } catch (e) {}
    }

    function primeAudio() {
        if (_primed || !_audio) return;
        _primed = true;
        var p = _audio.play();
        if (p && p.then) {
            p.then(function () { _audio.pause(); _audio.currentTime = 0; })
             .catch(function () {});
        }
    }

    function playDing() {
        if (!_audio) return;
        _audio.currentTime = 0;
        var p = _audio.play();
        if (p && p.catch) p.catch(function () {});
    }

    /* ── 2. TAB TITLE ─────────────────────────────────────── */

    function setTitle(count) {
        if (_baseTitle === null) {
            _baseTitle = document.title.replace(/^\(\d+\)\s*/, '');
        }
        var t = count > 0 ? '(' + count + ') ' + _baseTitle : _baseTitle;
        if (document.title !== t) document.title = t;
    }

    /* ── 3. BELL REFRESH ──────────────────────────────────── */

    function refreshBell() {
        try {
            if (!window.Livewire) return;
            document.querySelectorAll('[wire\\:id]').forEach(function (el) {
                var comp = window.Livewire.find(el.getAttribute('wire:id'));
                if (!comp) return;
                var name = comp.name || (comp.__livewire && comp.__livewire.name) || '';
                if (name.includes('notification') || name.includes('database-notification')) {
                    comp.$refresh();
                }
            });
        } catch (e) {}
    }

    /* ── 4. POLL — dùng Filament unread-count ─────────────── */
    // Mỗi 3s fetch số thông báo chưa đọc.
    // Khi count tăng → đơn mới → phát chuông + cập nhật dashboard.
    // Khi user đọc hết rồi count về 0, đơn tiếp theo count = 1 > 0 → vẫn trigger bình thường.

    function poll() {
        fetch('/admin/api/notifications/unread-count', {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
            if (!data || data.count === undefined) return;
            var n = parseInt(data.count, 10);

            setTitle(n);

            if (_prevCount !== null && n > _prevCount) {
                // Có thông báo mới → chuông + refresh bell + cập nhật dashboard
                playDing();
                refreshBell();
                if (typeof window.rcPollNow === 'function') window.rcPollNow();
            }

            _prevCount = n;
        })
        .catch(function () {});
    }

    /* ── 5. BOOT ──────────────────────────────────────────── */

    document.addEventListener('DOMContentLoaded', function () {
        initAudio();

        // Prime audio on first user interaction (browser autoplay policy)
        ['click', 'keydown', 'touchend', 'pointerdown'].forEach(function (ev) {
            document.addEventListener(ev, primeAudio, { once: true, capture: true });
        });

        poll();
        setInterval(poll, 3000);
    });
})();
</script>
BLADE
        );

        // Hover-to-open for top nav group dropdowns
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn (): string => <<<'BLADE'
<script>
(function () {
    function patchTopNavHover() {
        var topNav = document.querySelector('.fi-topbar nav');
        if (!topNav) return;
        topNav.querySelectorAll('.fi-dropdown').forEach(function (el) {
            if (el._hoverNav) return;
            el._hoverNav = true;
            var timer;
            el.addEventListener('mouseenter', function () {
                clearTimeout(timer);
                var data = window.Alpine && Alpine.$data(el);
                if (!data) return;
                if (typeof data.isOpen !== 'undefined') data.isOpen = true;
                else if (typeof data.open !== 'undefined') data.open = true;
            });
            el.addEventListener('mouseleave', function () {
                timer = setTimeout(function () {
                    var data = window.Alpine && Alpine.$data(el);
                    if (!data) return;
                    if (typeof data.isOpen !== 'undefined') data.isOpen = false;
                    else if (typeof data.open !== 'undefined') data.open = false;
                }, 100);
            });
        });
    }

    document.addEventListener('alpine:initialized', function () {
        patchTopNavHover();
        document.addEventListener('livewire:navigated', function () {
            setTimeout(patchTopNavHover, 300);
        });
    });
})();
</script>
BLADE
        );

        // FCM token registration — xin quyền thông báo + lưu device token vào DB
        // Chạy khi admin mở trang, kể cả trên điện thoại
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            function (): string {
                $vapidKey = config('services.firebase.vapid_key', '');
                if (! $vapidKey) {
                    return '';
                }
                return <<<HTML
<script>
(function () {
    if (!('Notification' in window) || !('serviceWorker' in navigator)) return;

    var FIREBASE_CONFIG = {
        apiKey:            'AIzaSyDZQjQNuNmhiumNFM43GgbMUxIT5SXMwvU',
        authDomain:        'ittriet.firebaseapp.com',
        projectId:         'ittriet',
        storageBucket:     'ittriet.firebasestorage.app',
        messagingSenderId: '811008242226',
        appId:             '1:811008242226:web:e47169f406189fa585c22b',
    };
    var VAPID_KEY = '{$vapidKey}';

    function loadScript(src, cb) {
        var s = document.createElement('script');
        s.src = src;
        s.onload = cb;
        s.onerror = function () {};
        document.head.appendChild(s);
    }

    function doRegister() {
        loadScript('https://www.gstatic.com/firebasejs/12.12.1/firebase-app-compat.js', function () {
            loadScript('https://www.gstatic.com/firebasejs/12.12.1/firebase-messaging-compat.js', function () {
                try {
                    if (!firebase.apps.length) {
                        firebase.initializeApp(FIREBASE_CONFIG);
                    }
                    var messaging = firebase.messaging();
                    navigator.serviceWorker.register('/firebase-messaging-sw.js')
                        .then(function (reg) {
                            return messaging.getToken({ vapidKey: VAPID_KEY, serviceWorkerRegistration: reg });
                        })
                        .then(function (token) {
                            if (!token) return;
                            var csrf = document.querySelector('meta[name="csrf-token"]');
                            fetch('/admin/api/fcm-token', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : '',
                                    'Accept': 'application/json',
                                },
                                credentials: 'same-origin',
                                body: JSON.stringify({ token: token }),
                            }).catch(function () {});
                        })
                        .catch(function () {});
                } catch (e) {}
            });
        });
    }

    function tryRegister() {
        if (Notification.permission === 'granted') {
            doRegister();
        } else if (Notification.permission !== 'denied') {
            Notification.requestPermission().then(function (perm) {
                if (perm === 'granted') doRegister();
            }).catch(function () {});
        }
    }

    // Nếu đã được cấp quyền → đăng ký ngay sau DOMContentLoaded
    // Chưa có quyền → hỏi khi user click lần đầu (tránh bị trình duyệt block)
    if (Notification.permission === 'granted') {
        document.addEventListener('DOMContentLoaded', doRegister);
    } else {
        document.addEventListener('click', tryRegister, { once: true, capture: true });
    }
})();
</script>
HTML;
            }
        );
    }
}
