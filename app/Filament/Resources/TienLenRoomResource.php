<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TienLenRoomResource\Pages;
use App\Models\TienLenRoom;
use App\Models\TienLenRoomSeat;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

// Phòng Tiến lên nhiều người (chỉ xem, chỉ Super Admin) — cùng dữ liệu với /api/admin/games/tien-len/rooms.
class TienLenRoomResource extends Resource
{
    protected static ?string $model = TienLenRoom::class;

    protected static ?string $navigationIcon   = 'heroicon-o-user-group';
    protected static ?string $navigationGroup  = 'Quản lý API';
    protected static ?string $navigationLabel  = 'Tiến lên — Phòng';
    protected static ?string $modelLabel       = 'Phòng Tiến lên';
    protected static ?string $pluralModelLabel = 'Phòng Tiến lên';
    protected static ?string $slug             = 'game-tien-len-rooms';
    protected static ?int    $navigationSort   = 35;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['host', 'seats.customer']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Tạo lúc')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('code')->label('Mã phòng')->searchable()->copyable()->fontFamily('mono'),
                TextColumn::make('host.fullname')->label('Chủ phòng')->searchable(),
                TextColumn::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn (string $state) => TienLenRoom::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => [TienLenRoom::STATUS_WAITING => 'warning', TienLenRoom::STATUS_PLAYING => 'success'][$state] ?? 'gray'),
                IconColumn::make('is_public')->label('Công khai')->boolean(),
                TextColumn::make('seats_summary')->label('Ghế')->wrap()
                    ->state(fn (TienLenRoom $r) => $r->seats->count() . '/' . $r->max_players . ': '
                        . ($r->seats->map(fn (TienLenRoomSeat $s) => $s->is_bot ? 'Máy ' . ($s->seat + 1) : ($s->customer?->fullname ?? 'Khách'))->join(', ') ?: '—')),
                TextColumn::make('updated_at')->label('Cập nhật')->since(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Trạng thái')->options(TienLenRoom::STATUS_LABELS),
            ])
            ->actions([
                // Ván đang chơi / ván vừa xong của phòng
                Action::make('match')->label('Xem ván')->icon('heroicon-o-eye')
                    ->visible(fn (TienLenRoom $r) => (bool) ($r->current_match_id ?? $r->last_match_id))
                    ->url(fn (TienLenRoom $r) => TienLenMatchResource::getUrl('view', ['record' => $r->current_match_id ?? $r->last_match_id])),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTienLenRooms::route('/')];
    }
}
