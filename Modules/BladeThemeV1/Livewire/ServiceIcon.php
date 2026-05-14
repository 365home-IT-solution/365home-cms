<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class ServiceIcon extends Component
{
    use HandleConfigTrait;

    public $anniversaryLogo;
    public $title;
    public $subtitle;
    public $coreValues;

    public function mount($config)
    {
        $this->setConfig($config);

        // Lấy dữ liệu từ config
        $serviceData = $this->getConfig('service-icon');
        $serviceKey = key($serviceData); // Lấy UUID đầu tiên
        $serviceContent = $serviceData[$serviceKey] ?? [];

        // Gán dữ liệu vào các thuộc tính public
        $this->anniversaryLogo = $this->getFirstValue($serviceContent['anniversary_logo'] ?? []) ?? '';
        $this->title = $serviceContent['title'] ?? 'GIÁ TRỊ CỐT LÕI';
        $this->subtitle = $serviceContent['subtitle'] ?? 'Những giá trị định hình con đường phát triển bền vững của chúng tôi suốt 25 năm qua';
        $this->coreValues = $this->transformCoreValues($serviceContent['core_values'] ?? []);
    }

    /**
     * Lấy giá trị đầu tiên từ mảng, tránh lỗi khi mảng không tồn tại
     */
    protected function getFirstValue($array)
    {
        return is_array($array) && !empty($array) ? reset($array) : '';
    }

    /**
     * Chuyển đổi dữ liệu core_values thành mảng phù hợp
     */
    protected function transformCoreValues(array $coreValues): array
    {
        $transformed = [];

        foreach ($coreValues as $value) {
            if (!is_array($value)) {
                continue;
            }

            // Chuẩn hóa tên icon để dùng với <x-dynamic-component>
            $icon = str_replace(['heroicon-c-', 'heroicon-m-'], 'heroicon-o-', $value['icon'] ?? 'heroicon-o-clock');

            $transformed[] = [
                'icon' => $icon, // Đảm bảo định dạng là 'heroicon-o-...'
                'title' => $value['title'] ?? '',
                'description' => $value['description'] ?? '',
            ];
        }

        return $transformed;
    }

    public function render()
    {
        return view('bladethemev1::livewire.service-icon');
    }
}