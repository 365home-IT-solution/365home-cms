<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\Tables;

use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class ThemeTable
{
    private const NOTIFICATION_MESSAGES = [
        'success' => 'Kích hoạt theme thành công !',
        'error' => 'Có lỗi xảy ra, vui lòng thử lại !',
    ];

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                self::themeName(),
                self::themeDescription(),
                self::themeIsActive()
            ])
            ->actionsColumnLabel('Hành động')
            ->defaultSort('updated_at', 'desc')
            ->actions([self::configAction()]);
    }

    private static function configAction(): Action
    {
        return Action::make('config')
            ->label('Cấu hình')
            ->icon('heroicon-o-cog')
            ->color('primary')
            ->visible(fn($record) => $record->is_active)
            ->url(fn($record) => route('filament.admin.resources.themes.edit', $record));
    }

    private static function themeIsActive(): ToggleColumn
    {
        return ToggleColumn::make('is_active')
            ->label('Trạng thái')
            ->disabled(fn($record) => $record->is_active)
            ->afterStateUpdated(function ($record, $state) {
                if (!$state) return;

                DB::beginTransaction();

                try {
                    self::deactivateOtherThemes($record);
                    self::activateTheme($record);

                    DB::commit();
                    self::sendNotification('success');
                } catch (\Exception $e) {
                    DB::rollBack();
                    self::sendNotification('error');
                }
            });
    }

    private static function themeName(): TextColumn
    {
        return TextColumn::make('name')
            ->label('Theme name')
            ->searchable()
            ->sortable();
    }

    private static function themeDescription(): TextColumn
    {
        return TextColumn::make('description')
            ->label('Mô tả')
            ->wrap();
    }

    private static function deactivateOtherThemes($record): void
    {
        $record->query()
            ->where('id', '!=', $record->id)
            ->update(['is_active' => false]);
    }

    private static function activateTheme($record): void
    {
        $record->update(['is_active' => true]);
    }

    private static function sendNotification(string $type): void
    {
        Notification::make()
            ->title(self::NOTIFICATION_MESSAGES[$type])
            ->{$type}()
            ->send();
    }
}
