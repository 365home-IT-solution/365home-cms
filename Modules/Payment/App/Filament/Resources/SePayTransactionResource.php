<?php

namespace Modules\Payment\App\Filament\Resources;

use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Modules\Payment\App\Filament\Resources\SePayTransactionResource\Pages;
use Modules\Payment\Entities\SePayTransaction;

// "Giao dịch SePay" — nhật ký MỌI giao dịch tiền vào mà SePay báo về, chỉ đọc, chỉ super_admin. Dùng để đối soát: giao dịch "Bị từ chối"
// (sai số tiền, chuyển trùng, đơn đã hết hạn...) là tiền ĐÃ VÀO tài khoản đối tác nhưng đơn chưa được xác nhận — cần xử lý thủ công/hoàn tiền.
class SePayTransactionResource extends Resource
{
    protected static ?string $model = SePayTransaction::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Giao dịch SePay';

    protected static ?string $modelLabel = 'giao dịch SePay';

    protected static ?string $pluralModelLabel = 'Giao dịch SePay';

    public const STATUS = [
        'processed' => 'Đã xác nhận đơn',
        'rejected' => 'Bị từ chối',
        'failed' => 'Lỗi xử lý (SePay sẽ gửi lại)',
        'received' => 'Đang xử lý',
    ];

    public static function getNavigationGroup(): ?string
    {
        return __('payment::payment-configuration.resource.navigation_group');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('account.partner'))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('transaction_at')->label('Thời gian')->dateTime('d/m/Y H:i')->sortable()->placeholder('—'),
                Tables\Columns\TextColumn::make('account.partner.name')->label('Đối tác')->placeholder('Không xác định')->wrap(),
                Tables\Columns\TextColumn::make('payment_code')->label('Mã thanh toán')->searchable()->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('amount')->label('Số tiền')->numeric(0, ',', '.')->suffix(' ₫')->sortable(),
                Tables\Columns\TextColumn::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn (string $state) => static::STATUS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'processed' => 'success', 'rejected' => 'danger', 'failed' => 'warning', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('failure_reason')->label('Lý do')->wrap()->limit(120)->placeholder('—'),
                Tables\Columns\TextColumn::make('reference_code')->label('Mã tham chiếu')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Trạng thái')->options(static::STATUS),
            ])
            ->actions([
                Tables\Actions\Action::make('detail')
                    ->label('Chi tiết')
                    ->icon('heroicon-o-eye')
                    ->modalHeading('Dữ liệu SePay gửi về')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
                    ->modalContent(fn (SePayTransaction $record) => new HtmlString(
                        '<pre style="white-space:pre-wrap;font-size:12px">' . e(json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>'
                    )),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSePayTransactions::route('/')];
    }
}
