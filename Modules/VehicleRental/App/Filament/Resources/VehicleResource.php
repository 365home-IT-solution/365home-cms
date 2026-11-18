<?php

declare(strict_types=1);

namespace Modules\VehicleRental\App\Filament\Resources;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Category\Entities\Category;
use Modules\VehicleRental\App\Filament\Resources\VehicleResource\Pages;
use Modules\VehicleRental\App\Filament\Resources\VehicleResource\RelationManagers\BlocksRelationManager;
use Modules\VehicleRental\App\Filament\Resources\VehicleResource\RelationManagers\RatePlansRelationManager;
use Modules\VehicleRental\App\Models\Vehicle;
use Modules\VehicleRental\App\Support\VehicleRentalAccess;

// Đội xe cho thuê của 365Home. Hard-code Super Admin (nhân sự 365) như PartnerSettlementResource: xe là tài sản của 365,
// đối tác homestay không được xem/sửa. Phân quyền nhân viên chi nhánh sẽ thêm cùng bước giao/nhận xe.
class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Đội xe';

    protected static ?string $modelLabel = 'Xe';

    protected static ?string $pluralModelLabel = 'Đội xe';

    protected static ?string $slug = 'rental-vehicles';

    protected static ?int $navigationSort = 2;

    public const TYPES = [
        Vehicle::TYPE_MOTORBIKE => 'Xe máy',
        Vehicle::TYPE_CAR       => 'Ô tô',
    ];

    public const STATUSES = [
        Vehicle::STATUS_AVAILABLE   => 'Sẵn sàng',
        Vehicle::STATUS_MAINTENANCE => 'Bảo dưỡng',
        Vehicle::STATUS_INACTIVE    => 'Ngừng cho thuê',
    ];

    public static function canViewAny(): bool
    {
        return VehicleRentalAccess::canViewVehicles(auth()->user());
    }

    public static function canCreate(): bool
    {
        return VehicleRentalAccess::canManageVehicles(auth()->user());
    }

    public static function canEdit($record): bool
    {
        return VehicleRentalAccess::canManageVehicles(auth()->user());
    }

    public static function canDelete($record): bool
    {
        return VehicleRentalAccess::canManageVehicles(auth()->user());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['branch', 'ratePlans']);
    }

    /** Chi nhánh = categories gốc loại product (cùng cách các nơi khác trong dự án liệt kê chi nhánh). */
    public static function branchOptions(): array
    {
        return Category::query()->withoutGlobalScopes()
            ->where('category_type', 'product')->whereNull('parent_id')
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin xe')->columns(3)->schema([
                Forms\Components\Select::make('branch_id')->label('Chi nhánh nhận/trả xe')
                    ->options(fn () => static::branchOptions())->searchable()->required()
                    ->default(fn () => config('vehiclerental.default_branch_id')),
                Forms\Components\Select::make('type')->label('Loại xe')->options(self::TYPES)->required()->native(false)
                    ->default(Vehicle::TYPE_MOTORBIKE)->live(),
                Forms\Components\TextInput::make('plate_display')->label('Biển số')->required()->maxLength(30)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get, ?Vehicle $record) {
                        // Gợi ý thiết bị GPS theo biển số (chỉ khi chưa chọn thiết bị nào).
                        if (! $get('motrak_device_id') && ($id = app(\Modules\VehicleRental\App\Services\VehicleGpsLinker::class)->suggestId((string) $state, $record))) {
                            $set('motrak_device_id', $id);
                        }
                    })
                    ->helperText('Nhập tự do (VD 29A-123.45); hệ thống tự chuẩn hoá để chặn trùng.')
                    ->rules([fn (?Vehicle $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                        $exists = Vehicle::withTrashed()->where('plate', Vehicle::normalizePlate((string) $value))
                            ->when($record, fn ($q) => $q->where('id', '!=', $record->id))->exists();
                        if ($exists) {
                            $fail('Biển số này đã có trong đội xe.');
                        }
                    }]),
                Forms\Components\TextInput::make('brand')->label('Hãng')->required()->maxLength(100),
                Forms\Components\TextInput::make('model')->label('Dòng xe')->required()->maxLength(100),
                Forms\Components\TextInput::make('year')->label('Năm sản xuất')->numeric()->minValue(1990)->maxValue((int) date('Y') + 1),
                Forms\Components\TextInput::make('seats')->label('Số chỗ')->numeric()->minValue(1)->maxValue(60)
                    ->visible(fn (Forms\Get $get) => $get('type') === Vehicle::TYPE_CAR),
                Forms\Components\Select::make('transmission')->label('Hộp số')->native(false)
                    ->options(['auto' => 'Số tự động', 'manual' => 'Số sàn']),
                Forms\Components\Select::make('fuel')->label('Nhiên liệu')->native(false)
                    ->options(['petrol' => 'Xăng', 'diesel' => 'Dầu', 'electric' => 'Điện', 'hybrid' => 'Hybrid']),
                Forms\Components\TextInput::make('color')->label('Màu')->maxLength(50),
                Forms\Components\Select::make('status')->label('Trạng thái')->options(self::STATUSES)->required()->native(false)
                    ->default(Vehicle::STATUS_AVAILABLE),
                Forms\Components\TextInput::make('odometer_km')->label('Số km hiện tại')->numeric()->minValue(0)->default(0)->suffix('km')->required(),
                Forms\Components\TextInput::make('deposit_amount')->label('Cọc thế chấp')->numeric()->minValue(0)->default(0)->suffix('đ')->required()
                    ->helperText('Thu tại quầy khi nhận xe, hoàn sau khi kiểm xe; không tính vào doanh thu.'),
            ]),
            Forms\Components\Section::make('Hạn giấy tờ & thiết bị')->columns(3)->schema([
                Forms\Components\DatePicker::make('registration_expires_on')->label('Hạn đăng kiểm'),
                Forms\Components\DatePicker::make('insurance_expires_on')->label('Hạn bảo hiểm TNDS'),
                Forms\Components\Select::make('motrak_device_id')->label('Thiết bị GPS (Motrak)')
                    ->options(fn (?Vehicle $record) => app(\Modules\VehicleRental\App\Services\VehicleGpsLinker::class)->options($record))
                    ->searchable()->native(false)->placeholder('Chưa gắn GPS')->dehydrated(false)
                    ->visible(fn () => app(\Modules\VehicleRental\App\Services\VehicleGpsLinker::class)->available())
                    ->afterStateHydrated(function (Forms\Components\Select $component, ?Vehicle $record) {
                        $record && $component->state(app(\Modules\VehicleRental\App\Services\VehicleGpsLinker::class)->currentId($record));
                    })
                    ->helperText('Chọn thiết bị Motrak của xe này (một thiết bị chỉ gắn một xe). Biển số trùng khớp được gợi ý / gắn tự động. Không thấy thiết bị? Kiểm tra Token ở "Cấu hình Motrak" và bấm đồng bộ.'),
            ]),
            Forms\Components\Section::make('Hình ảnh & mô tả')->schema([
                Forms\Components\FileUpload::make('image')->label('Ảnh đại diện')->image()->imageEditor()->directory('rental-vehicles')->imagePreviewHeight('120'),
                Forms\Components\FileUpload::make('photos')->label('Thư viện ảnh')->image()->multiple()->reorderable()->directory('rental-vehicles')->maxFiles(10),
                Forms\Components\Textarea::make('description')->label('Mô tả')->rows(3)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $money = fn ($state) => $state ? number_format((int) $state, 0, ',', '.') . 'đ' : '—';
        // Filament nạp closure theo TÊN tham số: phải là $state (không phải $date) — nếu không, trang có xe mang hạn đăng kiểm/bảo hiểm sẽ lỗi 500.
        $expiry = fn ($state) => $state && \Carbon\Carbon::parse($state)->lt(now()->addDays(30)) ? 'danger' : 'gray';

        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\ImageColumn::make('image')->label('')->circular(),
                Tables\Columns\ToggleColumn::make('rentable')->label('Cho thuê')
                    ->getStateUsing(fn (Vehicle $r) => $r->isRentable())
                    ->updateStateUsing(function (Vehicle $r, $state) {
                        $r->setRentable((bool) $state);
                        $n = $r->upcomingBookingsCount();
                        $note = (! $state && $n > 0) ? " Còn {$n} đơn sắp tới vẫn được giữ, chỉ chặn đặt mới." : '';
                        \Filament\Notifications\Notification::make()->success()->title(($state ? 'Đã mở cho thuê: ' : 'Đã ngừng cho thuê: ') . $r->plate_display)->body(trim($note) ?: null)->send();

                        return (bool) $state;
                    })
                    ->disabled(fn () => ! VehicleRentalAccess::canManageVehicles(auth()->user()))
                    ->tooltip('Bật = khách tìm và đặt được; tắt = ngừng cho thuê'),
                Tables\Columns\TextColumn::make('plate_display')->label('Biển số')->searchable(['plate', 'plate_display'])->weight('bold'),
                Tables\Columns\TextColumn::make('brand')->label('Xe')->state(fn (Vehicle $r) => trim($r->brand . ' ' . $r->model . ($r->year ? " ({$r->year})" : '')))
                    ->searchable(['brand', 'model']),
                Tables\Columns\TextColumn::make('type')->label('Loại')->badge()->formatStateUsing(fn ($state) => self::TYPES[$state] ?? $state),
                Tables\Columns\TextColumn::make('branch.name')->label('Chi nhánh')->limit(28),
                Tables\Columns\TextColumn::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn ($state) => self::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        Vehicle::STATUS_AVAILABLE   => 'success',
                        Vehicle::STATUS_MAINTENANCE => 'warning',
                        default                     => 'gray',
                    }),
                Tables\Columns\TextColumn::make('gps')->label('GPS')->badge()
                    ->state(function (Vehicle $r) {
                        $t = app(\Modules\VehicleRental\App\Services\VehicleGpsService::class)->trackerFor($r->id);

                        return $t ? $t->connectionLabel() : 'Chưa ghép';
                    })
                    ->color(fn ($state) => match ($state) { 'Đang kết nối' => 'success', 'Mất kết nối' => 'danger', 'Dữ liệu quá cũ' => 'warning', default => 'gray' })
                    ->toggleable(),
                Tables\Columns\TextColumn::make('day_price')->label('Giá ngày')
                    ->state(fn (Vehicle $r) => optional($r->ratePlans->where('unit', 'day')->where('is_active', true)->first())->price)
                    ->formatStateUsing($money),
                Tables\Columns\TextColumn::make('deposit_amount')->label('Cọc')->formatStateUsing($money),
                Tables\Columns\TextColumn::make('odometer_km')->label('Km')->numeric(thousandsSeparator: '.')->toggleable(),
                Tables\Columns\TextColumn::make('registration_expires_on')->label('Đăng kiểm')->date('d/m/Y')->color($expiry)->toggleable(),
                Tables\Columns\TextColumn::make('insurance_expires_on')->label('Bảo hiểm')->date('d/m/Y')->color($expiry)->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Loại xe')->options(self::TYPES),
                Tables\Filters\SelectFilter::make('status')->label('Trạng thái')->options(self::STATUSES),
                Tables\Filters\SelectFilter::make('branch_id')->label('Chi nhánh')->options(fn () => static::branchOptions()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('openRental')->label('Mở cho thuê')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn () => VehicleRentalAccess::canManageVehicles(auth()->user()))
                    ->requiresConfirmation()->deselectRecordsAfterCompletion()
                    ->action(fn (\Illuminate\Support\Collection $records) => $records->each->setRentable(true)),
                Tables\Actions\BulkAction::make('closeRental')->label('Ngừng cho thuê')->icon('heroicon-o-pause-circle')->color('gray')
                    ->visible(fn () => VehicleRentalAccess::canManageVehicles(auth()->user()))
                    ->requiresConfirmation()->deselectRecordsAfterCompletion()
                    ->modalDescription('Xe bị tắt sẽ không còn hiện cho khách đặt mới. Các đơn đã đặt vẫn được giữ.')
                    ->action(fn (\Illuminate\Support\Collection $records) => $records->each->setRentable(false)),
            ])
            ->emptyStateHeading('Chưa có xe nào');
    }

    public static function getRelations(): array
    {
        return [
            RatePlansRelationManager::class,
            BlocksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListVehicles::route('/'),
            'create' => Pages\CreateVehicle::route('/create'),
            'edit'   => Pages\EditVehicle::route('/{record}/edit'),
        ];
    }
}
