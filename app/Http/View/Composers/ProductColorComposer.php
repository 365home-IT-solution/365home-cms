<?php

namespace App\Http\View\Composers;


use App\Settings\GeneralSettings;
use Illuminate\View\View;
use Modules\Product\App\Models\Product;

class ProductColorComposer
{
    protected $settings;

    // Composer đăng ký cho view('*') nên compose() chạy lại với 1 INSTANCE MỚI mỗi lần bất kỳ
    // view nào được render — kể cả @include('_slot-cell') lặp hàng nghìn lần trong 1 lần tải
    // lịch đặt phòng (xem book/_desktop-grid.blade.php, _mobile.blade.php). Cache tĩnh theo
    // process để tính 1 LẦN/request thay vì lặp lại y hệt cho từng ô — dữ liệu nguồn
    // ($settings->color_product) không đổi trong vòng đời 1 request nên an toàn để cache.
    protected static ?array $productColorsCache = null;

    protected static ?string $cssVariablesCache = null;

    public function __construct(GeneralSettings $settings)
    {
        $this->settings = $settings;
    }

    public function compose(View $view)
    {
        if (static::$productColorsCache === null) {
            // 1. Chuyển mảng setting thành Collection để dễ xử lý
            $colorSettings = collect($this->settings->color_product ?? []);

            // 2. Tạo mảng lookup: [product_id => ['color' => ..., 'color_text' => ...]]
            $productColors = $colorSettings->keyBy('product_id')->toArray();

            // 3. Tạo CSS variables (Tối ưu: Không query DB trong vòng lặp nếu chỉ cần ID)
            $cssVariables = "";
            foreach ($productColors as $productId => $config) {
                $bg = $config['color'] ?? '#ffffff';
                $text = $config['color_text'] ?? '#333333';

                // Tạo biến CSS cho từng ID sản phẩm
                $cssVariables .= "--bg-prod-{$productId}: {$bg}; ";
                $cssVariables .= "--text-prod-{$productId}: {$text}; ";
            }

            static::$productColorsCache = $productColors;
            static::$cssVariablesCache = $cssVariables;
        }

        $view->with('productColors', static::$productColorsCache)
            ->with('productColorsCss', static::$cssVariablesCache);
    }

    protected function hexToRgb($hex)
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return "{$r}, {$g}, {$b}";
    }
}