<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\HeldSubsidyOrderResource\Pages;
use App\Services\SettlementService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Payment\Entities\Order;

// ĐƠN BỊ GIỮ KHOẢN BÙ trên trang quản trị web (cùng nghiệp vụ với API …/settlements/held-orders — App\Services\SettlementService::resolveHeldOrder):
// đơn có khuyến mãi do 365home bù nhưng bị giữ lại chờ Super Admin xem xét. Thả = bù bình thường ở kỳ đối soát kế tiếp; Huỷ bù = đơn vào kỳ tới nhưng
// không được bù khoản này. Hard-code quyền Super Admin như các màn tài chính khác, không dùng permission Shield.
class HeldSubsidyOrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-pause-circle';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Đơn bị giữ khoản bù';

    protected static ?string $modelLabel = 'Đơn bị giữ khoản bù';

    protected static ?string $pluralModelLabel = 'Đơn bị giữ khoản bù';

    protected static ?string $slug = 'held-subsidy-orders';

    protected static ?int $navigationSort = 5;

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
        return Order::withoutGlobalScopes()->whereNotNull('subsidy_held_at')->whereNull('settlement_id')->with('partner');
    }

    public static function table(Table $table): Table
    {
        $money = fn ($state) => number_format((int) $state, 0, ',', '.') . 'đ';

        return $table
            ->defaultSort('subsidy_held_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('order_code')->label('Mã đơn')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('partner.name')->label('Đối tác')->searchable(),
                Tables\Columns\TextColumn::make('buyer_name')->label('Khách')->description(fn (Order $record) => $record->buyer_phone),
                Tables\Columns\TextColumn::make('platform_subsidy')->label('Khoản bù đang giữ')->formatStateUsing($money)->alignEnd(),
                Tables\Columns\TextColumn::make('commission_amount')->label('Hoa hồng')->formatStateUsing($money)->alignEnd(),
                Tables\Columns\TextColumn::make('subsidy_held_reason')->label('Lý do giữ')->wrap()->limit(90)->tooltip(fn (Order $record) => $record->subsidy_held_reason),
                Tables\Columns\TextColumn::make('subsidy_held_at')->label('Giữ lúc')->dateTime('d/m/Y H:i'),
            ])
            ->actions([
                Tables\Actions\Action::make('release')
                    ->label('Thả — bù bình thường')->icon('heroicon-o-check-circle')->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('Đơn vào kỳ đối soát kế tiếp và 365home bù khoản khuyến mãi như bình thường.')
                    ->action(fn (Order $record) => self::resolve($record, true)),
                Tables\Actions\Action::make('forfeit')
                    ->label('Huỷ khoản bù')->icon('heroicon-o-x-circle')->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Đơn vào kỳ đối soát kế tiếp nhưng 365home KHÔNG bù khoản khuyến mãi này (đối tác tự chịu).')
                    ->action(fn (Order $record) => self::resolve($record, false)),
            ]);
    }

    private static function resolve(Order $order, bool $release): void
    {
        try {
            app(SettlementService::class)->resolveHeldOrder($order, $release, auth()->user());
            Notification::make()->success()->title('Đã xử lý đơn bị giữ khoản bù')->body('Đơn sẽ vào kỳ đối soát kế tiếp.')->send();
        } catch (\DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListHeldSubsidyOrders::route('/')];
    }
}
