<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomServiceResource\Pages;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Product\App\Filament\Resources\RoomServiceResource;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomService;

class CreateRoomService extends CreateRecord
{
    protected static string $resource = RoomServiceResource::class;

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('product_id')
                ->label('Phòng')
                ->options(fn () => Product::where('is_activated', true)->orderBy('name')->pluck('name', 'id')->toArray())
                ->searchable()
                ->required()
                ->columnSpanFull(),

            Repeater::make('services')
                ->label('Danh sách dịch vụ')
                ->schema([
                    TextInput::make('name')
                        ->label('Tên dịch vụ')
                        ->placeholder('VD: Coffee, Nước suối, Khăn tắm thêm')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),

                    TextInput::make('icon')
                        ->label('Icon name')
                        ->placeholder('VD: coffee, water, towel')
                        ->maxLength(150),

                    TextInput::make('price')
                        ->label('Giá')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->suffix('VND'),

                    TextInput::make('unit')
                        ->label('Đơn vị')
                        ->placeholder('VD: người/ngày, lần, ly')
                        ->maxLength(100),

                    TextInput::make('sort_order')
                        ->label('Thứ tự')
                        ->numeric()
                        ->default(0)
                        ->minValue(0),

                    Textarea::make('description')
                        ->label('Mô tả')
                        ->placeholder('Mô tả chi tiết dịch vụ')
                        ->rows(2)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->minItems(1)
                ->defaultItems(1)
                ->addActionLabel('+ Thêm dịch vụ')
                ->reorderable()
                ->columnSpanFull(),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $productId = $data['product_id'];
        $services  = $data['services'] ?? [];

        $lastCreated = null;

        foreach ($services as $item) {
            $record              = new RoomService();
            $record->product_id  = $productId;
            $record->name        = $item['name'];
            $record->icon        = $item['icon'] ?? null;
            $record->description = $item['description'] ?? null;
            $record->price       = (int) ($item['price'] ?? 0);
            $record->unit        = $item['unit'] ?? null;
            $record->sort_order  = (int) ($item['sort_order'] ?? 0);
            $record->save();
            $lastCreated = $record;
        }

        return $lastCreated ?? new RoomService(['product_id' => $productId]);
    }
}
