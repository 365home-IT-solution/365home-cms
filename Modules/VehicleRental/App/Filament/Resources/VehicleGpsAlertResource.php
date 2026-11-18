<?php

declare(strict_types=1);

namespace Modules\VehicleRental\App\Filament\Resources;

use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\VehicleRental\App\Filament\Resources\VehicleGpsAlertResource\Pages;
use Modules\VehicleRental\App\Models\VehicleGpsAlert;
use Modules\VehicleRental\App\Services\VehicleGpsAlertService;
use Modules\VehicleRental\App\Support\VehicleRentalAccess;

// Cảnh báo GPS của xe thuê (ra khỏi khu vực, vượt tốc độ, chạy khi không có đơn, mất tín hiệu khi đang thuê, trả xe xa chi nhánh, lệch km).
// Super Admin + vai trò "Nhân viên thuê xe". Danh sách mặc định chỉ hiện cảnh báo CHƯA xử lý.
class VehicleGpsAlertResource extends Resource
{
    protected static ?string $model = VehicleGpsAlert::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Cảnh báo GPS';

    protected static ?string $modelLabel = 'Cảnh báo GPS';

    protected static ?string $pluralModelLabel = 'Cảnh báo GPS';

    protected static ?string $slug = 'rental-vehicle-gps-alerts';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return VehicleRentalAccess::canViewGpsAlerts(auth()->user());
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

    public static function getNavigationBadge(): ?string
    {
        $n = VehicleGpsAlert::query()->whereNull('acknowledged_at')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['vehicle', 'booking']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('30s')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Lúc')->dateTime('H:i d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('type')->label('Loại')->badge()->formatStateUsing(fn ($state) => VehicleGpsAlert::TYPES[$state] ?? $state)
                    ->color(fn (VehicleGpsAlert $r) => $r->severity === 'danger' ? 'danger' : 'warning'),
                Tables\Columns\TextColumn::make('vehicle.plate_display')->label('Xe')->searchable(['plate', 'plate_display'])
                    ->description(fn (VehicleGpsAlert $r) => trim(($r->vehicle?->brand ?? '') . ' ' . ($r->vehicle?->model ?? ''))),
                Tables\Columns\TextColumn::make('booking.code')->label('Đơn')->placeholder('—')
                    ->url(fn (VehicleGpsAlert $r) => $r->booking ? VehicleBookingResource::getUrl('view', ['record' => $r->booking->id]) : null),
                Tables\Columns\TextColumn::make('message')->label('Nội dung')->wrap()->limit(160)->tooltip(fn (VehicleGpsAlert $r) => $r->message),
                Tables\Columns\TextColumn::make('acknowledged_at')->label('Xử lý')->state(fn (VehicleGpsAlert $r) => $r->isOpen() ? 'Chưa xử lý' : 'Đã xử lý')
                    ->badge()->color(fn ($state) => $state === 'Chưa xử lý' ? 'danger' : 'gray'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Trạng thái')->default('open')
                    ->options(['open' => 'Chưa xử lý', 'acknowledged' => 'Đã xử lý'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'open'         => $query->whereNull('acknowledged_at'),
                        'acknowledged' => $query->whereNotNull('acknowledged_at'),
                        default        => $query,
                    }),
                Tables\Filters\SelectFilter::make('type')->label('Loại')->options(VehicleGpsAlert::TYPES),
            ])
            ->actions([
                Tables\Actions\Action::make('ack')->label('Đã xử lý')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (VehicleGpsAlert $r) => $r->isOpen())
                    ->requiresConfirmation()
                    ->action(function (VehicleGpsAlert $record) {
                        app(VehicleGpsAlertService::class)->acknowledge($record, (string) auth()->id());
                        Notification::make()->success()->title('Đã đánh dấu xử lý')->send();
                    }),
            ])
            ->emptyStateHeading('Không có cảnh báo GPS nào');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListVehicleGpsAlerts::route('/')];
    }
}
