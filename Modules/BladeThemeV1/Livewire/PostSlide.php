<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleColorTrait;
use Modules\BladeThemeV1\Traits\HandleCalculateTrait;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;
use Modules\BladeThemeV1\Services\Post\PostService;
class PostSlide extends Component
{
    use HandleColorTrait, HandleCalculateTrait, HandleConfigTrait;

    public $posts;
    public $primaryColor;
    public $primaryColorRgb;
    protected PostService $postService;

    public function boot(PostService $postService)
    {
        $this->postService = $postService;
    }
    
    public function mount($config)
    {
        $this->setConfig($config);
        $this->primaryColor = $this->getFilamentPrimaryColor();
        $this->primaryColorRgb = $this->hexToRgb($this->primaryColor);
        $this->posts = $this->fetchData();
        $this->uniqueId = 'swiper-' . uniqid();
    }

    public function fetchData()
    {
        return $this->postService->postSlide($this->config);
    }

    public function calculateColumns($default = 4)
    {
        return $this->calculateColumnsTrait($this->config, $default);
    }

    public function render()
    {
        return view('bladethemev1::livewire.post-slide', [
            'columns' => $this->calculateColumns(),
        ]);
    }
}
