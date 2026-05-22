<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomAmenityResource\Pages;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Product\App\Filament\Resources\RoomAmenityResource;
use Modules\Product\App\Models\RoomAmenity;

class CreateRoomAmenity extends CreateRecord
{
    protected static string $resource = RoomAmenityResource::class;

    public function form(Form $form): Form
    {
        return $form->schema([
            Repeater::make('amenities')
                ->label('Danh sách tiện ích')
                ->schema([
                    TextInput::make('amenity_type')
                        ->label('Nhóm tiện ích')
                        ->placeholder('VD: Phòng tắm, Tiện nghi, Giải trí')
                        ->required()
                        ->maxLength(100),

                    TextInput::make('name')
                        ->label('Tên tiện ích')
                        ->placeholder('VD: Máy sấy tóc, WiFi, TV')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('icon')
                        ->label('Icon name')
                        ->placeholder('VD: cut, wifi, tv')
                        ->maxLength(150),

                    TextInput::make('sort_order')
                        ->label('Thứ tự')
                        ->numeric()
                        ->default(0)
                        ->minValue(0),

                    Toggle::make('status')
                        ->label('Kích hoạt')
                        ->default(true)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->minItems(1)
                ->defaultItems(1)
                ->addActionLabel('+ Thêm tiện ích')
                ->reorderable()
                ->columnSpanFull(),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $lastCreated = null;

        foreach ($data['amenities'] ?? [] as $item) {
            $record               = new RoomAmenity();
            $record->amenity_type = $item['amenity_type'];
            $record->name         = $item['name'];
            $record->icon         = $item['icon'] ?? null;
            $record->sort_order   = (int) ($item['sort_order'] ?? 0);
            $record->status       = (bool) ($item['status'] ?? true);
            $record->save();
            $lastCreated = $record;
        }

        return $lastCreated ?? new RoomAmenity();
    }
}
