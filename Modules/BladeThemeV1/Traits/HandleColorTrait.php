<?php

namespace Modules\BladeThemeV1\Traits;

use Filament\Facades\Filament;
use Filament\Support\Colors\Color;

trait HandleColorTrait
{
    public function getFilamentPrimaryColor()
    {
        $panel = Filament::getCurrentPanel();
        $colors = $panel->getColors();

        if (isset($colors['primary'])) {
            $primaryColor = $colors['primary'];

            if (is_string($primaryColor)) {
                // Check if it's an RGB string
                if (strpos($primaryColor, 'rgb') === 0) {
                    return $this->rgbToHex($primaryColor);
                }

                // Check if it's a hex color
                if (preg_match('/^#[a-f0-9]{6}$/i', $primaryColor)) {
                    return $primaryColor;
                }

                // Check if it's a color name
                $colorClass = new \ReflectionClass(Color::class);
                $constants = $colorClass->getConstants();
                
                if (isset($constants[strtoupper($primaryColor)])) {
                    return $this->rgbToHex($constants[strtoupper($primaryColor)][500]);
                }
            } elseif (is_array($primaryColor)) {
                // If it's an array, we assume it's in the format [r, g, b] or has a '500' key
                if (isset($primaryColor[0], $primaryColor[1], $primaryColor[2])) {
                    return $this->rgbToHex(implode(',', $primaryColor));
                } elseif (isset($primaryColor[500])) {
                    return $this->rgbToHex($primaryColor[500]);
                }
            }
        }

        // If no valid primary color is found, return a default color
        return '#000000'; // You can change this to any default color you prefer
    }

    private function rgbToHex($rgb)
    {
        // Remove 'rgb(' and ')' if present
        $rgb = str_replace(['rgb(', ')', ' '], '', $rgb);
        
        $rgb_array = explode(',', $rgb);

        if (count($rgb_array) !== 3) {
            return false;
        }

        $hex = sprintf(
            "#%02x%02x%02x",
            intval($rgb_array[0]),
            intval($rgb_array[1]),
            intval($rgb_array[2])
        );

        return $hex;
    }

    public function hexToRgb($hex)
    {
        $hex = ltrim($hex, '#');
        $rgb = array_map('hexdec', str_split($hex, 2));
        
        return implode(',', $rgb);
    }

    public function adjustBrightness($hex, $steps)
    {
        $rgb = sscanf($hex, "#%02x%02x%02x");
        
        foreach ($rgb as &$color) {
            $color = max(0, min(255, $color + $steps));
        }
        return sprintf("#%02x%02x%02x", $rgb[0], $rgb[1], $rgb[2]);
    }

    public function isHexColor($color)
    {
        return preg_match('/^#?([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $color);
    }

    function lightenColor($hex, $amount) {
        $rgb = sscanf($hex, "#%02x%02x%02x");

        for ($i = 0; $i < 3; $i++) {
            $rgb[$i] = min(255, $rgb[$i] + $amount); // Tăng giá trị màu
        }

        return sprintf("#%02x%02x%02x", $rgb[0], $rgb[1], $rgb[2]); // Trả về màu hex
    }

    function darkenColor($hex, $amount) {
        $rgb = sscanf($hex, "#%02x%02x%02x");

        for ($i = 0; $i < 3; $i++) {
            $rgb[$i] = max(0, $rgb[$i] - $amount);
        }

        return sprintf("#%02x%02x%02x", $rgb[0], $rgb[1], $rgb[2]);
    }

}
