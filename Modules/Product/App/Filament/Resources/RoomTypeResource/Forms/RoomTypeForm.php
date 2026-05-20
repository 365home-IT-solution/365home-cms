<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomTypeResource\Forms;

use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Illuminate\Support\Str;

class RoomTypeForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->label(__('product::room_type.form.label.name'))
                ->placeholder(__('product::room_type.form.placeholder.name'))
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Str::snake(Str::ascii($state)))),

            TextInput::make('slug')
                ->label(__('product::room_type.form.label.slug'))
                ->placeholder(__('product::room_type.form.placeholder.slug'))
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),

            TextInput::make('icon')
                ->label(__('product::room_type.form.label.icon'))
                ->placeholder(__('product::room_type.form.placeholder.icon'))
                ->helperText(__('product::room_type.form.helper_text.icon'))
                ->hintActions([
                    Action::make('browse_icons')
                        ->label('Xem danh sách icon')
                        ->url('https://icons.expo.fyi/Index')
                        ->openUrlInNewTab()
                        ->icon('heroicon-o-arrow-top-right-on-square'),
                ])
                ->maxLength(150),

            TextInput::make('icon_url')
                ->label(__('product::room_type.form.label.icon_url'))
                ->placeholder(__('product::room_type.form.placeholder.icon_url'))
                ->url()
                ->maxLength(500),

            TextInput::make('sort_order')
                ->label(__('product::room_type.form.label.sort_order'))
                ->numeric()
                ->default(0)
                ->minValue(0),

            Toggle::make('is_active')
                ->label(__('product::room_type.form.label.is_active'))
                ->default(false),
        ]);
    }
}
