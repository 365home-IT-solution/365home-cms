<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ScratchCardResource\Pages;
use App\Models\ScratchCard;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

// Lịch sử thẻ cào đã cào / trúng thưởng (chỉ xem, chỉ Super Admin) — cùng dữ liệu với /api/admin/games/scratch-card/winners.
class ScratchCardResource extends Resource
{
    protected static ?string $model = ScratchCard::class;

    protected static ?string $navigationIcon   = 'heroicon-o-gift';
    protected static ?string $navigationGroup  = 'Quản lý API';
    protected static ?string $navigationLabel  = 'Thẻ cào — Lịch sử trúng';
    protected static ?string $modelLabel       = 'Thẻ cào';
    protected static ?string $pluralModelLabel = 'Lịch sử trúng thẻ cào';
    protected static ?string $slug             = 'game-scratch-card-winners';
    protected static ?int    $navigationSort   = 32;

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
        return parent::getEloquentQuery()->whereNotNull('scratched_at')->with(['customer', 'order', 'coupon']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('scratched_at')->label('Thời gian cào')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('customer.fullname')->label('Khách hàng')->searchable(),
                TextColumn::make('customer.phone')->label('SĐT')->searchable(),
                TextColumn::make('order.order_code')->label('Mã đơn')->placeholder('—'),
                TextColumn::make('source')->label('Nguồn thẻ')->badge()
                    ->formatStateUsing(fn (string $state) => ['order' => 'Đơn đã trả phòng', 'rating_bonus' => 'Đánh giá ảnh', 'referral' => 'Giới thiệu'][$state] ?? $state),
                TextColumn::make('prize_label')->label('Giải'),
                TextColumn::make('coupon.code')->label('Mã voucher')->placeholder('—')->copyable(),
                TextColumn::make('coupon_used')->label('Đã dùng')
                    ->state(fn (ScratchCard $r) => $r->coupon ? ((int) $r->coupon->used_count > 0 ? 'Có' : 'Chưa') : '—'),
                TextColumn::make('status')->label('Trạng thái thẻ')->badge()
                    ->formatStateUsing(fn (string $state) => [
                        ScratchCard::STATUS_AVAILABLE => 'Chưa cào', ScratchCard::STATUS_SCRATCHED => 'Đã cào',
                        ScratchCard::STATUS_EXPIRED => 'Hết hạn', ScratchCard::STATUS_VOIDED => 'Đã huỷ',
                    ][$state] ?? $state),
            ])
            ->defaultSort('scratched_at', 'desc')
            ->filters([
                SelectFilter::make('source')->label('Nguồn thẻ')
                    ->options(['order' => 'Đơn đã trả phòng', 'rating_bonus' => 'Đánh giá ảnh', 'referral' => 'Giới thiệu']),

                Filter::make('range')->label('Khoảng ngày')
                    ->form([DatePicker::make('from')->label('Từ ngày'), DatePicker::make('to')->label('Đến ngày')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $v) => $q->where('scratched_at', '>=', \Carbon\Carbon::parse($v)->startOfDay()))
                        ->when($data['to'] ?? null, fn ($q, $v) => $q->where('scratched_at', '<=', \Carbon\Carbon::parse($v)->endOfDay()))),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListScratchCardWinners::route('/')];
    }
}
