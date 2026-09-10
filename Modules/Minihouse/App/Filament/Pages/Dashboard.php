<?php

namespace Modules\Minihouse\App\Filament\Pages;

use Filament\Pages\Dashboard as FilamentDashboard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Minihouse\App\Filament\Widgets\ExpiringContractsWidget;
use Modules\Minihouse\App\Filament\Widgets\RoomOccupancyMapWidget;
use Modules\Minihouse\App\Filament\Widgets\UpcomingRemindersWidget;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Transaction;
use Modules\Minihouse\App\Support\ActiveBuildingScope;

// Header + bộ lọc kỳ (tab Hôm nay/Hôm qua/.../Tuỳ chọn) mô phỏng ĐÚNG UX của Dashboard Home (xem
// Modules\Dashboard\App\Filament\Pages\Dashboard) theo yêu cầu "làm giao diện giống như home luôn"
// — CÙNG bộ preset (không thêm/bớt tab nào), CHỈ bỏ nút "Tô đen/Khoá lịch" (đặc thù nghiệp vụ đặt
// phòng theo giờ của Home, không áp dụng cho thuê theo tháng) và bỏ luôn "Chi nhánh" (MiniHouse đã
// có sẵn bộ lọc "Toà nhà" riêng ở header panel — mọi Model đã tự áp theo ActiveBuildingScope, không
// cần thêm 1 bộ lọc trùng chức năng ở đây).
class Dashboard extends FilamentDashboard
{
    protected static ?string $navigationLabel = 'Tổng quan';

    protected static ?int $navigationSort = 0;

    public string $period = '30d';

    /** Chỉ dùng khi period === 'custom' */
    public ?string $customStart = null;
    public ?string $customEnd   = null;

    public const PERIOD_LABELS = [
        'today'      => 'Hôm nay',
        'yesterday'  => 'Hôm qua',
        '7d'         => '7 Ngày',
        '30d'        => '30 Ngày',
        '90d'        => '90 Ngày',
        'this_month' => 'Tháng này',
        'last_month' => 'Tháng trước',
        'ytd'        => 'Năm nay',
        'last_year'  => 'Năm trước',
    ];

    // MinihouseStatsWidget (thẻ "Toà nhà/Phòng/Hợp đồng...") đã GỘP vào getViewData() bên dưới —
    // widget đó dùng khung Filament Stat riêng (viền/nền khác, không cùng bộ lọc kỳ với các thẻ
    // Doanh thu/Chi phí mới thêm), khiến trang hiện thành 2 khối tách biệt trông rối mắt và số liệu
    // "Thu/Chi tháng này" của nó bị trùng + xung đột với "Doanh thu/Chi phí" đã lọc theo kỳ tuỳ chọn
    // ở trên. Gộp chung 1 lưới thẻ, cùng 1 khung nền, cùng bộ lọc kỳ cho phần liên quan tới thời gian.
    public function getWidgets(): array
    {
        return [
            RoomOccupancyMapWidget::class,
            ExpiringContractsWidget::class,
            UpcomingRemindersWidget::class,
        ];
    }

    public function getView(): string
    {
        return 'minihouse::filament.pages.dashboard';
    }

    // Y HỆT thuật toán của Modules\Dashboard\App\Filament\Pages\Dashboard::getPeriodDates() (giữ
    // đúng tên biến trả về để dễ đối chiếu 2 bên khi cần sửa sau này) — chỉ khác domain dữ liệu
    // (hợp đồng/hoá đơn MiniHouse thay vì đơn đặt phòng của Home).
    private function getPeriodDates(): array
    {
        $end = Carbon::now()->endOfDay();

        if ($this->period === 'custom') {
            $start = $this->customStart
                ? Carbon::parse($this->customStart)->startOfDay()
                : Carbon::now()->subDays(29)->startOfDay();
            $end = $this->customEnd
                ? Carbon::parse($this->customEnd)->endOfDay()
                : Carbon::now()->endOfDay();
            if ($start->gt($end)) {
                [$start, $end] = [$end, $start];
            }
        } elseif ($this->period === 'today') {
            $start = Carbon::today()->startOfDay();
            $end   = Carbon::today()->endOfDay();
        } elseif ($this->period === 'yesterday') {
            $start = Carbon::yesterday()->startOfDay();
            $end   = Carbon::yesterday()->endOfDay();
        } elseif ($this->period === 'this_month') {
            $start = Carbon::now()->startOfMonth()->startOfDay();
            $end   = Carbon::now()->endOfDay();
        } elseif ($this->period === 'last_month') {
            $start = Carbon::now()->subMonthNoOverflow()->startOfMonth()->startOfDay();
            $end   = Carbon::now()->subMonthNoOverflow()->endOfMonth()->endOfDay();
        } elseif ($this->period === 'last_year') {
            $start = Carbon::now()->subYear()->startOfYear()->startOfDay();
            $end   = Carbon::now()->subYear()->endOfYear()->endOfDay();
        } elseif ($this->period === 'ytd') {
            $start = Carbon::today()->startOfYear()->startOfDay();
        } else {
            $days  = match ($this->period) { '7d' => 7, '90d' => 90, default => 30 };
            $start = Carbon::now()->subDays($days - 1)->startOfDay();
        }

        if ($this->period === 'this_month') {
            $prevStart = Carbon::now()->subMonthNoOverflow()->startOfMonth()->startOfDay();
            $prevEnd   = $prevStart->copy()->addDays(Carbon::now()->day - 1)->endOfDay();
        } elseif ($this->period === 'last_month') {
            $prevStart = Carbon::now()->subMonths(2)->startOfMonth()->startOfDay();
            $prevEnd   = Carbon::now()->subMonths(2)->endOfMonth()->endOfDay();
        } elseif ($this->period === 'last_year') {
            $prevStart = Carbon::now()->subYears(2)->startOfYear()->startOfDay();
            $prevEnd   = Carbon::now()->subYears(2)->endOfYear()->endOfDay();
        } else {
            $periodDays = max(1, (int) $start->diffInDays($end));
            $prevEnd    = $start->copy()->subSecond();
            $prevStart  = $prevEnd->copy()->subDays($periodDays - 1)->startOfDay();
        }

        $dateRange     = $start->format('j/n') . ' – ' . $end->format('j/n');
        $prevDateRange = $prevStart->format('j/n') . ' – ' . $prevEnd->format('j/n');

        return [$start, $end, $prevStart, $prevEnd, $dateRange, $prevDateRange];
    }

    private static function delta(float $current, float $previous): float
    {
        return $previous > 0 ? round((($current - $previous) / $previous) * 100, 1) : 0;
    }

    // "Đã thu" tính theo NGÀY THANH TOÁN THẬT (InvoicePayment.paid_at, chỉ khoản đã duyệt) — không
    // dùng Invoice.amount_paid nhóm theo tháng hoá đơn, cùng lý do đã áp dụng ở FinanceReports::
    // collectedInPeriod() (hoá đơn tháng này có thể được trả ở tháng khác).
    //
    // InvoicePayment KHÔNG có global scope theo toà nhà nào cả (khác Invoice/Contract/Room/
    // Transaction đều tự áp ActiveBuildingScope) — bỏ sót lọc thủ công ở đây khiến đổi bộ lọc "Toà
    // nhà" ở header KHÔNG ảnh hưởng gì tới 3 thẻ Doanh thu/Chuyển khoản/Tiền mặt, dù mọi số liệu
    // khác trên trang đã lọc đúng. Phải tự áp lại đúng logic ActiveBuildingScope ở đây, đi qua
    // invoice→contract→room (withoutGlobalScopes() ở cả 2 tầng — hợp đồng/phòng đã xoá mềm vẫn phải
    // tính đúng vào doanh thu, cùng lỗi lớp SoftDeletes đã gặp nhiều lần trong module này).
    private static function collectedBetween(Carbon $start, Carbon $end, ?string $method = null): float
    {
        return (float) InvoicePayment::query()
            ->where('status', InvoicePayment::STATUS_APPROVED)
            ->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()])
            ->when($method, fn ($q, $m) => $q->where('payment_method', $m))
            ->when(
                ActiveBuildingScope::shouldFilter(),
                fn ($q) => $q->whereHas(
                    'invoice',
                    fn ($q2) => $q2->withoutGlobalScopes()->whereHas(
                        'contract',
                        fn ($q3) => $q3->withoutGlobalScopes()->whereHas(
                            'room',
                            fn ($q4) => $q4->withoutGlobalScopes()->whereIn('building_id', ActiveBuildingScope::activeBuildingIds()),
                        ),
                    ),
                ),
            )
            ->sum('amount');
    }

    public function getViewData(): array
    {
        [$start, $end, $prevStart, $prevEnd, $dateRange, $prevDateRange] = $this->getPeriodDates();

        $newContracts     = Contract::query()->whereBetween('created_at', [$start, $end])->count();
        $prevNewContracts = Contract::query()->whereBetween('created_at', [$prevStart, $prevEnd])->count();

        $revenue     = self::collectedBetween($start, $end);
        $prevRevenue = self::collectedBetween($prevStart, $prevEnd);

        $revenueTransfer     = self::collectedBetween($start, $end, InvoicePayment::METHOD_TRANSFER);
        $prevRevenueTransfer = self::collectedBetween($prevStart, $prevEnd, InvoicePayment::METHOD_TRANSFER);

        $revenueCash     = self::collectedBetween($start, $end, InvoicePayment::METHOD_CASH);
        $prevRevenueCash = self::collectedBetween($prevStart, $prevEnd, InvoicePayment::METHOD_CASH);

        $expense = (float) Transaction::query()
            ->where('type', Transaction::TYPE_OUT)
            ->whereBetween('transaction_date', [$start, $end])
            ->sum('amount');
        $prevExpense = (float) Transaction::query()
            ->where('type', Transaction::TYPE_OUT)
            ->whereBetween('transaction_date', [$prevStart, $prevEnd])
            ->sum('amount');

        // Nhóm "hiện trạng" — KHÔNG phụ thuộc kỳ đang lọc ở trên (tỷ lệ lấp đầy/hợp đồng hiệu lực/
        // nợ đều là ảnh chụp TẠI THỜI ĐIỂM XEM, đổi tab kỳ không đổi số này) — trước đây nằm ở
        // MinihouseStatsWidget riêng, giờ gộp vào cùng 1 khối với KPI theo kỳ để đỡ rối mắt.
        $totalRooms    = Room::count();
        $rentedRooms   = Room::where('status', Room::STATUS_RENTED)->count();
        $reservedRooms = Room::where('status', Room::STATUS_RESERVED)->count();
        $emptyRooms    = Room::where('status', Room::STATUS_EMPTY)->count();

        $unpaidInvoiceTotal = (float) Invoice::whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
            ->sum(DB::raw('total_amount - amount_paid'));

        $expiringContracts = Contract::where('status', Contract::STATUS_ACTIVE)
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->startOfDay(), now()->addDays(30)->endOfDay()])
            ->count();

        return [
            'dateRange'     => $dateRange,
            'prevDateRange' => $prevDateRange,

            'newContracts'      => $newContracts,
            'newContractsDelta' => self::delta($newContracts, $prevNewContracts),

            'revenue'      => $revenue,
            'revenueDelta' => self::delta($revenue, $prevRevenue),

            'revenueTransfer'      => $revenueTransfer,
            'revenueTransferDelta' => self::delta($revenueTransfer, $prevRevenueTransfer),

            'revenueCash'      => $revenueCash,
            'revenueCashDelta' => self::delta($revenueCash, $prevRevenueCash),

            'expense'      => $expense,
            'expenseDelta' => self::delta($expense, $prevExpense),

            'totalBuildings'      => Building::count(),
            'totalRooms'          => $totalRooms,
            'rentedRooms'         => $rentedRooms,
            'reservedRooms'       => $reservedRooms,
            'emptyRooms'          => $emptyRooms,
            'activeContracts'     => Contract::where('status', Contract::STATUS_ACTIVE)->count(),
            'expiringContracts'   => $expiringContracts,
            'unpaidInvoiceTotal'  => $unpaidInvoiceTotal,
        ];
    }
}
