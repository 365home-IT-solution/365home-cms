<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerVoucherResource\Pages;

use App\Filament\Resources\PartnerVoucherResource;
use App\Models\Partner;
use App\Models\VoucherBlock;
use App\Services\PartnerVoucherPolicy;
use App\Services\VoucherBlockService;
use App\Support\VoucherScope;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithFormActions;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Modules\Promotion\App\Models\Coupon;

/** @property Partner $record */
class ManagePartnerVoucher extends Page implements HasForms
{
    use InteractsWithFormActions;
    use InteractsWithForms;
    use InteractsWithRecord;

    protected static string $resource = PartnerVoucherResource::class;

    protected static string $view = 'filament.resources.partner-voucher-resource.pages.manage';

    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(PartnerVoucherResource::canEdit($this->record), 403);

        $this->fillForm();
    }

    private function fillForm(): void
    {
        $this->form->fill($this->policy()->load($this->record) + [
            'preview_month' => now()->format('Y-m'),
            'test_date'     => now()->toDateString(),
            'test_code'     => null,
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Voucher tại ' . $this->policy()->partnerName($this->record);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Làm lần lượt ① → ② → ③, xem kết quả ở ④ rồi bấm "Lưu thiết lập". Xem trước chạy ngay trên màn hình, chưa cần lưu.';
    }

    public function getBreadcrumbs(): array
    {
        return [PartnerVoucherResource::getUrl() => 'Voucher theo đối tác', 'Thiết lập'];
    }

    private function policy(): PartnerVoucherPolicy
    {
        return app(PartnerVoucherPolicy::class);
    }

    public function form(Form $form): Form
    {
        $branches = $this->policy()->branches($this->record->id);

        return $form->statePath('data')->schema([
            // ① ────────────────────────────────────────────────────────────
            Section::make('① Áp dụng ở chi nhánh nào')
                ->description('Thiết lập ở bước ② và ③ áp cho các chi nhánh này.')
                ->schema([
                    Radio::make('branch_mode')
                        ->hiddenLabel()
                        ->options([
                            'all'  => 'Mọi chi nhánh (kể cả chi nhánh mở sau)',
                            'some' => 'Chỉ một số chi nhánh',
                        ])
                        ->default('all')
                        ->inline()
                        ->inlineLabel(false)
                        ->live(),

                    Select::make('category_ids')
                        ->label('Chi nhánh')
                        ->multiple()
                        ->options($branches)
                        ->visible(fn (Get $get) => $get('branch_mode') === 'some')
                        ->required(fn (Get $get) => $get('branch_mode') === 'some')
                        ->validationMessages(['required' => 'Chọn ít nhất 1 chi nhánh.'])
                        ->helperText('Chi nhánh KHÔNG chọn: không nhận quà điểm danh / trò chơi; các loại voucher khác vẫn dùng bình thường.')
                        ->live(),
                ]),

            // ② ────────────────────────────────────────────────────────────
            Section::make('② Khách được dùng loại voucher nào')
                ->description('Áp dụng MỌI NGÀY. Bỏ tick = khách không dùng được loại đó khi đặt phòng ở đây. Quà điểm danh / trò chơi là quà 365home tặng — chỉ tick khi đối tác đã đồng ý nhận.')
                ->schema([
                    CheckboxList::make('accept')
                        ->hiddenLabel()
                        ->options(VoucherBlock::GROUPS)
                        ->descriptions(VoucherBlock::GROUP_HINTS)
                        ->columns(['default' => 1, 'md' => 2])
                        ->bulkToggleable()
                        ->live(),

                    Placeholder::make('accept_none')
                        ->hiddenLabel()
                        ->content(new HtmlString('<span style="color:#dc2626;font-weight:600">⚠ Không tick loại nào: khách sẽ KHÔNG dùng được voucher nào ở đối tác này.</span>'))
                        ->visible(fn (Get $get) => empty($get('accept'))),

                    Placeholder::make('gift_open')
                        ->hiddenLabel()
                        ->content(fn () => new HtmlString('<span style="color:#b45309">ⓘ ' . collect(PartnerVoucherPolicy::GIFT_SCOPES)
                            ->filter(fn ($scope) => ! VoucherScope::isConfigured($scope))
                            ->keys()->map(fn ($g) => VoucherBlock::GROUPS[$g])->implode(', ')
                            . ' hiện chưa giới hạn nơi nhận (mọi đối tác đều dùng được) — bỏ tick ở đây chỉ chặn riêng đối tác này.</span>'))
                        ->visible(fn () => collect(PartnerVoucherPolicy::GIFT_SCOPES)->contains(fn ($scope) => ! VoucherScope::isConfigured($scope))),
                ]),

            // ③ ────────────────────────────────────────────────────────────
            Section::make('③ Đợt ngừng nhận voucher (không bắt buộc)')
                ->description(new HtmlString('Dùng cho ngày lễ, Festival, cuối tuần… khi giá đã ưu đãi và <b>không áp dụng đồng thời với khuyến mãi khác</b>.<br>'
                    . 'ⓘ Chỉ chặn khách <b>DÙNG</b> voucher khi đặt phòng có đêm rơi vào các ngày này. Trò chơi, điểm danh, lên hạng vẫn chạy; khách vẫn nhận voucher và dùng được ở ngày khác.'))
                ->schema([
                    Actions::make([
                        $this->importHolidaysAction(),
                        FormAction::make('addWeekend')
                            ->label('Cuối tuần')
                            ->icon('heroicon-o-plus')
                            ->color('gray')
                            ->action(fn (Get $get, Set $set) => $this->appendPeriod($get, $set, 'Cuối tuần', [], ['weekdays' => ['5', '6']])),
                    ]),

                    Repeater::make('periods')
                        ->hiddenLabel()
                        ->addActionLabel('Thêm đợt')
                        ->defaultItems(0)
                        ->reorderable(false)
                        ->collapsible()
                        ->live()
                        ->itemLabel(fn (array $state) => (trim((string) ($state['name'] ?? '')) ?: 'Đợt mới')
                            . ' — ' . (new VoucherBlock($this->policy()->periodAttributes($state)))->scheduleSummary()
                            . (($state['is_active'] ?? true) ? '' : ' (đang tắt)'))
                        ->deleteAction(fn ($action) => $action->requiresConfirmation()->modalHeading('Xoá đợt này?')->modalDescription('Đợt chỉ bị xoá thật khi bấm "Lưu thiết lập".'))
                        ->schema($this->periodSchema()),
                ]),

            // ④ ────────────────────────────────────────────────────────────
            Section::make('④ Xem trước')
                ->description('Theo thiết lập đang có trên màn hình (chưa cần lưu).')
                ->schema([
                    Select::make('preview_month')
                        ->label('Xem từ tháng')
                        ->options(collect(range(-1, 12))->mapWithKeys(fn ($i) => [
                            now()->startOfMonth()->addMonths($i)->format('Y-m') => 'Tháng ' . now()->startOfMonth()->addMonths($i)->format('m/Y'),
                        ])->all())
                        ->selectablePlaceholder(false)
                        ->live()
                        ->dehydrated(false)
                        ->maxWidth('xs'),

                    Placeholder::make('calendar')
                        ->hiddenLabel()
                        ->content(fn () => $this->calendarHtml()),

                    Fieldset::make('Thử như khách đặt phòng')
                        ->columns(['default' => 1, 'md' => 2])
                        ->schema([
                            DatePicker::make('test_date')
                                ->label('Đêm ở')
                                ->native(false)
                                ->displayFormat('d/m/Y')
                                ->live()
                                ->dehydrated(false),
                            TextInput::make('test_code')
                                ->label('Mã voucher (không bắt buộc)')
                                ->placeholder('VD BAOTOANSILVER — để trống xem mọi loại')
                                ->live(onBlur: true)
                                ->dehydrated(false),
                            Placeholder::make('test_result')
                                ->hiddenLabel()
                                ->columnSpanFull()
                                ->content(fn () => $this->testResultHtml()),
                        ]),
                ]),
        ]);
    }

    private function periodSchema(): array
    {
        $date = fn (string $name, string $label) => DatePicker::make($name)->label($label)->native(false)->displayFormat('d/m/Y')->live();

        return [
            Hidden::make('id'),

            Grid::make(['default' => 1, 'md' => 3])->schema([
                TextInput::make('name')
                    ->label('Tên đợt')
                    ->placeholder('VD: Festival Hoa Đà Lạt 2026')
                    ->required()
                    ->maxLength(150)
                    ->live(onBlur: true)
                    ->columnSpan(['md' => 2]),
                Toggle::make('is_active')->label('Đang bật')->default(true)->inline(false)->live(),
            ]),

            Repeater::make('ranges')
                ->label('Ngừng nhận từ ngày – đến ngày')
                ->addActionLabel('Thêm khoảng ngày')
                ->defaultItems(1)
                ->reorderable(false)
                ->columns(['default' => 1, 'md' => 3])
                ->live()
                ->schema([
                    $date('from', 'Từ ngày')->required(),
                    $date('to', 'Đến ngày')->required()->afterOrEqual('from'),
                    TextInput::make('name')->label('Tên dịp (không bắt buộc)')->placeholder('Mặc định = tên đợt')->maxLength(100)->live(onBlur: true),
                ]),

            CheckboxList::make('blocked_groups')
                ->label('Trong đợt này khách KHÔNG dùng được')
                ->options(VoucherBlock::GROUPS)
                ->default(array_keys(VoucherBlock::GROUPS))
                ->columns(['default' => 1, 'md' => 3])
                ->bulkToggleable()
                ->required()
                ->validationMessages(['required' => 'Chọn ít nhất 1 loại voucher bị ngừng.'])
                ->live(),

            Placeholder::make('warnings')
                ->hiddenLabel()
                ->content(fn (Placeholder $component) => $this->warningsHtml($component))
                ->visible(fn (Placeholder $component) => $this->warningsHtml($component) !== null),

            Section::make('Tuỳ chọn nâng cao')
                ->description('Thứ trong tuần, lặp hằng năm, ngày lễ theo bảng giá, ngày ngoại lệ…')
                ->compact()
                ->collapsible()
                ->collapsed(fn (Get $get) => ! $get('use_price_board_holidays') && empty($get('weekdays')) && empty($get('recurring_ranges'))
                    && empty($get('except_dates')) && ! $get('valid_from') && ! $get('valid_to') && $get('mixed_mode') !== VoucherBlock::MIXED_ALL)
                ->schema([
                    Toggle::make('use_price_board_holidays')
                        ->label('Ngày lễ trong bảng giá')
                        ->helperText('Tự lấy các dòng "Giá ngày lễ, Tết" của bảng giá phòng — sửa bảng giá là đợt tự cập nhật.')
                        ->live(),

                    CheckboxList::make('weekdays')
                        ->label('Đêm thứ trong tuần')
                        ->options(VoucherBlock::WEEKDAYS)
                        ->columns(['default' => 4, 'md' => 7])
                        ->live(),

                    Repeater::make('recurring_ranges')
                        ->label('Lặp lại hằng năm (dd/mm)')
                        ->addActionLabel('Thêm ngày lặp hằng năm')
                        ->defaultItems(0)
                        ->reorderable(false)
                        ->columns(['default' => 1, 'md' => 3])
                        ->live()
                        ->schema([
                            TextInput::make('from')->label('Từ (dd/mm)')->placeholder('31/12')->required()->live(onBlur: true),
                            TextInput::make('to')->label('Đến (dd/mm)')->placeholder('01/01')->required()->live(onBlur: true),
                            TextInput::make('name')->label('Tên dịp')->maxLength(100),
                        ]),

                    Repeater::make('except_dates')
                        ->label('Trừ các đêm (vẫn nhận voucher)')
                        ->simple(DatePicker::make('date')->native(false)->displayFormat('d/m/Y')->required())
                        ->addActionLabel('Thêm ngày ngoại lệ')
                        ->defaultItems(0)
                        ->live(),

                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        $date('valid_from', 'Đợt chỉ hiệu lực từ')->helperText('Để trống = luôn hiệu lực.'),
                        $date('valid_to', 'đến'),
                    ]),

                    Radio::make('mixed_mode')
                        ->label('Đơn ở cả đêm thường lẫn đêm ngừng nhận')
                        ->options([
                            VoucherBlock::MIXED_ANY => 'Không nhận voucher cho cả đơn (mặc định)',
                            VoucherBlock::MIXED_ALL => 'Chỉ chặn khi TẤT CẢ các đêm đều rơi vào đợt',
                        ])
                        ->default(VoucherBlock::MIXED_ANY)
                        ->live(),

                    Textarea::make('note')->label('Ghi chú nội bộ')->rows(2)->maxLength(1000),
                ]),
        ];
    }

    // ── Thêm đợt nhanh ──────────────────────────────────────────────────────

    private function importHolidaysAction(): FormAction
    {
        return FormAction::make('importHolidays')
            ->label('Lấy ngày lễ từ bảng giá')
            ->icon('heroicon-o-calendar-days')
            ->modalHeading('Ngày lễ trong bảng giá')
            ->modalDescription(fn () => $this->holidays() === []
                ? 'Bảng giá của các chi nhánh đã chọn chưa có ngày lễ nào sắp tới (khai báo ở Bảng giá › "Giá ngày lễ, Tết"). Có thể bật "Tự động" để ngày lễ thêm sau tự ngừng nhận.'
                : 'Mỗi ngày lễ được chọn thành 1 đợt (sửa được sau).')
            ->modalSubmitActionLabel('Thêm đợt')
            ->form(fn () => [
                CheckboxList::make('holidays')
                    ->label('Ngày lễ sắp tới')
                    ->options(collect($this->holidays())->map(fn ($r) => "{$r['name']}: " . VoucherBlock::dmy($r['from']) . ' → ' . VoucherBlock::dmy($r['to']) . " ({$r['rooms']} phòng)")->all())
                    ->bulkToggleable()
                    ->visible($this->holidays() !== []),
                Toggle::make('auto')
                    ->label('Tự động theo bảng giá')
                    ->helperText('Thêm 1 đợt "Ngày lễ trong bảng giá": ngày lễ khai báo thêm sau cũng tự ngừng nhận voucher.'),
            ])
            ->action(function (array $data, Get $get, Set $set) {
                $rows = $this->holidays();
                foreach ((array) ($data['holidays'] ?? []) as $i) {
                    if (isset($rows[$i])) {
                        $this->appendPeriod($get, $set, $rows[$i]['name'], [['from' => $rows[$i]['from'], 'to' => $rows[$i]['to']]]);
                    }
                }
                if (! empty($data['auto'])) {
                    $this->appendPeriod($get, $set, 'Ngày lễ trong bảng giá', [], ['use_price_board_holidays' => true]);
                }
                if (empty($data['holidays']) && empty($data['auto'])) {
                    Notification::make()->warning()->title('Chưa chọn ngày lễ nào.')->send();
                }
            });
    }

    private function holidays(): array
    {
        return app(VoucherBlockService::class)->upcomingHolidays($this->record->id, $this->policy()->selectedBranches($this->record, $this->data ?? []));
    }

    private function appendPeriod(Get $get, Set $set, string $name, array $ranges, array $extra = []): void
    {
        $key     = fn () => (string) Str::uuid();
        $periods = (array) $get('periods');

        $periods[$key()] = $extra + [
            'id'                       => null,
            'name'                     => $name,
            'is_active'                => true,
            'ranges'                   => collect($ranges)->mapWithKeys(fn ($r) => [$key() => ['name' => null] + $r])->all(),
            'blocked_groups'           => array_keys(VoucherBlock::GROUPS),
            'use_price_board_holidays' => false,
            'weekdays'                 => [],
            'recurring_ranges'         => [],
            'except_dates'             => [],
            'valid_from'               => null,
            'valid_to'                 => null,
            'mixed_mode'               => VoucherBlock::MIXED_ANY,
            'note'                     => null,
        ];

        $set('periods', $periods);
    }

    // ── Cảnh báo & xem trước ────────────────────────────────────────────────

    private function warningsHtml(Placeholder $component): ?HtmlString
    {
        $key      = Str::afterLast($component->getContainer()->getStatePath(), '.');
        $periods  = (array) ($this->data['periods'] ?? []);
        $warnings = isset($periods[$key]) ? $this->policy()->periodWarnings($this->record, (array) $periods[$key], $periods, $key) : [];

        return $warnings === [] ? null : new HtmlString('<div style="color:#b45309">' . collect($warnings)->map(fn ($w) => '⚠ ' . e($w))->implode('<br>') . '</div>');
    }

    private function calendarHtml(): HtmlString
    {
        $policy = $this->policy();
        $state  = (array) $this->data;
        $rules  = $policy->rulesFromState($this->record, $state, includeBase: false)->where('is_active', true)->values();
        $room   = $policy->sampleRoom($this->record->id, $policy->selectedBranches($this->record, $state));
        $svc    = app(VoucherBlockService::class);
        $start  = Carbon::createFromFormat('Y-m-d', ($state['preview_month'] ?? now()->format('Y-m')) . '-01')->startOfMonth();
        $today  = now()->toDateString();

        $missing = array_diff(array_keys(VoucherBlock::GROUPS), (array) ($state['accept'] ?? []));
        $html    = $missing
            ? '<div style="margin-bottom:.5rem;color:#dc2626">Mọi ngày khách không dùng được: <b>' . e(collect($missing)->map(fn ($g) => VoucherBlock::GROUPS[$g])->implode(', ')) . '</b> (bước ②).</div>'
            : '';

        $html .= '<div style="display:flex;flex-wrap:wrap;gap:1.25rem">';
        for ($m = 0; $m < 3; $m++) {
            $month = $start->copy()->addMonths($m);
            $html .= '<div style="min-width:15rem"><div style="font-weight:600;margin-bottom:.25rem">Tháng ' . $month->format('m/Y') . '</div>'
                . '<div style="display:grid;grid-template-columns:repeat(7,2rem);gap:2px;font-size:.75rem;text-align:center">';
            foreach (['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'] as $d) {
                $html .= "<div style=\"opacity:.6\">{$d}</div>";
            }
            $html .= str_repeat('<div></div>', $month->dayOfWeekIso - 1);

            for ($day = $month->copy(); $day->month === $month->month; $day->addDay()) {
                $date    = $day->toDateString();
                $blocked = $rules->isEmpty() ? [] : $svc->blockedGroupsOn($rules, $room, $date);
                $style   = 'padding:.3rem 0;border-radius:.375rem;';
                $title   = $day->format('d/m/Y');
                if ($blocked) {
                    $style .= 'background:rgba(220,38,38,.85);color:#fff;font-weight:600;';
                    $title .= ' — ngừng nhận: ' . implode(', ', array_unique($blocked));
                } else {
                    $style .= 'background:rgba(22,163,74,.12);';
                }
                if ($date < $today) {
                    $style .= 'opacity:.4;';
                }
                if ($date === $today) {
                    $style .= 'outline:2px solid #2563eb;';
                }
                $html .= '<div title="' . e($title) . "\" style=\"{$style}\">{$day->day}</div>";
            }
            $html .= '</div></div>';
        }

        $html .= '</div><div style="margin-top:.5rem;font-size:.8rem;opacity:.8">'
            . '<span style="display:inline-block;width:.8rem;height:.8rem;border-radius:.2rem;background:rgba(220,38,38,.85);vertical-align:middle"></span> Ngừng nhận voucher (rê chuột xem đợt) &nbsp; '
            . '<span style="display:inline-block;width:.8rem;height:.8rem;border-radius:.2rem;background:rgba(22,163,74,.25);vertical-align:middle"></span> Nhận bình thường &nbsp; '
            . '<span style="display:inline-block;width:.8rem;height:.8rem;border-radius:.2rem;outline:2px solid #2563eb;vertical-align:middle"></span> Hôm nay'
            . ($room->exists ? ' · Ngày lễ bảng giá tính theo phòng <b>' . e($room->name) . '</b>' : '')
            . '</div>';

        return new HtmlString($html);
    }

    private function testResultHtml(): HtmlString
    {
        $state = (array) $this->data;
        if (empty($state['test_date'])) {
            return new HtmlString('<span style="opacity:.7">Chọn đêm ở để thử.</span>');
        }

        $policy = $this->policy();
        $date   = Carbon::parse($state['test_date'])->toDateString();
        $rules  = $policy->rulesFromState($this->record, $state)->where('is_active', true)->values();
        $room   = $policy->sampleRoom($this->record->id, $policy->selectedBranches($this->record, $state));
        $svc    = app(VoucherBlockService::class);
        $code   = trim((string) ($state['test_code'] ?? ''));
        $roomTx = $room->exists ? ' <span style="opacity:.7">(thử với phòng ' . e($room->name) . ')</span>' : '';

        if ($code !== '') {
            $coupon = Coupon::query()->where('code', $code)->first();
            if (! $coupon) {
                return new HtmlString('<span style="color:#b45309">Không tìm thấy mã "' . e($code) . '".</span>');
            }

            $group  = VoucherBlock::GROUPS[VoucherBlockService::groupOf($coupon)];
            $reason = $room->exists ? $svc->blockReasonWith($rules, $coupon, $room, [$date]) : null;
            if ($reason) {
                return new HtmlString('<div style="color:#dc2626;font-weight:600">❌ Khách sẽ thấy: "' . e($reason) . '"</div>'
                    . '<div style="opacity:.7">Mã thuộc loại: ' . e($group) . '</div>' . $roomTx);
            }

            $note = $room->exists && ! $coupon->appliesToRoom((string) $room->id)
                ? '<div style="color:#b45309">⚠ Đối tác không chặn, nhưng bản thân mã này không áp dụng cho phòng này (phạm vi phòng / đối tác / chi nhánh của mã).</div>'
                : '';

            return new HtmlString('<div style="color:#16a34a;font-weight:600">✓ Đối tác nhận mã này vào đêm ' . Carbon::parse($date)->format('d/m/Y') . '</div>'
                . '<div style="opacity:.7">Mã thuộc loại: ' . e($group) . '</div>' . $note . $roomTx);
        }

        $blocked = $svc->blockedGroupsOn($rules, $room, $date);
        $items   = collect(VoucherBlock::GROUPS)->map(fn ($label, $g) => isset($blocked[$g])
            ? '<li style="color:#dc2626">✗ ' . e($label) . ' <span style="opacity:.7">— ' . e($blocked[$g]) . '</span></li>'
            : '<li style="color:#16a34a">✓ ' . e($label) . '</li>')->implode('');

        return new HtmlString('<div>Đêm ' . Carbon::parse($date)->format('d/m/Y') . ':' . $roomTx . '</div><ul style="margin:.25rem 0 0;padding-left:1rem;list-style:none">' . $items . '</ul>');
    }

    // ── Lưu ─────────────────────────────────────────────────────────────────

    public function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Lưu thiết lập')
                ->requiresConfirmation(fn () => empty($this->data['accept']))
                ->modalHeading('Khách sẽ không dùng được voucher nào')
                ->modalDescription('Bạn đã bỏ tick mọi loại voucher ở bước ②: khách đặt phòng ở đối tác này sẽ không dùng được bất kỳ voucher nào. Chắc chắn lưu?')
                ->modalSubmitActionLabel('Vẫn lưu')
                ->action(fn () => $this->save()),
            Action::make('back')
                ->label('Quay lại danh sách')
                ->color('gray')
                ->url(PartnerVoucherResource::getUrl()),
        ];
    }

    public function save(): void
    {
        $this->form->validate();

        $this->policy()->save($this->record, (array) $this->data, auth()->id());

        $this->fillForm();

        Notification::make()->success()->title('Đã lưu thiết lập voucher')->body('Khách đặt phòng mới sẽ theo thiết lập này ngay.')->send();
    }
}
