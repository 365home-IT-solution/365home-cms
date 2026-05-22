<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomSpecialResource\Forms;

use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Modules\Product\App\Models\Product;

class RoomSpecialForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('product_id')
                ->label('Phòng')
                ->options(fn () => Product::where('is_activated', true)->pluck('name', 'id')->toArray())
                ->searchable()
                ->required(),

            TextInput::make('title')
                ->label('Tiêu đề')
                ->placeholder('VD: Miễn phí WiFi tốc độ cao, Ban công view đẹp')
                ->required()
                ->maxLength(255),

            TextInput::make('icon')
                ->label('Icon name')
                ->placeholder('VD: wifi, balcony, pool')
                ->helperText('Chỉ nhập tên icon — tham khảo Expo Icons')
                ->hintActions([
                    Action::make('browse_icons')
                        ->label('Xem danh sách icon')
                        ->url('https://icons.expo.fyi/Index')
                        ->openUrlInNewTab()
                        ->icon('heroicon-o-arrow-top-right-on-square'),
                ])
                ->maxLength(150),

            Textarea::make('short_description')
                ->label('Mô tả ngắn')
                ->placeholder('Mô tả ngắn về điểm đặc biệt này')
                ->rows(3)
                ->maxLength(500),

            TextInput::make('sort_order')
                ->label('Thứ tự')
                ->numeric()
                ->default(0)
                ->minValue(0),
        ]);
    }
}
