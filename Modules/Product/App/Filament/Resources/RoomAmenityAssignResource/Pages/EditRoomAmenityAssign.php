<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomAmenityAssignResource\Pages;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Product\App\Filament\Resources\RoomAmenityAssignResource;
use Modules\Product\App\Models\RoomAmenity;
use Modules\Product\App\Models\RoomAmenityAssign;

class EditRoomAmenityAssign extends EditRecord
{
    protected static string $resource = RoomAmenityAssignResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function form(Form $form): Form
    {
        $amenitiesByGroup = RoomAmenity::where('status', true)
            ->orderBy('amenity_type')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('amenity_type');

        $sections = $amenitiesByGroup->map(function ($items, $group) {
            return Section::make($group)
                ->schema([
                    CheckboxList::make('amenity_ids_' . Str::slug($group, '_'))
                        ->label('')
                        ->options($items->pluck('name', 'id')->toArray())
                        ->columns(3)
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->columnSpanFull();
        })->values()->toArray();

        return $form->schema(array_merge([
            Placeholder::make('product_name')
                ->label('Phòng')
                ->content(fn () => $this->getRecord()?->name ?? '—')
                ->columnSpanFull(),
        ], $sections));
    }

    protected function fillForm(): void
    {
        $record = $this->getRecord();

        $assignedIds = RoomAmenityAssign::where('room_id', $record->id)
            ->pluck('amenity_id')
            ->toArray();

        $amenitiesByGroup = RoomAmenity::where('status', true)
            ->orderBy('amenity_type')
            ->get()
            ->groupBy('amenity_type');

        $data = ['product_name' => $record->name];

        foreach ($amenitiesByGroup as $group => $items) {
            $key        = 'amenity_ids_' . Str::slug($group, '_');
            $data[$key] = array_values(array_map('strval', array_intersect($assignedIds, $items->pluck('id')->toArray())));
        }

        $this->form->fill($data);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $amenitiesByGroup = RoomAmenity::where('status', true)
            ->get()
            ->groupBy('amenity_type');

        $selectedIds = [];

        foreach ($amenitiesByGroup as $group => $items) {
            $key         = 'amenity_ids_' . Str::slug($group, '_');
            $selectedIds = array_merge($selectedIds, $data[$key] ?? []);
        }

        RoomAmenityAssign::where('room_id', $record->id)->delete();

        foreach ($selectedIds as $amenityId) {
            RoomAmenityAssign::create([
                'room_id'    => $record->id,
                'amenity_id' => $amenityId,
            ]);
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
