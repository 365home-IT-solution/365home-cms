<?php

namespace App\Http\View\Composers;


use App\Settings\GeneralSettings;
use Illuminate\View\View;
use Modules\Product\App\Models\Product;

class ProductColorComposer
{
    protected $settings;

    public function __construct(GeneralSettings $settings)
    {
        $this->settings = $settings;
    }

    public function compose(View $view)
    {
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

        $view->with('productColors', $productColors)
            ->with('productColorsCss', $cssVariables);
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