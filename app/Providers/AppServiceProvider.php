<?php

namespace App\Providers;

use App\Http\View\Composers\ProductColorComposer;
use App\Services\ContractSigning\ContractSigningManager;
use App\Services\ContractSigning\Contracts\DigitalSignatureProvider;
use App\Settings\MailSettings;
use Filament\Tables\Table;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Guava\FilamentKnowledgeBase\Enums\TableOfContentsPosition;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;
use Guava\FilamentKnowledgeBase\Filament\Panels\KnowledgeBasePanel;
use Illuminate\Support\Facades\View as ViewFacade;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Jeffgreco13\FilamentBreezy\Livewire\PersonalInfo;
use Jeffgreco13\FilamentBreezy\Livewire\UpdatePassword;
use Livewire\Livewire;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Mime\MimeTypeGuesserInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Never expose debug assets or stack traces in production, even if an old deployment
        // accidentally leaves APP_DEBUG enabled. Livewire uses this value to select its minified
        // bundle, which removes ~50KB from the initial page and is also the secure default.
        if ($this->app->environment('production')) {
            config(['app.debug' => false]);
        }

        KnowledgeBasePanel::configureUsing(
            fn(KnowledgeBasePanel $panel) => $panel
                ->viteTheme('resources/css/filament/admin/theme.css')
                ->brandName('Tài liệu hướng dẫn')
                ->tableOfContentsPosition(TableOfContentsPosition::Start)
                ->disableBreadcrumbs()
        );

        Blade::directive('livewireIf', function ($expression) {
            return "<?php if(view()->exists($expression)) { echo \Livewire\Livewire::mount($expression)->html(); } ?>";
        });

        // Provider ký số hợp đồng điện tử — chọn theo config/contract_signing.php (mặc định
        // 'local', đổi sang 'vnpt_smartca' qua .env khi có tài khoản đối tác thật, không cần sửa
        // ContractSignController/PartnerForm vì cả 2 nơi chỉ phụ thuộc vào interface này.
        $this->app->bind(DigitalSignatureProvider::class, function ($app) {
            return $app->make(ContractSigningManager::class)->driver();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // livewire.min.js được chèn ở cuối <body> nên không chặn hiển thị ban đầu, nhưng vẫn chặn
        // trình duyệt "hoàn tất" trang (ảnh hưởng TTI) vì mặc định là <script> đồng bộ, không có
        // defer — Lighthouse xếp nó vào chuỗi phụ thuộc quan trọng. defer=true chỉ đổi THỜI ĐIỂM
        // fetch/chạy (chờ HTML parse xong), không đổi thứ tự khởi tạo Alpine/Livewire vì Livewire tự
        // quản lý việc đó bên trong bundle của nó — an toàn, không ảnh hưởng hành vi component nào.
        Livewire::useScriptTagAttributes(['defer' => true]);

        Table::configureUsing(function (Table $table): void {
            $table
                ->emptyStateHeading('Không có dữ liệu')
                ->defaultPaginationPageOption(10)
                ->paginated([10, 25, 50, 100])
                ->extremePaginationLinks()
                ->defaultSort('created_at', 'desc');
        });

        LogViewer::auth(function ($request) {
            $role = auth()?->user()?->roles?->first()->name;
            return $role == config('filament-shield.super_admin.name');
        });

        FilamentView::registerRenderHook(
            PanelsRenderHook::FOOTER,
            fn(): View => view('filament.components.panel-footer'),
        );
        FilamentView::registerRenderHook(
            PanelsRenderHook::USER_MENU_BEFORE,
            function (): string {
                $user = auth()->user();

                // Chỉ hiện nút chuyển đổi chi nhánh khi có từ 2 chi nhánh trở lên để chọn — 1 chi
                // nhánh thì không có gì để chuyển đổi.
                if (! $user instanceof \App\Models\User || count($user->rootProductCategoryIds()) <= 1) {
                    return '';
                }

                return \Livewire\Livewire::mount('branch-switcher');
            },
        );
        FilamentView::registerRenderHook(
            PanelsRenderHook::USER_MENU_BEFORE,
            fn (): string => \Livewire\Livewire::mount('account-switcher'),
        );
        FilamentView::registerRenderHook(
            PanelsRenderHook::USER_MENU_BEFORE,
            fn(): View => view('filament.components.button-website'),
        );

        try {
            $settings = app(MailSettings::class);
            $settings->loadMailSettingsToConfig();
        } catch (\Exception $e) {
            \Log::error('Failed to load mail settings: ' . $e->getMessage());
        }

        ViewFacade::composer('*', ProductColorComposer::class);

        // finfo on Windows does not detect AVIF as image/avif; register a signature-based guesser
        MimeTypes::getDefault()->registerGuesser(new class implements MimeTypeGuesserInterface {
            public function isGuesserSupported(): bool { return true; }
            public function guessMimeType(string $path): ?string {
                $handle = @fopen($path, 'rb');
                if (!$handle) return null;
                $header = fread($handle, 12);
                fclose($handle);
                if (strlen($header) >= 12 && substr($header, 4, 4) === 'ftyp' && in_array(substr($header, 8, 4), ['avif', 'avis'])) {
                    return 'image/avif';
                }
                return null;
            }
        });

        // build-bladethemev1's app.scss/app.js CSS (~83KB combined) render-blocks every frontend
        // page — measured as the dominant contributor to FCP/LCP under throttled mobile (PageSpeed
        // "Render-blocking requests"). Load it the same non-blocking way as the Google Fonts
        // stylesheet in master.blade.php: media="print" doesn't stop the browser from downloading
        // the file (the <link rel="preload"> Vite already emits starts that immediately either
        // way), it only stops the browser from APPLYING it until onload flips media back to "all".
        // Scoped to build-bladethemev1 only — Filament admin's own Vite build (public/build) is
        // untouched, so this can't affect the admin panel's CSS loading.
        Vite::useStyleTagAttributes(function (string $src, string $url, ?array $chunk, ?array $manifest) {
            // The dedicated home CSS is small and contains the above-the-fold layout. Applying it
            // immediately prevents the large CLS caused by painting unstyled HTML and restyling it
            // after the file finishes. Larger legacy bundles remain non-blocking on other pages.
            if (str_contains($url, 'build-bladethemev1') && ! str_contains($src, 'home')) {
                return [
                    'media' => 'print',
                    'onload' => "this.media='all'",
                ];
            }
            return [];
        });

        // Đảm bảo Breezy profile components luôn được đăng ký
        // (BreezyCore::boot() chỉ chạy khi panel boot — không đủ cho Livewire update requests)
        Livewire::component('personal_info', PersonalInfo::class);
        Livewire::component('update_password', UpdatePassword::class);

        // Secure the Livewire update route with rate limiting + origin validation
        Livewire::setUpdateRoute(function ($handle) {
            return Route::post('/livewire/update', $handle)
                ->middleware(['web', 'livewire.secure']);
        });
    }
}
