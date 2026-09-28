<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Pages\Ttlock;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;
use Modules\Minihouse\App\Support\TtlockLocks;
use Modules\TTLock\App\Services\TTLockService;

// Y hệt IssueTtlockCard.php nhưng cho vân tay — cùng giới hạn: fingerprintNumber PHẢI đã biết trước (đọc
// qua SDK Bluetooth/app riêng), API /v3/fingerprint/add chỉ đăng ký + đồng bộ cloud, không tự đọc
// vân tay mới được (khác mã mở — xem IssueTtlockPasscode.php có thể tự sinh mã qua cloud).
class IssueTtlockFingerprint extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'minihouse::filament.pages.ttlock.issue-fingerprint';

    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationLabel = 'Cấp vân tay';

    protected static ?string $title = 'Cấp vân tay';

    protected static ?string $slug = 'ttlock/issue-fingerprint';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('page_ttlock_locks') ?? false);
    }

    public function mount(): void
    {
        $this->form->fill([
            'type' => 'permanent',
        ]);
    }

    private function allLocks(): array
    {
        $locks = [];

        $accounts = \Modules\Minihouse\App\Support\TtlockLocks::buildings();

        foreach ($accounts as $account) {
            $categoryId = $account->id;

            if (! $categoryId) {
                continue;
            }

            $ttlock = TtlockLocks::service($categoryId);

            if (! $ttlock) {
                continue;
            }

            foreach ($ttlock->getLockList() as $lock) {
                $branchName = $account->name;
                $alias = $lock['lockAlias'] ?? $lock['lockName'] ?? "Lock #{$lock['lockId']}";

                $locks[(int) $lock['lockId']] = [
                    'label'      => $branchName ? "{$alias} ({$branchName})" : $alias,
                    'categoryId' => $categoryId,
                ];
            }
        }

        return $locks;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('lock_ids')
                    ->label('Khóa')
                    ->helperText('Chọn 1 hoặc nhiều khóa — vân tay sẽ được cấp cho TẤT CẢ khóa đã chọn.')
                    ->multiple()
                    ->options(fn () => collect($this->allLocks())->map(fn ($l) => $l['label'])->all())
                    ->searchable()
                    ->required(),

                TextInput::make('name')
                    ->label('Tên vân tay')
                    ->placeholder('VD: Vân tay nhân viên A')
                    ->required()
                    ->maxLength(255),

                Select::make('type')
                    ->label('Loại')
                    ->options([
                        'permanent' => 'Vĩnh viễn (Permanent)',
                        'timing'    => 'Có thời hạn (Timing)',
                    ])
                    ->default('permanent')
                    ->live()
                    ->required(),

                DateTimePicker::make('start_date')
                    ->label('Bắt đầu hiệu lực')
                    ->seconds(false)
                    ->displayFormat('d/m/Y H:i')
                    ->required(fn (Get $get) => $get('type') === 'timing')
                    ->visible(fn (Get $get) => $get('type') === 'timing'),

                DateTimePicker::make('end_date')
                    ->label('Hết hạn')
                    ->seconds(false)
                    ->displayFormat('d/m/Y H:i')
                    ->after('start_date')
                    ->required(fn (Get $get) => $get('type') === 'timing')
                    ->visible(fn (Get $get) => $get('type') === 'timing'),

                Placeholder::make('fingerprint_number_note')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<div class="text-sm text-gray-500 dark:text-gray-400">'
                        . 'API của TTLock không đọc được vân tay mới từ xa — cần đọc vân tay 1 lần qua '
                        . 'app/thiết bị SDK riêng (đứng cạnh khóa) rồi dán số vân tay đó vào đây.'
                        . '</div>'
                    )),

                TextInput::make('fingerprint_number')
                    ->label('Số vân tay')
                    ->placeholder('Dán số vân tay đã đọc được từ app/thiết bị SDK')
                    ->required()
                    ->maxLength(64),
            ])
            ->statePath('data');
    }

    public function issue(): void
    {
        $data = $this->form->getState();
        $locks = $this->allLocks();

        $isTiming  = $data['type'] === 'timing';
        $startDate = $isTiming ? \Carbon\Carbon::parse($data['start_date'])->getTimestampMs() : 0;
        $endDate   = $isTiming ? \Carbon\Carbon::parse($data['end_date'])->getTimestampMs() : 0;

        $succeeded = [];
        $failed    = [];

        foreach ($data['lock_ids'] ?? [] as $lockId) {
            $categoryId = $locks[(int) $lockId]['categoryId'] ?? null;
            $ttlock = $categoryId ? TtlockLocks::service($categoryId) : null;

            if (! $ttlock) {
                $failed[] = $lockId;
                continue;
            }

            $result = $ttlock->addFingerprint(
                lockId:            (int) $lockId,
                fingerprintNumber: (string) $data['fingerprint_number'],
                startDate:         $startDate,
                endDate:           $endDate,
                name:              (string) $data['name'],
            );

            if ($result) {
                $succeeded[] = $lockId;
            } else {
                $failed[] = $lockId;
            }
        }

        if ($succeeded && ! $failed) {
            Notification::make()
                ->title('Cấp vân tay thành công')
                ->body('Đã cấp cho ' . count($succeeded) . ' khóa.')
                ->success()
                ->send();

            $this->form->fill(['type' => 'permanent']);

            return;
        }

        if ($succeeded && $failed) {
            Notification::make()
                ->title('Cấp vân tay 1 phần')
                ->body('Thành công ' . count($succeeded) . ' khóa, thất bại ' . count($failed) . ' khóa — kiểm tra log để biết chi tiết.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Cấp vân tay thất bại')
            ->body('Không cấp được cho khóa nào — kiểm tra log để biết chi tiết.')
            ->danger()
            ->send();
    }
}
