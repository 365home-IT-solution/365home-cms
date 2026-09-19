<?php

declare(strict_types=1);

namespace Modules\TTLock\App\Filament\Pages;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\TTLock\App\Services\TTLockService;
use Modules\TTLock\Entities\TtlockAccount;

// KHÁC với thẻ từ/vân tay: mã mở (passcode) có thể TỰ SINH qua cloud (generatePasscode(), API
// /v3/keyboardPwd/get) — không bắt buộc phải đọc/biết trước qua SDK Bluetooth. Vẫn cho phép nhập mã
// tuỳ chỉnh (addCustomPasscode()) nếu muốn 1 mã cụ thể dễ nhớ.
class IssuePasscode extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'ttlock::filament.pages.issue-passcode';

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationLabel = 'Cấp mã mở';

    protected static ?string $title = 'Cấp mã mở (Passcode)';

    protected static ?string $slug = 'ttlock/issue-passcode';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('page_IssueCard') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'type'   => 'permanent',
            'source' => 'generate',
        ]);
    }

    // Giống hệt IssueCard::allLocks() — gộp khóa mọi chi nhánh vào 1 danh sách phẳng, không bắt chọn
    // chi nhánh trước.
    private function allLocks(): array
    {
        $locks = [];

        $accounts = TtlockAccount::query()->where('is_active', true)->with('categories:id,name')->get();

        foreach ($accounts as $account) {
            $categoryId = $account->categories->first()?->id;

            if (! $categoryId) {
                continue;
            }

            $ttlock = TTLockService::forCategory($categoryId);

            if (! $ttlock) {
                continue;
            }

            foreach ($ttlock->getLockList() as $lock) {
                $branchName = $account->categories->first()?->name;
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
                    ->helperText('Chọn 1 hoặc nhiều khóa — mã sẽ được cấp cho TẤT CẢ khóa đã chọn.')
                    ->multiple()
                    ->options(fn () => collect($this->allLocks())->map(fn ($l) => $l['label'])->all())
                    ->searchable()
                    ->required(),

                TextInput::make('name')
                    ->label('Tên mã')
                    ->placeholder('VD: Mã nhân viên A, Mã phòng 101')
                    ->required()
                    ->maxLength(255),

                Select::make('source')
                    ->label('Nguồn mã')
                    ->options([
                        'generate' => 'Tự sinh mã ngẫu nhiên (khuyên dùng)',
                        'custom'   => 'Tự nhập mã tuỳ chọn',
                    ])
                    ->default('generate')
                    ->live()
                    ->required(),

                TextInput::make('custom_code')
                    ->label('Mã tuỳ chọn')
                    ->placeholder('4-9 chữ số')
                    ->numeric()
                    ->required(fn (Get $get) => $get('source') === 'custom')
                    ->visible(fn (Get $get) => $get('source') === 'custom')
                    ->maxLength(9),

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
                    ->default(now())
                    ->required(fn (Get $get) => $get('type') === 'timing')
                    ->visible(fn (Get $get) => $get('type') === 'timing'),

                DateTimePicker::make('end_date')
                    ->label('Hết hạn')
                    ->seconds(false)
                    ->displayFormat('d/m/Y H:i')
                    ->after('start_date')
                    ->required(fn (Get $get) => $get('type') === 'timing')
                    ->visible(fn (Get $get) => $get('type') === 'timing'),
            ])
            ->statePath('data');
    }

    public function issue(): void
    {
        $data = $this->form->getState();
        $locks = $this->allLocks();

        $isTiming  = $data['type'] === 'timing';
        $startDate = $isTiming ? \Carbon\Carbon::parse($data['start_date'])->getTimestampMs() : (int) round(microtime(true) * 1000);
        $endDate   = $isTiming ? \Carbon\Carbon::parse($data['end_date'])->getTimestampMs() : 0;

        $succeeded = [];
        $failed    = [];
        $generatedCode = null;

        foreach ($data['lock_ids'] ?? [] as $lockId) {
            $categoryId = $locks[(int) $lockId]['categoryId'] ?? null;
            $ttlock = $categoryId ? TTLockService::forCategory($categoryId) : null;

            if (! $ttlock) {
                $failed[] = $lockId;
                continue;
            }

            if ($data['source'] === 'custom') {
                $result = $ttlock->addCustomPasscode(
                    lockId:     (int) $lockId,
                    code:       (string) $data['custom_code'],
                    startDate:  $startDate,
                    endDate:    $endDate,
                    name:       (string) $data['name'],
                );
            } else {
                // Tự sinh — dùng CHUNG 1 mã cho mọi khóa đã chọn: khóa ĐẦU TIÊN tự sinh mã ngẫu
                // nhiên, các khóa sau dùng addCustomPasscode() với ĐÚNG mã đó để đồng bộ (giống hệt
                // cách IssueTTLockPasscodeAction.php trong module AccessCode đã làm cho khoá check-
                // in/check-out).
                if ($generatedCode === null) {
                    $result = $ttlock->generatePasscode(
                        lockId:     (int) $lockId,
                        startDate:  $startDate,
                        endDate:    $endDate,
                        name:       (string) $data['name'],
                    );

                    if ($result) {
                        $generatedCode = $result['code'];
                    }
                } else {
                    $result = $ttlock->addCustomPasscode(
                        lockId:     (int) $lockId,
                        code:       $generatedCode,
                        startDate:  $startDate,
                        endDate:    $endDate,
                        name:       (string) $data['name'],
                    );
                }
            }

            if ($result) {
                $succeeded[] = $lockId;
            } else {
                $failed[] = $lockId;
            }
        }

        $codeDisplay = $data['source'] === 'custom' ? $data['custom_code'] : ($generatedCode ?? '—');

        if ($succeeded && ! $failed) {
            Notification::make()
                ->title('Cấp mã mở thành công')
                ->body("Mã: {$codeDisplay} — đã cấp cho " . count($succeeded) . ' khóa.')
                ->success()
                ->send();

            $this->form->fill(['type' => 'permanent', 'source' => 'generate']);

            return;
        }

        if ($succeeded && $failed) {
            Notification::make()
                ->title('Cấp mã 1 phần')
                ->body("Mã: {$codeDisplay} — thành công " . count($succeeded) . ' khóa, thất bại ' . count($failed) . ' khóa.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Cấp mã thất bại')
            ->body('Không cấp được cho khóa nào — kiểm tra log để biết chi tiết.')
            ->danger()
            ->send();
    }
}
