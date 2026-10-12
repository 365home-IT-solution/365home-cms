<?php

namespace App\Filament\Pages\Setting;

use App\Models\Province;
use App\Settings\EscrowSettings;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\SettingsPage;

// Cấu hình MIỄN PHÍ THÁNG ĐẦU của ký quỹ (xem App\Services\PartnerTrialService). Một công tắc bật/tắt chính sách "đối tác mới ngoài tỉnh phải ký quỹ ngay được miễn hoa hồng
// và miễn nạp ký quỹ trong thời gian đầu"; số tháng, danh sách tỉnh phải ký quỹ ngay và mốc nhắc đều đổi ở đây, không phải sửa code. Mặc định TẮT.
class ManageEscrowSettings extends SettingsPage
{
    use HasPageShield;

    protected static string $settings = EscrowSettings::class;

    protected static ?int $navigationSort = 97;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $title = 'Ký quỹ — miễn phí tháng đầu';

    public static function getNavigationGroup(): ?string
    {
        return 'Cấu hình web';
    }

    public static function getNavigationLabel(): string
    {
        return 'Ký quỹ';
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Miễn phí hoa hồng tháng đầu cho đối tác mới')
                ->description('Bật: đối tác mới đủ điều kiện (mọi tỉnh/thành, kể cả Cần Thơ) được miễn hoa hồng trong thời gian miễn phí kể từ khi hợp đồng có hiệu lực. Chính sách này KHÔNG ghi trong văn bản hợp đồng. Khoản tiền đảm bảo của đối tác theo mẫu CT (trong Cần Thơ) vẫn phải nộp trước khi mở bán; mẫu VT+ĐL không có ký quỹ. Tắt: hoa hồng tính ngay từ đơn đầu tiên. Không ảnh hưởng đối tác đang hoạt động.')
                ->schema([
                    Forms\Components\Toggle::make('free_trial_enabled')
                        ->label('Bật miễn phí tháng đầu')
                        ->helperText('Chỉ áp dụng cho đối tác Homestay MỚI (ký hợp đồng lần đầu) và chưa từng được miễn (theo mã số thuế, CCCD người đại diện, số điện thoại). Đối tác chuyển sang bằng phụ lục không được miễn.')
                        ->live()
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('free_trial_months')
                        ->label('Số tháng miễn phí')->numeric()->integer()->minValue(1)->maxValue(12)->required()->suffix('tháng')
                        ->helperText('Tính từ ngày hợp đồng có hiệu lực (ngày 365home ký số).'),
                    Forms\Components\TextInput::make('guarantee_vnd')
                        ->label('Khoản tiền đảm bảo (Điều 4.2 hợp đồng mẫu CT)')->numeric()->integer()->minValue(100000)->maxValue(100000000)->required()->suffix('đ')
                        ->helperText('Mặc định 1.000.000đ. Hợp đồng TẠO MỚI in đúng số này và đối tác mẫu CT phải nộp đủ trước khi mở bán. Hợp đồng đã tạo giữ nguyên số đã in.'),
                    Forms\Components\Select::make('immediate_province_codes')
                        ->label('Tỉnh/thành dùng mẫu hợp đồng CT (tiền về Bên A, có khoản tiền đảm bảo)')
                        ->multiple()->searchable()->preload()
                        ->options(fn () => Province::query()->whereNotNull('code')->orderBy('name')->pluck('name', 'code')->all())
                        ->helperText('Mặc định Thành phố Cần Thơ. Cơ sở ở các tỉnh này dùng mẫu CT (365home thu hộ, khoản tiền đảm bảo, đối soát hằng tháng); nơi khác dùng mẫu VT+ĐL.'),
                    Forms\Components\Select::make('reminder_days')
                        ->label('Nhắc trước khi hết miễn phí')
                        ->multiple()->native(false)
                        ->options([14 => '14 ngày trước', 7 => '7 ngày trước', 3 => '3 ngày trước', 1 => '1 ngày trước'])
                        ->helperText('Mỗi mốc nhắc một lần (thông báo trong app, push và email), bỏ qua đối tác đã nạp đủ. Hết thời gian miễn phí mà chưa nạp đủ thì phòng bị tạm ngưng bán.'),
                ])->columns(2),
        ]);
    }

    // Lưu về dạng số nguyên (Select trả chuỗi) để so sánh mã tỉnh chính xác.
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['free_trial_months'] = max(1, (int) ($data['free_trial_months'] ?? 1));
        $data['guarantee_vnd'] = max(100000, (int) ($data['guarantee_vnd'] ?? 1000000));
        $data['immediate_province_codes'] = array_values(array_map('intval', (array) ($data['immediate_province_codes'] ?? [])));
        $data['reminder_days'] = array_values(array_map('intval', (array) ($data['reminder_days'] ?? [])));

        return $data;
    }
}
