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
            Forms\Components\Section::make('Miễn phí tháng đầu cho đối tác mới ngoài tỉnh phải ký quỹ ngay')
                ->description('Bật: hợp đồng TẠO MỚI cho đối tác mới đủ điều kiện sẽ có điều "Ưu đãi tháng đầu"; khi hợp đồng được ký số, đối tác được miễn hoa hồng và miễn nạp ký quỹ trong thời gian miễn phí, sau đó ký quỹ và hoa hồng áp dụng như ban đầu. Tắt: mọi đối tác mới ký quỹ và tính hoa hồng ngay. Bật/tắt không làm đổi hợp đồng đã tạo và không ảnh hưởng đối tác đang hoạt động.')
                ->schema([
                    Forms\Components\Toggle::make('free_trial_enabled')
                        ->label('Bật miễn phí tháng đầu')
                        ->helperText('Chỉ áp dụng cho đối tác Homestay MỚI (ký hợp đồng lần đầu), có tỉnh/thành ở ngoài danh sách bên dưới và chưa từng được miễn (theo mã số thuế, CCCD người đại diện, số điện thoại). Đối tác chuyển sang bằng phụ lục không được miễn.')
                        ->live()
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('free_trial_months')
                        ->label('Số tháng miễn phí')->numeric()->integer()->minValue(1)->maxValue(12)->required()->suffix('tháng')
                        ->helperText('Tính từ ngày hợp đồng có hiệu lực (ngày 365home ký số).'),
                    Forms\Components\Select::make('immediate_province_codes')
                        ->label('Tỉnh/thành phải ký quỹ và tính hoa hồng ngay (không được miễn)')
                        ->multiple()->searchable()->preload()
                        ->options(fn () => Province::query()->whereNotNull('code')->orderBy('name')->pluck('name', 'code')->all())
                        ->helperText('Mặc định Thành phố Cần Thơ. Đối tác có cơ sở hoặc chi nhánh ở các tỉnh này không được miễn; đang được miễn mà phát sinh chi nhánh ở đây thì dừng miễn phí.'),
                    Forms\Components\Select::make('reminder_days')
                        ->label('Nhắc nạp ký quỹ trước khi hết miễn phí')
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
        $data['immediate_province_codes'] = array_values(array_map('intval', (array) ($data['immediate_province_codes'] ?? [])));
        $data['reminder_days'] = array_values(array_map('intval', (array) ($data['reminder_days'] ?? [])));

        return $data;
    }
}
