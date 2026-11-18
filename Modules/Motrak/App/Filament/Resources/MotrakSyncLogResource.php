<?php

declare(strict_types=1);

namespace Modules\Motrak\App\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Motrak\App\Filament\Resources\MotrakSyncLogResource\Pages;
use Modules\Motrak\App\Models\MotrakSyncLog;
use Modules\Motrak\App\Support\MotrakAccess;

// Nhật ký đồng bộ (chỉ xem): mỗi lần gọi API Motrak — đồng bộ tay, tự động, kiểm tra kết nối. Không bao giờ chứa Token.
class MotrakSyncLogResource extends Resource
{
    protected static ?string $model = MotrakSyncLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Nhật ký đồng bộ GPS';

    protected static ?string $modelLabel = 'Lần đồng bộ';

    protected static ?string $pluralModelLabel = 'Nhật ký đồng bộ';

    protected static ?string $slug = 'gps-sync-logs';

    protected static ?int $navigationSort = 8;

    public const TYPES = ['manual' => 'Đồng bộ tay', 'scheduled' => 'Tự động', 'test' => 'Kiểm tra kết nối'];

    public const STATUSES = ['success' => 'Thành công', 'partial' => 'Một phần', 'failed' => 'Thất bại', 'skipped' => 'Bỏ qua'];

    public static function canViewAny(): bool
    {
        return MotrakAccess::canView(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->poll('30s')
            ->columns([
                Tables\Columns\TextColumn::make('started_at')->label('Bắt đầu')->dateTime('H:i:s d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('sync_type')->label('Loại')->badge()->formatStateUsing(fn ($state) => self::TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('status')->label('Kết quả')->badge()->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'success' => 'success', 'partial' => 'warning', 'failed' => 'danger', default => 'gray' }),
                Tables\Columns\TextColumn::make('total_vehicles')->label('Xe nhận được'),
                Tables\Columns\TextColumn::make('success_count')->label('Lưu được'),
                Tables\Columns\TextColumn::make('failed_count')->label('Lỗi'),
                Tables\Columns\TextColumn::make('new_locations')->label('Điểm mới'),
                Tables\Columns\TextColumn::make('http_status')->label('HTTP')->placeholder('—'),
                Tables\Columns\TextColumn::make('error_type')->label('Loại lỗi')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('error_message')->label('Ghi chú / lỗi')->limit(60)->tooltip(fn (MotrakSyncLog $r) => $r->error_message)->placeholder('—'),
                Tables\Columns\TextColumn::make('duration')->label('Thời gian')->state(fn (MotrakSyncLog $r) => $r->durationMs() === null ? '—' : $r->durationMs() . ' ms')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('sync_type')->label('Loại')->options(self::TYPES),
                Tables\Filters\SelectFilter::make('status')->label('Kết quả')->options(self::STATUSES),
            ])
            ->emptyStateHeading('Chưa có lần đồng bộ nào');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListMotrakSyncLogs::route('/')];
    }
}
