<?php

declare(strict_types=1);

namespace Modules\Motrak\App\Filament\Resources\MotrakVehicleResource\RelationManagers;

use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Motrak\App\Models\MotrakVehicleLocation;

// Lịch sử vị trí ĐÃ LƯU ở 365home — không phải lịch sử đầy đủ từ Motrak (endpoint chỉ trả điểm mới nhất; xe đứng yên được lưu thưa).
class LocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'locations';

    protected static ?string $title = 'Lịch sử vị trí đã lưu';

    protected static ?string $modelLabel = 'điểm GPS';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Chỉ gồm các điểm 365Home đã đồng bộ và lưu theo chính sách lưu trữ — KHÔNG phải lịch sử đầy đủ từ Motrak.')
            ->defaultSort('gps_time', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('gps_time')->label('Giờ GPS')->dateTime('H:i:s d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('speed_kmh')->label('Tốc độ')->suffix(' km/h')->placeholder('—'),
                Tables\Columns\TextColumn::make('latitude')->label('Vĩ độ')->formatStateUsing(fn ($state) => number_format($state, 6, '.', '')),
                Tables\Columns\TextColumn::make('longitude')->label('Kinh độ')->formatStateUsing(fn ($state) => number_format($state, 6, '.', '')),
                Tables\Columns\TextColumn::make('address')->label('Địa chỉ')->limit(40)->tooltip(fn (MotrakVehicleLocation $r) => $r->address)->placeholder('—'),
                Tables\Columns\TextColumn::make('administrative_area')->label('Khu vực')->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\Filter::make('range')->label('Khoảng thời gian')
                    ->form([
                        Forms\Components\DateTimePicker::make('from')->label('Từ')->seconds(false),
                        Forms\Components\DateTimePicker::make('until')->label('Đến')->seconds(false),
                    ])->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($w, $v) => $w->where('gps_time', '>=', $v))
                        ->when($data['until'] ?? null, fn ($w, $v) => $w->where('gps_time', '<=', $v))),
            ])
            ->actions([
                Tables\Actions\Action::make('googleMaps')->label('Google Maps')->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (MotrakVehicleLocation $r) => $r->googleMapsUrl(), shouldOpenInNewTab: true),
            ])
            ->paginated([10, 25, 50, 100]);
    }
}
