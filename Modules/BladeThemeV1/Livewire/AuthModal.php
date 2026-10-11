<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Settings\GeneralSettings;
use Illuminate\View\View;
use Livewire\Component;

class AuthModal extends Component
{
    public bool $enabled = false;
    public string $primaryHex = '#FBCB1C';
    public string $textOnPrimary = '#1a1e25';

    /** false: HTML ban đầu chỉ có 1 thẻ chờ sự kiện 'open-auth-modal'; nội dung popup (form số điện
     *  thoại / OTP / đăng ký, ~20KB) chỉ được tải về qua load() khi khách bấm mở lần đầu. */
    public bool $loaded = false;

    public function load(): void
    {
        $this->loaded = true;
    }

    public function mount(): void
    {
        $settings = new GeneralSettings();
        $this->enabled = (bool) ($settings->auth_header['enabled'] ?? false);

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
        return view('bladethemev1::livewire.auth-modal');
    }
}
