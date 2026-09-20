<?php

declare(strict_types=1);

namespace App\Filament\Pages\Setting;

use App\Settings\InvoiceSettings;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Support\Facades\FilamentView;
use Illuminate\Contracts\Support\Htmlable;

use function Filament\Support\is_app_url;

// Cấu hình kết nối MISA meInvoice — nơi DUY NHẤT cần điền thông tin để tính năng "Xuất hoá đơn"
// (nút trên OrderResource, xem IssueInvoiceDraftAction) chuyển từ chỉ tạo bản nháp sang phát hành
// hoá đơn thật qua MisaInvoiceClient. Không bật "Kích hoạt" hoặc thiếu bất kỳ ô bắt buộc nào thì
// InvoiceSettings::isConfigured() = false, hệ thống chỉ tạo được bản nháp có watermark, không gọi
// API MISA thật — xem Modules\Invoice\App\Services\MisaInvoiceClient.
class ManageInvoice extends SettingsPage
{
    use HasPageShield;

    protected static string $settings = InvoiceSettings::class;

    protected static ?int $navigationSort = 98;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Kích hoạt')
                    ->schema([
                        Forms\Components\Toggle::make('enabled')
                            ->label('Bật xuất hoá đơn điện tử thật qua MISA')
                            ->helperText('Khi tắt (mặc định), nút "Xuất hoá đơn" trên đơn hàng chỉ tạo được bản nháp nội bộ, không gọi MISA.')
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Kết nối MISA meInvoice')
                    ->description('Lấy từ bộ phận tích hợp MISA khi đăng ký dịch vụ meInvoice — KHÔNG phải tài khoản đăng nhập web MISA thông thường.')
                    ->schema([
                        Forms\Components\TextInput::make('base_url')
                            ->label('Base URL')
                            ->placeholder('https://testapi.meinvoice.vn/api/v3')
                            ->helperText('Dùng testapi.meinvoice.vn/api/v3 khi thử nghiệm, chuyển sang api.meinvoice.vn/api/v3 khi phát hành thật.')
                            ->url()
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('app_id')
                            ->label('AppID')
                            ->password()
                            ->revealable(),

                        Forms\Components\TextInput::make('tax_code')
                            ->label('Mã số thuế công ty')
                            ->password()
                            ->revealable(),

                        Forms\Components\TextInput::make('username')
                            ->label('Tài khoản kỹ thuật (API)')
                            ->password()
                            ->revealable(),

                        Forms\Components\TextInput::make('password')
                            ->label('Mật khẩu tài khoản kỹ thuật')
                            ->password()
                            ->revealable(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Mẫu số / Ký hiệu hoá đơn')
                    ->description('Chuỗi đã đăng ký chính thức với MISA và cơ quan thuế — sai chuỗi này hoá đơn sẽ bị từ chối phát hành.')
                    ->schema([
                        Forms\Components\TextInput::make('invoice_type_code')
                            ->label('Loại mẫu hoá đơn')
                            ->placeholder('01GTKT'),

                        Forms\Components\TextInput::make('invoice_template_code')
                            ->label('Mẫu số')
                            ->placeholder('01GTKT0/001'),

                        Forms\Components\TextInput::make('invoice_series')
                            ->label('Ký hiệu')
                            ->placeholder('1C25TYY'),

                        Forms\Components\TextInput::make('default_vat_rate')
                            ->label('Thuế suất GTGT mặc định (%)')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('%'),
                    ])
                    ->columns(4),

                Forms\Components\Section::make('Ký số')
                    ->description('Quyết định cách ký hoá đơn trước khi phát hành — hỏi bộ phận tích hợp MISA nếu chưa rõ công ty đang dùng loại nào.')
                    ->schema([
                        Forms\Components\Select::make('signing_mode')
                            ->label('Phương thức ký số')
                            ->options([
                                'usb_token' => 'USB Token (SignService cục bộ)',
                                'hsm'       => 'Chữ ký số từ xa / HSM (API)',
                            ])
                            ->native(false),

                        Forms\Components\TextInput::make('hsm_endpoint')
                            ->label('Endpoint HSM (nếu chọn ký từ xa)')
                            ->password()
                            ->revealable()
                            ->visible(fn ($get) => $get('signing_mode') === 'hsm'),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(InvoiceSettings $settings = null): void
    {
        $data = $this->form->getState();

        $settings->fill($data);
        $settings->save();

        Notification::make()->title('Đã lưu cấu hình hoá đơn điện tử.')->success()->send();

        $this->redirect(static::getUrl(), navigate: FilamentView::hasSpaMode() && is_app_url(static::getUrl()));
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Cấu hình web';
    }

    public static function getNavigationLabel(): string
    {
        return 'Hoá đơn điện tử';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Cấu hình hoá đơn điện tử (MISA)';
    }
}
