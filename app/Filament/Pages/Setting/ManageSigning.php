<?php

namespace App\Filament\Pages\Setting;

use App\Filament\Support\SigningProviderForm;
use App\Models\ContractSigningToken;
use App\Services\ContractSigning\ContractSigningManager;
use App\Settings\SigningSettings;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\SettingsPage;
use Illuminate\Support\Facades\Log;

// Cấu hình CHỮ KÝ SỐ của HOMESTAY ngay trong web (thay cho sửa .env): chọn nhà cung cấp (VNPT SmartCA, MISA eSign, nhà cung cấp khác theo chuẩn CSC,
// hoặc ký thử nghiệm) để nền tảng ký số hợp đồng đối tác, nhập thông tin nhà cung cấp, kiểm tra kết nối (không tốn lượt ký).
// MiniHouse có trang cấu hình RIÊNG ở panel MiniHouse (Hệ thống > Chữ ký số) và lưu ở nơi khác — trang này không đụng tới.
class ManageSigning extends SettingsPage
{
    use HasPageShield;

    protected static string $settings = SigningSettings::class;

    protected static ?int $navigationSort = 98;

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $title = 'Chữ ký số';

    public static function getNavigationGroup(): ?string
    {
        return 'Cấu hình web';
    }

    public static function getNavigationLabel(): string
    {
        return 'Chữ ký số';
    }

    // Dữ liệu mã hoá bằng APP_KEY khác (import DB từ máy khác...) không được làm sập trang: coi như chưa nhập.
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        try {
            $data = app(static::getSettings())->toArray();
        } catch (\Throwable $e) {
            Log::warning('ManageSigning: không giải mã được cấu hình chữ ký số đã lưu — hiển thị form trống.', ['error' => $e->getMessage()]);
            $data = SigningProviderForm::emptyState();
        }

        $this->form->fill($this->mutateFormDataBeforeFill($data));

        $this->callHook('afterFill');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Nhà cung cấp chữ ký số — Homestay')
                ->description('Dùng để nền tảng ký số hợp đồng với đối tác Homestay (hợp đồng và phụ lục). Nhập trực tiếp tại đây — không cần sửa file .env; để trống ô nào thì hệ thống dùng lại giá trị trong .env (nếu có).')
                ->schema([SigningProviderForm::providerSelect('Nhà cung cấp')]),
            ...SigningProviderForm::sections(),
        ]);
    }

    public function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            Action::make('testConnection')->label('Kiểm tra kết nối')->color('gray')->icon('heroicon-o-signal')
                ->action(fn () => SigningProviderForm::test($this->form->getState(), ContractSigningManager::SIDE_HOMESTAY)),
        ];
    }

    // Lưu xong thì xoá token đăng nhập cũ của Homestay để lần sau đăng nhập lại bằng thông tin mới (không đụng token của MiniHouse).
    protected function afterSave(): void
    {
        ContractSigningToken::query()->where('provider', ContractSigningManager::vnptTokenKey(ContractSigningManager::SIDE_HOMESTAY))->delete();
    }
}
