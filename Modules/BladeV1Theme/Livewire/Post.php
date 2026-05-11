<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Illuminate\Support\Facades\Cache;

class Post extends Component
{
    public array $config = [];

    public function mount($config)
    {
        $this->config = $config ?? [];
        $this->updateCacheIfNeeded();
    }

    private function updateCacheIfNeeded()
    {
        $cacheKey = 'post_config_style';
        $cachedStyle = Cache::get($cacheKey) ?? 'default';

        $currentStyle = isset($this->config['component']['style']) ? $this->config['component']['style'] : 'default';

        if ($cachedStyle === null || $cachedStyle !== $currentStyle) {
            Cache::forever($cacheKey, $currentStyle);
        }
    }

    public function render()
    {
        return view('bladethemev1::livewire.post');
    }
}
