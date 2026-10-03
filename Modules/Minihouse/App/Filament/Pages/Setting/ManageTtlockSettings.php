<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Pages\Setting;

use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\TtlockSetting;
use Modules\Minihouse\App\Services\ContractTtlockService;
use Modules\Minihouse\App\Support\ActiveBuildingScope;
use Modules\TTLock\App\Services\TTLockService;

// Mirror Modules\TTLock\App\Filament\Resources\TtlockAccountResource (Home) — khác đúng 1 chỗ: Home
// có bảng "Tài khoản TTLock" dùng chung, gán nhiều chi nhánh vào 1 tài khoản; MiniHouse cấu hình
// TỪNG TOÀ NHÀ (mỗi Toà nhà tự có tài khoản TTLock Open Platform riêng, không có cấu hình chung) —
// cùng mô hình ManageCameraSettings. Xem Modules\Minihouse\App\Models\TtlockSetting.
class ManageTtlockSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-lock-closed';
    protected static ?string $navigationGroup = 'Hệ thống';
    protected static ?string $navigationLabel = 'Cấu hình TTLock';
    protected static ?string $title           = 'Cấu hình TTLock theo Toà nhà';
    protected static ?int    $navigationSort  = 98;

    protected static string $view = 'minihouse::filament.pages.setting.manage-ttlock-settings';

    public ?string $buildingId = null;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('page_manage_ttlock_settings') ?? false);
    }

    public function updatedBuildingId(): void
    {
        $this->fillFormForBuilding();
    }

    private function fillFormForBuilding(): void
    {
        if (blank($this->buildingId) || ! in_array((int) $this->buildingId, ActiveBuildingScope::permittedBuildingIds(), true)) {
            $this->buildingId = null;
            $this->form->fill([]);

            return;
        }

        $setting = TtlockSetting::forBuilding((int) $this->buildingId);

        // client_secret/password_md5 KHÔNG đổ lại ra form (bí mật) — để trống = giữ nguyên giá trị cũ.
        $this->form->fill([
            'client_id' => $setting->client_id,
            'username'  => $setting->username,
            'api_base'  => $setting->api_base ?: 'https://euapi.ttlock.com',
            'is_active' => $setting->exists ? $setting->is_active : true,
            'gate_lock_ids'  => $setting->gateLockIds(),
            'gate_code_mode' => $setting->gate_code_mode ?: TtlockSetting::GATE_CODE_SHARED,
            'issue_mode'     => $setting->issue_mode ?: TtlockSetting::ISSUE_ON_CONTRACT,
        ]);
    }

    // Danh sách khoá của Toà nhà để chọn làm KHOÁ CỔNG — form tính lại options mỗi lần re-render nên
    // cache ngắn, không gọi TTLock /v3/lock/list liên tục (không cache kết quả rỗng: vừa lưu tài khoản
    // xong phải thấy khoá ngay).
    public function lockOptions(): array
    {
        if (! $this->hasSecrets()) {
            return [];
        }

        $key = "minihouse_ttlock_gate_lock_options_{$this->buildingId}";

        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $options = [];

        foreach (TTLockService::forBuilding((int) $this->buildingId)?->getLockList() ?? [] as $lock) {
            $options[(int) $lock['lockId']] = ($lock['lockAlias'] ?? $lock['lockName'] ?? "Lock #{$lock['lockId']}") . ' • ' . ($lock['lockMac'] ?? '');
        }

        if ($options) {
            Cache::put($key, $options, now()->addMinutes(5));
        }

        return $options;
    }

    public function buildingOptions(): array
    {
        return Building::withoutGlobalScope('activeBuilding')
            ->whereIn('id', ActiveBuildingScope::permittedBuildingIds())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function hasSecrets(): bool
    {
        return filled($this->buildingId) && TtlockSetting::forBuilding((int) $this->buildingId)->isConfigured();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Tài khoản TTLock Open Platform')
                    ->description('Thông tin xác thực của CHÍNH TOÀ NHÀ NÀY — mỗi toà nhà dùng tài khoản riêng, không chung với toà nhà khác.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('client_id')->label('Client ID')->required()->maxLength(100),
                        Forms\Components\TextInput::make('client_secret')->label('Client Secret')
                            ->password()->revealable()->maxLength(100)
                            ->required(fn () => ! $this->hasSecrets())
                            ->helperText('Để trống = giữ nguyên giá trị đã lưu.'),
                        Forms\Components\TextInput::make('username')->label('Username (email hoặc số điện thoại TTLock App)')->required()->maxLength(100),
                        Forms\Components\TextInput::make('password_md5')->label('Mật khẩu TTLock')
                            ->password()->revealable()->maxLength(64)
                            ->required(fn () => ! $this->hasSecrets())
                            ->helperText('Nhập mật khẩu thường hoặc chuỗi MD5 (32 ký tự) — hệ thống tự chuyển sang MD5. Để trống = giữ nguyên.'),
                        Forms\Components\TextInput::make('api_base')->label('API Base URL')
                            ->default('https://euapi.ttlock.com')->required()->url()->maxLength(200)->columnSpanFull(),
                        Forms\Components\Toggle::make('is_active')->label('Kích hoạt')->default(true),
                    ]),
                Forms\Components\Section::make('Khoá cổng & cấp mã cho khách thuê')
                    ->description('Mã mở được cấp tự động theo Hợp đồng: có hạn đúng bằng thời hạn hợp đồng, tự xoá khi thanh lý/huỷ.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('gate_lock_ids')->label('Khoá cổng của toà nhà')
                            ->multiple()->searchable()
                            ->options(fn () => $this->lockOptions())
                            ->helperText(fn () => $this->hasSecrets()
                                ? 'Mọi hợp đồng đang hiệu lực của toà đều được cấp mã trên các khoá này. Để trống nếu toà không có khoá cổng TTLock.'
                                : 'Lưu tài khoản TTLock ở trên trước, sau đó mới chọn được khoá cổng.')
                            ->columnSpanFull(),
                        Forms\Components\Radio::make('gate_code_mode')->label('Mã cổng')
                            ->options([
                                TtlockSetting::GATE_CODE_SHARED   => 'Dùng chung với mã phòng',
                                TtlockSetting::GATE_CODE_SEPARATE => 'Mã riêng cho cổng',
                            ])
                            ->descriptions([
                                TtlockSetting::GATE_CODE_SHARED   => 'Khách chỉ nhớ 1 số, mở được cả cổng lẫn phòng.',
                                TtlockSetting::GATE_CODE_SEPARATE => 'Mỗi hợp đồng có 1 mã cổng và 1 mã phòng khác nhau, đổi độc lập.',
                            ])
                            ->default(TtlockSetting::GATE_CODE_SHARED)->required()
                            ->helperText('Đổi lựa chọn này chỉ áp dụng cho mã cấp mới/đổi mã sau đó, mã đang dùng giữ nguyên.'),
                        Forms\Components\Radio::make('issue_mode')->label('Thời điểm cấp mã')
                            ->options([
                                TtlockSetting::ISSUE_ON_CONTRACT => 'Ngay khi tạo hợp đồng',
                                TtlockSetting::ISSUE_ON_PAYMENT  => 'Sau khi thu cọc hoặc thanh toán hoá đơn đầu tiên',
                            ])
                            ->descriptions([
                                TtlockSetting::ISSUE_ON_PAYMENT => 'Hợp đồng không cọc thì cấp khi hoá đơn đầu tiên được thanh toán đủ. Hợp đồng đã có mã không bị thu hồi.',
                            ])
                            ->default(TtlockSetting::ISSUE_ON_CONTRACT)->required(),
                    ]),
            ])
            ->statePath('data');
    }

    // Chuẩn hoá mật khẩu: đã là MD5 (32 hex) thì giữ nguyên, ngược lại md5() — TTLock yêu cầu MD5 thường.
    public static function normalizePassword(string $value): string
    {
        return preg_match('/^[a-f0-9]{32}$/i', $value) ? strtolower($value) : md5($value);
    }

    public function save(): void
    {
        if (blank($this->buildingId) || ! in_array((int) $this->buildingId, ActiveBuildingScope::permittedBuildingIds(), true)) {
            Notification::make()->title('Chưa chọn Toà nhà cần cấu hình.')->danger()->send();

            return;
        }

        $data    = $this->form->getState();
        $setting = TtlockSetting::forBuilding((int) $this->buildingId);

        $setting->fill([
            'client_id' => $data['client_id'],
            'username'  => $data['username'],
            'api_base'  => $data['api_base'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'gate_lock_ids'  => array_values(array_map('intval', $data['gate_lock_ids'] ?? [])),
            'gate_code_mode' => $data['gate_code_mode'],
            'issue_mode'     => $data['issue_mode'],
        ]);

        $gateLocksChanged = $setting->isDirty('gate_lock_ids');

        if (filled($data['client_secret'] ?? null)) {
            $setting->client_secret = $data['client_secret'];
        }
        if (filled($data['password_md5'] ?? null)) {
            $setting->password_md5 = self::normalizePassword($data['password_md5']);
        }

        $setting->building_id = (int) $this->buildingId;
        $setting->save();

        // Đổi tài khoản thì token cũ trong cache không còn hợp lệ.
        TTLockService::forBuilding((int) $this->buildingId)?->clearTokenCache();

        // Đổi khoá cổng -> cấp/thu hồi mã cổng cho mọi hợp đồng đang hiệu lực. Mỗi hợp đồng là 1+ lần
        // gọi TTLock (~1-2s) nên chạy SAU khi đã trả response, không bắt người dùng chờ.
        if ($gateLocksChanged) {
            $buildingId = (int) $this->buildingId;
            dispatch(fn () => ContractTtlockService::syncForBuilding($buildingId))->afterResponse();
        }

        Notification::make()->title('Đã lưu cấu hình TTLock.')
            ->body($gateLocksChanged ? 'Mã cổng của các hợp đồng đang hiệu lực sẽ được cập nhật trong ít phút.' : null)
            ->success()->send();
        $this->fillFormForBuilding();
    }

    // Thử đăng nhập bằng thông tin ĐÃ LƯU của Toà nhà (không dùng dữ liệu chưa lưu trong form).
    public function testConnection(): void
    {
        if (blank($this->buildingId) || ! in_array((int) $this->buildingId, ActiveBuildingScope::permittedBuildingIds(), true)) {
            return;
        }

        $ttlock = TTLockService::forBuilding((int) $this->buildingId);

        if (! $ttlock) {
            Notification::make()->title('Toà nhà chưa lưu đủ thông tin hoặc đang tắt.')->danger()->send();

            return;
        }

        $ttlock->clearTokenCache();

        if ($ttlock->fetchNewToken()) {
            Notification::make()->title('Kết nối TTLock thành công.')->success()->send();

            return;
        }

        Notification::make()->title('Kết nối TTLock thất bại')->body('Kiểm tra lại Client ID/Secret, tài khoản và mật khẩu.')->danger()->send();
    }
}
