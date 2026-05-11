<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
class DefaultPage extends Component
{
    public ?string $first_page_title;
    public ?string $second_page_title;
    public ?string $first_page_background_image;
    public ?string $second_page_background_image;
    public ?string $first_page_cta_link;
    public ?string $second_page_cta_link;
    public array $config;

    public function mount($config) {
        $this->config = $config['component'] ?? [];
        $this->first_page_title = $this->config['first_page_title'] ?? null;
        $this->second_page_title = $this->config['second_page_title'] ?? null;
        $this->first_page_background_image = $this->config['first_page_background_image'] ?? null;
        $this->second_page_background_image = $this->config['second_page_background_image'] ?? null;
        $this->first_page_cta_link = $this->config['first_page_cta_link'] ?? null;
        $this->second_page_cta_link = $this->config['second_page_cta_link'] ?? null;
    }

    public function render()
    {
        return view('bladethemev1::livewire.default-page');
    }
}

