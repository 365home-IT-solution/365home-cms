<?php

namespace Modules\BladeThemeV1\Http\Controllers;

use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Category\Entities\Category;
use Modules\Page\Entities\Page;
use Modules\Page\Entities\PageComponent;
use Modules\BladeThemeV1\Traits\HandleColorTrait;
use Modules\Post\Entities\Post;
use Modules\Product\App\Models\Product;
use Modules\Payment\Entities\Order;
use Modules\Payment\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Log;

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

        $pageComponents = PageComponent::with('pageComponentConfigurationValues')
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
        ]);
    }

    public function postDetail($slug)
    {
        $post = Post::with(['user'])->where('slug', $slug)->first();

        if (!$post) {
            abort(404);
        }

        $seoOgImage = null;
        if ($post->hasMedia('Ảnh chính')) {
            $seoOgImage = $post->getFirstMedia('Ảnh chính')->getUrl();
        }

        $seoData = [
            'seo_title'              => $post->seo_title ?? $post->title ?? '',
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
            'primaryColor' => $this->primaryColor,
            'primaryColorRgb' => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor
        ]);
    }

    public function bookingDetail(Request $request, $code)
    {
        // Tìm đơn hàng theo order_code, load kèm items và product
        $order = Order::with(['items.product', 'accessCodes'])
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

    public function productDetail($slug)
    {
        $product = Product::where([
            'slug' => $slug,
            'is_activated' => true,
            'type' => 'simple'
        ])
            ->whereHas('categories', function ($query) {
                $query->where('status', 1);
            })
            ->with('tags:id,name')
            ->select(['id', 'name', 'slug', 'short_description', 'description', 'price', 'discount', 'is_in_stock', 'updated_at'])
            ->first();

        if (!$product) {
            abort(404);
        }

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
            'seo_title'          => $product->name,
            'seo_description'    => $seoDescription,
            'seo_keywords'       => $seoKeywords,
            'og_image'           => $seoOgImage,
            'og_type'            => 'product',
            'offer_price'        => $offerPrice,
            'offer_currency'     => 'VND',
            'offer_availability' => $product->is_in_stock ? 'InStock' : 'OutOfStock',
            'offer_url'          => url()->current(),
            'offer_sku'          => $product->slug,
            'video_ids'          => $videoIds,
            'video_name'         => $product->name,
            'video_description'  => $seoDescription,
            'video_upload_date'  => $product->updated_at?->toIso8601String(),
        ];

        return view('bladethemev1::pages.product.detail', [
            'slug' => $slug,
            'name' => $product->name,
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
            'seo_title'          => $template->name,
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

    public function searchProduct(Request $request)
    {
        $this->primaryColor = $this->getFilamentPrimaryColor();
        $search = $request->input('tim-kiem', '');

        $seoData = [
            'seo_title' => 'Tìm kiếm sản phẩm' . ($search ? ': ' . $search : '') . ' | Goldenbeeltd',
            'seo_description' => 'Trang tìm kiếm sản phẩm với đa dạng các mặt hàng chất lượng. Dễ dàng tìm kiếm và lọc sản phẩm theo danh mục, giá cả và nhiều tiêu chí khác.',
            'seo_keywords' => 'tìm kiếm sản phẩm, tìm kiếm, mua sắm online, sản phẩm chất lượng, giá tốt, shop online'
        ];

        return view('bladethemev1::pages.product.search', [
            'seoData' => $seoData,
            'primaryColor' => $this->primaryColor,
            'primaryColorRgb' => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor
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
        return view('bladethemev1::pages.account.index', [
            'primaryColor' => $this->primaryColor,
            'primaryColorRgb' => $this->primaryColorRgb,
            'heavyPrimaryColor' => $this->heavyPrimaryColor,
            'lightPrimaryColor' => $this->lightPrimaryColor,
        ]);
    }

}
