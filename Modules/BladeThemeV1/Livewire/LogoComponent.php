<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class LogoComponent extends Component
{
    use HandleConfigTrait;

    public array $images = [];
    public array $imagePaths = [];

    public function mount($config)
    {
        $this->setConfig($config);
        $this->images = $this->getConfig('images');

        // Tạo mảng chứa các đường dẫn ảnh từ mảng ban đầu
        $imagePaths = [];

        foreach ($this->images as $key => $value) {
            if (isset($value['image']) && is_array($value['image'])) {
                foreach ($value['image'] as $imageKey => $imagePath) {
                    $imagePaths[] = $imagePath; // Thêm đường dẫn ảnh vào mảng mà không giữ key
                }
            }
        }
        // Chuyển các giá trị thành mảng chỉ số (0, 1, 2,...)
        $this->imagePaths = array_values($imagePaths);
    }

    public function render()
    {
        return view('bladethemev1::livewire.logo-component', [
            'logos' => $this->imagePaths, // Truyền mảng ảnh vào view
        ]);
    }
}

