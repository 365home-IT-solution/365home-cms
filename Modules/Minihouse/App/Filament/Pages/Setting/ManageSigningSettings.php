<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Pages\Setting;

use App\Filament\Support\SigningProviderForm;
use App\Models\ContractSigningToken;
use App\Services\ContractSigning\ContractSigningManager;
use App\Settings\MinihouseSigningSettings;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\SettingsPage;
use Illuminate\Support\Facades\Log;

// Cấu hình CHỮ KÝ SỐ của MINIHOUSE — chủ trọ ký số (PAdES, Mức C) lên PDF cuối của hợp đồng thuê. Lưu ở MinihouseSigningSettings, TÁCH BIỆT HOÀN TOÀN với
// trang "Chữ ký số" của Homestay (App\Filament\Pages\Setting\ManageSigning): nhà cung cấp, tài khoản và công tắc của 2 bên độc lập nhau. Chỉ super_admin.
class ManageSigningSettings extends SettingsPage
{
    protected static string $settings = MinihouseSigningSettings::class;

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $navigationGroup = 'Hệ thống';

    protected static ?string $navigationLabel = 'Chữ ký số';

    protected static ?string $title = 'Chữ ký số (MiniHouse)';

    protected static ?int $navigationSort = 95;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        try {
            $data = app(static::getSettings())->toArray();
        } catch (\Throwable $e) {
            Log::warning('MiniHouse ManageSigningSettings: không giải mã được cấu hình chữ ký số đã lưu — hiển thị form trống.', ['error' => $e->getMessage()]);
            $data = SigningProviderForm::emptyState() + ['pki_enabled' => false];
        }

        $this->form->fill($this->mutateFormDataBeforeFill($data));

        $this->callHook('afterFill');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Chữ ký số hợp đồng thuê — MiniHouse')
                ->description('Cấu hình này chỉ áp dụng cho hợp đồng thuê của MiniHouse và độc lập với cấu hình của Homestay. Nhập trực tiếp tại đây; để trống ô nào thì hệ thống dùng lại giá trị trong .env (nếu có).')
                ->schema([
                    Forms\Components\Toggle::make('pki_enabled')->label('Bắt buộc chủ trọ ký SỐ khi ký chốt hợp đồng thuê (Mức C)')
                        ->helperText('Tắt = giữ nguyên như hiện nay (ký tay + OTP). Bật = ký chốt xong phải ký số PAdES bằng chứng thư của nhà cung cấp đã chọn; ký số lỗi thì KHÔNG chốt hợp đồng. Khách thuê vẫn ký tay + OTP.'),
                    SigningProviderForm::providerSelect('Nhà cung cấp'),
                ]),
            ...SigningProviderForm::sections(),
        ]);
    }

    public function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            Action::make('testConnection')->label('Kiểm tra kết nối')->color('gray')->icon('heroicon-o-signal')
                ->action(fn () => SigningProviderForm::test($this->form->getState(), ContractSigningManager::SIDE_MINIHOUSE)),
        ];
    }

    protected function afterSave(): void
    {
        ContractSigningToken::query()->where('provider', ContractSigningManager::vnptTokenKey(ContractSigningManager::SIDE_MINIHOUSE))->delete();
    }
}
