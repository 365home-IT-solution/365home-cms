<?php

declare(strict_types=1);

namespace Modules\Motrak\App\Filament\Resources;

use DomainException;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Motrak\App\Filament\Resources\MotrakVehicleResource\Pages;
use Modules\Motrak\App\Filament\Resources\MotrakVehicleResource\RelationManagers\LocationsRelationManager;
use Modules\Motrak\App\Models\MotrakVehicle;
use Modules\Motrak\App\Services\MotrakMappingService;
use Modules\Motrak\App\Services\MotrakVehicleQuery;
use Modules\Motrak\App\Support\MotrakAccess;
use Modules\Motrak\App\Support\MotrakTransformer;

// Danh sách phương tiện GPS (chỉ đọc dữ liệu đã đồng bộ về DB). Xem: Super Admin + vai trò "Quản lý GPS"; ghép IMEI / xe cho thuê: chỉ Super Admin.
class MotrakVehicleResource extends Resource
{
    protected static ?string $model = MotrakVehicle::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Thiết bị GPS';

    protected static ?string $modelLabel = 'Phương tiện GPS';

    protected static ?string $pluralModelLabel = 'Phương tiện GPS';

    protected static ?string $slug = 'gps-vehicles';

    protected static ?int $navigationSort = 7;

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

    /** id xe cho thuê → biển số (1 truy vấn cho cả trang bảng). */
    private static function rentalPlates(): array
    {
        static $cache = null;
        if ($cache === null) {
            $ids = MotrakVehicle::query()->whereNotNull('rental_vehicle_id')->pluck('rental_vehicle_id')->all();
            $cache = $ids && Schema::hasTable('rental_vehicles') ? DB::table('rental_vehicles')->whereIn('id', $ids)->pluck('plate_display', 'id')->all() : [];
        }

        return $cache;
    }

    public static function table(Table $table): Table
    {
        $stateColor = fn (MotrakVehicle $r) => match ($r->connectionState()) {
            MotrakVehicle::STATE_CONNECTED    => 'success',
            MotrakVehicle::STATE_DISCONNECTED => 'danger',
            MotrakVehicle::STATE_STALE        => 'warning',
            default                           => 'gray',
        };

        return $table
            ->defaultSort('plate')
            ->poll('30s')
            ->columns([
                Tables\Columns\TextColumn::make('plate')->label('Biển số')->weight('bold')
                    ->searchable(query: fn (Builder $query, string $search) => MotrakVehicleQuery::apply($query, ['q' => $search])),
                Tables\Columns\TextColumn::make('vehicle_id')->label('Vehicle ID')->copyable()->limit(10)->tooltip(fn (MotrakVehicle $r) => $r->vehicle_id)->toggleable(),
                Tables\Columns\TextColumn::make('imei')->label('IMEI')->placeholder('Chưa ánh xạ')->toggleable(),
                Tables\Columns\TextColumn::make('longitude')->label('Kinh độ')->formatStateUsing(fn ($state) => $state === null ? '—' : number_format($state, 6, '.', ''))->toggleable(),
                Tables\Columns\TextColumn::make('latitude')->label('Vĩ độ')->formatStateUsing(fn ($state) => $state === null ? '—' : number_format($state, 6, '.', ''))->toggleable(),
                Tables\Columns\TextColumn::make('speed_kmh')->label('Tốc độ')->suffix(' km/h')->placeholder('—'),
                Tables\Columns\TextColumn::make('heading')->label('Hướng')->formatStateUsing(fn ($state) => $state === null ? '—' : MotrakTransformer::headingLabel((int) $state) . " ({$state}°)")->toggleable(),
                Tables\Columns\TextColumn::make('address')->label('Địa chỉ')->limit(36)->tooltip(fn (MotrakVehicle $r) => $r->address)->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('moving')->label('Trạng thái GPS')->badge()
                    ->state(fn (MotrakVehicle $r) => match ($r->isMoving()) { true => 'Đang di chuyển', false => 'Đang dừng', default => 'Chưa rõ' })
                    ->color(fn ($state) => match ($state) { 'Đang di chuyển' => 'success', 'Đang dừng' => 'info', default => 'gray' }),
                Tables\Columns\TextColumn::make('connection')->label('Kết nối')->badge()
                    ->state(fn (MotrakVehicle $r) => $r->connectionLabel())->color($stateColor)
                    ->tooltip('isConnected là giá trị thô của Motrak (chưa xác minh quy tắc): "Mất kết nối" không đồng nghĩa xe offline hoàn toàn. "Dữ liệu quá cũ" = điểm GPS cũ hơn ' . (int) config('motrak.freshness.stale_after_minutes', 10) . ' phút.'),
                Tables\Columns\TextColumn::make('gps_time')->label('Cập nhật GPS')->since()->tooltip(fn (MotrakVehicle $r) => $r->gps_time?->format('H:i:s d/m/Y'))->placeholder('Không có dữ liệu')->sortable(),
                Tables\Columns\TextColumn::make('rental_vehicle_id')->label('Xe cho thuê')->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state ? (self::rentalPlates()[$state] ?? "#{$state}") : null)->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('state')->label('Kết nối')
                    ->options([
                        MotrakVehicle::STATE_CONNECTED    => 'Đang kết nối (dữ liệu mới, isConnected = true)',
                        MotrakVehicle::STATE_DISCONNECTED => 'Mất kết nối (dữ liệu mới, isConnected = false)',
                        MotrakVehicle::STATE_STALE        => 'Dữ liệu quá cũ',
                        MotrakVehicle::STATE_NO_DATA      => 'Không có dữ liệu',
                    ])->query(fn (Builder $query, array $data) => $data['value'] ? MotrakVehicleQuery::apply($query, ['state' => $data['value']]) : $query),
                Tables\Filters\SelectFilter::make('moving')->label('Di chuyển')
                    ->options(['moving' => 'Đang di chuyển', 'stopped' => 'Đang dừng'])
                    ->query(fn (Builder $query, array $data) => $data['value'] ? MotrakVehicleQuery::apply($query, ['moving' => $data['value']]) : $query),
                Tables\Filters\SelectFilter::make('updated')->label('Cập nhật trong')
                    ->options(['5' => '5 phút', '30' => '30 phút', '60' => '1 giờ', '1440' => '24 giờ'])
                    ->query(fn (Builder $query, array $data) => $data['value'] ? MotrakVehicleQuery::apply($query, ['updated_within_minutes' => (int) $data['value']]) : $query),
                Tables\Filters\SelectFilter::make('linked')->label('Xe cho thuê')
                    ->options(['yes' => 'Đã ghép', 'no' => 'Chưa ghép'])
                    ->query(fn (Builder $query, array $data) => $data['value'] ? MotrakVehicleQuery::apply($query, ['linked' => $data['value']]) : $query),
            ])
            ->actions([
                Tables\Actions\Action::make('map')->label('Bản đồ')->icon('heroicon-o-map')
                    ->visible(fn (MotrakVehicle $r) => $r->hasPosition())
                    ->modalHeading(fn (MotrakVehicle $r) => 'Vị trí ' . $r->plate)
                    ->modalContent(fn (MotrakVehicle $r) => view('motrak::partials.map', ['markers' => array_filter([MotrakTransformer::marker($r)]), 'height' => 420]))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Đóng')->modalWidth('4xl'),
                Tables\Actions\Action::make('googleMaps')->label('Google Maps')->icon('heroicon-o-arrow-top-right-on-square')
                    ->visible(fn (MotrakVehicle $r) => $r->hasPosition())
                    ->url(fn (MotrakVehicle $r) => $r->googleMapsUrl(), shouldOpenInNewTab: true),
                Tables\Actions\Action::make('mapping')->label('Ghép IMEI / xe thuê')->icon('heroicon-o-link')->color('gray')
                    ->visible(fn () => MotrakAccess::canConfigure(auth()->user()))
                    ->fillForm(fn (MotrakVehicle $r) => ['imei' => $r->imei, 'rental_vehicle_id' => $r->rental_vehicle_id])
                    ->form(fn (MotrakVehicle $r) => [
                        Forms\Components\TextInput::make('imei')->label('IMEI thiết bị')->maxLength(20)
                            ->helperText('14–16 chữ số. Để trống nếu chưa biết — hệ thống KHÔNG tự bịa IMEI và không dùng Vehicle ID thay IMEI.'),
                        Forms\Components\Select::make('rental_vehicle_id')->label('Xe cho thuê tương ứng')->native(false)->searchable()->placeholder('Không ghép')
                            ->options(fn () => app(MotrakMappingService::class)->rentalVehicleOptions($r->rental_vehicle_id)),
                    ])
                    ->action(function (array $data, MotrakVehicle $record) {
                        try {
                            app(MotrakMappingService::class)->update($record, $data['imei'] ?? null, isset($data['rental_vehicle_id']) ? (int) $data['rental_vehicle_id'] : null);
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }
                        Notification::make()->success()->title('Đã lưu ánh xạ')->send();
                    }),
                Tables\Actions\ViewAction::make()->label('Chi tiết'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('suggest')->label('Ghép xe thuê theo biển số')->icon('heroicon-o-sparkles')->color('gray')
                    ->visible(fn () => MotrakAccess::canConfigure(auth()->user()))
                    ->modalHeading('Gợi ý ghép theo biển số')
                    ->modalDescription('Chỉ gợi ý khi biển số (bỏ dấu cách/gạch/chấm) trùng ĐÚNG một xe cho thuê chưa ghép. Hãy tích chọn các cặp bạn xác nhận là đúng.')
                    ->form(fn () => [
                        Forms\Components\CheckboxList::make('ids')->label('Cặp gợi ý')
                            ->options(collect(app(MotrakMappingService::class)->suggestions())->mapWithKeys(fn ($s) => [$s['motrak_vehicle_id'] => "GPS {$s['motrak_plate']}  ↔  xe cho thuê {$s['rental_plate']}"])->all())
                            ->default([])->bulkToggleable()->columns(1)
                            ->helperText(fn () => app(MotrakMappingService::class)->suggestions() ? null : 'Không có gợi ý nào.'),
                    ])
                    ->action(function (array $data) {
                        $n = app(MotrakMappingService::class)->applySuggestions(array_map('intval', $data['ids'] ?? []));
                        Notification::make()->success()->title("Đã ghép {$n} xe")->send();
                    }),
            ])
            ->emptyStateHeading('Chưa có phương tiện nào')
            ->emptyStateDescription('Nhập Token ở "Cấu hình Motrak" rồi bấm "Đồng bộ ngay".');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Phương tiện')->columns(4)->schema([
                Infolists\Components\TextEntry::make('plate')->label('Biển số')->weight('bold'),
                Infolists\Components\TextEntry::make('vehicle_id')->label('Vehicle ID (Motrak)')->copyable(),
                Infolists\Components\TextEntry::make('imei')->label('IMEI')->placeholder('Chưa ánh xạ'),
                Infolists\Components\TextEntry::make('rental_vehicle_id')->label('Xe cho thuê')->placeholder('Chưa ghép')
                    ->formatStateUsing(fn ($state) => $state ? (self::rentalPlates()[$state] ?? "#{$state}") : null),
            ]),
            Infolists\Components\Section::make('Vị trí mới nhất')->columns(4)->schema([
                Infolists\Components\TextEntry::make('connection')->label('Kết nối')->badge()->state(fn (MotrakVehicle $r) => $r->connectionLabel())
                    ->color(fn (MotrakVehicle $r) => match ($r->connectionState()) { 'connected' => 'success', 'disconnected' => 'danger', 'stale' => 'warning', default => 'gray' }),
                Infolists\Components\TextEntry::make('gps_time')->label('Giờ GPS')->dateTime('H:i:s d/m/Y')->placeholder('Không có dữ liệu'),
                Infolists\Components\TextEntry::make('speed_kmh')->label('Tốc độ')->suffix(' km/h')->placeholder('—'),
                Infolists\Components\TextEntry::make('heading')->label('Hướng')->formatStateUsing(fn ($state) => $state === null ? '—' : MotrakTransformer::headingLabel((int) $state) . " ({$state}°)"),
                Infolists\Components\TextEntry::make('latitude')->label('Vĩ độ')->formatStateUsing(fn ($state) => $state === null ? '—' : number_format($state, 6, '.', '')),
                Infolists\Components\TextEntry::make('longitude')->label('Kinh độ')->formatStateUsing(fn ($state) => $state === null ? '—' : number_format($state, 6, '.', '')),
                Infolists\Components\TextEntry::make('address')->label('Địa chỉ')->placeholder('—')->columnSpan(2),
                Infolists\Components\TextEntry::make('administrative_area')->label('Khu vực hành chính')->placeholder('—'),
                Infolists\Components\TextEntry::make('googleMaps')->label('Google Maps')->state(fn (MotrakVehicle $r) => $r->hasPosition() ? 'Mở bản đồ' : null)->placeholder('—')
                    ->url(fn (MotrakVehicle $r) => $r->googleMapsUrl(), shouldOpenInNewTab: true)->color('primary'),
            ]),
            Infolists\Components\Section::make('Hôm nay & tích luỹ')->columns(4)->schema([
                Infolists\Components\TextEntry::make('day_km')->label('Km hôm nay')->suffix(' km')->placeholder('—'),
                Infolists\Components\TextEntry::make('day_run_seconds')->label('Thời gian chạy hôm nay')->formatStateUsing(fn ($state) => $state === null ? '—' : gmdate('H:i:s', (int) $state)),
                Infolists\Components\TextEntry::make('day_idle_seconds')->label('Thời gian nổ máy đứng yên')->formatStateUsing(fn ($state) => $state === null ? '—' : gmdate('H:i:s', (int) $state)),
                Infolists\Components\TextEntry::make('kmage')->label('Km tích luỹ (GPS)')->suffix(' km')->placeholder('—')
                    ->hintIcon('heroicon-o-information-circle')->hintIconTooltip('Km tích luỹ do GPS tính, không phải odometer của xe.'),
            ]),
            Infolists\Components\Section::make('Bản đồ')->schema([
                Infolists\Components\ViewEntry::make('map')->hiddenLabel()->columnSpanFull()
                    ->view('motrak::partials.vehicle-map-entry'),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [LocationsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMotrakVehicles::route('/'),
            'view'  => Pages\ViewMotrakVehicle::route('/{record}'),
        ];
    }
}
