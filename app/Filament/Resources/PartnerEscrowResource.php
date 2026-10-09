<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PartnerEscrowResource\Pages;
use App\Filament\Resources\PartnerEscrowResource\RelationManagers\EscrowDeductionsRelationManager;
use App\Filament\Resources\PartnerEscrowResource\RelationManagers\EscrowEntriesRelationManager;
use App\Models\Partner;
use App\Services\EscrowService;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

// KÝ QUỸ ĐỐI TÁC trên trang quản trị web (cùng nghiệp vụ với API /api/admin/partners/{partner}/escrow — App\Services\EscrowService):
// Super Admin xem mọi đối tác Homestay, đặt mức, ghi nạp tay, lập đề xuất trừ, xử lý khiếu nại; chủ đối tác chỉ thấy ký quỹ CỦA MÌNH,
// nạp qua QR PayOS và đồng ý/khiếu nại đề xuất trừ. Không dựa vào permission Shield mà hard-code như PartnerResource (nghiệp vụ tài chính).
class PartnerEscrowResource extends Resource
{
    protected static ?string $model = Partner::class;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Ký quỹ đối tác';

    protected static ?string $modelLabel = 'Ký quỹ đối tác';

    protected static ?string $pluralModelLabel = 'Ký quỹ đối tác';

    protected static ?string $slug = 'partner-escrows';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        $query = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->where('is_platform_partner', false)
            ->where('id', '!=', \Modules\Minihouse\App\Support\HomestayBridge::PARTNER_ID);

        $user = auth()->user();

        return $user?->isSuperAdmin() ? $query : $query->where('id', $user?->partner_id ?? '-');
    }

    public static function table(Table $table): Table
    {
        $money = fn ($state) => $state === null ? '—' : number_format((int) $state, 0, ',', '.') . 'đ';

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Đối tác')->searchable()->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
                    ->description(fn (Partner $record) => $record->legal_name),
                Tables\Columns\TextColumn::make('escrow_status')->label('Trạng thái')->badge()
                    ->state(fn (Partner $record) => app(EscrowService::class)->state($record))
                    ->formatStateUsing(fn (string $state) => EscrowService::STATES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        EscrowService::STATE_OK => 'success', EscrowService::STATE_GRACE, EscrowService::STATE_LOW => 'warning',
                        EscrowService::STATE_SUSPENDED => 'danger', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('escrow_min_amount')->label('Mức tối thiểu')->formatStateUsing($money),
                Tables\Columns\TextColumn::make('escrow_balance')->label('Số dư')->formatStateUsing($money)->color(fn ($state) => (int) $state < 0 ? 'danger' : null),
                Tables\Columns\TextColumn::make('held')->label('Đang tạm giữ')->state(fn (Partner $record) => app(EscrowService::class)->heldAmount($record))->formatStateUsing($money),
                Tables\Columns\TextColumn::make('payment_flow_effective_at')->label('Luồng tiền mới')->placeholder('Chưa hiệu lực (365home thu hộ)')->dateTime('d/m/Y'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('state')->label('Trạng thái')->options(EscrowService::STATES)
                    ->query(function (Builder $query, array $data) {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return;
                        }
                        $ids = Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->get()
                            ->filter(fn (Partner $partner) => app(EscrowService::class)->state($partner) === $value)->pluck('id');
                        $query->whereIn('id', $ids);
                    })->visible(fn () => auth()->user()?->isSuperAdmin() ?? false),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('Chi tiết')])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [EscrowEntriesRelationManager::class, EscrowDeductionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPartnerEscrows::route('/'),
            'view'  => Pages\ViewPartnerEscrow::route('/{record}'),
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
