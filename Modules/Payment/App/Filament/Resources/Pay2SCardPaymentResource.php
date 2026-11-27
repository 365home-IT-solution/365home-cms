<?php

namespace Modules\Payment\App\Filament\Resources;

use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Modules\Payment\App\Filament\Resources\Pay2SCardPaymentResource\Pages;
use Modules\Payment\Entities\Pay2SCardPayment;

// "Giao dịch thẻ Pay2S" — mọi phiên thanh toán thẻ, chỉ đọc, chỉ super_admin. Phiên "Cần hoàn tiền" là tiền thẻ ĐÃ THU nhưng đơn không còn nhận được khoản đó
// (đã huỷ / hết hạn / sai số tiền...) — bộ thẻ của Pay2S không có API hoàn tiền nên hoàn thủ công trên Pay2S.
class Pay2SCardPaymentResource extends Resource
{
    protected static ?string $model = Pay2SCardPayment::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Giao dịch thẻ Pay2S';

    protected static ?string $modelLabel = 'giao dịch thẻ Pay2S';

    protected static ?string $pluralModelLabel = 'Giao dịch thẻ Pay2S';

    public const STATUS = [
        'pending' => 'Đang chờ',
        'paid' => 'Đã thanh toán',
        'review' => 'Cần hoàn tiền / đối soát',
        'resolved' => 'Đã xử lý hoàn tiền',
        'failed' => 'Thất bại',
        'cancelled' => 'Đã huỷ',
        'expired' => 'Hết hạn',
    ];

    public const KIND = ['initial' => 'Thanh toán đầu / cọc', 'remaining' => 'Phần còn lại', 'extra' => 'Phát sinh'];

    public static function getNavigationGroup(): ?string
    {
        return __('payment::payment-configuration.resource.navigation_group');
    }

    // Thẻ đang tắt và chưa từng có giao dịch thẻ ⇒ ẩn khỏi menu (như chưa có tính năng). Đã có giao dịch thì vẫn hiện để đối soát dù đang tắt.
    public static function shouldRegisterNavigation(): bool
    {
        return app(\App\Services\Payment\Pay2SCardService::class)->status()['enabled'] || Pay2SCardPayment::query()->exists();
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Pay2SCardPayment::query()->where('status', Pay2SCardPayment::REVIEW)->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('order'))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Thời gian')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('order.order_code')->label('Đơn')->searchable()->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('kind')->label('Khoản thu')->formatStateUsing(fn (string $state) => static::KIND[$state] ?? $state),
                Tables\Columns\TextColumn::make('card_brand')->label('Loại thẻ')->formatStateUsing(fn (?string $state) => $state ? strtoupper($state) : '—'),
                Tables\Columns\TextColumn::make('amount')->label('Số tiền')->numeric(0, ',', '.')->suffix(' ₫')->sortable(),
                Tables\Columns\TextColumn::make('environment')->label('Môi trường')->badge()->color(fn (?string $state) => $state === 'production' ? 'success' : 'gray')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn (string $state) => static::STATUS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'paid', 'resolved' => 'success', 'review' => 'danger', 'failed' => 'warning', 'pending' => 'info', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('failure_reason')->label('Lý do')->wrap()->limit(120)->placeholder('—'),
                Tables\Columns\TextColumn::make('pay2s_order_id')->label('Mã gửi Pay2S')->searchable()->copyable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('trans_id')->label('Mã giao dịch Pay2S')->toggleable(isToggledHiddenByDefault: true)->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Trạng thái')->options(static::STATUS),
            ])
            ->actions([
                Tables\Actions\Action::make('sync')
                    ->label('Hỏi Pay2S')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Pay2SCardPayment $record) => $record->status === Pay2SCardPayment::PENDING)
                    ->action(function (Pay2SCardPayment $record) {
                        $status = app(\App\Services\Payment\Pay2SCardService::class)->sync($record)->status;

                        Notification::make()->title('Trạng thái hiện tại: ' . (static::STATUS[$status] ?? $status))->success()->send();
                    }),
                Tables\Actions\Action::make('resolve')
                    ->label('Đã hoàn tiền')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Pay2SCardPayment $record) => $record->status === Pay2SCardPayment::REVIEW)
                    ->modalHeading('Đánh dấu đã xử lý hoàn tiền')
                    ->form([Forms\Components\Textarea::make('note')->label('Ghi chú (tuỳ chọn)')->maxLength(490)->rows(2)])
                    ->action(function (Pay2SCardPayment $record, array $data) {
                        app(\App\Services\Payment\Pay2SCardService::class)->resolve($record, (string) auth()->id(), $data['note'] ?? null);

                        Notification::make()->title('Đã đánh dấu đã xử lý')->success()->send();
                    }),
                Tables\Actions\Action::make('detail')
                    ->label('Chi tiết')
                    ->icon('heroicon-o-eye')
                    ->modalHeading('Dữ liệu Pay2S gửi về')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
                    ->modalContent(fn (Pay2SCardPayment $record) => new HtmlString(
                        '<pre style="white-space:pre-wrap;font-size:12px">' . e(json_encode($record->safePayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>'
                    )),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPay2SCardPayments::route('/')];
    }
}
