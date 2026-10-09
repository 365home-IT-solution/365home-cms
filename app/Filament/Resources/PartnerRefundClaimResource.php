<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PartnerRefundClaimResource\Pages;
use App\Models\PartnerRefundClaim as Claim;
use App\Services\OrderRefundService;
use App\Services\RefundClaimService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Payment\Entities\Order;

// YÊU CẦU HOÀN TIỀN KHÁCH trên trang quản trị web (cùng nghiệp vụ với API …/refund-claims — App\Services\RefundClaimService): 365home (Super Admin / nhân viên đối tác
// nền tảng) mở yêu cầu cho đơn có tiền ở đối tác; đối tác có 24 giờ để hoàn (nút "Đã hoàn tiền cho khách"); quá hạn thì Super Admin hoàn thay và hệ thống tự lập đề xuất
// trừ ký quỹ. Hard-code quyền như các màn tài chính khác, không dùng permission Shield.
class PartnerRefundClaimResource extends Resource
{
    protected static ?string $model = Claim::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Yêu cầu hoàn tiền';

    protected static ?string $modelLabel = 'Yêu cầu hoàn tiền';

    protected static ?string $pluralModelLabel = 'Yêu cầu hoàn tiền';

    protected static ?string $slug = 'partner-refund-claims';

    protected static ?int $navigationSort = 4;

    private static function isPlatformSide(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || $user?->belongsToPlatformPartner());
    }

    public static function getEloquentQuery(): Builder
    {
        return self::isPlatformSide() ? Claim::query() : Claim::query()->where('partner_id', auth()->user()?->partner_id ?? '-');
    }

    public static function table(Table $table): Table
    {
        $money = fn ($state) => number_format((int) $state, 0, ',', '.') . 'đ';

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_code')->label('Mã đơn')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('partner.name')->label('Đối tác')->visible(fn () => self::isPlatformSide())->searchable(),
                Tables\Columns\TextColumn::make('amount')->label('Số tiền hoàn')->formatStateUsing($money)->alignEnd(),
                Tables\Columns\TextColumn::make('reason')->label('Lý do')->wrap()->limit(90)->tooltip(fn (Claim $record) => $record->reason),
                Tables\Columns\TextColumn::make('status')->label('Trạng thái')->badge()
                    ->state(fn (Claim $record) => $record->toApi()['is_overdue'] ? Claim::STATUS_OVERDUE : $record->status)
                    ->formatStateUsing(fn (string $state) => Claim::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Claim::STATUS_OPEN => 'info', Claim::STATUS_OVERDUE => 'danger', Claim::STATUS_REFUNDED_BY_PARTNER => 'success',
                        Claim::STATUS_REFUNDED_BY_PLATFORM => 'warning', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('due_at')->label('Hạn hoàn')->dateTime('d/m/Y H:i')
                    ->color(fn (Claim $record) => in_array($record->status, Claim::ACTIVE, true) && $record->due_at->isPast() ? 'danger' : null),
                Tables\Columns\TextColumn::make('requested_at')->label('Yêu cầu lúc')->dateTime('d/m/Y H:i')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Trạng thái')->options(Claim::STATUSES),
                Tables\Filters\SelectFilter::make('partner_id')->label('Đối tác')->relationship('partner', 'name')->searchable()->preload()->visible(fn () => self::isPlatformSide()),
            ])
            ->actions([
                // Đối tác: hoàn tiền cho khách rồi ghi nhận — đơn chuyển "đã hoàn", yêu cầu tự đóng, không trừ ký quỹ.
                Tables\Actions\Action::make('partnerRefund')
                    ->label('Đã hoàn tiền cho khách')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (Claim $record) => ! self::isPlatformSide() && in_array($record->status, Claim::ACTIVE, true))
                    ->modalDescription('Chỉ bấm sau khi bạn đã hoàn tiền cho khách (tiền mặt hoặc chuyển khoản ngoài hệ thống). Đơn sẽ chuyển sang "đã hoàn tiền".')
                    ->form([Forms\Components\Select::make('method')->label('Hình thức hoàn')->required()->native(false)->options(['cash' => 'Tiền mặt', 'transfer' => 'Chuyển khoản'])])
                    ->action(function (Claim $record, array $data) {
                        try {
                            $order = Order::withoutGlobalScopes()->findOrFail($record->order_id);
                            app(OrderRefundService::class)->refund($order, (int) $record->amount, $data['method'], 'Hoàn theo yêu cầu #' . $record->id . ': ' . $record->reason, (string) auth()->id());
                            Notification::make()->success()->title('Đã ghi nhận hoàn tiền cho khách.')->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                // Super Admin: quá hạn mới được hoàn thay; hệ thống tự lập đề xuất trừ ký quỹ khoản đã ứng.
                Tables\Actions\Action::make('refundOnBehalf')
                    ->label('Hoàn thay & trừ ký quỹ')->icon('heroicon-o-banknotes')->color('danger')
                    ->visible(fn (Claim $record) => (auth()->user()?->isSuperAdmin() ?? false) && $record->status === Claim::STATUS_OVERDUE)
                    ->modalDescription('365home hoàn tiền cho khách thay đối tác (thủ công, ngoài hệ thống). Hệ thống tự lập đề xuất trừ khoản đã ứng vào ký quỹ của đối tác.')
                    ->form([Forms\Components\Select::make('method')->label('Hình thức hoàn')->required()->native(false)->options(['cash' => 'Tiền mặt', 'transfer' => 'Chuyển khoản'])])
                    ->action(function (Claim $record, array $data) {
                        try {
                            app(RefundClaimService::class)->refundOnBehalf($record, $data['method'], auth()->user());
                            Notification::make()->success()->title('Đã hoàn thay và lập đề xuất trừ ký quỹ.')->send();
                        } catch (\DomainException|\RuntimeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),

                Tables\Actions\Action::make('cancel')
                    ->label('Huỷ yêu cầu')->icon('heroicon-o-x-circle')->color('gray')->requiresConfirmation()
                    ->visible(fn (Claim $record) => self::isPlatformSide() && in_array($record->status, Claim::ACTIVE, true))
                    ->action(function (Claim $record) {
                        try {
                            app(RefundClaimService::class)->cancel($record, auth()->user());
                            Notification::make()->success()->title('Đã huỷ yêu cầu hoàn tiền.')->send();
                        } catch (\DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPartnerRefundClaims::route('/')];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return (bool) (self::isPlatformSide() || ($user?->partner_id && ($user->hasRole('partner') || $user->can('update_partner'))));
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
