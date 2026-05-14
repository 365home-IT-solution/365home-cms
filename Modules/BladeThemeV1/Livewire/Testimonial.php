<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class Testimonial extends Component
{
    use HandleConfigTrait;

    public function mount($config)
    {
        $this->setConfig($config);
    }

    protected function getTestimonialData(): ?array
    {
        $rawData = $this->getConfig('testiminal_data');
        return is_array($rawData) ? $rawData : null;
    }

    protected function getTestimonialType(): string
    {
        return $this->getConfig('choose_style_testiminal', 'default');
    }

    public function render()
    {
        return view('bladethemev1::livewire.testimonial', [
            'type' => $this->getTestimonialType(),
            'testimonialData' => $this->getTestimonialData(),
        ]);
    }
}
