<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomAmenityResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Product\App\Filament\Resources\RoomAmenityResource;
use Modules\Product\App\Models\RoomAmenity;

class EditRoomAmenity extends EditRecord
{
    protected static string $resource = RoomAmenityResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Placeholder::make('amenity_type_display')
                ->label('Nhóm tiện ích')
                ->content(fn () => $this->getRecord()?->amenity_type ?? '—')
                ->columnSpanFull(),

            Repeater::make('amenities')
                ->label('Danh sách tiện ích')
                ->schema([
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
                ->addActionLabel('+ Thêm tiện ích')
                ->reorderable()
                ->columnSpanFull(),
        ]);
    }

    protected function fillForm(): void
    {
        $record = $this->getRecord();

        $amenities = RoomAmenity::where('amenity_type', $record->amenity_type)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($a) => [
                'name'       => $a->name,
                'icon'       => $a->icon,
                'sort_order' => $a->sort_order,
                'status'     => $a->status,
            ])
            ->toArray();

        $this->form->fill([
            'amenity_type_display' => $record->amenity_type,
            'amenities'            => $amenities,
        ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $amenityType = $record->amenity_type;

        RoomAmenity::where('amenity_type', $amenityType)->delete();

        foreach ($data['amenities'] ?? [] as $item) {
            RoomAmenity::create([
                'amenity_type' => $amenityType,
                'name'         => $item['name'],
                'icon'         => $item['icon'] ?? null,
                'sort_order'   => (int) ($item['sort_order'] ?? 0),
                'status'       => (bool) ($item['status'] ?? true),
            ]);
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
