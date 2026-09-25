<?php

namespace Modules\Payment\App\Filament\Resources;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Modules\Category\Entities\Category;
use Modules\Minihouse\App\Support\HomestayBridge;
use Modules\Payment\App\Filament\Resources\BranchPayOsAccountResource\Pages;
use Modules\Payment\Entities\BranchPayOsAccount;
use PayOS\PayOS;
use Throwable;

// "PayOS theo chi nhánh" — kết nối tài khoản PayOS RIÊNG của chủ nhà cho 1 chi nhánh Homestay: khách
// đặt phòng ở chi nhánh đó thanh toán thẳng vào tài khoản chủ nhà thay vì tài khoản chung ("Thanh
// toán online"). Chỉ super_admin — đây là nơi đổi tiền chảy về tài khoản nào. Toà nhà MiniHouse có
// cấu hình PayOS riêng ở form Toà nhà, không quản lý ở đây.
class BranchPayOsAccountResource extends Resource
{
    protected static ?string $model = BranchPayOsAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'PayOS theo chi nhánh';

    protected static ?string $modelLabel = 'tài khoản PayOS chi nhánh';

    protected static ?string $pluralModelLabel = 'PayOS theo chi nhánh';

    public static function getNavigationGroup(): ?string
    {
        return __('payment::payment-configuration.resource.navigation_group');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function webhookUrl(): string
    {
        return route('webhook.payos');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Chi nhánh')
                ->schema([
                    Forms\Components\Select::make('category_id')
                        ->label('Chi nhánh')
                        ->options(fn () => Category::query()
                            ->whereNull('parent_id')
                            ->where('category_type', 'product')
                            ->where(fn (Builder $q) => $q
                                ->whereNull('partner_id')
                                ->orWhere('partner_id', '!=', HomestayBridge::PARTNER_ID))
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->validationMessages(['unique' => 'Chi nhánh này đã có tài khoản PayOS riêng — sửa dòng đang có thay vì tạo mới.'])
                        ->helperText('Áp dụng cho cả các khu vực con của chi nhánh.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Đang dùng tài khoản riêng')
                        ->helperText('Tắt = chi nhánh quay về dùng tài khoản PayOS chung của hệ thống. Link đã tạo trước đó vẫn được tra cứu/xác nhận bình thường.')
                        ->default(true),

                    Forms\Components\TextInput::make('account_holder')
                        ->label('Chủ tài khoản / chủ nhà')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('note')
                        ->label('Ghi chú')
                        ->maxLength(255),
                ])
                ->columns(2),

            Forms\Components\Section::make('Thông tin kênh thanh toán PayOS của chủ nhà')
                ->description('Lấy tại my.payos.vn → Kênh thanh toán → chọn kênh của chủ nhà. Các khoá được mã hoá khi lưu.')
                ->schema([
                    Forms\Components\TextInput::make('client_id')
                        ->label('Client ID')
                        ->password()->revealable()
                        ->required(),
                    Forms\Components\TextInput::make('api_key')
                        ->label('API Key')
                        ->password()->revealable()
                        ->required(),
                    Forms\Components\TextInput::make('checksum_key')
                        ->label('Checksum Key')
                        ->password()->revealable()
                        ->required(),
                    Forms\Components\Placeholder::make('webhook_hint')
                        ->label('Webhook URL')
                        ->content(fn () => new HtmlString(
                            '<code>' . e(self::webhookUrl()) . '</code><br>'
                            . '<span style="font-size:0.8rem;color:#6b7280;">Sau khi lưu, bấm "Đăng ký webhook" ở danh sách (hoặc tự dán URL này vào kênh thanh toán trên my.payos.vn) để đơn tự xác nhận khi khách chuyển khoản.</span>'
                        )),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category'))
            ->columns([
                Tables\Columns\TextColumn::make('category.name')
                    ->label('Chi nhánh')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('account_holder')
                    ->label('Chủ tài khoản')
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Đang dùng')
                    ->boolean(),
                Tables\Columns\TextColumn::make('webhook_confirmed_at')
                    ->label('Webhook')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Chưa đăng ký'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('d/m/Y H:i'),
            ])
            ->actions([
                Tables\Actions\Action::make('confirmWebhook')
                    ->label('Đăng ký webhook')
                    ->icon('heroicon-o-link')
                    ->requiresConfirmation()
                    ->modalDescription(fn () => 'Đăng ký ' . self::webhookUrl() . ' làm webhook cho kênh PayOS này. Đồng thời kiểm tra Client ID/API Key có hợp lệ không.')
                    ->action(function (BranchPayOsAccount $record) {
                        try {
                            (new PayOS(...$record->credentials()))->confirmWebhook(self::webhookUrl());
                            $record->update(['webhook_confirmed_at' => now()]);

                            Notification::make()->success()->title('Đã đăng ký webhook thành công')->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->danger()
                                ->title('Không đăng ký được webhook')
                                ->body($e->getMessage() . ' — kiểm tra lại Client ID/API Key, và URL phải truy cập được công khai (không chạy được trên máy local).')
                                ->persistent()
                                ->send();
                        }
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBranchPayOsAccounts::route('/'),
            'create' => Pages\CreateBranchPayOsAccount::route('/create'),
            'edit'   => Pages\EditBranchPayOsAccount::route('/{record}/edit'),
        ];
    }
}
