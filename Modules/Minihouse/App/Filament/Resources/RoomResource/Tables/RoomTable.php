<?php

namespace Modules\Minihouse\App\Filament\Resources\RoomResource\Tables;

use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Support\Money;

class RoomTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('photos')
                    ->label('Ảnh')
                    ->getStateUsing(fn (Room $record) => $record->photos[0] ?? null)
                    ->circular(),
                TextColumn::make('code')->label('Mã / Tên phòng')->searchable()->sortable(),
                TextColumn::make('floor')->label('Tầng')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('position_row')->label('Hàng')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('position_col')->label('Cột')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('building.name')->label('Toà nhà')->searchable()->sortable(),
                TextColumn::make('area')->label('Diện tích')->suffix(' m²')->sortable(),
                TextColumn::make('price')->label('Giá thuê')->formatStateUsing(fn ($state) => Money::format($state))->sortable(),
                TextColumn::make('status')->label('Tình trạng')->badge()->formatStateUsing(fn (string $state) => match ($state) {
                    Room::STATUS_EMPTY    => 'Trống',
                    Room::STATUS_RESERVED => 'Đã đặt cọc',
                    Room::STATUS_RENTED   => 'Đã thuê',
                    Room::STATUS_REPAIR   => 'Đã khoá',
                    default => $state,
                })->color(fn (string $state) => match ($state) {
                    Room::STATUS_EMPTY    => 'success',
                    Room::STATUS_RESERVED => 'info',
                    Room::STATUS_RENTED   => 'warning',
                    Room::STATUS_REPAIR   => 'danger',
                    default => 'gray',
                }),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('building_id')
                    ->label('Toà nhà')
                    ->options(fn () => Building::query()->pluck('name', 'id')),
                SelectFilter::make('status')
                    ->label('Tình trạng')
                    ->options([
                        Room::STATUS_EMPTY    => 'Trống',
                        Room::STATUS_RESERVED => 'Đã đặt cọc',
                        Room::STATUS_RENTED   => 'Đã thuê',
                        Room::STATUS_REPAIR   => 'Đã khoá',
                    ]),
            ])
            ->actions([
                // Đưa link công khai (xem TenantFeedbackController) để dán QR trong phòng hoặc gửi
                // khách qua Zalo lúc bàn giao — không cần đăng nhập, khách chấm sao + góp ý trực tiếp.
                Action::make('feedbackLink')
                    ->label('Link phản hồi')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('gray')
                    ->action(function (Room $record) {
                        Notification::make()
                            ->title('Link phản hồi phòng ' . $record->code)
                            ->body(route('minihouse.feedback.create', ['room' => $record->id]))
                            ->persistent()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->searchable()
            ->paginated([10, 25, 50, 100]);
    }
}
