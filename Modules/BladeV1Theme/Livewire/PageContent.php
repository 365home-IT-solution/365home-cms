<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;
use Illuminate\Support\Str;

class PageContent extends Component
{
    use HandleConfigTrait;
    public string $url;
    public string $title;

    public function mount($config): void
    {
        $this->setConfig($config);
        
    }
    public function render()
    {
        return view('bladethemev1::livewire.page-content', [
            'page_content' => Str::of($this->getConfig('page_content'))->markdown(),
        ]);
    }

}
