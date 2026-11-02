<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CaroRewardResource\Pages;
use App\Models\CaroReward;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

// Lịch sử trao voucher caro (chỉ xem, chỉ Super Admin).
class CaroRewardResource extends Resource
{
    protected static ?string $model = CaroReward::class;

    protected static ?string $navigationIcon   = 'heroicon-o-trophy';
    protected static ?string $navigationGroup  = 'Quản lý API';
    protected static ?string $navigationLabel  = 'Caro — Lịch sử thưởng';
    protected static ?string $modelLabel       = 'Thưởng caro';
    protected static ?string $pluralModelLabel = 'Lịch sử thưởng caro';
    protected static ?string $slug             = 'game-caro-rewards';
    protected static ?int    $navigationSort   = 34;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customer', 'coupon']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Thời gian')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('customer.fullname')->label('Khách hàng')->searchable(),
                TextColumn::make('customer.phone')->label('SĐT')->searchable(),
                TextColumn::make('prize_label')->label('Giải'),
                TextColumn::make('coupon.code')->label('Mã voucher')->placeholder('—')->copyable(),
                TextColumn::make('coupon_used')->label('Đã dùng')
                    ->state(fn (CaroReward $r) => $r->coupon ? ((int) $r->coupon->used_count > 0 ? 'Có' : 'Chưa') : '—'),
                TextColumn::make('coupon.end_at')->label('Hạn dùng')->dateTime('d/m/Y')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Filter::make('range')->label('Khoảng ngày')
                    ->form([DatePicker::make('from')->label('Từ ngày'), DatePicker::make('to')->label('Đến ngày')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', \Carbon\Carbon::parse($v)->startOfDay()))
                        ->when($data['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', \Carbon\Carbon::parse($v)->endOfDay()))),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCaroRewards::route('/')];
    }
}
