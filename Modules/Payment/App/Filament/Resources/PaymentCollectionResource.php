<?php

namespace Modules\Payment\App\Filament\Resources;

use App\Services\Payment\PaymentCollectionService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Modules\BladeThemeV1\Support\QrCodeGenerator;
use Modules\Payment\App\Filament\Resources\PaymentCollectionResource\Pages;
use Modules\Payment\Entities\PaymentCollection;

// "Mã thu tiền" — admin tạo khoản thu độc lập (số tiền + nội dung) cho khách: hệ thống sinh mã TTxxxxxx, QR và link trang QR để gửi khách. Tiền vào tài khoản Pay2S của 365home,
// khi Pay2S báo tiền vào đúng mã + đủ số tiền thì khoản tự chuyển "Đã thanh toán". Chỉ super_admin. Dùng chung PaymentCollectionService với API admin.
class PaymentCollectionResource extends Resource
{
    protected static ?string $model = PaymentCollection::class;

    protected static ?string $navigationIcon = 'heroicon-o-qr-code';

    protected static ?string $navigationLabel = 'Mã thu tiền';

    protected static ?string $modelLabel = 'mã thu tiền';

    protected static ?string $pluralModelLabel = 'Mã thu tiền';

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
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('amount')->label('Số tiền (₫)')->numeric()->required()
                ->minValue(PaymentCollectionService::MIN_AMOUNT)->maxValue(PaymentCollectionService::MAX_AMOUNT)
                ->helperText('Khách phải chuyển ĐÚNG hoặc nhiều hơn số tiền này thì mới tự ghi nhận.'),
            Forms\Components\TextInput::make('description')->label('Nội dung thu')->maxLength(255)->placeholder('VD: Phí dịch vụ tháng 12')
                ->helperText('Hiện trên trang QR gửi khách. Nội dung chuyển khoản luôn là mã TTxxxxxx hệ thống sinh ra.'),
            Forms\Components\TextInput::make('customer_name')->label('Tên khách')->maxLength(255),
            Forms\Components\TextInput::make('customer_phone')->label('Số điện thoại khách')->tel()->maxLength(30),
            Forms\Components\Select::make('expires_in_hours')->label('Hiệu lực của trang QR')->native(false)->default(24)->selectablePlaceholder(false)
                ->options([1 => '1 giờ', 6 => '6 giờ', 24 => '24 giờ', 72 => '3 ngày', 168 => '7 ngày', 0 => 'Không giới hạn'])
                ->helperText('Hết hạn thì trang QR báo đã đóng, nhưng tiền chuyển về muộn (chưa huỷ) vẫn được ghi nhận.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Tạo lúc')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('code')->label('Mã (nội dung CK)')->searchable()->copyable()->weight('bold'),
                Tables\Columns\TextColumn::make('amount')->label('Số tiền')->numeric(0, ',', '.')->suffix(' ₫')->sortable(),
                Tables\Columns\TextColumn::make('description')->label('Nội dung thu')->searchable()->wrap()->limit(60)->placeholder('—'),
                Tables\Columns\TextColumn::make('customer_name')->label('Khách')->description(fn (PaymentCollection $record) => $record->customer_phone)->searchable(['customer_name', 'customer_phone'])->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn (PaymentCollection $record) => $record->isExpired() ? 'Hết hạn' : (PaymentCollection::STATUSES[$record->status] ?? $record->status))
                    ->color(fn (PaymentCollection $record) => match (true) {
                        $record->status === PaymentCollection::PAID => 'success',
                        $record->status === PaymentCollection::CANCELLED => 'gray',
                        $record->isExpired() => 'warning',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('paid_at')->label('Thanh toán lúc')->dateTime('d/m/Y H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('expires_at')->label('Hết hạn')->dateTime('d/m/Y H:i')->placeholder('Không giới hạn')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Trạng thái')->options(PaymentCollection::STATUSES),
            ])
            ->actions([
                Tables\Actions\Action::make('qr')
                    ->label('Xem QR / gửi khách')
                    ->icon('heroicon-o-qr-code')
                    ->modalHeading(fn (PaymentCollection $record) => 'Mã thu tiền ' . $record->code)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
                    ->modalContent(fn (PaymentCollection $record) => static::qrContent($record)),
                Tables\Actions\Action::make('cancel')
                    ->label('Huỷ')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('QR đã gửi khách sẽ không còn dùng được. Nếu khách vẫn chuyển khoản, khoản tiền sẽ hiện ở "Giao dịch SePay / Pay2S" (rejected) để hoàn tiền thủ công.')
                    ->visible(fn (PaymentCollection $record) => $record->status === PaymentCollection::PENDING)
                    ->action(function (PaymentCollection $record) {
                        app(PaymentCollectionService::class)->cancel($record);

                        Notification::make()->title('Đã huỷ mã thu tiền')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    /** Nội dung cửa sổ "Xem QR": ảnh QR, thông tin chuyển khoản, link trang QR để chép gửi khách. */
    private static function qrContent(PaymentCollection $record): HtmlString
    {
        $data = $record->toApi();
        $qr = QrCodeGenerator::dataUri($data['qr_code'], 260);
        $url = e($data['checkout_url']);

        return new HtmlString(
            '<div style="text-align:center">'
            . '<img src="' . e($qr) . '" alt="QR" style="margin:0 auto;width:260px;height:260px" />'
            . '<p style="margin-top:12px;font-size:18px;font-weight:600">' . number_format($record->amount, 0, ',', '.') . ' ₫</p>'
            . '<p style="font-size:13px;opacity:.8">' . e((string) $data['bank']['code']) . ' · ' . e((string) $data['bank']['account_number']) . ' · ' . e((string) $data['bank']['account_holder']) . '</p>'
            . '<p style="font-size:13px">Nội dung chuyển khoản: <b>' . e($record->code) . '</b></p>'
            . '<p style="margin-top:12px;font-size:12px;word-break:break-all">Link gửi khách: <a href="' . $url . '" target="_blank" rel="noopener" style="text-decoration:underline">' . $url . '</a></p>'
            . '</div>'
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentCollections::route('/'),
            'create' => Pages\CreatePaymentCollection::route('/create'),
        ];
    }
}
