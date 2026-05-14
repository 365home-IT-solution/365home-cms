<?php

namespace Modules\BladeThemeV1\Livewire;

use App\Settings\GeneralSettings;
use Livewire\Component;

class Popup extends Component
{
    public bool $isVisible = false;
    public array $popupConfig = [];
    public bool $isEnabled = false;

    protected $listeners = ['openPopup' => 'show', 'closePopup' => 'hide'];

    public function mount()
    {
        // Lấy cấu hình popup từ GeneralSettings
        $settings = app(GeneralSettings::class);
        $this->popupConfig = $settings->popup ?? [];
        $this->isEnabled = $this->popupConfig['enabled'] ?? false;

        // Kiểm tra xem có nên hiển thị popup không dựa vào cookie
        if ($this->isEnabled && $this->shouldShowPopup()) {
            $this->isVisible = $this->getInitialVisibility();
        }
    }

    /**
     * Kiểm tra xem popup có nên hiển thị hay không
     */
    protected function shouldShowPopup(): bool
    {
        $frequency = $this->popupConfig['show_frequency'] ?? 1;
        $popupId = md5(json_encode($this->popupConfig['title'] ?? 'popup'));

        if ($frequency == 0) {
            return true;
        }

        $lastShown = request()->cookie("popup_dismissed_{$popupId}");

        if (!$lastShown) {
            return true;
        }

        $minutesPassed = (time() - strtotime($lastShown)) / 60;
        return $minutesPassed >= $frequency;
    }

    /**
     * Xác định visibility ban đầu dựa trên display_mode
     */
    protected function getInitialVisibility(): bool
    {
        $displayMode = $this->popupConfig['display_mode'] ?? 'auto';

        // Chỉ hiển thị ngay với mode 'auto'
        // Các mode khác sẽ được xử lý bằng JavaScript
        return $displayMode === 'auto';
    }

    /**
     * Hiển thị popup (có thể gọi từ JavaScript hoặc component khác)
     */
    public function show()
    {
        $this->isVisible = true;
    }

    /**
     * Ẩn popup
     */
    public function hide()
    {
        $this->isVisible = false;

        $frequency = $this->popupConfig['show_frequency'] ?? 1;
        $popupId = md5(json_encode($this->popupConfig['title'] ?? 'popup'));

        if ($frequency > 0) {
            $this->js("
                const expires = new Date();
                expires.setMinutes(expires.getMinutes() + {$frequency});
                document.cookie = 'popup_dismissed_{$popupId}=' + new Date().toISOString() 
                    + '; expires=' + expires.toUTCString() + '; path=/; SameSite=Lax';
            ");
        }

        $this->dispatch('popup-closed');
    }

    public function handleButtonClick($buttonIndex)
    {
        $button = $this->popupConfig['buttons'][$buttonIndex] ?? null;

        if (!$button) {
            $this->hide();
            return;
        }

        $url = $button['url'] ?? null;
        $target = $button['target'] ?? '_self';

        if (empty($url)) {
            $this->hide();
            return;
        }

        // Sửa lại cách dispatch - thêm browser() để chạy JavaScript trực tiếp
        $this->js("
        const url = '{$url}';
        const target = '{$target}';
        if (target === '_blank') {
            window.open(url, '_blank');
        } else {
            window.location.href = url;
        }
    ");

        $this->hide();
    }

    /**
     * Get computed styles cho popup background
     */
    public function getBackgroundStyle(): string
    {
        $bgType = $this->popupConfig['background_type'] ?? 'color';

        switch ($bgType) {
            case 'gradient':
                $start = $this->popupConfig['gradient_start'] ?? '#6366f1';
                $end = $this->popupConfig['gradient_end'] ?? '#8b5cf6';
                $direction = $this->popupConfig['gradient_direction'] ?? 'to-br';

                // Convert Tailwind direction to CSS
                $cssDirection = match($direction) {
                    'to-r' => 'to right',
                    'to-b' => 'to bottom',
                    'to-br' => 'to bottom right',
                    'to-bl' => 'to bottom left',
                    default => 'to bottom right'
                };

                return "background: linear-gradient({$cssDirection}, {$start}, {$end});";

            case 'image':
                $image = $this->popupConfig['background_image'] ?? '';
                $position = $this->popupConfig['image_position'] ?? 'center';
                $size = $this->popupConfig['image_size'] ?? 'cover';
                $opacity = $this->popupConfig['image_opacity'] ?? 100;

                if ($image) {
                    return "background-image: url('" . asset('storage/' . $image) . "'); 
                            background-position: {$position}; 
                            background-size: {$size}; 
                            background-repeat: no-repeat;
                            opacity: " . ($opacity / 100) . ";";
                }
                // Fallback to color
                return "background-color: " . ($this->popupConfig['background_color'] ?? '#ffffff') . ";";

            case 'color':
            default:
                return "background-color: " . ($this->popupConfig['background_color'] ?? '#ffffff') . ";";
        }
    }

    /**
     * Get popup size class
     */
    public function getPopupSizeClass(): string
    {
        $size = $this->popupConfig['size'] ?? 'md';

        return match($size) {
            'sm' => 'max-w-md',
            'md' => 'max-w-2xl',
            'lg' => 'max-w-4xl',
            'xl' => 'max-w-6xl',
            'custom' => '',
            default => 'max-w-2xl'
        };
    }

    /**
     * Get custom dimensions if applicable
     */
    public function getCustomDimensions(): string
    {
        if (($this->popupConfig['size'] ?? 'md') !== 'custom') {
            return '';
        }

        $width  = $this->popupConfig['custom_width']  ?? 600;
        $height = $this->popupConfig['custom_height'] ?? null;
        $unit   = $this->popupConfig['size_unit']     ?? 'px';

        // Với đơn vị %, vw, vh → dùng max-width để responsive tốt hơn
        if (in_array($unit, ['%', 'vw'])) {
            $style = "width: {$width}{$unit}; max-width: 98vw;";
        } elseif ($unit === 'vh') {
            // vh thường dùng cho height hơn, nhưng vẫn hỗ trợ
            $style = "width: {$width}{$unit}; max-width: 98vw;";
        } else {
            // px
            $style = "width: {$width}px; max-width: 90vw;";
        }

        if ($height) {
            if (in_array($unit, ['%', 'vh'])) {
                $style .= " height: {$height}{$unit}; max-height: 95vh;";
            } elseif ($unit === 'vw') {
                $style .= " height: {$height}vw; max-height: 95vh;";
            } else {
                $style .= " height: {$height}px; max-height: 90vh;";
            }
        }

        return $style;
    }

    /**
     * Get animation class based on config
     */
    public function getAnimationClass(): string
    {
        $animation = $this->popupConfig['animation'] ?? 'fade';

        return match($animation) {
            'slide-up' => 'animate-slide-up',
            'slide-down' => 'animate-slide-down',
            'zoom' => 'animate-zoom',
            'bounce' => 'animate-bounce-in',
            'fade' => 'animate-fade-in',
            default => 'animate-fade-in'
        };
    }

    /**
     * Get button style
     */
    public function getButtonStyle(array $button): string
    {
        $bgColor = $button['background_color'] ?? '#3b82f6';
        $textColor = $button['text_color'] ?? '#ffffff';
        $style = $button['style'] ?? 'solid';

        switch ($style) {
            case 'outline':
                return "background: transparent; border: 2px solid {$bgColor}; color: {$bgColor};";
            case 'ghost':
                return "background: transparent; color: {$bgColor};";
            case 'solid':
            default:
                return "background: {$bgColor}; color: {$textColor};";
        }
    }

    /**
     * Get button size class
     */
    public function getButtonSizeClass(array $button): string
    {
        $size = $button['size'] ?? 'md';

        return match($size) {
            'sm' => 'px-4 py-2 text-sm',
            'md' => 'px-6 py-3 text-base',
            'lg' => 'px-8 py-4 text-lg',
            default => 'px-6 py-3 text-base'
        };
    }

    public function getPreloadImage(): ?string
    {
        if (!empty($this->popupConfig['content_image'])) {
            return asset('storage/' . $this->popupConfig['content_image']);
        }
        return null;
    }
    public function getOptimizedImageUrl(): ?string
    {
        if (empty($this->popupConfig['content_image'])) {
            return null;
        }

        $imagePath = $this->popupConfig['content_image'];
        return asset('storage/' . $imagePath);
    }

    public function render()
    {
        // Nếu popup không được enabled, không render gì
        if (!$this->isEnabled) {
            return <<<'HTML'
        <div></div>
        HTML;
        }

        return view('bladethemev1::livewire.popup', [
            'config' => $this->popupConfig,
            'backgroundStyle' => $this->getBackgroundStyle(),
            'sizeClass' => $this->getPopupSizeClass(),
            'customDimensions' => $this->getCustomDimensions(),
            'animationClass' => $this->getAnimationClass(),
        ]);
    }
}