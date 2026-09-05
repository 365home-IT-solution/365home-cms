<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Collection;
use Modules\BladeThemeV1\Http\Controllers\BladeThemeV1Controller;
use Modules\BladeThemeV1\Http\Controllers\SitemapController;
use Modules\BladeThemeV1\Support\BranchBookConfig;
use Modules\BladeThemeV1\Support\ThemeCache;

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

// SEO
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/robots.txt',  [SitemapController::class, 'robots'])->name('robots');
Route::get('/llms.txt',    [SitemapController::class, 'llmsTxt'])->name('llms');

// Static routes TRƯỚC dynamic routes để tránh conflict
Route::get('/bai-viet/{slug}', [BladeThemeV1Controller::class, 'postDetail'])->name('post.detail');
Route::get('/s/{location?}', [BladeThemeV1Controller::class, 'searchProduct'])->name('product.search');
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
Route::get('/yeu-thich', [BladeThemeV1Controller::class, 'favoritesPage'])->name('favorites.page');
Route::get('/dang-nhap', [BladeThemeV1Controller::class, 'loginPage'])->name('login.page');
Route::get('/tin-tuc', [BladeThemeV1Controller::class, 'postsPage'])->name('posts.page');

// {type} — slug URL rút gọn theo LOẠI HÌNH (homestay/khach-san/mini-house/villa/nha-nghi/chung-cu
// — xem BranchBookConfig::TYPE_URL_MAP, nguồn duy nhất của mapping này, PHẢI khớp
// window.__typeUrlMap trong public/js/home-sections.js). Ràng buộc where() để 6 route {type} bên
// dưới không nuốt nhầm các route tĩnh khác (/gio-hang, /tai-khoan...) — an toàn bất kể thứ tự
// đăng ký vì regex chỉ khớp đúng 6 giá trị này.
$typeUrlPattern = implode('|', BranchBookConfig::typeUrlSlugs());

// URL rút gọn cho /s?type={db_slug} (và /s/{location}?view=branches, lọc theo đúng loại hình —
// xem SearchController::branches()) — mỗi loại hình đáng có URL riêng cho SEO/chia sẻ. Cùng
// controller/view với '/s', search-results.js tự suy filter 'type' + chế độ "danh sách chi nhánh"
// từ pathname (xem getTypeParam()/isBranchesView() trong public/js/search-results.js) vì URL này
// không mang query string ?type=/?view=.
Route::get('/{type}/{location?}', [BladeThemeV1Controller::class, 'searchProduct'])
    ->where('type', $typeUrlPattern)
    ->name('product.search.type');
// URL canonical cho trang chi tiết chi nhánh — gộp loại hình + khu vực vào path (tốt cho SEO local
// hơn URL phẳng). $type/$location chỉ để tự sửa về đúng nếu gõ sai (xem
// BladeThemeV1Controller::renderBookingBoard() — 301 nếu không khớp).
Route::get('/{type}/{location}/{slug}', [BladeThemeV1Controller::class, 'bookingBoardWithLocation'])
    ->where('type', $typeUrlPattern)
    ->name('branch.booking.location');
// /chi-nhanh/{slug} — URL phẳng (chi nhánh chưa xác định được loại hình/khu vực, hoặc link cũ
// chưa có trong path). Controller tự 301 sang URL canonical /{type}/{location}/{slug} khi xác
// định được, nên 2 URL không cùng sống song song (tránh duplicate content).
Route::get('/chi-nhanh/{slug}', [BladeThemeV1Controller::class, 'bookingBoard'])->name('branch.booking');
// Alias cũ — redirect vĩnh viễn để không phá link đã chia sẻ/index trước khi đổi URL chi nhánh.
Route::get('/branch/{slug}', fn (string $slug) => redirect('/chi-nhanh/' . $slug, 301));
// URL canonical cho trang chi tiết phòng — nối tiếp silo loại hình/khu vực/chi nhánh xuống tới
// từng phòng. $type/$location/$branch chỉ để tự sửa về đúng nếu gõ sai (xem
// BladeThemeV1Controller::renderProductDetail() — 301 nếu không khớp).
Route::get('/{type}/{location}/{branch}/{slug}', [BladeThemeV1Controller::class, 'productDetailWithLocation'])
    ->where('type', $typeUrlPattern)
    ->name('product.detail.location');
// /room/{slug}/ — URL phẳng (phòng chưa xác định được loại hình/chi nhánh, hoặc link cũ/chiến
// dịch quảng cáo chưa có trong path). Controller tự 301 sang URL canonical khi xác định được, nên
// 2 URL không cùng sống song song (tránh duplicate content).
Route::get('/room/{slug}/', [BladeThemeV1Controller::class, 'productDetail'])->name('product.detail');
//Route::get('/local/home-{slug}/', [BladeThemeV1Controller::class, 'categoryDetail'])->name('category.detail');

$menus = ThemeCache::menuForRoutes();

if ($menus->isNotEmpty()) {
    foreach ($menus as $menu) {
        if ($menu->menuItems->isNotEmpty()) {
            $pages = formatMenu($menu->menuItems);
            createRoutes($pages);
        }
    }

    // Trang tĩnh riêng, thay cho CMS Page id 63 — menu item "Hình thức thanh toán" vẫn trỏ url này
    // (createRoutes() ở trên đăng ký nó về BladeThemeV1Controller@index/page_id=63). Route cùng URI
    // đăng ký SAU sẽ đè route đăng ký trước trong route table của Laravel (khác với route có
    // {wildcard}, nơi thứ tự đăng ký mới quyết định route nào match trước) — nên phải đặt SAU
    // createRoutes(), không phải cùng khối "Static routes TRƯỚC dynamic" ở trên.
    Route::get('/hinh-thuc-thanh-toan', [BladeThemeV1Controller::class, 'paymentMethodsPage'])->name('payment-methods.page');
    // Trang tĩnh riêng, thay cho CMS Page id 65/62 — menu item vẫn trỏ 2 url này (createRoutes() ở
    // trên đăng ký chúng về BladeThemeV1Controller@index/page_id=65,62). Cùng lý do/cùng cơ chế
    // override như route /hinh-thuc-thanh-toan ngay phía trên.
    Route::get('/huong-dan-su-dung', [BladeThemeV1Controller::class, 'usageGuidePage'])->name('usage-guide.page');
    Route::get('/privacy', [BladeThemeV1Controller::class, 'privacyPolicyPage'])->name('privacy.page');
} else {
    Route::get('/', [BladeThemeV1Controller::class, 'index'])->name('home');
}
// routes/web.php
Route::get('/theme.css', function () {
    $theme = ThemeCache::generalSettings()->site_theme;

    $rgb = fn($hex) => implode(', ', sscanf($hex, "#%02x%02x%02x"));

    return response()->make(
        ":root{--color-primary:{$theme['primary']};--color-primary-rgb:" . $rgb($theme['primary']) . ";"
        . "--color-text-secondary:{$theme['Secondary']};--color-secondary:{$theme['secondary']};"
        . "--color-gray:{$theme['gray']};--color-success:{$theme['success']};--color-danger:{$theme['danger']};"
        . "--color-info:{$theme['info']};--color-warning:{$theme['warning']};--color-background:{$theme['background']};"
        . "--color-bgDark:{$theme['bg_dark']};--color-textDark:{$theme['text_dark']};--color-red9C:{$theme['red_9c']};"
        . "--color-borderGray:{$theme['border_gray']};--color-tickGreen:{$theme['tick_green']};"
        . "--color-tickYellow:{$theme['tick_yellow']};--color-tickGray:{$theme['tick_gray']}}"
    , 200, [
        'Content-Type' => 'text/css',
        'Cache-Control' => 'public, max-age=3600'
    ]);
})->name('theme.css');