<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomAmenityAssignResource\Pages;

use App\Filament\Support\PartnerTableHelpers;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Modules\Product\App\Filament\Resources\RoomAmenityAssignResource;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomAmenity;
use Modules\Product\App\Models\RoomAmenityAssign;

class ListRoomAmenityAssign extends ListRecords
{
    protected static string $resource = RoomAmenityAssignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('assign_amenities')
                ->label('Gán tiện ích cho phòng')
                ->icon('heroicon-o-plus-circle')
                ->modalWidth('3xl')
                ->form(function (): array {
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

                    return array_merge([
                        Select::make('room_id')
                            ->label('Phòng')
                            ->options(
                                Product::where('is_activated', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                            )
                            ->searchable()
                            ->required()
                            ->columnSpanFull(),
                    ], $sections);
                })
                ->action(function (array $data): void {
                    $roomId = $data['room_id'];

                    $amenitiesByGroup = RoomAmenity::where('status', true)
                        ->get()
                        ->groupBy('amenity_type');

                    $selectedIds = [];
                    foreach ($amenitiesByGroup as $group => $items) {
                        $key         = 'amenity_ids_' . Str::slug($group, '_');
                        $selectedIds = array_merge($selectedIds, $data[$key] ?? []);
                    }

                    RoomAmenityAssign::where('room_id', $roomId)->delete();

                    foreach ($selectedIds as $amenityId) {
                        RoomAmenityAssign::create([
                            'room_id'    => $roomId,
                            'amenity_id' => (int) $amenityId,
                        ]);
                    }

                    Notification::make()
                        ->title('Gán tiện ích thành công')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Product::query()
                    ->where('is_activated', true)
                    ->orderBy('name')
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Tên phòng')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('amenity_assigns_count')
                    ->label('Số tiện ích')
                    ->counts('amenityAssigns')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),

                TextColumn::make('amenities_summary')
                    ->label('Tiện ích đã gán')
                    ->getStateUsing(function ($record): string {
                        $names = RoomAmenityAssign::where('room_id', $record->id)
                            ->join('room_amenities', 'room_amenities.id', '=', 'room_amenity_assigns.amenity_id')
                            ->orderBy('room_amenities.amenity_type')
                            ->orderBy('room_amenities.sort_order')
                            ->pluck('room_amenities.name')
                            ->toArray();

                        if (empty($names)) {
                            return '—';
                        }

                        return implode(', ', array_slice($names, 0, 5))
                            . (count($names) > 5 ? ' +' . (count($names) - 5) . ' nữa' : '');
                    })
                    ->wrap(),
                PartnerTableHelpers::column(),
            ])
            ->filters([
                PartnerTableHelpers::filter(),
            ]);
    }
}
