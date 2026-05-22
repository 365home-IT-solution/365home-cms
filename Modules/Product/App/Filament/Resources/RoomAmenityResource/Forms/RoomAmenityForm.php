<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomAmenityResource\Forms;

use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;

class RoomAmenityForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('amenity_type')
                ->label('Nhóm tiện ích')
                ->placeholder('VD: Phòng tắm, Tiện nghi, Giải trí')
                ->required()
                ->maxLength(100),

            TextInput::make('name')
                ->label('Tên tiện ích')
                ->placeholder('VD: Vòi sen, Điều hoà, TV')
                ->required()
                ->maxLength(255),

            TextInput::make('icon')
                ->label('Icon name')
                ->placeholder('VD: shower, air-conditioner, tv')
                ->helperText('Chỉ nhập tên icon — tham khảo Expo Icons')
                ->hintActions([
                    Action::make('browse_icons')
                        ->label('Xem danh sách icon')
                        ->url('https://icons.expo.fyi/Index')
                        ->openUrlInNewTab()
                        ->icon('heroicon-o-arrow-top-right-on-square'),
                ])
                ->maxLength(150),

            TextInput::make('sort_order')
                ->label('Thứ tự')
                ->numeric()
                ->default(0)
                ->minValue(0),

            Toggle::make('status')
                ->label('Kích hoạt')
                ->default(true),
        ]);
    }
}
