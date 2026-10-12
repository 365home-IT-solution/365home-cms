<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AppErrorResource\Pages;
use App\Models\AppError;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

// LỖI APP TỰ BÁO trên trang quản trị web (cùng dữ liệu với API /api/admin/app-errors — bảng `app_errors`, ghi bởi App\Http\Controllers\Api\AppErrorReportController):
// mỗi dòng là một lỗi đã gộp, phân theo mục nghiệp vụ. CHỈ Super Admin thấy — hard-code quyền, không dùng permission Shield, để không role nào khác được cấp nhầm.
class AppErrorResource extends Resource
{
    protected static ?string $model = AppError::class;

    protected static ?string $navigationIcon = 'heroicon-o-bug-ant';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Lỗi app';

    protected static ?string $modelLabel = 'Lỗi app';

    protected static ?string $pluralModelLabel = 'Lỗi app';

    protected static ?string $slug = 'app-errors';

    protected static ?int $navigationSort = 90;

    public static function getNavigationBadge(): ?string
    {
        $open = AppError::whereNull('resolved_at')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Lỗi')->state(fn (AppError $record) => $record->title())->weight('bold')->wrap()
                    ->description(fn (AppError $record) => $record->isApi() ? $record->message : $record->route)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $q) => $q
                        ->where('endpoint', 'like', "%{$search}%")->orWhere('message', 'like', "%{$search}%")->orWhere('route', 'like', "%{$search}%"))),
                Tables\Columns\TextColumn::make('module')->label('Mục')->badge()->color('gray')->formatStateUsing(fn (string $state) => AppError::MODULES[$state] ?? $state),
                Tables\Columns\TextColumn::make('kind')->label('Loại')->badge()->color(fn (string $state) => $state === 'api' ? 'warning' : 'danger'),
                Tables\Columns\TextColumn::make('occurrences')->label('Số lần')->numeric()->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('reporter')->label('Người gặp gần nhất')->wrap()->toggleable(),
                Tables\Columns\TextColumn::make('last_seen_at')->label('Lần cuối')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('first_seen_at')->label('Lần đầu')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('resolved_at')->label('Trạng thái')->badge()
                    ->state(fn (AppError $record) => $record->resolved_at ? 'Đã xử lý' : 'Đang mở')
                    ->color(fn (string $state) => $state === 'Đã xử lý' ? 'success' : 'danger'),
            ])
            ->filters([
                // Mặc định chỉ hiện lỗi đang mở, như GET /api/admin/app-errors.
                Tables\Filters\SelectFilter::make('status')->label('Trạng thái')->options(['open' => 'Đang mở', 'resolved' => 'Đã xử lý'])->default('open')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'open'     => $query->whereNull('resolved_at'),
                        'resolved' => $query->whereNotNull('resolved_at'),
                        default    => $query,
                    }),
                Tables\Filters\SelectFilter::make('module')->label('Mục')->options(AppError::MODULES),
                Tables\Filters\SelectFilter::make('kind')->label('Loại')->options(fn () => AppError::query()->distinct()->orderBy('kind')->pluck('kind', 'kind')->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Chi tiết'),
                Tables\Actions\Action::make('resolve')
                    ->label('Đã xử lý')->icon('heroicon-o-check-circle')->color('success')->requiresConfirmation()
                    ->modalDescription('Lỗi sẽ rời khỏi danh sách đang mở. Nếu app báo lại đúng lỗi này, nó tự mở lại và Super Admin nhận push.')
                    ->visible(fn (AppError $record) => $record->resolved_at === null)
                    ->action(function (AppError $record) {
                        $record->update(['resolved_at' => now()]);
                        Notification::make()->success()->title('Đã đánh dấu lỗi là đã xử lý.')->send();
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('resolveMany')
                    ->label('Đánh dấu đã xử lý')->icon('heroicon-o-check-circle')->color('success')->requiresConfirmation()->deselectRecordsAfterCompletion()
                    ->action(function ($records) {
                        AppError::whereKey($records->modelKeys())->whereNull('resolved_at')->update(['resolved_at' => now()]);
                        Notification::make()->success()->title('Đã đánh dấu các lỗi đã chọn là đã xử lý.')->send();
                    }),
                Tables\Actions\DeleteBulkAction::make(),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(3)->schema([
                TextEntry::make('title')->label('Lỗi')->state(fn (AppError $record) => $record->title())->weight('bold')->columnSpanFull(),
                TextEntry::make('module')->label('Mục')->badge()->color('gray')->formatStateUsing(fn (string $state) => AppError::MODULES[$state] ?? $state),
                TextEntry::make('kind')->label('Loại')->badge(),
                TextEntry::make('area')->label('Khu vực app')->placeholder('—'),
                TextEntry::make('occurrences')->label('Số lần gặp')->numeric(),
                TextEntry::make('first_seen_at')->label('Lần đầu')->dateTime('d/m/Y H:i'),
                TextEntry::make('last_seen_at')->label('Lần cuối')->dateTime('d/m/Y H:i'),
            ]),
            Section::make('Lần báo gần nhất')->columns(2)->schema([
                TextEntry::make('reporter')->label('Người gặp')->placeholder('—'),
                TextEntry::make('app')->label('App')->placeholder('—')
                    ->state(fn (AppError $record) => $record->app
                        ? trim(($record->app['platform'] ?? '?') . ' ' . ($record->app['os_version'] ?? '') . ' · v' . ($record->app['version'] ?? '?') . ' · ' . ($record->app['channel'] ?? '?'))
                        : null),
                TextEntry::make('route')->label('Màn hình')->placeholder('—')->copyable(),
                TextEntry::make('url')->label('URL đã gọi')->placeholder('—')->copyable(),
                TextEntry::make('message')->label('Thông điệp')->placeholder('—')->columnSpanFull(),
                TextEntry::make('detail')->label('Chi tiết (body response lỗi hoặc stack trace)')->placeholder('—')->fontFamily('mono')->copyable()->columnSpanFull()
                    ->extraAttributes(['style' => 'white-space: pre-wrap; word-break: break-word;']),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAppErrors::route('/')];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
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
        return static::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny();
    }
}
