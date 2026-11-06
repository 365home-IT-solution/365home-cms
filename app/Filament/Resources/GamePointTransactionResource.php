<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\GamePointTransactionResource\Pages;
use App\Models\GamePointTransaction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

// Lịch sử cộng/trừ điểm game (chỉ Super Admin) — cùng dữ liệu với /api/admin/games/points/transactions.
// Cộng/trừ tay là nút "Cộng / trừ điểm" ở đầu danh sách.
class GamePointTransactionResource extends Resource
{
    protected static ?string $model = GamePointTransaction::class;

    protected static ?string $navigationIcon   = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup  = 'Quản lý API';
    protected static ?string $navigationLabel  = 'Điểm game — Lịch sử';
    protected static ?string $modelLabel       = 'Giao dịch điểm';
    protected static ?string $pluralModelLabel = 'Lịch sử điểm game';
    protected static ?string $slug             = 'game-point-transactions';
    protected static ?int    $navigationSort   = 37;

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
        return parent::getEloquentQuery()->with(['customer', 'coupon', 'creator']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Thời gian')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('customer.fullname')->label('Khách hàng')->searchable(),
                TextColumn::make('customer.phone')->label('SĐT')->searchable(),
                TextColumn::make('type')->label('Loại')->badge()
                    ->formatStateUsing(fn (string $state) => GamePointTransaction::TYPE_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => [GamePointTransaction::TYPE_EARN => 'success', GamePointTransaction::TYPE_REDEEM => 'warning', GamePointTransaction::TYPE_ADJUST => 'info'][$state] ?? 'gray'),
                TextColumn::make('amount')->label('Điểm')
                    ->formatStateUsing(fn (int $state) => sprintf('%+d', $state))
                    ->color(fn (int $state) => $state >= 0 ? 'success' : 'danger'),
                TextColumn::make('balance_after')->label('Số dư sau'),
                TextColumn::make('note')->label('Ghi chú')->placeholder('—')->wrap(),
                TextColumn::make('coupon.code')->label('Mã voucher')->placeholder('—')->copyable(),
                TextColumn::make('creator.fullname')->label('Người chỉnh')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')->label('Loại')->options(GamePointTransaction::TYPE_LABELS),
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
        return ['index' => Pages\ListGamePointTransactions::route('/')];
    }
}
