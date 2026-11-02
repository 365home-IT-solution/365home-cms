<?php

namespace Modules\Payment\App\Filament\Resources;

use App\Services\Payment\SePayService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Payment\App\Filament\Resources\ManualPaymentResource\Pages;
use Modules\Payment\Entities\PartnerSePayAccount;
use Modules\Payment\Entities\SePayPaymentLink;

// "Chờ xác nhận chuyển khoản" — dành cho đối tác dùng "Xác nhận thủ công" (không qua SePay / Pay2S): khách chuyển khoản vào tài khoản của đối tác theo QR,
// đối tác đối chiếu với app ngân hàng rồi bấm "Xác nhận đã nhận tiền" → đơn được xác nhận. Đối tác chỉ thấy khoản của mình; Super Admin thấy tất cả.
class ManualPaymentResource extends Resource
{
    protected static ?string $model = SePayPaymentLink::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Chờ xác nhận chuyển khoản';

    protected static ?string $modelLabel = 'khoản chờ xác nhận';

    protected static ?string $pluralModelLabel = 'Chờ xác nhận chuyển khoản';

    protected static ?string $slug = 'manual-payments';

    public static function getNavigationGroup(): ?string
    {
        return __('payment::payment-configuration.resource.navigation_group');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->partner_id && PartnerSePayAccount::query()->where('partner_id', $user->partner_id)->where('provider', PartnerSePayAccount::PROVIDER_MANUAL)->where('is_active', true)->exists();
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

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $partnerId = $user && ! $user->seesAllPartners() ? (string) $user->partner_id : null;

        return app(SePayService::class)->pendingManualLinks($partnerId)->with(['order', 'account.category'])
            // Khoản khách đã báo "Tôi đã chuyển khoản" lên đầu để đối tác kiểm tra trước.
            ->orderByRaw('customer_claimed_at is null');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::canViewAny() ? static::getEloquentQuery()->count() : 0;

        return $count ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'asc')
            ->poll('30s')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Tạo lúc')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('order.order_code')->label('Mã đơn')->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('order.buyer_name')->label('Khách')->placeholder('—'),
                Tables\Columns\TextColumn::make('order.buyer_phone')->label('SĐT')->placeholder('—'),
                Tables\Columns\TextColumn::make('kind')->label('Khoản thu')
                    ->state(fn (SePayPaymentLink $record) => match (app(SePayService::class)->kindOf($record->order, $record)) {
                        'initial' => 'Thanh toán / cọc đầu', 'remaining' => 'Phần còn lại', 'deposit_retry' => 'Cọc lại', 'extra' => 'Phát sinh', default => '—',
                    }),
                Tables\Columns\TextColumn::make('amount')->label('Số tiền')->numeric(0, ',', '.')->suffix(' ₫')->sortable(),
                Tables\Columns\TextColumn::make('account.account_number')->label('Tài khoản nhận')->placeholder('—')
                    ->formatStateUsing(fn (SePayPaymentLink $record) => $record->account ? $record->account->bank_code . ' ' . $record->account->account_number . ($record->account->category_id ? ' — ' . $record->account->category?->name : '') : '—'),
                Tables\Columns\TextColumn::make('payment_code')->label('Nội dung chuyển khoản')->state(fn (SePayPaymentLink $record) => $record->paymentCode())->copyable(),
                Tables\Columns\TextColumn::make('customer_claimed_at')->label('Khách báo đã chuyển')->dateTime('d/m/Y H:i')->placeholder('Chưa báo')->badge()->color(fn ($state) => $state ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('expires_at')->label('Hạn đơn')->dateTime('d/m/Y H:i')->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('confirm')
                    ->label('Xác nhận đã nhận tiền')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->modalHeading('Xác nhận đã nhận tiền chuyển khoản?')
                    ->modalDescription(fn (SePayPaymentLink $record) => 'Chỉ xác nhận khi tiền ĐÃ VÀO tài khoản ngân hàng của bạn: ' . number_format((int) $record->amount, 0, ',', '.') . ' ₫, nội dung ' . $record->paymentCode() . '. Đơn sẽ được xác nhận cho khách ngay.')
                    ->form([Forms\Components\TextInput::make('note')->label('Ghi chú (không bắt buộc)')->maxLength(255)])
                    ->action(function (SePayPaymentLink $record, array $data) {
                        $result = app(SePayService::class)->confirmManually($record, auth()->id(), $data['note'] ?? null);

                        if ($result['status'] === 'processed') {
                            Notification::make()->success()->title('Đã xác nhận — đơn #' . $result['order']->order_code)->send();

                            return;
                        }

                        Notification::make()->danger()->title($result['reason'] ?: 'Không xác nhận được khoản thanh toán')->persistent()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListManualPayments::route('/')];
    }
}
