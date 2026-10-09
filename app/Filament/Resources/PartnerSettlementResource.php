<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PartnerSettlementResource\Pages;
use App\Filament\Resources\PartnerSettlementResource\RelationManagers\SettlementDisputesRelationManager;
use App\Filament\Resources\PartnerSettlementResource\RelationManagers\SettlementOrdersRelationManager;
use App\Models\PartnerSettlement as Settlement;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

// ĐỐI SOÁT HOA HỒNG theo kỳ trên trang quản trị web (cùng nghiệp vụ với API /api/admin/partners/{partner}/settlements — App\Services\SettlementService):
// Super Admin xem mọi đối tác, gửi bảng nháp, xử lý khiếu nại, xác nhận đã chi/đã nộp; chủ đối tác chỉ thấy bảng ĐÃ GỬI của mình, lấy QR nộp và khiếu nại
// từng đơn. Hard-code quyền như PartnerResource (nghiệp vụ tài chính), không dùng permission Shield.
class PartnerSettlementResource extends Resource
{
    protected static ?string $model = Settlement::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Đối soát hoa hồng';

    protected static ?string $modelLabel = 'Bảng đối soát';

    protected static ?string $pluralModelLabel = 'Đối soát hoa hồng';

    protected static ?string $slug = 'partner-settlements';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return $user?->isSuperAdmin()
            ? Settlement::query()
            : Settlement::query()->where('partner_id', $user?->partner_id ?? '-')->where('status', '!=', Settlement::STATUS_DRAFT);
    }

    public static function table(Table $table): Table
    {
        $money = fn ($state) => number_format((int) $state, 0, ',', '.') . 'đ';

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Mã bảng')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('partner.name')->label('Đối tác')->searchable()->visible(fn () => auth()->user()?->isSuperAdmin() ?? false),
                Tables\Columns\TextColumn::make('period_start')->label('Kỳ')->state(fn (Settlement $record) => $record->period_start->format('d/m/Y') . ' – ' . $record->period_end->format('d/m/Y')),
                Tables\Columns\TextColumn::make('status')->label('Trạng thái')->badge()->formatStateUsing(fn (string $state) => Settlement::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Settlement::STATUS_DRAFT => 'gray', Settlement::STATUS_SENT => 'info', Settlement::STATUS_DISPUTED => 'warning',
                        Settlement::STATUS_PAID, Settlement::STATUS_PAID_OUT => 'success', default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('orders_count')->label('Số đơn')->alignEnd(),
                Tables\Columns\TextColumn::make('commission_total')->label('Hoa hồng')->formatStateUsing($money)->alignEnd(),
                Tables\Columns\TextColumn::make('subsidy_total')->label('365home bù')->formatStateUsing($money)->alignEnd(),
                Tables\Columns\TextColumn::make('net_amount')->label('Đối tác nộp (+) / 365home chi (−)')->formatStateUsing(fn ($state) => ($state > 0 ? '+' : '') . $money($state))
                    ->color(fn ($state) => $state > 0 ? 'danger' : ($state < 0 ? 'success' : null))->alignEnd(),
                Tables\Columns\TextColumn::make('due_at')->label('Hạn nộp')->dateTime('d/m/Y')->placeholder('—')
                    ->color(fn (Settlement $record) => $record->status === Settlement::STATUS_SENT && $record->net_amount > 0 && $record->due_at?->isPast() ? 'danger' : null),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Trạng thái')->options(Settlement::STATUSES),
                Tables\Filters\SelectFilter::make('partner_id')->label('Đối tác')->relationship('partner', 'name')->searchable()->preload()
                    ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('Chi tiết')])
            ->defaultSort('period_end', 'desc');
    }

    public static function getRelations(): array
    {
        return [SettlementOrdersRelationManager::class, SettlementDisputesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPartnerSettlements::route('/'),
            'view'  => Pages\ViewPartnerSettlement::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || ($user?->partner_id && ($user->hasRole('partner') || $user->can('update_partner'))));
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
        return false;
    }
}
