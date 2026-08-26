<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerCheckinResource\Pages;
use App\Models\CustomerCheckinCycle;
use App\Models\MembershipTier;
use App\Services\CustomerCheckinService;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CustomerCheckinResource extends Resource
{
    protected static ?string $model = CustomerCheckinCycle::class;

    protected static ?string $navigationIcon   = 'heroicon-o-calendar-days';
    protected static ?string $navigationGroup  = 'Phân quyền';
    protected static ?string $navigationLabel  = 'Điểm danh khách hàng';
    protected static ?string $modelLabel       = 'Chu kỳ điểm danh';
    protected static ?string $pluralModelLabel = 'Điểm danh khách hàng';
    protected static ?int    $navigationSort   = 26;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_customer::checkin') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->with(['customer', 'membershipTier'])
                ->withMax('days', 'checkin_date'))
            ->columns([
                TextColumn::make('customer.fullname')
                    ->label('Khách hàng')
                    ->description(fn (CustomerCheckinCycle $record) => $record->customer?->phone)
                    ->searchable(query: fn ($query, string $search) => $query
                        ->whereHas('customer', fn ($q) => $q
                            ->where('fullname', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")))
                    ->sortable(false),

                TextColumn::make('membershipTier.name')
                    ->label('Hạng')
                    ->badge(),

                TextColumn::make('cycle_start_date')
                    ->label('Bắt đầu chu kỳ')
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('progress')
                    ->label('Tiến độ')
                    ->state(fn (CustomerCheckinCycle $record) => "{$record->days_checked}/{$record->days_required} ngày"),

                TextColumn::make('days_max_checkin_date')
                    ->label('Điểm danh gần nhất')
                    ->date('d/m/Y')
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (CustomerCheckinCycle $record) => $record->isCompleted() ? 'Hoàn thành' : 'Đang điểm danh')
                    ->color(fn (string $state) => $state === 'Hoàn thành' ? 'success' : 'warning'),

                TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('membership_tier_id')
                    ->label('Hạng')
                    ->options(fn () => MembershipTier::pluck('name', 'id')),

                TernaryFilter::make('completed')
                    ->label('Trạng thái')
                    ->placeholder('Tất cả')
                    ->trueLabel('Hoàn thành')
                    ->falseLabel('Đang điểm danh')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('completed_at'),
                        false: fn ($query) => $query->whereNull('completed_at'),
                    ),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('adminCheckin')
                    ->label('Tick bù hôm nay')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CustomerCheckinCycle $record) => ! $record->isCompleted()
                        && auth()->user()?->can('update_customer::checkin'))
                    ->requiresConfirmation()
                    ->modalDescription('Tick 1 ngày điểm danh hôm nay thay cho khách này?')
                    ->action(function (CustomerCheckinCycle $record) {
                        app(CustomerCheckinService::class)->adminCheckin($record->customer);
                    }),

                Action::make('resetCycle')
                    ->label('Reset chu kỳ')
                    ->icon('heroicon-o-arrow-path')
                    ->color('danger')
                    ->visible(fn () => auth()->user()?->can('update_customer::checkin') ?? false)
                    ->requiresConfirmation()
                    ->modalDescription('Xoá toàn bộ tiến độ chu kỳ điểm danh hiện tại của khách này? Lần điểm danh kế tiếp sẽ bắt đầu chu kỳ mới từ đầu.')
                    ->action(function (CustomerCheckinCycle $record) {
                        app(CustomerCheckinService::class)->resetCycle($record);
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomerCheckins::route('/'),
        ];
    }
}
