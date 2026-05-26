<?php

use Illuminate\Support\Facades\Route;
use Modules\Menu\Entities\Menu;
use Illuminate\Support\Collection;
use Modules\BladeThemeV1\Http\Controllers\BladeThemeV1Controller;
use Modules\BladeThemeV1\Http\Controllers\SitemapController;

if (!function_exists('formatMenu')) {
function formatMenu(Collection $items): array
{
    return $items->map(function ($item) {
        $page = [
            'name' => $item['title'],
            'url' => $item['url'],
            'page_id' => $item['page_id'],
        ];

        if (isset($item['params'])) {
            $page['params'] = $item['params'];
        }

        if (isset($item['children']) && $item['children'] instanceof Collection && $item['children']->isNotEmpty()) {
            $page['children'] = formatMenu($item['children']);
        }

        return $page;
    })->toArray();
}
} // end if !function_exists('formatMenu')

if (!function_exists('createRoutes')) {
function createRoutes(array $pages, string $prefix = ''): void
{
    foreach ($pages as $page) {
        if (isset($page['url'])) {
            $url = '/' . trim($prefix . '/' . trim($page['url'], '/'), '/');

            $route = Route::get($url, [BladeThemeV1Controller::class, 'index'])
                ->name('page.' . str_replace(['{', '}'], '', trim($url, '/')))
                ->defaults('page_id', $page['page_id']);

            if (isset($page['params']) && is_array($page['params'])) {
                $route->where($page['params']);
            }
        }

        if (!empty($page['children'])) {
            createRoutes($page['children'], $url ?? $prefix);
        }
    }
}
} // end if !function_exists('createRoutes')

try {
    $menus = Menu::query()
        ->with([
            'menuItems' => function ($query) {
                $query->whereNull('parent_id')
                    ->orderBy('order')
                    ->with(['children' => function ($query) {
                        $query->orderBy('order')
                            ->with(['children' => function ($query) {
                                $query->orderBy('order');
                            }]);
                    }]);
            },
            'locations'
        ])
        ->where('is_visible', true)
        ->get();
} catch (\Exception $e) {
    $menus = collect();
}

if ($menus->isNotEmpty()) {
    foreach ($menus as $menu) {
        if ($menu->menuItems->isNotEmpty()) {
            $pages = formatMenu($menu->menuItems);
            createRoutes($pages);
        }
    }
} else {
    Route::get('/', [BladeThemeV1Controller::class, 'index'])->name('home');
}

// SEO
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/robots.txt',  [SitemapController::class, 'robots'])->name('robots');

Route::get('/bai-viet/{slug}', [BladeThemeV1Controller::class, 'postDetail'])->name('post.detail');
Route::get('/san-pham/tim-kiem', [BladeThemeV1Controller::class, 'searchProduct'])->name('product.search');
Route::get('/room/{slug}/', [BladeThemeV1Controller::class, 'productDetail'])->name('product.detail');
//Route::get('/local/home-{slug}/', [BladeThemeV1Controller::class, 'categoryDetail'])->name('category.detail');
Route::get('/mau-giao-dien/{slug}', [BladeThemeV1Controller::class, 'templateDetail'])->name('template.detail');
Route::get('/gio-hang', [BladeThemeV1Controller::class, 'cartPage'])->name('cart.page');
Route::get('/thanh-toan', [BladeThemeV1Controller::class, 'paymentPage'])->name('payment.page');
Route::get('/kiem-tra-ten-mien', [BladeThemeV1Controller::class, 'domainLookupDetail'])->name('domain-lookup.detail');
Route::get('/api/check-domain', function (\Illuminate\Http\Request $request) {
    $domain = $request->query('domain', '');

    // Chỉ cho phép domain hợp lệ (chữ, số, dấu chấm, gạch ngang) — chặn SSRF
    if (!$domain || !preg_match('/^[a-zA-Z0-9\-\.]{2,253}$/', $domain)) {
        return response()->json(['error' => 'Invalid domain'], 422);
    }

    $response = \Illuminate\Support\Facades\Http::timeout(5)
        ->get('https://tracking-domain.goldenbeeltd.vn/', ['domain' => $domain]);

    return $response->json();
});
Route::get('/thong-tin-dat-phong/{code}', [BladeThemeV1Controller::class, 'bookingDetail'])->name('booking.detail');
Route::get('/tai-khoan', [BladeThemeV1Controller::class, 'accountPage'])->name('account.page');
// routes/web.php
Route::get('/theme.css', function () {
    $theme = app(\App\Settings\GeneralSettings::class)->site_theme;

    $rgb = fn($hex) => implode(', ', sscanf($hex, "#%02x%02x%02x"));

    return response()->make("
        :root {
            --color-primary: {$theme['primary']};
            --color-primary-rgb: " . $rgb($theme['primary']) . ";
            --color-text-secondary: {$theme['Secondary']};
            --color-secondary: {$theme['secondary']};
            --color-gray: {$theme['gray']};
            --color-success: {$theme['success']};
            --color-danger: {$theme['danger']};
            --color-info: {$theme['info']};
            --color-warning: {$theme['warning']};
            --color-background: {$theme['background']};
            --color-bgDark: {$theme['bg_dark']};
            --color-textDark: {$theme['text_dark']};
            --color-red9C: {$theme['red_9c']};
            --color-borderGray: {$theme['border_gray']};
            --color-tickGreen: {$theme['tick_green']};
            --color-tickYellow: {$theme['tick_yellow']};
            --color-tickGray: {$theme['tick_gray']};
        }
    ", 200, [
        'Content-Type' => 'text/css',
        'Cache-Control' => 'no-cache'
    ]);
})->name('theme.css');