<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Services\AvailableProvinceService;
use App\Settings\GeneralSettings;
use Illuminate\View\View;
use Livewire\Component;

class LocationModal extends Component
{
    public string $primaryHex = '#FBCB1C';
    public string $textOnPrimary = '#1a1e25';
    public array $provinces = [];

    public function mount(): void
    {
        $this->provinces = app(AvailableProvinceService::class)->get();
        $settings = new GeneralSettings();
        $this->primaryHex = $settings->site_theme['primary'] ?? '#FBCB1C';

        $hex = ltrim($this->primaryHex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        $this->textOnPrimary = $luminance > 0.5 ? '#1a1e25' : '#ffffff';
    }

    public function render(): View
    {
        return view('bladethemev1::livewire.location-modal');
    }
}
