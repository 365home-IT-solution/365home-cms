<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\ManualLockPasswordResource\Tables\Actions;

use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\ManualLockPassword;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Support\ManualLockPasswordTtlockIssuer as Issuer;

// Nút "Cấp mã mở hàng loạt" trong trang Khóa cổng — chỉ là form; logic cấp mã dùng chung với API app
// nằm ở ManualLockPasswordTtlockIssuer (xem giải thích luồng ở đó).
class ManualLockPasswordTtlockBulkAction
{
    public static function make(): Action
    {
        return Action::make('ttlockBulkPasscodes')
            ->label('Cấp mã mở hàng loạt')
            ->icon('heroicon-o-key')
            ->color('warning')
            ->visible(fn (): bool => auth()->user()?->can('create', ManualLockPassword::class) ?? false)
            ->modalHeading('Cấp mã mở TTLock hàng loạt')
            ->modalDescription('Hệ thống tự sinh Pass Cổng qua TTLock cho từng ngày (hoặc 1 mã cho cả khoảng), cấp lên khóa cổng đã chọn và lưu thành các bộ mật khẩu trong bảng này.')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Cấp mã')
            ->form([
                Section::make('Chi nhánh & khóa')
                    ->icon('heroicon-o-building-office-2')
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('category_id')
                                ->label('Chi nhánh')
                                ->options(fn () => Issuer::branches(auth()->user()))
                                ->helperText('Chỉ hiện chi nhánh đã có tài khoản TTLock.')
                                ->searchable()
                                ->native(false)
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Set $set) {
                                    $set('lock_ids', []);
                                    $set('product_ids', []);
                                }),

                            Placeholder::make('partner')
                                ->label('Đối tác')
                                ->content(fn (Get $get) => $get('category_id')
                                    ? (Category::find($get('category_id'))?->partner?->name ?? '—')
                                    : 'Theo chi nhánh đã chọn'),
                        ]),

                        Select::make('lock_ids')
                            ->label('Khóa cổng (TTLock)')
                            ->helperText('Chọn 1 hoặc nhiều khóa — cùng 1 mã được cấp lên tất cả khóa đã chọn.')
                            ->multiple()
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->disabled(fn (Get $get) => ! $get('category_id'))
                            ->options(fn (Get $get) => $get('category_id') ? Issuer::locks((int) $get('category_id')) : []),

                        Select::make('product_ids')
                            ->label('Phòng áp dụng')
                            ->helperText('Bỏ trống = áp dụng cho cả chi nhánh.')
                            ->multiple()
                            ->searchable()
                            ->native(false)
                            ->disabled(fn (Get $get) => ! $get('category_id'))
                            ->options(fn (Get $get) => $get('category_id') ? Issuer::products((int) $get('category_id')) : [])
                            ->live()
                            ->afterStateUpdated(function (Get $get, Set $set, ?array $state) {
                                // Gợi ý tên theo phòng (giống "neoclassic – 01/09/2026" của Import Excel).
                                if (blank($get('name')) && count($state ?? []) === 1) {
                                    $set('name', Product::find($state[0])?->name);
                                }
                            }),
                    ]),

                Section::make('Thông tin mã')
                    ->icon('heroicon-o-key')
                    ->schema([
                        TextInput::make('name')
                            ->label('Tên / Ghi chú')
                            ->placeholder('VD: neoclassic')
                            ->helperText(fn (Get $get) => $get('per_day')
                                ? 'Mỗi bản ghi sẽ có tên "<tên> – dd/mm/yyyy".'
                                : null)
                            ->required()
                            ->maxLength(200),

                        Grid::make(2)->schema([
                            Toggle::make('room_same_as_gate')
                                ->label('Pass Phòng = Pass Cổng')
                                ->helperText('Tắt thì Pass Phòng để trống.')
                                ->default(true),

                            Toggle::make('is_active')
                                ->label('Đang hoạt động')
                                ->default(true),
                        ]),
                    ]),

                Section::make('Thời gian')
                    ->icon('heroicon-o-clock')
                    ->schema([
                        Toggle::make('per_day')
                            ->label('Mỗi ngày 1 mã riêng')
                            ->helperText('Tắt = 1 mã duy nhất cho cả khoảng thời gian.')
                            ->default(true)
                            ->live(),

                        Grid::make(2)->schema([
                            DatePicker::make('from_date')
                                ->label('Từ ngày')
                                ->displayFormat('d/m/Y')
                                ->native(false)
                                ->default(now()->toDateString())
                                ->required()
                                ->live(),

                            DatePicker::make('to_date')
                                ->label('Đến ngày')
                                ->displayFormat('d/m/Y')
                                ->native(false)
                                ->default(now()->toDateString())
                                ->afterOrEqual('from_date')
                                ->required()
                                ->live()
                                ->rule(fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                    if ($get('from_date') && Carbon::parse($get('from_date'))->diffInDays(Carbon::parse($value)) >= Issuer::MAX_DAYS) {
                                        $fail('Tối đa ' . Issuer::MAX_DAYS . ' ngày mỗi lần.');
                                    }
                                }),

                            TimePicker::make('from_time')
                                ->label('Bắt đầu lúc')
                                ->seconds(false)
                                ->default('06:00')
                                ->required(),

                            TimePicker::make('until_time')
                                ->label(fn (Get $get) => $get('per_day') ? 'Hết hạn lúc (ngày hôm sau)' : 'Hết hạn lúc (ngày cuối)')
                                ->seconds(false)
                                ->default('12:00')
                                ->required(),
                        ]),

                        Placeholder::make('summary')
                            ->label('')
                            ->content(fn (Get $get) => self::summary($get)),
                    ]),
            ])
            ->action(function (array $data): void {
                self::handle($data);
            });
    }

    private static function handle(array $data): void
    {
        $result = Issuer::issue(auth()->user(), $data);

        if ($result['message']) {
            Notification::make()->title($result['message'])->danger()->send();

            return;
        }

        $created  = count($result['created']);
        $skipped  = $result['skipped'];
        $errors   = $result['errors'];
        $warnings = $result['warnings'];

        if ($created > 0 || $skipped > 0) {
            Notification::make()
                ->title("Đã cấp {$created} mã" . ($skipped ? ", bỏ qua {$skipped} mã đã tồn tại" : ''))
                ->success()
                ->send();
        }

        if ($errors || $warnings) {
            Notification::make()
                ->title(count($errors) . ' ngày lỗi' . ($warnings ? ', ' . count($warnings) . ' ngày lỗi 1 phần' : ''))
                ->body(implode("\n", array_slice([...$errors, ...$warnings], 0, 10)))
                ->status($errors && ! $created ? 'danger' : 'warning')
                ->persistent()
                ->send();
        }
    }

    private static function summary(Get $get): string
    {
        if (! $get('from_date') || ! $get('to_date') || ! $get('from_time') || ! $get('until_time')) {
            return '';
        }

        $days = Carbon::parse($get('from_date'))->diffInDays(Carbon::parse($get('to_date'))) + 1;

        if (! $get('per_day')) {
            return 'Sẽ tạo 1 mã, hiệu lực ' . Carbon::parse($get('from_date'))->format('d/m/Y') . ' ' . substr((string) $get('from_time'), 0, 5)
                . ' → ' . Carbon::parse($get('to_date'))->format('d/m/Y') . ' ' . substr((string) $get('until_time'), 0, 5) . '.';
        }

        return "Sẽ tạo {$days} mã (mỗi ngày 1 mã), mỗi mã hiệu lực từ " . substr((string) $get('from_time'), 0, 5)
            . ' ngày đó tới ' . substr((string) $get('until_time'), 0, 5) . ' hôm sau.';
    }
}
