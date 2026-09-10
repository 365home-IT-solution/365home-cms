<?php

namespace Modules\Minihouse\App\Filament\Pages;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Transaction;
use Modules\Minihouse\App\Support\ActiveBuildingScope;

// Báo cáo doanh thu/công nợ/tỷ lệ lấp đầy THẬT — đọc trực tiếp từ Invoice/InvoicePayment/Transaction/
// Room, không có bảng tổng hợp riêng (cache/snapshot) vì khối lượng dữ liệu MiniHouse còn nhỏ.
//
// Bộ lọc kỳ báo cáo (period) mô phỏng đúng UX tab "Hôm nay/Tháng này/.../Tùy chọn" của Dashboard
// Home (xem Modules\Dashboard\App\Filament\Pages\Dashboard::getPeriodDates()) — thêm 2 mốc "Quý"
// (không có ở bản Home) theo đúng yêu cầu "báo cáo doanh thu theo quý/năm". Khác Home ở chỗ dùng
// Filament DatePicker cho khoảng tuỳ chọn thay vì flatpickr riêng (Home tự nhúng flatpickr cho đúng
// trang đó, MiniHouse dùng lại đúng component Filament đã có sẵn trong toàn bộ module, không thêm
// thư viện JS mới).
class FinanceReports extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Thu chi & Báo cáo';
    protected static ?string $title           = 'Thu chi & báo cáo';
    protected static ?int $navigationSort     = 10;

    protected static string $view = 'minihouse::filament.pages.finance-reports';

    public ?array $filters = [];

    // Mốc thời gian báo cáo — TÁCH RIÊNG khỏi $filters (không qua Filament Form) giống hệt cách
    // Modules\Dashboard\App\Filament\Pages\Dashboard làm với $period/$customStart/$customEnd, để
    // dùng được nút bấm "tab" đổi kỳ ngay lập tức qua wire:click="$set(...)" mà không cần validate
    // qua form.
    public string $period = 'this_month';

    public ?string $customStart = null;
    public ?string $customEnd   = null;

    public const PERIOD_LABELS = [
        'today'         => 'Hôm nay',
        'this_month'    => 'Tháng này',
        'last_month'    => 'Tháng trước',
        'this_quarter'  => 'Quý này',
        'last_quarter'  => 'Quý trước',
        'this_year'     => 'Năm nay',
        'last_year'     => 'Năm trước',
        'custom'        => 'Tuỳ chọn',
    ];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('view_any_reports') ?? false);
    }

    public function mount(): void
    {
        $this->filters = [
            'building_id' => null,
        ];
    }

    // Trang này chỉ có 1 form lọc (không có form "form" mặc định như Create/Edit) — phải khai báo
    // lại tên form ở đây, nếu không InteractsWithForms sẽ tìm method form() (không tồn tại) và lỗi.
    protected function getForms(): array
    {
        return ['filtersForm'];
    }

    public function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('building_id')
                    ->label('Toà nhà')
                    ->options(fn () => Building::pluck('name', 'id'))
                    ->searchable()
                    ->live()
                    ->placeholder('Tất cả toà nhà (trong phạm vi được quản lý)'),
            ])
            ->statePath('filters')
            ->columns(1);
    }

    private function buildingId(): ?int
    {
        return $this->filters['building_id'] ?? null;
    }

    // Danh sách toà nhà cần lọc cho các query KHÔNG có global scope riêng (InvoicePayment — không
    // như Invoice/Contract/Room/Transaction đều tự áp ActiveBuildingScope theo bộ lọc "Toà nhà" ở
    // HEADER panel). Ưu tiên đúng 1 toà đang chọn ở dropdown CỦA TRANG này nếu có; không thì rơi về
    // đúng phạm vi header đang lọc (activeBuildingIds() — có thể nhiều toà nếu tài khoản quản lý
    // nhiều toà và chưa thu hẹp ở header); null = không cần lọc gì (super_admin, header đang "Tất
    // cả"). Thiếu bước rơi về header này là lý do trước đây đổi bộ lọc "Toà nhà" ở header KHÔNG ảnh
    // hưởng gì tới số "Đã thu" trong khi mọi số liệu Invoice/Transaction khác đã lọc đúng.
    private function invoicePaymentBuildingIds(): ?array
    {
        if ($this->buildingId()) {
            return [$this->buildingId()];
        }

        return ActiveBuildingScope::shouldFilter() ? ActiveBuildingScope::activeBuildingIds() : null;
    }

    // Suy ra [start, end] (Carbon, đã startOfDay()/endOfDay()) từ $period đang chọn — CHỈ tính lại
    // mỗi lần gọi (không cache), chi phí rẻ, tránh lệch trạng thái nếu $period đổi giữa chừng 1
    // request. "Quý" dùng thẳng Carbon::startOfQuarter()/endOfQuarter() (Home chưa cần tới vì
    // dashboard đặt phòng không có khái niệm "báo cáo theo quý").
    private function periodDates(): array
    {
        $now = Carbon::now();

        return match ($this->period) {
            'today'        => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'last_month'   => [
                $now->copy()->subMonthNoOverflow()->startOfMonth()->startOfDay(),
                $now->copy()->subMonthNoOverflow()->endOfMonth()->endOfDay(),
            ],
            'this_quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()->min($now->copy()->endOfDay())],
            'last_quarter' => [
                $now->copy()->subQuarterNoOverflow()->startOfQuarter(),
                $now->copy()->subQuarterNoOverflow()->endOfQuarter(),
            ],
            'this_year'    => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            'last_year'    => [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear()],
            'custom'       => $this->resolveCustomRange($now),
            default        => [$now->copy()->startOfMonth()->startOfDay(), $now->copy()->endOfDay()], // this_month
        };
    }

    private function resolveCustomRange(Carbon $now): array
    {
        $start = $this->customStart ? Carbon::parse($this->customStart)->startOfDay() : $now->copy()->startOfMonth()->startOfDay();
        $end   = $this->customEnd ? Carbon::parse($this->customEnd)->endOfDay() : $now->copy()->endOfDay();

        if ($start->gt($end)) {
            [$start, $end] = [$end, $start];
        }

        return [$start, $end];
    }

    public function periodLabel(): string
    {
        [$start, $end] = $this->periodDates();

        return $start->format('d/m/Y') . ' – ' . $end->format('d/m/Y');
    }

    // Toàn bộ 3 nhánh chung 1 cách lọc theo toà nhà (qua Contract/Room, withoutGlobalScopes() ở cả
    // 2 tầng — hợp đồng/phòng đã xoá mềm vẫn phải tính đúng vào báo cáo, cùng lỗi lớp SoftDeletes đã
    // gặp nhiều lần trong module này).
    private function scopeInvoiceQueryToBuilding($query, string $relationBase = 'invoice')
    {
        $buildingIds = $this->invoicePaymentBuildingIds();

        if (! $buildingIds) {
            return $query;
        }

        return $query->whereHas($relationBase, fn ($q) => $q->withoutGlobalScopes()->whereHas(
            'contract',
            fn ($q2) => $q2->withoutGlobalScopes()->whereHas(
                'room',
                fn ($q3) => $q3->withoutGlobalScopes()->whereIn('building_id', $buildingIds),
            ),
        ));
    }

    // "Đã thu" = tiền THẬT sự đã vào tay theo ĐÚNG ngày thanh toán (InvoicePayment.paid_at), KHÔNG
    // dùng Invoice.amount_paid nhóm theo Invoice.month như bản cũ — 1 hoá đơn tháng 8 hoàn toàn có
    // thể được khách trả vào tháng 9, gộp theo tháng lập hoá đơn sẽ sai số liệu "đã thu" của đúng kỳ
    // đang xem khi đổi sang lọc theo ngày/quý/năm (khác bản cũ trước đây CHỈ có duy nhất 1 kỳ "tháng
    // báo cáo" nên sai lệch này chưa lộ ra). Chỉ tính thanh toán ĐÃ DUYỆT (approved) — pending chưa
    // chắc là tiền thật, xem InvoicePayment::STATUS_PENDING.
    private function collectedInPeriod(Carbon $start, Carbon $end): float
    {
        $query = InvoicePayment::query()
            ->where('status', InvoicePayment::STATUS_APPROVED)
            ->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()]);

        return (float) $this->scopeInvoiceQueryToBuilding($query)->sum('amount');
    }

    // Invoice không có quan hệ tên "invoice" (chính nó), nên KHÔNG dùng chung được
    // scopeInvoiceQueryToBuilding() ở trên (viết cho InvoicePayment, lọc qua quan hệ 'invoice') —
    // viết riêng 1 bản cho Invoice (lọc thẳng qua 'contract', không qua lớp 'invoice' trung gian).
    private function scopeInvoiceToBuilding($query)
    {
        $buildingId = $this->buildingId();

        if (! $buildingId) {
            return $query;
        }

        return $query->whereHas(
            'contract',
            fn ($q2) => $q2->withoutGlobalScopes()->whereHas(
                'room',
                fn ($q3) => $q3->withoutGlobalScopes()->where('building_id', $buildingId),
            ),
        );
    }

    public function stats(): array
    {
        [$start, $end] = $this->periodDates();

        $collected = $this->collectedInPeriod($start, $end);

        $invoicedQuery = $this->scopeInvoiceToBuilding(
            Invoice::query()->whereBetween('month', [$start->copy()->startOfMonth(), $end->copy()->startOfMonth()])
        );

        $invoicedTotal = (float) (clone $invoicedQuery)->sum('total_amount');
        $uncollected   = (float) (clone $invoicedQuery)
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            ->sum(DB::raw('total_amount - amount_paid'));

        $expense = (float) Transaction::query()
            ->where('type', Transaction::TYPE_OUT)
            ->whereBetween('transaction_date', [$start, $end])
            ->when($this->buildingId(), fn ($q, $buildingId) => $q->where('building_id', $buildingId))
            ->sum('amount');

        $roomsQuery = Room::query()->when($this->buildingId(), fn ($q, $buildingId) => $q->where('building_id', $buildingId));
        $totalRooms = (clone $roomsQuery)->count();
        $rentedRooms = (clone $roomsQuery)->where('status', Room::STATUS_RENTED)->count();

        return [
            'collected'       => $collected,
            'invoiced_total'  => $invoicedTotal,
            'uncollected'     => $uncollected,
            'expense'         => $expense,
            'profit'          => $collected - $expense,
            'total_rooms'     => $totalRooms,
            'rented_rooms'    => $rentedRooms,
            'occupancy_rate'  => $totalRooms > 0 ? round($rentedRooms / $totalRooms * 100, 1) : 0,
        ];
    }

    // Doanh thu (đã thu/chưa thu) trong kỳ, gộp theo từng Toà nhà — bỏ qua nếu đang lọc đúng 1 toà
    // (khi đó bảng tổng theo toà không còn ý nghĩa, chỉ 1 dòng).
    public function revenueByBuilding(): array
    {
        if ($this->buildingId()) {
            return [];
        }

        [$start, $end] = $this->periodDates();

        // "Đã thu" theo building — nhóm InvoicePayment (paid_at trong kỳ) theo building của hoá đơn.
        // scopeInvoiceQueryToBuilding() ở đây CHỈ còn tác dụng lọc theo header (buildingId() luôn
        // null tới đây do early-return ở trên) — vẫn cần gọi để bảng tổng hợp không lộ số liệu của
        // toà nằm NGOÀI phạm vi header đang xem.
        $collectedByBuilding = $this->scopeInvoiceQueryToBuilding(
            InvoicePayment::query()
                ->where('status', InvoicePayment::STATUS_APPROVED)
                ->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()])
        )
            ->with([
                'invoice'                        => fn ($q) => $q->withoutGlobalScopes(),
                'invoice.contract'                => fn ($q) => $q->withoutGlobalScopes(),
                'invoice.contract.room'           => fn ($q) => $q->withoutGlobalScopes(),
                'invoice.contract.room.building'  => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->get()
            ->groupBy(fn (InvoicePayment $p) => $p->invoice?->contract?->room?->building?->name ?? '—');

        // "Chưa thu" theo building — hoá đơn phát sinh trong kỳ (theo Invoice.month) còn nợ.
        $uncollectedByBuilding = Invoice::query()
            ->whereBetween('month', [$start->copy()->startOfMonth(), $end->copy()->startOfMonth()])
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            ->with([
                'contract'                => fn ($q) => $q->withoutGlobalScopes(),
                'contract.room'           => fn ($q) => $q->withoutGlobalScopes(),
                'contract.room.building'  => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->get()
            ->groupBy(fn (Invoice $invoice) => $invoice->contract?->room?->building?->name ?? '—');

        $buildingNames = $collectedByBuilding->keys()->merge($uncollectedByBuilding->keys())->unique();

        return $buildingNames->map(function (string $buildingName) use ($collectedByBuilding, $uncollectedByBuilding) {
            $payments = $collectedByBuilding->get($buildingName, collect());
            $invoices = $uncollectedByBuilding->get($buildingName, collect());

            return [
                'building'    => $buildingName,
                'count'       => $payments->count(),
                'collected'   => (float) $payments->sum('amount'),
                'uncollected' => (float) $invoices->sum(fn (Invoice $invoice) => $invoice->remainingAmount()),
            ];
        })
            ->sortByDesc('collected')
            ->values()
            ->all();
    }

    // Công nợ HIỆN TẠI (không giới hạn theo kỳ báo cáo — nợ cũ vẫn là nợ) — mọi hoá đơn chưa
    // thanh toán, gộp theo hợp đồng/khách thuê.
    public function tenantDebts(): array
    {
        return Invoice::query()
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            // withoutGlobalScopes() ở whereHas VÀ ở eager-load bên dưới — công nợ của 1 hợp đồng đã
            // xoá mềm (khách đã trả phòng, tổng dọn nhưng vẫn còn nợ cũ) không được phép "biến mất"
            // khỏi báo cáo công nợ chỉ vì hợp đồng bị lưu trữ.
            ->when($this->buildingId(), fn ($q, $buildingId) => $q->whereHas(
                'contract',
                fn ($q2) => $q2->withoutGlobalScopes()->whereHas(
                    'room',
                    fn ($q3) => $q3->withoutGlobalScopes()->where('building_id', $buildingId),
                ),
            ))
            ->with([
                'contract'        => fn ($q) => $q->withoutGlobalScopes(),
                'contract.room'   => fn ($q) => $q->withoutGlobalScopes(),
                'contract.tenant' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->get()
            ->groupBy('contract_id')
            ->map(function ($invoices) {
                $contract = $invoices->first()->contract;

                return [
                    'tenant'        => $contract?->tenant?->fullname ?? '—',
                    'room'          => $contract?->room?->code ?? '—',
                    'invoice_count' => $invoices->count(),
                    // Còn nợ = phần CHƯA trả của mỗi hoá đơn (không phải nguyên total_amount) — hoá
                    // đơn đã trả 1 phần chỉ còn nợ đúng phần thiếu.
                    'total_debt'    => (float) $invoices->sum(fn (Invoice $invoice) => $invoice->remainingAmount()),
                    'oldest_month'  => $invoices->min('month'),
                ];
            })
            ->sortByDesc('total_debt')
            ->values()
            ->all();
    }
}
