<?php

namespace App\Providers;

use App\Http\View\Composers\ProductColorComposer;
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
use Jeffgreco13\FilamentBreezy\Livewire\PersonalInfo;
use Jeffgreco13\FilamentBreezy\Livewire\UpdatePassword;
use Livewire\Livewire;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Mime\MimeTypeGuesserInterface;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Mime\MimeTypeGuesserInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
