<?php

namespace Modules\BladeThemeV1\Http\Controllers;

use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Category\Entities\Category;
use Modules\Page\Entities\Page;
use Modules\Page\Entities\PageComponent;
use Modules\BladeThemeV1\Traits\HandleColorTrait;
use Modules\BladeThemeV1\Support\BranchBookConfig;
use Modules\Post\Entities\Post;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomType;
use App\Models\Province;
use Modules\Payment\Entities\Order;
use Modules\Payment\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\AppPage\App\Models\AppPage;
use Modules\AppPage\App\Models\Banner;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Cache;
use App\Services\AvailableProvinceService;

class BladeThemeV1Controller extends Controller
{
    use HandleColorTrait;

    public $primaryColor;
    public string $primaryColorRgb;
    public ?string $heavyPrimaryColor;
    public ?string $lightPrimaryColor;

    public function __construct(
        private readonly GeneralSettings $generalSetting
    )
    {
        $this->primaryColor = $this->getFilamentPrimaryColor();
        if ($this->isHexColor($this->primaryColor)) {
            $this->primaryColorRgb = $this->hexToRgb($this->primaryColor);
        } else {
            $this->primaryColorRgb = $this->primaryColor;
        }

        $this->heavyPrimaryColor = $this->lightenColor($this->primaryColor, 100);
        $this->lightPrimaryColor = $this->darkenColor($this->primaryColor, 30);
    }

    public function index(Request $request)
    {
        $pageId = $request->route('page_id');

        $page = Page::findOrFail($pageId);

        $seoData = [
            'seo_title'       => $page->seo_title       ?? '',
            'seo_description' => $page->seo_description ?? '',
            'seo_keywords'    => $page->seo_keywords    ?? '',
            'og_type'         => 'website',
        ];

        $pageComponents = PageComponent::with(['component', 'pageComponentConfigurationValues'])
            ->where('page_id', $pageId)
            ->get();

        $configuration = $pageComponents->map(function ($component) use ($page) {
            $layout = [];
            $componentData = ['name' => $component->component->name ?? ""];

            foreach ($component->pageComponentConfigurationValues as $config) {
                switch ($config->name) {
                    case 'heading':
                    case 'heading_color':
                    case 'heading_sub':
                    case 'heading_sub_color':
                    case 'heading_alignment':
                    case 'heading_background_image':
                    case 'background_color':
                    case 'background_image':
                    case 'background_linear':
                    case 'gradient_start':
                    case 'gradient_end':
                    case 'gradient_direction':
                    case 'overlay_color':
                    case 'overlay_opacity':
                    case 'layout_style':
                        $layout[$config->name] = $config->pivot->value ?? '';
                        break;
                    default:
                        $componentData[$config->name] = $config->pivot->value ?? 4;
                        break;
                }
            }

            if (!empty($layout['background_image'])) {
                $bgImageData = json_decode($layout['background_image'], true);
                if (is_array($bgImageData) && !empty($bgImageData)) {
                    $layout['background_image'] = reset($bgImageData);
                }
            }

            return [
                'layout' => [
                    'heading' => !empty($layout['heading']) ? $layout['heading'] : false,
                    'heading_color' => !empty($layout['heading_color']) ? $layout['heading_color'] : null,
                    'heading_sub' => !empty($layout['heading_sub']) ? $layout['heading_sub'] : false,
                    'heading_sub_color' => !empty($layout['heading_sub_color']) ? $layout['heading_sub_color'] : null,
                    'heading_background_image' => !empty($layout['heading_background_image']) ? $layout['heading_background_image'] : false,
                    'heading_alignment' => !empty($layout['heading_alignment']) ? $layout['heading_alignment'] : null,
                    'background_color' => !empty($layout['background_color']) ? $layout['background_color'] : false,
                    'background_image' => !empty($layout['background_image']) ? $layout['background_image'] : false,
                    'background_linear' => !empty($layout['background_linear']) ? $layout['background_linear'] : false,
                    'gradient_start' => !empty($layout['gradient_start']) ? $layout['gradient_start'] : false,
                    'gradient_end' => !empty($layout['gradient_end']) ? $layout['gradient_end'] : false,
                    'gradient_direction' => !empty($layout['gradient_direction']) ? $layout['gradient_direction'] : false,
                    'overlay_color' => !empty($layout['overlay_color']) ? $layout['overlay_color'] : '#000000',
                    'overlay_opacity' => !empty($layout['overlay_opacity']) ? ($layout['overlay_opacity'] * 0.01) : '0',
                    'style' => !empty($layout['layout_style']) ? $layout['layout_style'] : false,
                ],
                'component' => $componentData
            ];
        });

        return view('bladethemev1::pages.index', [
            'configuration' => $configuration,
            'primaryColor' => $this->primaryColor,
            'primaryColorRgb' => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor,
            'seoData' => $seoData,
            'page' => $page,
            // Only the small, above-the-fold subset is embedded in the initial HTML. The full
            // home payload still loads through /api/v1/home, so CMS behaviour remains unchanged.
            'criticalHome' => request()->path() === '/' ? $this->criticalHomeData() : [],
        ]);
    }

    /**
     * Return enough CMS data to paint the first home banner before Alpine and the home API load.
     * Any data/configuration problem deliberately falls back to the existing client-side flow.
     */
    private function criticalHomeData(): array
    {
        try {
            $page = AppPage::query()
                ->where('slug', 'home')
                ->where('is_active', true)
                ->first();

            $bannerBlock = collect($page?->content ?? [])
                ->first(fn (array $block) => ($block['type'] ?? null) === 'banner');
            $bannerIds = collect($bannerBlock['data']['items'] ?? [])
                ->pluck('banner_id')
                ->filter()
                ->values();
            $banners = Banner::query()
                ->whereIn('id', $bannerIds)
                ->where('is_active', true)
                ->get()
                ->keyBy('id');
            $items = $bannerIds
                ->map(fn ($id) => $banners->get($id))
                ->filter()
                ->map(fn (Banner $banner) => [
                    'title' => $banner->title,
                    'image_url' => $banner->image
                        ? Storage::disk($banner->disk ?? 'public')->url($banner->image)
                        : null,
                    'thumbnail' => $banner->thumbnail,
                    'url' => $banner->url,
                ])
                ->filter(fn (array $banner) => filled(data_get($banner, 'thumbnail.wide') ?? $banner['image_url']))
                ->values()
                ->all();

            return [
                'banner' => empty($items) ? null : [
                    'type' => 'banner',
                    'id' => 1,
                    'sort_order' => 1,
                    'items' => $items,
                ],
                'room_types' => RoomType::query()
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['id', 'slug', 'name', 'icon', 'icon_url'])
                    ->toArray(),
                'booking' => $this->criticalBookingData(),
            ];
        } catch (\Throwable $e) {
            Log::warning('Unable to prepare critical home data; using the existing API fallback.', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function criticalBookingData(): array
    {
        $resolve = function (): array {
            $provinces = app(AvailableProvinceService::class)->get();
            $defaultProvince = ! empty($provinces)
                ? Province::find($provinces[0]['id'])
                : null;
            $defaultBranches = $defaultProvince
                ? SearchController::branchesDataForProvince($defaultProvince)['data']
                : [];
            $defaultBranchSlug = data_get($defaultBranches, '0.slug');

            return [
                'provinces' => $provinces,
                'active_province_id' => $defaultProvince ? (string) $defaultProvince->id : null,
                'default_branches' => $defaultBranches,
                // Render the initial grid without waiting for a Livewire update request.
                'default_book_config' => $defaultBranchSlug
                    ? data_get(BranchBookConfig::build($defaultBranchSlug), 'bookConfig')
                    : null,
            ];
        };

        try {
            return Cache::store('file')->remember('bladethemev1:home:critical-booking', now()->addMinutes(5), $resolve);
        } catch (\Throwable) {
            return $resolve();
        }
    }

    public function postDetail($slug)
    {
        $post = Post::with(['user', 'categories'])->where('slug', $slug)->first();

        if (!$post) {
            abort(404);
        }

        // Danh mục đầu tiên của bài viết, chèn vào breadcrumb (Trang chủ > Bài viết > Danh mục >
        // Tiêu đề) — trang danh sách bài viết lọc theo danh mục qua query string ?danh-muc=<tên>
        // (xem PostPage::$selectedCategory), nên link ở đây trỏ thẳng tới bộ lọc đó.
        $postCategory = $post->categories->first();

        $seoOgImage = null;
        if ($post->hasMedia('Ảnh chính')) {
            $seoOgImage = $post->getFirstMedia('Ảnh chính')->getUrl();
        }

        // H1 luôn là $post->title (post-detail.blade.php). Nếu seo_title bỏ trống hoặc được
        // nhập y hệt title, title tag sẽ trùng H1 từng ký tự — SEO tool flag "duplicate H1/title
        // tag". Thêm hậu tố "| 365Home" cho trường hợp đó để 2 thẻ luôn khác nhau, không cần
        // sửa tay từng bài; seo_title đã được tùy biến thật sự thì giữ nguyên.
        $postTitle = $post->title ?? '';
        $seoTitle = trim((string) ($post->seo_title ?? ''));
        if ($seoTitle === '' || $seoTitle === trim($postTitle)) {
            // Toàn bộ title hiện có đều đã tự mở đầu bằng "365Home" (dưới nhiều dạng phân cách
            // khác nhau: |, –, -, hoặc không có) — bỏ tiền tố này trước khi thêm hậu tố, tránh
            // lặp thương hiệu 2 lần kiểu "365Home | X | 365Home" (vừa xấu vừa tốn ký tự hiển thị
            // trên SERP). Title không mở đầu bằng "365Home" thì giữ nguyên, chỉ thêm hậu tố.
            $core = trim((string) preg_replace('/^365\s*home\s*[|–—-]?\s*/iu', '', trim($postTitle)));
            $seoTitle = trim(($core !== '' ? $core : trim($postTitle)) . ' | 365Home');
        }

        $seoData = [
            'seo_title'              => $seoTitle,
            'seo_description'        => $post->seo_description ?? '',
            'seo_keywords'           => $post->seo_keywords ?? '',
            'og_image'               => $seoOgImage,
            'og_type'                => 'article',
            'article_published_time' => $post->published_at?->toIso8601String() ?? $post->created_at?->toIso8601String(),
            'article_modified_time'  => $post->updated_at?->toIso8601String(),
            'author_name'            => $post->user?->fullname ?? $post->user?->name ?? '',
            'site_name'              => config('app.name'),
        ];

        return view('bladethemev1::pages.post.detail', [
            'seoData' => $seoData,
            'slug' => $slug,
            'name' => $post->title,
            'postCategory' => $postCategory,
            'primaryColor' => $this->primaryColor,
            'primaryColorRgb' => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor
        ]);
    }

    public function bookingDetail(Request $request, $code)
    {
        // Tìm đơn hàng theo order_code, load kèm items và product
        $order = Order::with(['items.product.manualLockPasswords', 'accessCodes'])
            ->where('order_code', $code)
            ->firstOrFail();

        // ── Xử lý return từ PayOS sau khi thanh toán còn lại ──
        // ?remaining_paid=1  → returnUrl cũ (trước fix)
        // ?orderCode=X       → returnUrl mới
        $hintCode = $request->query('orderCode')
            ?: ($request->query('remaining_paid') ? $order->remaining_payos_code : null);

        if ($hintCode && $order->status === 'deposit' && !empty($order->remaining_payos_code)) {
            try {
                $paymentController = app(PaymentController::class);
                $paymentController->checkPaymentStatus($order, (string) $hintCode);
                $order->refresh();
                Log::info('bookingDetail: checkPaymentStatus called', [
                    'order_id' => $order->id, 'new_status' => $order->status,
                ]);
            } catch (\Exception $e) {
                Log::error('bookingDetail: checkPaymentStatus error', ['error' => $e->getMessage()]);
            }
        }

        // Nếu vừa thanh toán đủ → redirect sang success page để hiện thông báo đầy đủ
        if ($order->status === 'paid' && ($request->query('remaining_paid') || $request->query('orderCode'))) {
            return redirect()->route('payment.success', ['orderCode' => $order->order_code]);
        }

        $item = $order->items->first();
        $product = $item ? $item->product : null;

        return view('bladethemev1::pages.booking-detail', compact('order', 'item', 'product'));
    }

//    public function categoryDetail($slug)
//    {
//
//        $parts = explode('-', $slug);
//
//        if (count($parts) < 4) {
//            abort(404);
//        }
//
//        $slug2 = implode('-', array_slice($parts, -2));
//
//        $slug3 = implode('-', array_slice($parts, 0, count($parts) - 2));
//
//        $category = Category::query()
//            ->select([
//                'c1.name as c1_name',
//                'c2.name as c2_name',
//                'c3.name as c3_name',
//                'c3.image',
//                'c3.description as c3_dep',
//                'c1.slug as c1_slug',
//                'c2.slug as c2_slug',
//                'c3.slug as c3_slug',
//                'c3.id as c3_id',
//            ])
//            ->from('categories as c3')
//            ->join('categories as c2', 'c3.parent_id', '=', 'c2.id')
//            ->join('categories as c1', 'c2.parent_id', '=', 'c1.id')
//            ->join('categorizables as cz', 'cz.category_id', '=', 'c3.id')
//            ->where('c1.parent_id', null)
//            ->where([
//                ['c1.category_type', '=', 'product'],
//                ['c2.category_type', '=', 'product'],
//                ['c3.category_type', '=', 'product'],
//                ['c1.status', '=', 1],
//                ['c2.status', '=', 1],
//                ['c3.status', '=', 1],
//                ['cz.categorizable_type', '=', Product::class],
//                ['c3.slug', '=', $slug3],
//                ['c2.slug', '=', $slug2],
//            ])
//            ->first();
//
//        if (!$category) {
//            abort(404, 'Danh mục không tồn tại.');
//        }
//
//        // SEO data
//        $seoData = [
//            'seo_title' => 'Home - ' . $category->c3_name . ', ' . $category->c2_name,
//            'seo_description' => strip_tags($category->c3_dep),
//            'seo_keywords' => implode(', ', array_filter([$category->c1_name, $category->c2_name, $category->c3_name])),
//            'og_image' => $category->image ? asset($category->image) : null,
//        ];
//
//        return view('bladethemev1::pages.category.detail', [
//            'slug' => $category->c3_slug,
//            'name' => $category->c3_name,
//            'seoData' => $seoData,
//            'primaryColor' => $this->primaryColor,
//            'primaryColorRgb' => $this->primaryColorRgb,
//            'heavyPrimaryColor' => $this->heavyPrimaryColor,
//            'lightPrimaryColor' => $this->lightPrimaryColor,
//        ]);
//    }

    public function favoritesPage(Request $request)
    {
        $seoData = [
            'seo_title' => 'Danh sách phòng yêu thích - Lưu và quản lý phòng đã thích tại 365 HOME',
            'seo_description' => 'Danh sách phòng đã lưu',
            'seo_keywords' => 'yêu thích, phòng nghỉ, 365 home',
            'og_type' => 'website',
        ];

        return view('bladethemev1::pages.favorites', [
            'seoData' => $seoData,
            'primaryColor' => $this->primaryColor,
            'primaryColorRgb' => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor,
        ]);
    }

    public function postsPage()
    {
        $seoData = [
            'seo_title' => 'Tin tức và bài viết mới nhất về đặt phòng tại 365 HOME',
            'seo_description' => 'Tin tức và bài viết mới nhất',
            'seo_keywords' => 'tin tức, bài viết, 365 home',
            'og_type' => 'website',
        ];

        return view('bladethemev1::pages.posts', [
            'seoData' => $seoData,
            'primaryColor' => $this->primaryColor,
            'primaryColorRgb' => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor,
        ]);
    }

    // textOnPrimary: màu chữ tương phản đặt lên nền $primaryColor (nút CTA trong form đăng
    // nhập/đăng ký) — cùng công thức luminance đang dùng ở Modules/BladeThemeV1/Livewire/AuthModal.php
    // (giữ nguyên modal đó cho các nơi khác vẫn đang mở popup, xem components/auth/form.blade.php).
    private function textOnPrimaryColor(): string
    {
        $hex = ltrim($this->primaryColor, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $luminance > 0.5 ? '#1a1e25' : '#ffffff';
    }

    public function loginPage()
    {
        $seoData = [
            'seo_title' => 'Đăng nhập tài khoản 365 HOME - Đặt phòng nhanh chóng',
            'seo_description' => 'Đăng nhập tài khoản 365 Home để đặt phòng nhanh hơn và nhận ưu đãi dành riêng cho thành viên.',
            'seo_keywords' => 'đăng nhập, 365 home',
            'og_type' => 'website',
        ];

        return view('bladethemev1::pages.login', [
            'seoData' => $seoData,
            'primaryColor' => $this->primaryColor,
            'primaryColorRgb' => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor,
            'textOnPrimary' => $this->textOnPrimaryColor(),
        ]);
    }

    // /hinh-thuc-thanh-toan — trang tĩnh riêng (trước đây là CMS Page id 63, đã bỏ vì component
    // "Nội dung" dump HTML thô chứa sẵn 1 thẻ <h1> khác, trùng với <h1 class="sr-only"> của layout
    // CMS chung — xem Modules/BladeThemeV1/Resources/views/pages/index.blade.php).
    public function paymentMethodsPage()
    {
        $seoData = [
            'seo_title' => 'Hình Thức Thanh Toán Tại 365 HOME - QR & Chuyển Khoản',
            'seo_description' => 'Hướng dẫn thanh toán tại 365 Home: quét mã QR trực tuyến hoặc chuyển khoản ngân hàng — nhanh chóng, an toàn và tiện lợi.',
            'seo_keywords' => 'hình thức thanh toán, thanh toán QR, chuyển khoản ngân hàng, 365 home',
            'og_type' => 'website',
        ];

        return view('bladethemev1::pages.payment-methods', [
            'seoData' => $seoData,
            'primaryColor' => $this->primaryColor,
            'primaryColorRgb' => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor,
        ]);
    }


    // /room/{slug}/ — URL phẳng cũ (không có loại hình/khu vực/chi nhánh). Luôn tự chuyển sang URL
    // canonical /{type}/{location}/{branch}/{slug} nếu xác định được (xem renderProductDetail()).
    public function productDetail($slug)
    {
        return $this->renderProductDetail($slug, null, null, null);
    }

    // /{type}/{location}/{branch}/{slug} — URL canonical, nối tiếp silo loại hình/khu vực/chi
    // nhánh đã có (/{type}/{location}, /{type}/{location}/{branch}) xuống tới từng phòng.
    // $type/$location/$branch chỉ dùng để tự sửa về ĐÚNG loại hình + khu vực + chi nhánh thật
    // (redirect 301) nếu URL gõ sai — không dùng để lọc phòng, vì slug phòng vốn đã duy nhất toàn
    // hệ thống.
    public function productDetailWithLocation(string $type, string $location, string $branch, string $slug)
    {
        return $this->renderProductDetail($slug, $type, $location, $branch);
    }

    private function renderProductDetail(string $slug, ?string $type, ?string $location, ?string $branch)
    {
        $product = Product::where([
            'slug' => $slug,
            'is_activated' => true,
            'type' => 'simple'
        ])
            ->whereHas('categories', function ($query) {
                $query->where('status', 1);
            })
            ->with(['tags:id,name', 'categories:id,slug,parent_id', 'roomType:id,slug,name'])
            ->select(['id', 'name', 'slug', 'short_description', 'description', 'price', 'discount', 'is_in_stock', 'updated_at', 'room_type_id'])
            ->first();

        if (!$product) {
            abort(404);
        }

        // Loại hình + khu vực + chi nhánh THẬT của phòng — nguồn sự thật duy nhất cho URL
        // canonical, không tin theo $type/$location/$branch trên path (có thể sai/cũ/giả).
        $loc = BranchBookConfig::resolveLocationForProduct($product);

        // URL đang truy cập không khớp loại hình/khu vực/chi nhánh thật (kể cả /room/{slug}/
        // không có gì) → 301 thẳng về URL canonical duy nhất, tránh duplicate content.
        if ($loc && ($loc['type_url_slug'] !== $type || $loc['province_slug'] !== $location || $loc['branch_slug'] !== $branch)) {
            return redirect('/' . $loc['type_url_slug'] . '/' . $loc['province_slug'] . '/' . $loc['branch_slug'] . '/' . $slug . '/', 301);
        }

        $canonicalUrl = $loc
            ? url('/' . $loc['type_url_slug'] . '/' . $loc['province_slug'] . '/' . $loc['branch_slug'] . '/' . $slug . '/')
            : url('/room/' . $slug . '/');

        $seoKeywords    = $product->tags->pluck('name')->implode(', ');
        $seoDescription = html_entity_decode(strip_tags($product->short_description ?? ''));
        $seoOgImage     = $product->hasMedia('Ảnh bìa')
                            ? $product->getFirstMedia('Ảnh bìa')->getUrl()
                            : null;
        $offerPrice     = $product->discount > 0
                            ? (float) $product->price - (float) $product->discount
                            : (float) $product->price;

        // Extract YouTube video IDs from description
        preg_match_all(
            '/(?:youtube\.com\/embed\/|youtu\.be\/)([a-zA-Z0-9_-]{11})/',
            $product->description ?? '',
            $ytMatches
        );
        $videoIds = array_unique($ytMatches[1]);

        $seoData = [
            'seo_title'          => $product->name . ' - Đặt phòng tại 365 HOME',
            'seo_description'    => $seoDescription,
            'seo_keywords'       => $seoKeywords,
            'og_image'           => $seoOgImage,
            'og_type'            => 'product',
            'canonical_url'      => $canonicalUrl,
            'offer_price'        => $offerPrice,
            'offer_currency'     => 'VND',
            'offer_availability' => $product->is_in_stock ? 'InStock' : 'OutOfStock',
            'offer_url'          => $canonicalUrl,
            'offer_sku'          => $product->slug,
            'video_ids'          => $videoIds,
            'video_name'         => $product->name,
            'video_description'  => $seoDescription,
            'video_upload_date'  => $product->updated_at?->toIso8601String(),
        ];

        // Breadcrumb theo đúng chuỗi silo của URL canonical (loại hình > khu vực > chi nhánh) —
        // rỗng (chỉ còn "Trang chủ > tên phòng") khi không xác định được chi nhánh của phòng.
        $breadcrumbParents = $loc ? [
            ['title' => $loc['type_name'], 'url' => url('/' . $loc['type_url_slug'])],
            ['title' => $loc['province_name'], 'url' => url('/' . $loc['type_url_slug'] . '/' . $loc['province_slug'])],
            ['title' => $loc['branch_name'], 'url' => url('/' . $loc['type_url_slug'] . '/' . $loc['province_slug'] . '/' . $loc['branch_slug'])],
        ] : [];

        return view('bladethemev1::pages.product.detail', [
            'slug' => $slug,
            'name' => $product->name,
            'breadcrumbParents' => $breadcrumbParents,
            'primaryColor' => $this->primaryColor,
            'primaryColorRgb' => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor,
            'seoData' => $seoData,
        ]);
    }

    public function templateDetail($slug)
    {
        $template = Product::where([
            'slug' => $slug,
            'is_activated' => true,
            'type' => 'service'
        ])->whereHas('categories', function ($query) {
            $query->where('status', 1);
        })->with('tags:id,name')->first();

        if (!$template) {
            abort(404);
        }

        $seoKeywords    = $template->tags->pluck('name')->implode(', ');
        $seoDescription = html_entity_decode(strip_tags($template->short_description ?? ''));
        $seoOgImage     = $template->hasMedia('Ảnh bìa')
                            ? $template->getFirstMedia('Ảnh bìa')->getUrl()
                            : null;
        $offerPrice     = $template->discount > 0
                            ? (float) $template->price - (float) $template->discount
                            : (float) $template->price;

        preg_match_all(
            '/(?:youtube\.com\/embed\/|youtu\.be\/)([a-zA-Z0-9_-]{11})/',
            $template->description ?? '',
            $ytMatches
        );
        $videoIds = array_unique($ytMatches[1]);

        $seoData = [
            'seo_title'          => $template->name . ' - Dịch vụ tại 365 HOME',
            'seo_description'    => $seoDescription,
            'seo_keywords'       => $seoKeywords,
            'og_image'           => $seoOgImage,
            'og_type'            => 'product',
            'offer_price'        => $offerPrice,
            'offer_currency'     => 'VND',
            'offer_availability' => $template->is_in_stock ? 'InStock' : 'OutOfStock',
            'offer_url'          => url()->current(),
            'offer_sku'          => $template->slug,
            'video_ids'          => $videoIds,
            'video_name'         => $template->name,
            'video_description'  => $seoDescription,
            'video_upload_date'  => $template->updated_at?->toIso8601String(),
        ];

        return view('bladethemev1::pages.template.detail', [
            'slug' => $slug,
            'name' => $template->name,
            'seoData' => $seoData,
            'primaryColor' => $this->primaryColor,
            'primaryColorRgb' => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor
        ]);
    }

    public function domainLookupDetail()
    {
        $this->primaryColor = $this->getFilamentPrimaryColor();

        return view('bladethemev1::pages.domain-lookup.index', [
            'primaryColor' => $this->primaryColor,
            'primaryColorRgb' => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor
        ]);
    }

    public function home()
    {
        $seoData = [
            'seo_title'       => 'Đặt phòng nghỉ, coworking, phòng theo giờ tại 365 HOME',
            'seo_description' => 'Đặt phòng nghỉ, coworking, phòng theo giờ chất lượng tại 365 HOME',
            'seo_keywords'    => '365 home, đặt phòng, phòng theo giờ, coworking',
            'og_type'         => 'website',
            'canonical_url'   => url('/'),
        ];

        return view('bladethemev1::pages.home', [
            'primaryColor'     => $this->primaryColor,
            'primaryColorRgb'  => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor,
            'seoData'          => $seoData,
        ]);
    }

    // $type — slug URL rút gọn (homestay/khach-san/mini-house/villa/nha-nghi/chung-cu) khi vào
    // qua route '/{type}/{location?}' (product.search.type); null khi vào qua '/s/{location?}'
    // (product.search, không lọc loại hình). Không cần redirect/validate gì thêm ở đây — route đã
    // where()-ràng buộc $type chỉ nhận đúng 6 giá trị hợp lệ, còn việc LỌC theo loại hình hoàn
    // toàn nằm phía client (search-results.js tự suy filter 'type' từ pathname).
    public function searchProduct(Request $request, ?string $type = null, string $location = '')
    {
        // Ưu tiên location trên path (kiểu /s/ho-chi-minh), fallback query string
        // ?location=... để không phá các link cũ đã chia sẻ/bookmark trước khi đổi cấu trúc URL.
        $location = $location ?: $request->query('location', '');

        // Resolve province for map
        $province = null;
        $mapLat   = 16.0;
        $mapLng   = 106.0;
        $mapZoom  = 6;

        if ($location) {
            $province = Province::where('slug', $location)->first();
            if ($province && $province->lat && $province->lng) {
                $mapLat  = (float) $province->lat;
                $mapLng  = (float) $province->lng;
                $mapZoom = 13;
            }
        }

        $typeName = null;
        if ($type) {
            $typeDbSlug = BranchBookConfig::typeDbSlugFromUrl($type);
            $typeName   = $typeDbSlug ? RoomType::where('slug', $typeDbSlug)->value('name') : null;
        }

        // Generate unique description based on type and location
        $locName = $province ? $province->name : 'Cần Thơ';
        $typeLower = $typeName ? strtolower($typeName) : 'phòng';

        if ($typeName && $province) {
            $seoDesc = 'Đặt ' . $typeLower . ' tại ' . $locName . ' theo giờ, ngày. Giá tốt, linh hoạt, chất lượng cao tại 365 HOME.';
        } elseif ($typeName) {
            $seoDesc = 'Đặt ' . $typeLower . ' theo giờ, ngày tại Cần Thơ. Giá tốt, linh hoạt, chất lượng cao tại 365 HOME.';
        } elseif ($province) {
            $seoDesc = 'Tìm kiếm phòng tại ' . $locName . ' theo giờ, ngày. Homestay, villa, mini-house, khách sạn - giá tốt, chất lượng tại 365 HOME.';
        } else {
            $seoDesc = 'Tìm kiếm phòng theo giờ, ngày tại Cần Thơ. Homestay, villa, mini-house, khách sạn - chất lượng, giá tốt tại 365 HOME.';
        }

        $seoData = [
            'seo_title'       => trim(($typeName ?: 'Tìm kiếm phòng') . ($province ? ' tại ' . $province->name : '')) . ' | 365 HOME',
            'seo_description' => $seoDesc,
            'seo_keywords'    => 'đặt ' . $typeLower . ', ' . $locName . ', 365 home, tìm kiếm phòng',
            'og_type'         => 'website',
        ];

        return view('bladethemev1::pages.product.search', [
            'seoData'          => $seoData,
            'primaryColor'     => $this->primaryColor,
            'primaryColorRgb'  => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor,
            'province'         => $province,
            'location'         => $location,
            'mapLat'           => $mapLat,
            'mapLng'           => $mapLng,
            'mapZoom'          => $mapZoom,
        ]);
    }

    // /chi-nhanh/{slug} — URL phẳng cũ (không có loại hình/khu vực). Luôn tự chuyển sang URL
    // canonical /{type}/{location}/{slug} nếu chi nhánh xác định được (xem renderBookingBoard()).
    public function bookingBoard(string $slug)
    {
        return $this->renderBookingBoard($slug, null, null);
    }

    // /{type}/{location}/{slug} — URL canonical, gộp loại hình + khu vực vào path cho SEO local.
    // $type/$location chỉ dùng để tự sửa về ĐÚNG loại hình + khu vực thật của chi nhánh (redirect
    // 301) nếu URL gõ sai — không dùng để lọc/tìm chi nhánh, vì slug chi nhánh vốn đã là duy nhất
    // toàn hệ thống (BranchBookConfig::build()).
    public function bookingBoardWithLocation(string $type, string $location, string $slug)
    {
        return $this->renderBookingBoard($slug, $type, $location);
    }

    private function renderBookingBoard(string $slug, ?string $type, ?string $location)
    {
        $result = BranchBookConfig::build($slug);

        abort_unless($result, 404);

        $branch = $result['branch'];
        $bookConfig = $result['bookConfig'];

        // Loại hình + khu vực THẬT của chi nhánh — nguồn sự thật duy nhất cho URL canonical,
        // không tin theo $type/$location trên path (có thể sai/cũ/giả).
        $loc = BranchBookConfig::resolveTypeAndLocationForBranch($branch);

        // URL đang truy cập không khớp loại hình/khu vực thật (kể cả trường hợp /chi-nhanh/{slug}
        // không có gì) → 301 thẳng về URL canonical duy nhất, tránh trang trùng nội dung
        // (duplicate content) trên nhiều URL khác nhau — tốt cho SEO hơn giữ cả 2 URL cùng sống.
        if ($loc && ($loc['type_url_slug'] !== $type || $loc['province_slug'] !== $location)) {
            return redirect('/' . $loc['type_url_slug'] . '/' . $loc['province_slug'] . '/' . $slug, 301);
        }

        $canonicalUrl = $loc
            ? url('/' . $loc['type_url_slug'] . '/' . $loc['province_slug'] . '/' . $slug)
            : url('/chi-nhanh/' . $slug);

        $seoData = [
            'seo_title' => 'Đặt phòng ' . $branch->name . ' | 365 HOME',
            'seo_description' => 'Bảng đặt phòng theo khung giờ tại chi nhánh ' . $branch->name . '.',
            'seo_keywords' => 'đặt phòng, khung giờ, ' . $branch->name,
            'og_type' => 'website',
            'canonical_url' => $canonicalUrl,
        ];

        return view('bladethemev1::pages.booking-board', [
            'seoData' => $seoData,
            'primaryColor' => $this->primaryColor,
            'primaryColorRgb' => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor,
            'branch' => $branch,
            'bookConfig' => $bookConfig,
        ]);
    }

    public function cartPage()
    {
        return view('bladethemev1::pages.cart.cart', [
            'primaryColor' => $this->primaryColor,
            'primaryColorRgb' => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor
        ]);
    }

    public function paymentPage()
    {
        return view('bladethemev1::pages.payment.payment', [
            'primaryColor' => $this->primaryColor,
            'primaryColorRgb' => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor
        ]);
    }

    public function accountPage()
    {
        $seoData = [
            'seo_title' => 'Tài khoản của tôi',
            'seo_description' => 'Quản lý thông tin tài khoản, đơn đặt phòng và ưu đãi thành viên tại 365 Home.',
            'og_type' => 'website',
            'robots' => 'noindex, follow',
        ];

        return view('bladethemev1::pages.account.index', [
            'seoData' => $seoData,
            'primaryColor' => $this->primaryColor,
            'primaryColorRgb' => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor,
        ]);
    }

}
