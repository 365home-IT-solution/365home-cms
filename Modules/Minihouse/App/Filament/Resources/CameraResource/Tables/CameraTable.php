<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\CameraResource\Tables;

use App\Services\CameraSourceManager;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Modules\Minihouse\App\Models\Camera;

// Mirror ĐÚNG App\Filament\Resources\CameraResource::table() (Home), chỉ đổi cột "Chi nhánh" thành
// "Toà nhà" — cùng dùng quan hệ branch() có sẵn trên Camera (BelongsTo Category, MiniHouse Building
// CŨNG LÀ 1 Category nên quan hệ này hoạt động đúng không cần sửa gì ở model).
class CameraTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Tên camera')->searchable(),
                TextColumn::make('stream_key')->label('Stream key')->fontFamily('mono'),
                TextColumn::make('source_type')->label('Nguồn')->badge(),
                TextColumn::make('connection_status')->label('Kết nối')->badge(),
                TextColumn::make('branch.name')->label('Toà nhà')->toggleable(),
                ToggleColumn::make('status')->label('Hoạt động'),
                TextColumn::make('created_at')->label('Tạo lúc')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->actions([
                Action::make('syncSource')->label('Đồng bộ nguồn')->icon('heroicon-o-arrow-path')
                    ->visible(fn (Camera $record): bool => $record->supportsCapability('source_sync'))
                    ->action(function (Camera $record): void {
                        $error = app(CameraSourceManager::class)->sync($record);
                        Notification::make()->title($error ? 'Chưa đồng bộ được nguồn' : 'Đã đồng bộ nguồn với go2rtc')
                            ->body($error)->status($error ? 'warning' : 'success')->send();
                    }),
                Action::make('healthCheck')->label('Kiểm tra')->icon('heroicon-o-signal')
                    ->action(function (Camera $record): void {
                        $result = app(CameraSourceManager::class)->health($record);
                        Notification::make()->title($result['message'])
                            ->status($result['online'] ? 'success' : 'warning')->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }
}
