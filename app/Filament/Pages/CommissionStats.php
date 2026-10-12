<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Partner;
use App\Services\CommissionStatsService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

// THỐNG KÊ HOA HỒNG & DOANH THU trên trang quản trị (cùng số liệu với API GET /api/admin/dashboard/commission-stats — App\Services\CommissionStatsService).
// Tách hai góc nhìn: CHỦ NỀN TẢNG (Super Admin) thấy hoa hồng thu được, tiền 365home bù khuyến mãi, thu nhập ròng, theo đối tác / tháng / kênh thu
// tiền, công nợ đối soát và ký quỹ; ĐỐI TÁC chỉ thấy doanh thu, hoa hồng phải nộp, thực nhận và công nợ của chính mình.
// Hard-code quyền như các màn tài chính khác (không dùng permission Shield).
class CommissionStats extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Thống kê hoa hồng';

    protected static ?string $title = 'Thống kê hoa hồng & doanh thu';

    protected static ?string $slug = 'commission-stats';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.commission-stats';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return (bool) ($user->isSuperAdmin() || ($user->isPartnerOwner() && filled($user->partner_id) && Partner::query()->whereKey($user->partner_id)->where('partner_type', Partner::TYPE_HOMESTAY)->exists()));
    }

    public function mount(): void
    {
        $this->form->fill([
            'scope'      => 'platform',
            'filter'     => 'this_month',
            'start_date' => now()->subDays(29)->toDateString(),
            'end_date'   => now()->toDateString(),
            'partner_id' => null,
        ]);
    }

    public function isPlatformSide(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->columns(4)->schema([
            Forms\Components\Select::make('scope')->label('Góc nhìn')->native(false)->live()->selectablePlaceholder(false)
                ->options(['platform' => 'Chủ nền tảng', 'partner' => 'Đối tác'])
                ->visible(fn () => $this->isPlatformSide()),
            Forms\Components\Select::make('partner_id')
                ->label(fn (Forms\Get $get) => ($get('scope') ?? 'platform') === 'partner' ? 'Đối tác' : 'Lọc theo đối tác')
                ->native(false)->searchable()->live()
                ->placeholder('Tất cả đối tác')
                ->options(fn () => Partner::query()->where('partner_type', Partner::TYPE_HOMESTAY)->orderBy('name')->get(['id', 'name', 'legal_name'])
                    ->mapWithKeys(fn (Partner $p) => [$p->id => $p->legal_name ?: $p->name])->all())
                ->visible(fn () => $this->isPlatformSide()),
            Forms\Components\Select::make('filter')->label('Kỳ')->native(false)->live()->selectablePlaceholder(false)
                ->options([
                    'today' => 'Hôm nay', 'yesterday' => 'Hôm qua', '7d' => '7 ngày qua', '30d' => '30 ngày qua', '90d' => '90 ngày qua',
                    'this_month' => 'Tháng này', 'last_month' => 'Tháng trước', 'this_year' => 'Năm nay', 'last_year' => 'Năm trước', 'custom' => 'Tuỳ chọn',
                ]),
            Forms\Components\DatePicker::make('start_date')->label('Từ ngày')->live()->native(false)->displayFormat('d/m/Y')
                ->visible(fn (Forms\Get $get) => $get('filter') === 'custom'),
            Forms\Components\DatePicker::make('end_date')->label('Đến ngày')->live()->native(false)->displayFormat('d/m/Y')
                ->visible(fn (Forms\Get $get) => $get('filter') === 'custom'),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function getStats(): ?array
    {
        $service = app(CommissionStatsService::class);
        $user = auth()->user();
        [$from, $to] = $service->range((string) ($this->data['filter'] ?? 'this_month'), $this->data['start_date'] ?? null, $this->data['end_date'] ?? null);

        if ($this->isPlatformSide()) {
            $partnerId = filled($this->data['partner_id'] ?? null) ? (string) $this->data['partner_id'] : null;

            if (($this->data['scope'] ?? 'platform') === 'partner') {
                $partner = $partnerId ? Partner::query()->find($partnerId) : null;

                return $partner ? $service->forPartner($partner, $from, $to) : null;
            }

            return $service->forPlatform($user, $from, $to, $partnerId, 15);
        }

        $partner = Partner::query()->find($user->partner_id);

        return $partner ? $service->forPartner($partner, $from, $to) : null;
    }

    public static function money($value): string
    {
        return number_format((int) $value, 0, ',', '.') . 'đ';
    }
}
