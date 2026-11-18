<?php

declare(strict_types=1);

namespace Modules\Motrak\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;

// Gắn vào panel /homestay/admin (nhóm menu "GPS Tracking"). Tự phát hiện Pages/Resources trong module; nạp CSS Leaflet + script bản đồ (tự host ở public/vendor).
class MotrakPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Motrak';
    }

    public function getId(): string
    {
        return 'motrak';
    }

    public function boot(Panel $panel): void
    {
        $version = static fn (string $file): string => is_file(public_path($file)) ? (string) filemtime(public_path($file)) : '1';

        FilamentAsset::register([
            Css::make('motrak-leaflet', asset('vendor/leaflet/leaflet.css') . '?v=1.9.4'),
            Js::make('motrak-map', asset('vendor/motrak/motrak-map.js') . '?v=' . $version('vendor/motrak/motrak-map.js')),
        ], 'motrak');
    }
}
