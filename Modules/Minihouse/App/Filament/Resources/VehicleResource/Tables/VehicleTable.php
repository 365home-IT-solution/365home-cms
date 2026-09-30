<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources\VehicleResource\Tables;

use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Minihouse\App\Models\Vehicle;
use Modules\Minihouse\App\Services\VehicleService;

class VehicleTable
{
    private static function canManage(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('update_vehicles') ?? false);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'building:id,name',
                'tenant' => fn ($t) => $t->withoutGlobalScopes(),
                'contract' => fn ($c) => $c->withoutGlobalScopes(),
                'contract.room' => fn ($r) => $r->withoutGlobalScopes(),
            ]))
            ->columns([
                TextColumn::make('plate_display')->label('Biển số')
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($w) => $w
                        ->where('plate_display', 'like', "%{$search}%")
                        ->orWhere('plate', 'like', '%' . VehicleService::normalizePlate($search) . '%')))
                    ->weight('bold')->sortable(),
                TextColumn::make('vehicle_type')->label('Loại')->formatStateUsing(fn (string $state) => Vehicle::TYPES[$state] ?? $state),
                TextColumn::make('name')->label('Tên xe')->searchable(),
                ImageColumn::make('document_photo')->label('Giấy tờ xe')->disk('public')->size(32)->circular(false),
                TextColumn::make('tenant.fullname')->label('Khách thuê')->searchable(),
                TextColumn::make('contract.room.code')->label('Phòng'),
                TextColumn::make('building.name')->label('Toà nhà')->toggleable(),
                TextColumn::make('fee')->label('Phí/tháng')->state(fn (Vehicle $r) => number_format(VehicleService::monthlyFee($r), 0, ',', '.') . ' đ'),
                TextColumn::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn (string $state) => Vehicle::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Vehicle::STATUS_ACTIVE   => 'success',
                        Vehicle::STATUS_PENDING  => 'warning',
                        Vehicle::STATUS_REJECTED => 'danger',
                        default                  => 'gray',
                    }),
                TextColumn::make('requested_by')->label('Nguồn')
                    ->formatStateUsing(fn (string $state) => $state === 'tenant' ? 'Khách tự khai' : 'Nhân viên')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Tạo lúc')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Trạng thái')->options(Vehicle::STATUSES),
                SelectFilter::make('vehicle_type')->label('Loại xe')->options(Vehicle::TYPES),
                SelectFilter::make('building_id')->label('Toà nhà')->options(fn () => \Modules\Minihouse\App\Models\Building::withoutGlobalScope('activeBuilding')
                    ->whereIn('id', \Modules\Minihouse\App\Support\ActiveBuildingScope::permittedBuildingIds())
                    ->orderBy('name')->pluck('name', 'id')),
            ])
            ->actions([
                Action::make('approve')->label('Duyệt')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (Vehicle $r) => $r->status === Vehicle::STATUS_PENDING && self::canManage())
                    ->requiresConfirmation()
                    ->action(function (Vehicle $r) {
                        $warnings = VehicleService::limitWarnings($r);
                        VehicleService::approve($r, auth()->id());
                        Notification::make()->title('Đã duyệt xe ' . $r->plate_display)->success()->send();

                        foreach ($warnings as $w) {
                            Notification::make()->title('Vượt giới hạn')->body($w)->warning()->persistent()->send();
                        }
                    }),
                Action::make('reject')->label('Từ chối')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (Vehicle $r) => $r->status === Vehicle::STATUS_PENDING && self::canManage())
                    ->form([Textarea::make('reason')->label('Lý do từ chối')->required()->maxLength(255)])
                    ->action(function (Vehicle $r, array $data) {
                        VehicleService::reject($r, auth()->id(), $data['reason']);
                        Notification::make()->title('Đã từ chối xe ' . $r->plate_display)->success()->send();
                    }),
                Action::make('deactivate')->label('Ngưng gửi')->icon('heroicon-o-stop-circle')->color('gray')
                    ->visible(fn (Vehicle $r) => $r->status === Vehicle::STATUS_ACTIVE && self::canManage())
                    ->requiresConfirmation()
                    ->action(function (Vehicle $r) {
                        VehicleService::deactivate($r);
                        Notification::make()->title('Đã ngưng gửi xe ' . $r->plate_display)->success()->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
