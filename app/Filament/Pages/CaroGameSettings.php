<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\CaroRewardResource;
use App\Filament\Support\GameFormFields;
use App\Models\CaroPrize;
use App\Models\CaroSetting;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Modules\Category\Entities\Category;

// Toàn bộ cấu hình mini game CARO trên 1 màn (chỉ Super Admin): bật/tắt, luật chơi, mời bạn, thưởng, chống thông đồng và bộ voucher.
// Dữ liệu ở caro_settings (1 dòng) + caro_prizes — xem App\Services\CaroService.
class CaroGameSettings extends Page
{
    use Forms\Concerns\InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-squares-2x2';
    protected static ?string $navigationGroup = 'Quản lý API';
    protected static ?string $navigationLabel = 'Caro trực tuyến';
    protected static ?string $title           = 'Caro trực tuyến';
    protected static ?string $slug            = 'game-caro';
    protected static ?int    $navigationSort  = 33;

    protected static string $view = 'filament.pages.caro-game-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            ...CaroSetting::current()->only((new CaroSetting())->getFillable()),
            'prizes' => CaroPrize::with('categories:id')->orderBy('coupon_value')->get()
                ->map(fn (CaroPrize $p) => [
                    ...$p->only(['id', 'label', 'coupon_type', 'coupon_value', 'min_order_value', 'max_discount', 'validity_days',
                        'is_exclusive', 'weight', 'daily_limit', 'total_limit', 'awarded_count', 'is_fallback', 'is_active']),
                    'category_ids' => $p->categories->pluck('id')->all(),
                ])
                ->all(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Section::make('Trạng thái')->schema([
                Toggle::make('is_enabled')
                    ->label('Bật mini game Caro')
                    ->helperText('Bật cần có 1 voucher dự phòng (không giới hạn lượt) đang bật.')
                    ->inline(false),
            ]),

            Section::make('Luật chơi')->columns(3)->schema([
                TextInput::make('board_size')->label('Kích thước bàn cờ')->suffix('ô')->numeric()->integer()->minValue(10)->maxValue(20)->required(),
                TextInput::make('win_length')->label('Số quân liền để thắng')->numeric()->integer()->minValue(4)->maxValue(6)->required(),
                Toggle::make('block_two_ends')->label('Chặn 2 đầu không tính thắng')->inline(false),
                TextInput::make('turn_seconds')->label('Thời gian mỗi lượt')->suffix('giây')
                    ->helperText('Hết giờ chưa đánh thì xử thua.')
                    ->numeric()->integer()->minValue(10)->maxValue(120)->required(),
            ]),

            Section::make('Không có người trực tuyến & mời bạn')
                ->description('Không ghép được ai thì khách tiếp tục chờ; app gợi ý mời bạn vào đấu (theo số điện thoại hoặc gửi link/mã).')
                ->columns(2)
                ->schema([
                    Toggle::make('invite_enabled')->label('Cho mời bạn vào đấu')->live()->inline(false),
                    TextInput::make('match_wait_seconds')->label('Gợi ý mời bạn sau')->suffix('giây')
                        ->helperText('Chờ ghép bấy nhiêu giây chưa có ai thì app hiện gợi ý mời bạn.')
                        ->numeric()->integer()->minValue(5)->maxValue(300)->required(),
                    TextInput::make('invite_expire_minutes')->label('Hạn lời mời')->suffix('phút')
                        ->helperText('Quá hạn chưa ai nhận thì lời mời tự hết hiệu lực.')
                        ->numeric()->integer()->minValue(1)->maxValue(1440)->required()
                        ->visible(fn (Get $get) => (bool) $get('invite_enabled')),
                    Toggle::make('invite_wins_count')->label('Trận mời bạn được tính trận thắng')
                        ->helperText('Vẫn áp giới hạn thắng cùng 1 đối thủ / ngày ở phần dưới.')
                        ->inline(false)
                        ->visible(fn (Get $get) => (bool) $get('invite_enabled')),
                    TextInput::make('invite_share_url_base')->label('Link chia sẻ lời mời')
                        ->helperText('Link gửi cho bạn = link này + mã mời, ví dụ https://365home.vn/caro/AB12CD. App cần mở được link này.')
                        ->url()->maxLength(255)->required()
                        ->columnSpanFull()
                        ->visible(fn (Get $get) => (bool) $get('invite_enabled')),
                ]),

            Section::make('Mốc thưởng')
                ->description('Mỗi trận thắng được tính (không cần liên tiếp) +1 tiến độ. Chạm mốc thì khách nhận voucher; chạm mốc lớn nhất thì tiến độ về 0 và bắt đầu vòng mới.')
                ->schema([
                    Repeater::make('milestones')
                        ->hiddenLabel()
                        ->addActionLabel('Thêm mốc')
                        ->reorderable(false)
                        ->minItems(1)
                        ->columns(3)
                        ->itemLabel(fn (array $state): string => 'Mốc ' . ($state['wins'] ?? '?') . ' trận')
                        ->schema([
                            TextInput::make('wins')->label('Số trận')->numeric()->integer()->minValue(1)->maxValue(100)->required(),
                            Select::make('mode')->label('Phần thưởng')
                                ->options(['fixed' => 'Voucher cố định', 'random' => 'Bốc ngẫu nhiên theo tỉ lệ'])
                                ->default('random')->required()->live(),
                            Select::make('prize_id')->label('Voucher')
                                ->options(fn () => CaroPrize::where('is_active', true)->orderBy('coupon_value')->pluck('label', 'id'))
                                ->helperText('Voucher mới thêm cần lưu trước mới chọn được. Voucher hết lượt thì khách nhận voucher bốc ngẫu nhiên.')
                                ->required(fn (Get $get) => $get('mode') === 'fixed')
                                ->visible(fn (Get $get) => $get('mode') === 'fixed'),
                        ]),
                ]),

            Section::make('Giữ chân khách')->columns(2)->schema([
                TextInput::make('streak_days')->label('Thưởng chuỗi ngày chơi')->suffix('ngày')
                    ->helperText('Chơi đủ bấy nhiêu ngày liên tiếp (mỗi ngày ≥ 1 trận) thì +1 trận vào tiến độ. 0 = tắt.')
                    ->numeric()->integer()->minValue(0)->maxValue(30)->required(),
                TextInput::make('max_caro_coupons_per_order')->label('Tối đa mã Caro áp trong 1 đơn')
                    ->helperText('Mã không độc quyền cộng dồn được với nhau; giới hạn này chặn gom quá nhiều mã. Bỏ trống = không giới hạn.')
                    ->numeric()->integer()->minValue(1)->maxValue(20),
                Toggle::make('invite_bonus_enabled')->label('Thưởng mời bạn mới')
                    ->helperText('Bạn chưa từng chơi Caro nhận lời mời và chơi xong trận đầu → cả người mời và bạn +1 trận vào tiến độ.')
                    ->live()->inline(false),
                TextInput::make('invite_bonus_daily_cap')->label('Tối đa lần thưởng mời bạn / ngày (người mời)')
                    ->helperText('Bỏ trống = không giới hạn.')
                    ->numeric()->integer()->minValue(1)
                    ->visible(fn (Get $get) => (bool) $get('invite_bonus_enabled')),
                Toggle::make('reminder_enabled')->label('Nhắc voucher sắp hết hạn')
                    ->helperText('Gửi thông báo khi voucher Caro chưa dùng sắp hết hạn, mỗi voucher nhắc 1 lần.')
                    ->live()->inline(false),
                TextInput::make('reminder_hours_before')->label('Nhắc trước khi hết hạn')->suffix('giờ')
                    ->numeric()->integer()->minValue(1)->maxValue(168)->required()
                    ->visible(fn (Get $get) => (bool) $get('reminder_enabled')),
            ]),

            Section::make('Chống thông đồng')->columns(2)->schema([
                TextInput::make('min_moves_to_count')->label('Số nước tối thiểu khi thắng do đối thủ bỏ cuộc')
                    ->helperText('Đối thủ đầu hàng/hết giờ khi trận chưa đủ số nước này thì trận thắng không được tính.')
                    ->numeric()->integer()->minValue(0)->maxValue(200)->required(),
                TextInput::make('daily_counted_wins_cap')->label('Tối đa trận thắng được tính / ngày')
                    ->helperText('Bỏ trống = không giới hạn.')
                    ->numeric()->integer()->minValue(1),
                TextInput::make('same_opponent_daily_cap')->label('Tối đa trận thắng được tính trước cùng 1 đối thủ / ngày')
                    ->helperText('Bỏ trống = không giới hạn. Chặn 2 tài khoản đánh thả cho nhau, nhất là qua lời mời.')
                    ->numeric()->integer()->minValue(1),
            ]),

            Section::make('Voucher thưởng')
                ->description('Mốc "bốc ngẫu nhiên" chọn 1 voucher theo trọng số trong các voucher đang bật. Voucher mặc định hết hạn sau 7 ngày kể từ lúc nhận.')
                ->schema([
                    Repeater::make('prizes')
                        ->hiddenLabel()
                        ->addActionLabel('Thêm voucher')
                        ->reorderable(false)
                        ->collapsible()
                        ->defaultItems(0)
                        ->itemLabel(fn (array $state): string => trim(($state['label'] ?? 'Voucher mới')
                            . (! empty($state['is_exclusive']) ? ' · độc quyền' : '')
                            . (isset($state['is_active']) && ! $state['is_active'] ? ' · đang tắt' : '')))
                        ->deleteAction(fn ($action) => $action->requiresConfirmation()
                            ->modalDescription('Voucher đã được trao cho khách sẽ chỉ bị tắt, không xoá.'))
                        ->schema([
                            Hidden::make('id'),
                            Hidden::make('awarded_count')->dehydrated(false),

                            Grid::make(3)->schema([
                                TextInput::make('label')->label('Tên giải')->required()->maxLength(255),
                                Toggle::make('is_exclusive')->label('Mã độc quyền')
                                    ->helperText('Không dùng chung đơn với mã khác.')
                                    ->inline(false),
                                Placeholder::make('chance_info')->label('Tỉ lệ trúng · Đã trao')
                                    ->content(fn (Get $get) => self::chance($get('../../prizes') ?? [], $get('weight'), (bool) $get('is_active'))
                                        . ' · ' . (int) $get('awarded_count') . ' lần'),
                            ]),

                            // Voucher caro mặc định hạn 7 ngày (các game khác dùng chung ô này với mặc định 30 ngày)
                            Grid::make(3)->schema(array_map(
                                fn ($field) => $field->getName() === 'validity_days' ? $field->default(7) : $field,
                                GameFormFields::coupon(),
                            )),

                            Select::make('category_ids')
                                ->label('Áp dụng cho chi nhánh')
                                ->multiple()
                                ->searchable()
                                ->options(fn () => Category::query()->whereNull('parent_id')->where('category_type', 'product')->orderBy('name')->pluck('name', 'id'))
                                ->helperText('Để trống = dùng được ở TẤT CẢ chi nhánh (gồm cả khu vực con của chi nhánh đã chọn). Chỉ áp cho voucher trao sau khi lưu.'),

                            Grid::make(5)->schema([
                                TextInput::make('weight')->label('Trọng số')
                                    ->helperText('Tỉ lệ trúng = trọng số / tổng trọng số các voucher đang bật.')
                                    ->numeric()->integer()->minValue(1)->default(1)->required()
                                    ->live(onBlur: true),
                                TextInput::make('daily_limit')->label('Giới hạn / ngày')->placeholder('Không giới hạn')
                                    ->numeric()->integer()->minValue(1)
                                    ->disabled(fn (Get $get) => (bool) $get('is_fallback')),
                                TextInput::make('total_limit')->label('Giới hạn tổng')->placeholder('Không giới hạn')
                                    ->numeric()->integer()->minValue(1)
                                    ->disabled(fn (Get $get) => (bool) $get('is_fallback')),
                                Toggle::make('is_fallback')->label('Dự phòng')
                                    ->helperText('Không giới hạn lượt, hết ngân sách thì rơi về giải này.')
                                    ->live()->inline(false),
                                Toggle::make('is_active')->label('Đang bật')->default(true)->live()->inline(false),
                            ]),
                        ]),
                ]),
        ]);
    }

    // Tỉ lệ trúng của 1 voucher khi mọi voucher đang bật còn lượt — voucher hết giới hạn ngày/tổng thì phần của nó chia cho các voucher còn lại.
    private static function chance(array $prizes, mixed $weight, bool $active): string
    {
        $total = collect($prizes)->filter(fn (array $p) => ! empty($p['is_active']))->sum(fn (array $p) => max(0, (int) ($p['weight'] ?? 0)));

        if (! $active || $total <= 0) {
            return '0%';
        }

        return rtrim(rtrim(number_format(max(0, (int) $weight) / $total * 100, 1), '0'), '.') . '%';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rewards')
                ->label('Lịch sử thưởng')
                ->icon('heroicon-o-trophy')
                ->color('gray')
                ->url(CaroRewardResource::getUrl()),
        ];
    }

    public function save(): void
    {
        $data   = $this->form->getState();
        $prizes = collect($data['prizes'] ?? []);
        unset($data['prizes']);

        $data['milestones'] = array_values(array_map(fn (array $m) => [
            'wins'     => (int) $m['wins'],
            'mode'     => $m['mode'],
            'prize_id' => $m['mode'] === 'fixed' ? (int) $m['prize_id'] : null,
        ], $data['milestones'] ?? []));

        $wins = array_column($data['milestones'], 'wins');
        if (count($wins) !== count(array_unique($wins))) {
            Notification::make()->title('Hai mốc thưởng không được trùng số trận.')->danger()->send();

            return;
        }

        if ($data['win_length'] > $data['board_size']) {
            Notification::make()->title('Số quân liền để thắng không được lớn hơn kích thước bàn cờ.')->danger()->send();

            return;
        }

        if (($data['is_enabled'] ?? false) && ! $prizes->contains(fn (array $p) => ! empty($p['is_fallback']) && ! empty($p['is_active']))) {
            Notification::make()->title('Cần có 1 voucher dự phòng đang bật trước khi bật Caro.')->danger()->send();

            return;
        }

        DB::transaction(function () use ($data, $prizes) {
            CaroSetting::current()->update($data);

            $keptIds = [];
            foreach ($prizes as $row) {
                $prize = CaroPrize::findOrNew($row['id'] ?? null);
                $prize->fill(collect($row)->except(['id', 'category_ids', 'awarded_count'])->all());

                if ($prize->is_fallback) {
                    $prize->daily_limit = $prize->total_limit = null;
                }

                $prize->save();
                $prize->categories()->sync($row['category_ids'] ?? []);
                $keptIds[] = $prize->id;
            }

            // Voucher bị bỏ khỏi danh sách: đã trao thì chỉ tắt (giữ lịch sử), chưa trao thì xoá hẳn
            CaroPrize::whereNotIn('id', $keptIds)->get()->each(function (CaroPrize $prize) {
                if ($prize->awarded_count > 0) {
                    $prize->update(['is_active' => false]);

                    return;
                }

                $prize->categories()->detach();
                $prize->delete();
            });
        });

        $this->mount();
        Notification::make()->title('Đã lưu cấu hình Caro')->success()->send();
    }
}
