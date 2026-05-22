<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomSpecialResource\Pages;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Product\App\Filament\Resources\RoomSpecialResource;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomSpecial;

class CreateRoomSpecial extends CreateRecord
{
    protected static string $resource = RoomSpecialResource::class;

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('product_id')
                ->label('Phòng')
                ->options(fn () => Product::where('is_activated', true)->orderBy('name')->pluck('name', 'id')->toArray())
                ->searchable()
                ->required()
                ->columnSpanFull(),

            Repeater::make('specials')
                ->label('Danh sách điểm đặc biệt')
                ->schema([
                    TextInput::make('title')
                        ->label('Tiêu đề')
                        ->placeholder('VD: Miễn phí WiFi tốc độ cao, Ban công view đẹp')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),

                    TextInput::make('icon')
                        ->label('Icon name')
                        ->placeholder('VD: wifi, balcony, pool')
                        ->maxLength(150),

                    TextInput::make('sort_order')
                        ->label('Thứ tự')
                        ->numeric()
                        ->default(0)
                        ->minValue(0),

                    Textarea::make('short_description')
                        ->label('Mô tả ngắn')
                        ->placeholder('Mô tả ngắn về điểm đặc biệt này')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->minItems(1)
                ->defaultItems(1)
                ->addActionLabel('+ Thêm điểm đặc biệt')
                ->reorderable()
                ->columnSpanFull(),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $productId = $data['product_id'];
        $specials  = $data['specials'] ?? [];

        $lastCreated = null;

        foreach ($specials as $item) {
            $record                    = new RoomSpecial();
            $record->product_id        = $productId;
            $record->title             = $item['title'];
            $record->icon              = $item['icon'] ?? null;
            $record->short_description = $item['short_description'] ?? null;
            $record->sort_order        = (int) ($item['sort_order'] ?? 0);
            $record->save();
            $lastCreated = $record;
        }

        return $lastCreated ?? new RoomSpecial(['product_id' => $productId]);
    }
}
