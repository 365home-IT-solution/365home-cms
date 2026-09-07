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
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Transaction;

// Báo cáo doanh thu/công nợ/tỷ lệ lấp đầy THẬT — thay thế khung placeholder trước đây (xem lịch sử
// HasPlaceholderContent, không dùng trait đó nữa ở trang này). Đọc trực tiếp từ Invoice/Transaction/
// Room — không thêm bảng tổng hợp riêng (cache/snapshot) vì khối lượng dữ liệu MiniHouse còn nhỏ,
// tính trực tiếp mỗi lần xem là đủ nhanh; nếu sau này dữ liệu lớn hơn nhiều mới cần cache.
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

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('view_any_reports') ?? false);
    }

    public function mount(): void
    {
        $this->filters = [
            'month'       => now()->startOfMonth()->toDateString(),
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
                DatePicker::make('month')
                    ->label('Tháng báo cáo')
                    ->displayFormat('m/Y')
                    ->native(false)
                    ->live()
                    ->default(now()),
                Select::make('building_id')
                    ->label('Toà nhà')
                    ->options(fn () => Building::pluck('name', 'id'))
                    ->searchable()
                    ->live()
                    ->placeholder('Tất cả toà nhà (trong phạm vi được quản lý)'),
            ])
            ->statePath('filters')
            ->columns(2);
    }

    private function month(): Carbon
    {
        return Carbon::parse($this->filters['month'] ?? now())->startOfMonth();
    }

    private function buildingId(): ?int
    {
        return $this->filters['building_id'] ?? null;
    }

    // Hoá đơn của đúng tháng báo cáo, lọc thêm theo toà nhà nếu có chọn — ScopedToActiveBuildingViaContract
    // của Invoice đã tự lọc theo quyền/bộ lọc header, ở đây chỉ lọc thêm theo lựa chọn CỦA TRANG này.
    private function monthInvoicesQuery()
    {
        $month = $this->month();

        return Invoice::query()
            ->whereYear('month', $month->year)
            ->whereMonth('month', $month->month)
            ->when($this->buildingId(), fn ($q, $buildingId) => $q->whereHas('contract.room', fn ($q2) => $q2->where('building_id', $buildingId)));
    }

    public function stats(): array
    {
        $month = $this->month();

        // "Đã thu" = tổng amount_paid thật (kể cả hoá đơn thanh toán 1 phần) — KHÔNG lọc theo
        // status=paid, sẽ bỏ sót phần khách đã trả trước của hoá đơn còn dở dang.
        $collected = (clone $this->monthInvoicesQuery())->sum('amount_paid');
        $uncollected = (clone $this->monthInvoicesQuery())
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            ->sum(DB::raw('total_amount - amount_paid'));

        $expense = Transaction::query()
            ->where('type', Transaction::TYPE_OUT)
            ->whereYear('transaction_date', $month->year)
            ->whereMonth('transaction_date', $month->month)
            ->when($this->buildingId(), fn ($q, $buildingId) => $q->where('building_id', $buildingId))
            ->sum('amount');

        $roomsQuery = Room::query()->when($this->buildingId(), fn ($q, $buildingId) => $q->where('building_id', $buildingId));
        $totalRooms = (clone $roomsQuery)->count();
        $rentedRooms = (clone $roomsQuery)->where('status', Room::STATUS_RENTED)->count();

        return [
            'collected'      => (float) $collected,
            'uncollected'    => (float) $uncollected,
            'expense'        => (float) $expense,
            'profit'         => (float) $collected - (float) $expense,
            'total_rooms'    => $totalRooms,
            'rented_rooms'   => $rentedRooms,
            'occupancy_rate' => $totalRooms > 0 ? round($rentedRooms / $totalRooms * 100, 1) : 0,
        ];
    }

    // Doanh thu (đã thu/chưa thu) của tháng, gộp theo từng Toà nhà — bỏ qua nếu đang lọc đúng 1 toà
    // (khi đó bảng tổng theo toà không còn ý nghĩa, chỉ 1 dòng).
    public function revenueByBuilding(): array
    {
        if ($this->buildingId()) {
            return [];
        }

        return (clone $this->monthInvoicesQuery())
            ->with('contract.room.building')
            ->get()
            ->groupBy(fn (Invoice $invoice) => $invoice->contract?->room?->building?->name ?? '—')
            ->map(function ($invoices, $buildingName) {
                return [
                    'building'    => $buildingName,
                    'count'       => $invoices->count(),
                    'collected'   => (float) $invoices->sum('amount_paid'),
                    'uncollected' => (float) $invoices->sum(fn (Invoice $invoice) => $invoice->remainingAmount()),
                ];
            })
            ->sortByDesc('collected')
            ->values()
            ->all();
    }

    // Công nợ HIỆN TẠI (không giới hạn theo tháng báo cáo — nợ cũ vẫn là nợ) — mọi hoá đơn chưa
    // thanh toán, gộp theo hợp đồng/khách thuê.
    public function tenantDebts(): array
    {
        return Invoice::query()
            ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            ->when($this->buildingId(), fn ($q, $buildingId) => $q->whereHas('contract.room', fn ($q2) => $q2->where('building_id', $buildingId)))
            ->with('contract.room', 'contract.tenant')
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
