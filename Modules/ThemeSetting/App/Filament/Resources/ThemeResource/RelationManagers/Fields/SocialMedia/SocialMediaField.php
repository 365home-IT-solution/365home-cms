<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\SocialMedia;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\IconPosition;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\BaseField;

class SocialMediaField extends BaseField
{

    public function create(): Component
    {
        return $this->addCommonAttributes(
            Repeater::make("config.{$this->config->key}")
                ->label('Liên kết mạng xã hội')
                ->schema([
                    Grid::make()
                        ->schema([
                            FlatFormField::create(),
                            UrlField::create(),
                        ])
                        ->columns(2)
                ])
                ->defaultItems(0)
                ->maxItems(6)
                ->collapsible()
                ->collapsed()
                ->reorderableWithButtons()
                ->cloneable()
                ->itemLabel(fn(array $state): ?string =>
                isset($state['platform']) && SocialPlatform::tryFrom($state['platform'])
                    ? SocialPlatform::from($state['platform'])->label() . ': ' . ($state['url'] ?? 'Chưa có URL')
                    : 'Mạng xã hội mới'
                )
                ->addActionLabel('Thêm mạng xã hội')
                ->deleteAction(
                    fn($action) => $action
                        ->icon('heroicon-m-trash')
                        ->iconPosition(IconPosition::After)
                        ->size(ActionSize::Small)
                )
                ->reorderAction(
                    fn($action) => $action
                        ->icon('heroicon-m-arrows-up-down')
                        ->iconPosition(IconPosition::After)
                        ->size(ActionSize::Small)
                )
                ->cloneAction(
                    fn($action) => $action
                        ->icon('heroicon-m-square-2-stack')
                        ->iconPosition(IconPosition::After)
                        ->size(ActionSize::Small)
                )
        );
    }
}
