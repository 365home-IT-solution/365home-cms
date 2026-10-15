<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Partner;
use App\Services\PartnerSampleDataGenerator;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\Category\Entities\Category;
use Throwable;

// DỮ LIỆU MẪU — super_admin chọn đối tác + chi nhánh rồi sinh khung giờ, tiện ích, dịch vụ đi kèm,
// phòng theo khung giờ và 1 bảng giá của chi nhánh (App\Services\PartnerSampleDataGenerator). Chạy lại
// nhiều lần không sinh trùng: mục nào đối tác đã có (cùng giờ / cùng tên) thì bỏ qua. Không có nút xoá:
// dữ liệu sinh ra là của đối tác, họ sửa tiếp rồi dùng thật.
class PartnerSampleData extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Dữ liệu mẫu';

    protected static ?string $title = 'Sinh dữ liệu mẫu cho đối tác';

    protected static ?string $slug = 'partner-sample-data';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.pages.partner-sample-data';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public function mount(): void
    {
        $this->form->fill([
            'time_slots' => null,
            'tags'       => 5,
            'services'   => 4,
            'rooms'      => 3,
        ]);
    }

    public function form(Form $form): Form
    {
        $count = fn (string $name, string $label, int $max, string $help) => Forms\Components\TextInput::make($name)
            ->label($label)
            ->numeric()
            ->integer()
            ->minValue(0)
            ->maxValue($max)
            ->required()
            ->helperText($help);

        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Đối tác và chi nhánh')
                ->description('Bắt buộc chọn trước. Phòng và dịch vụ mẫu được tạo trong chi nhánh này.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('partner_id')
                        ->label('Đối tác')
                        ->options(fn () => Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('branch_id', null)),
                    Forms\Components\Select::make('branch_id')
                        ->label('Chi nhánh')
                        ->options(fn (Get $get) => $get('partner_id')
                            ? Category::withoutGlobalScopes()
                                ->whereNull('parent_id')
                                ->where('category_type', 'product')
                                ->whereNull('deleted_at')
                                ->where('partner_id', $get('partner_id'))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                            : [])
                        ->searchable()
                        ->required()
                        ->disabled(fn (Get $get) => blank($get('partner_id')))
                        ->helperText(fn (Get $get) => $get('partner_id') ? 'Đối tác chưa có chi nhánh thì tạo chi nhánh trước.' : 'Chọn đối tác trước.'),
                ]),

            Forms\Components\Section::make('Số lượng cần sinh')
                ->description('Nhập 0 để bỏ qua loại đó. Mục đối tác đã có (trùng giờ hoặc trùng tên) sẽ được bỏ qua, nên số tạo ra có thể ít hơn.')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('time_slots')
                        ->label('Số khung giờ mỗi phòng')
                        ->numeric()
                        ->integer()
                        ->minValue(\App\Services\RoomSlotRandomizer::MIN_COUNT)
                        ->maxValue(\App\Services\RoomSlotRandomizer::MAX_COUNT)
                        ->helperText('Để trống = theo phòng sẵn có của chi nhánh (hoặc 5 khung). Khung giờ trải từ sáng đến tối + 1 khung qua đêm, cách nhau 15–30 phút.'),
                    $count('tags', 'Tiện ích', 18, 'Tiện ích riêng của đối tác.'),
                    $count('services', 'Dịch vụ đi kèm', 12, 'Gắn cho chi nhánh đã chọn.'),
                    $count('rooms', 'Phòng theo khung giờ', 20, 'Gắn tiện ích, nhận dịch vụ của chi nhánh, khung giờ + giá theo 1 phòng sẵn có; các phòng được gom vào 1 bảng giá của chi nhánh.'),
                ]),
        ]);
    }

    public function generate(): void
    {
        $data = $this->form->getState();

        try {
            $result = app(PartnerSampleDataGenerator::class)->generate(
                (string) $data['partner_id'],
                (int) $data['branch_id'],
                $data,
            );
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Không sinh được dữ liệu mẫu')->body($e->getMessage())->danger()->send();

            return;
        }

        $labels = [
            'time_slots' => 'khung giờ mới',
            'tags'       => 'tiện ích',
            'services'   => 'dịch vụ',
            'rooms'      => 'phòng',
        ];

        $labels['price_boards'] = 'bảng giá';

        $body = collect($result['counts'])->map(fn ($n, $key) => "{$n} {$labels[$key]}")->implode(', ');

        Notification::make()->title('Đã sinh dữ liệu mẫu')->body("Đã tạo: {$body}.")->success()->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('generate')->label('Sinh dữ liệu mẫu')->icon('heroicon-o-sparkles')->submit('generate'),
        ];
    }
}
