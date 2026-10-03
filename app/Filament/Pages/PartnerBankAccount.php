<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Partner;
use App\Models\User;
use App\Services\PartnerOnboardingService;
use App\Support\Banks;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

// Chủ đối tác tự thiết lập TÀI KHOẢN NGÂN HÀNG sau khi đăng nhập (không còn là bước đăng ký). Ghi thẳng vào partners.bank_* —
// chính các trường Super Admin xem ở tab "Tài chính" của đối tác nên hai bên luôn đồng bộ. Ngân hàng CHỌN từ danh sách.
class PartnerBankAccount extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string  $view            = 'filament.pages.partner-bank-account';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationIcon  = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Tài khoản ngân hàng';
    protected static ?string $title           = 'Tài khoản ngân hàng';
    protected static ?int    $navigationSort  = 90;

    public ?array $data = [];

    // Chỉ chủ đối tác (vai trò partner / Quản lý MiniHouse) hoặc tài khoản được tích quyền page_PartnerBankAccount; Super Admin sửa ở trang Đối tác.
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->partner_id !== null
            && ($user->hasRole('partner') || $user->hasRole('Quản lý MiniHouse') || $user->can('page_PartnerBankAccount'));
    }

    public function mount(): void
    {
        $partner = $this->partner();
        $this->form->fill([
            'bank_code'           => Banks::findByShortName($partner?->bank_name)['code'] ?? null,
            'bank_branch'         => $partner?->bank_branch,
            'bank_account_number' => $partner?->bank_account_number,
            'bank_account_holder' => $partner?->bank_account_holder,
        ]);
    }

    private function partner(): ?Partner
    {
        $id = auth()->user()?->partner_id;

        return $id ? Partner::withoutGlobalScopes()->find($id) : null;
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Section::make('Tài khoản nhận tiền / đối soát')
                ->description('Thông tin này đồng bộ với hồ sơ đối tác tại 365 Home (tab Tài chính).')
                ->schema([
                    Select::make('bank_code')->label('Ngân hàng')->options(collect(Banks::all())->mapWithKeys(fn ($b, $code) => [$code => $b['short_name'] . ' — ' . $b['name']])->all())->searchable()->required(),
                    TextInput::make('bank_branch')->label('Chi nhánh')->maxLength(255),
                    TextInput::make('bank_account_number')->label('Số tài khoản')->required()->maxLength(20)->regex('/^[0-9]{6,20}$/')
                        ->validationMessages(['regex' => 'Số tài khoản chỉ gồm 6–20 chữ số.']),
                    TextInput::make('bank_account_holder')->label('Chủ tài khoản')->required()->maxLength(255)->helperText('Họ tên viết hoa, không dấu như trên thẻ.')
                        ->dehydrateStateUsing(fn ($state) => mb_strtoupper(trim((string) $state))),
                ])->columns(2),
        ]);
    }

    public function save(): void
    {
        $partner = $this->partner();
        if (! $partner || ! static::canAccess()) {
            Notification::make()->title('Không có quyền cập nhật')->danger()->send();

            return;
        }

        $service = app(PartnerOnboardingService::class);
        $service->updateBankAccount($partner, $this->form->getState());
        $service->logBankUpdate($partner->fresh(), auth()->user());

        Notification::make()->title('Đã lưu tài khoản ngân hàng')->success()->send();
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('Lưu thay đổi')->submit('save')];
    }
}
