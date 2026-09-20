<?php

declare(strict_types=1);

namespace Modules\TTLock\App\Filament\Pages;

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
use Modules\TTLock\App\Services\TTLockService;
use Modules\TTLock\Entities\TtlockAccount;

// Y hệt bước "Issue Card" trên web quản lý của TTLock (lock2.ttlock.com/manage/issueCard), làm ngay
// trên website của mình qua API — CHỈ khác đúng 1 điểm bắt buộc phải khác: KHÔNG có nút "Read card".
// Đã xác nhận qua doc thật (https://euopen.ttlock.com/doc/api/v3/identityCard/add): API chỉ ĐĂNG KÝ
// 1 số thẻ ĐÃ BIẾT vào khóa, không có khả năng tự đọc thẻ mới — việc đọc số thẻ vẫn phải làm 1 lần
// qua app/web TTLock (Bluetooth đứng cạnh khóa) như trước, sau đó dán số thẻ đó vào đây để cấp/quản
// lý qua nhiều khóa cùng lúc mà không cần quay lại web TTLock nữa.
class IssueCard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'ttlock::filament.pages.issue-card';

    protected static ?string $navigationIcon = 'heroicon-o-identification';

    // Không hiện riêng ngoài menu nữa — truy cập qua nút "Cấp thẻ từ" ngay trên trang danh sách khóa
    // (LockDashboard.php), giữ menu gọn, đúng yêu cầu.
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationLabel = 'Cấp thẻ từ';

    protected static ?string $title = 'Cấp thẻ từ (IC Card)';

    protected static ?string $slug = 'ttlock/issue-card';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('page_IssueCard') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'type' => 'permanent',
        ]);
    }

    // Gộp khóa của TẤT CẢ chi nhánh đang có tài khoản TTLock hoạt động vào 1 danh sách phẳng — không
    // còn bắt chọn chi nhánh trước nữa (theo yêu cầu bớt 1 bước thao tác). Trả về
    // [lockId => ['label' => ..., 'categoryId' => ...]] để issue() biết dùng đúng tài khoản TTLock
    // nào cho từng khóa đã chọn (1 khóa luôn thuộc đúng 1 chi nhánh/tài khoản).
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
                    ->helperText('Chọn 1 hoặc nhiều khóa — thẻ sẽ được cấp cho TẤT CẢ khóa đã chọn.')
                    ->multiple()
                    ->options(fn () => collect($this->allLocks())->map(fn ($l) => $l['label'])->all())
                    ->searchable()
                    ->required(),

                TextInput::make('name')
                    ->label('Tên thẻ')
                    ->placeholder('VD: Thẻ nhân viên A, Thẻ phòng 101')
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

                Placeholder::make('card_number_note')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        '<div class="text-sm text-gray-500 dark:text-gray-400">'
                        . 'API của TTLock không đọc được thẻ mới từ xa — cần đọc số thẻ 1 lần qua app/web '
                        . 'TTLock (đứng cạnh khóa, dùng Bluetooth điện thoại) rồi dán số thẻ đó vào đây.'
                        . '</div>'
                    )),

                TextInput::make('card_number')
                    ->label('Số thẻ')
                    ->placeholder('Dán số thẻ đã đọc được từ app/web TTLock')
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
            $ttlock = $categoryId ? TTLockService::forCategory($categoryId) : null;

            if (! $ttlock) {
                $failed[] = $lockId;
                continue;
            }

            $result = $ttlock->addIcCard(
                lockId:     (int) $lockId,
                cardNumber: (string) $data['card_number'],
                startDate:  $startDate,
                endDate:    $endDate,
                name:       (string) $data['name'],
            );

            if ($result) {
                $succeeded[] = $lockId;
            } else {
                $failed[] = $lockId;
            }
        }

        if ($succeeded && ! $failed) {
            Notification::make()
                ->title('Cấp thẻ thành công')
                ->body('Thẻ ' . $data['card_number'] . ' đã cấp cho ' . count($succeeded) . ' khóa.')
                ->success()
                ->send();

            $this->form->fill(['type' => 'permanent']);

            return;
        }

        if ($succeeded && $failed) {
            Notification::make()
                ->title('Cấp thẻ 1 phần')
                ->body('Thành công ' . count($succeeded) . ' khóa, thất bại ' . count($failed) . ' khóa — kiểm tra log để biết chi tiết.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Cấp thẻ thất bại')
            ->body('Không cấp được cho khóa nào — kiểm tra log để biết chi tiết.')
            ->danger()
            ->send();
    }
}
