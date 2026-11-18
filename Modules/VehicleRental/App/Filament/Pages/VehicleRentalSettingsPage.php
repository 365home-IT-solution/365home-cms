<?php

declare(strict_types=1);

namespace Modules\VehicleRental\App\Filament\Pages;

use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\VehicleRental\App\Support\VehicleRentalAccess;
use Modules\VehicleRental\App\Support\VehicleRentalFeature;

// Cài đặt thuê xe (chỉ Super Admin): công tắc bật/tắt phần thuê xe trên website khách. Tắt = website như trước khi có thuê xe
// (không có trang /thue-xe, không có lối vào ở menu/tài khoản). Công tắc lưu ngay khi bấm.
class VehicleRentalSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Cài đặt thuê xe';

    protected static ?string $title = 'Cài đặt thuê xe';

    protected static ?string $slug = 'rental-vehicle-settings';

    protected static ?int $navigationSort = 9;

    protected static string $view = 'vehiclerental::pages.settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return VehicleRentalAccess::canManageSettings(auth()->user());
    }

    public function mount(): void
    {
        $this->form->fill(['client_enabled' => VehicleRentalFeature::clientEnabled()]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Website khách hàng')->schema([
                Forms\Components\Toggle::make('client_enabled')
                    ->label('Hiển thị phần thuê xe trên website')
                    ->helperText('Bật: website có trang "Thuê xe" (/thue-xe) kèm lối vào ở menu và trang tài khoản. Tắt: website giữ nguyên như khi chưa có thuê xe, không ai vào được trang thuê xe. Dữ liệu xe và đơn thuê không bị ảnh hưởng.')
                    ->live()
                    ->afterStateUpdated(function ($state) {
                        VehicleRentalFeature::setClientEnabled((bool) $state);
                        Notification::make()->success()->title($state ? 'Đã bật phần thuê xe trên website' : 'Đã tắt phần thuê xe trên website')->send();
                    }),
            ]),
        ]);
    }
}
