<?php

namespace TomatoPHP\FilamentMediaManager;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Nwidart\Modules\Module;
use TomatoPHP\FilamentMediaManager\Resources\FolderResource;
use TomatoPHP\FilamentMediaManager\Resources\MediaResource;


class FilamentMediaManagerPlugin implements Plugin
{
    private bool $isActive = false;


    public ?bool $allowSubFolders = false;
    public ?bool $allowUserAccess = false;

    public function getId(): string
    {
        return 'filament-media-manager';
    }

    public function allowSubFolders(bool $condation = true): static
    {
        $this->allowSubFolders = $condation;
        return $this;
    }

    public function allowUserAccess(bool $condation = true): static
    {
        $this->allowUserAccess = $condation;
        return $this;
    }

    public function register(Panel $panel): void
    {
        if(class_exists(Module::class) && \Nwidart\Modules\Facades\Module::find('FilamentMediaManager')?->isEnabled()){
            $this->isActive = true;
        }
        else {
            $this->isActive = true;
        }

        if($this->isActive) {
            $panel->resources([
                FolderResource::class,
                MediaResource::class
            ]);
        }

    }

    public function boot(Panel $panel): void
    {
        //
    }

    // Tra cứu "allowUserAccess" AN TOÀN khi panel hiện tại KHÔNG đăng ký plugin này (VD panel
    // minihouse-admin chỉ dùng MediaManagerInput trong form Phòng) — filament('filament-media-manager')
    // ném Exception "Plugin ... is not registered" (500) ở các panel đó.
    public static function userAccessAllowed(): bool
    {
        $panel = \Filament\Facades\Filament::getCurrentPanel();

        return $panel && $panel->hasPlugin('filament-media-manager')
            ? (bool) filament('filament-media-manager')->allowUserAccess
            : false;
    }

    public static function make(): static
    {
        return new static();
    }
}
