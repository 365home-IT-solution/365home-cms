<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Filament\Pages;

use App\Services\ExtraChargeService;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as FilamentDashboard;
use Illuminate\Database\Eloquent\Builder;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Dashboard\App\Services\CommissionSummaryService;
use Modules\Dashboard\App\Services\HousekeepingSummaryService;
use Modules\Dashboard\App\Services\KpiService;
use Modules\Dashboard\App\Services\RoomCardsService;
use Illuminate\Support\Facades\DB;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\OrderItem;
use Modules\Product\App\Filament\Resources\ProductResource\Tables\Actions\RoomCleaningAction;
use Modules\Product\App\Models\Product;

class Dashboard extends FilamentDashboard
{
    public string $period = '30d';

    /** Chỉ dùng khi period === 'custom' */
    public ?string $customStart = null;
    public ?string $customEnd   = null;

    /** Tháng/năm đang xem ở phần "Doanh thu hoa hồng" — mặc định tháng hiện tại */
    public int $commissionMonth;
    public int $commissionYear;

    public function mount(): void
    {
        $this->commissionMonth = now()->month;
        $this->commissionYear  = now()->year;
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return '';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $user->can('page_Dashboard');
    }

    public function getView(): string
    {
        return 'dashboard::pages.dashboard';
    }

    public function getViewData(): array
    {
        $query = $this->baseQuery();

        [$startDate, $endDate, $prevStart, $prevEnd, $dateRange, $prevDateRange] = $this->getPeriodDates();

        $currentQuery  = (clone $query)->whereBetween('created_at', [$startDate, $endDate]);
        $previousQuery = (clone $query)->whereBetween('created_at', [$prevStart, $prevEnd]);

        $total     = (clone $currentQuery)->count();
        $prevTotal = (clone $previousQuery)->count();
        $totalDelta = $prevTotal > 0 ? round((($total - $prevTotal) / $prevTotal) * 100, 1) : 0;

        $revenue = static::sumOrderAmount((clone $currentQuery)->where('status', 'paid'))
                 + (clone $currentQuery)->where('status', 'deposit')->whereNotNull('money_deposit')->sum('money_deposit');
        $prevRevenue = static::sumOrderAmount((clone $previousQuery)->where('status', 'paid'))
                     + (clone $previousQuery)->where('status', 'deposit')->whereNotNull('money_deposit')->sum('money_deposit');
        $revenueDelta = $prevRevenue > 0 ? round((($revenue - $prevRevenue) / $prevRevenue) * 100, 1) : 0;

        // 'Tổng giá gốc' (full_amount, tổng giá lúc đặt, KHÔNG đổi kể cả có phát sinh sau này) đặt
        // cạnh 'Doanh thu' để dễ so sánh — CẢ 2 SỐ CÙNG PHẠM VI (chỉ đơn 'paid'), nếu 2 số khác
        // nhau thì chênh lệch chính là tổng phụ phí phát sinh trong kỳ (thêm dịch vụ/số khách sau
        // khi đã cọc/thanh toán).
        $revenuePaidOnly     = static::sumOrderAmount((clone $currentQuery)->where('status', 'paid'));
        $prevRevenuePaidOnly = static::sumOrderAmount((clone $previousQuery)->where('status', 'paid'));
        $revenueOriginal     = (int) ((clone $currentQuery)->where('status', 'paid')->sum('full_amount'));
        $prevRevenueOriginal = (int) ((clone $previousQuery)->where('status', 'paid')->sum('full_amount'));
        $revenueOriginalDelta = $prevRevenueOriginal > 0
            ? round((($revenueOriginal - $prevRevenueOriginal) / $prevRevenueOriginal) * 100, 1)
            : 0;
        $revenueExtraCharge = $revenuePaidOnly - $revenueOriginal;

        $revenuePayos = static::sumOrderAmount((clone $currentQuery)->where('status', 'paid')->where('payment_method', 'PayOS'));
        $prevRevenuePayos = static::sumOrderAmount((clone $previousQuery)->where('status', 'paid')->where('payment_method', 'PayOS'));
        $revenuePayosDelta = $prevRevenuePayos > 0 ? round((($revenuePayos - $prevRevenuePayos) / $prevRevenuePayos) * 100, 1) : 0;

        $revenueCod = static::sumOrderAmount((clone $currentQuery)->where('status', 'paid')->where('payment_method', 'cod'));
        $prevRevenueCod = static::sumOrderAmount((clone $previousQuery)->where('status', 'paid')->where('payment_method', 'cod'));
        $revenueCodDelta = $prevRevenueCod > 0 ? round((($revenueCod - $prevRevenueCod) / $prevRevenueCod) * 100, 1) : 0;

        $revenueDepositPayos = (clone $currentQuery)->where('status', 'deposit')->where('payment_method', 'PayOS')->sum('money_deposit');
        $prevRevenueDepositPayos = (clone $previousQuery)->where('status', 'deposit')->where('payment_method', 'PayOS')->sum('money_deposit');
        $revenueDepositPayosDelta = $prevRevenueDepositPayos > 0 ? round((($revenueDepositPayos - $prevRevenueDepositPayos) / $prevRevenueDepositPayos) * 100, 1) : 0;

        $revenueDepositCod = (clone $currentQuery)->where('status', 'deposit')->where('payment_method', 'cod')->sum('money_deposit');
        $prevRevenueDepositCod = (clone $previousQuery)->where('status', 'deposit')->where('payment_method', 'cod')->sum('money_deposit');
        $revenueDepositCodDelta = $prevRevenueDepositCod > 0 ? round((($revenueDepositCod - $prevRevenueDepositCod) / $prevRevenueDepositCod) * 100, 1) : 0;

        $paidCount = (clone $currentQuery)->whereIn('status', ['paid', 'completed'])->count();
        $prevPaid  = (clone $previousQuery)->whereIn('status', ['paid', 'completed'])->count();
        $paidDelta = $prevPaid > 0 ? round((($paidCount - $prevPaid) / $prevPaid) * 100, 1) : 0;

        $statusLabels = [
            'pending'   => 'Chờ xác nhận',
            'deposit'   => 'Đặt cọc',
            'paid'      => 'Đã thanh toán',
            'shipping'  => 'Đang xử lý',
            'completed' => 'Hoàn thành',
            'cancelled' => 'Đã huỷ',
            'failed'    => 'Thất bại',
        ];
        $statusColors = [
            'pending'   => '#e8c275',
            'deposit'   => '#d97757',
            'paid'      => '#9ab87a',
            'shipping'  => '#7a9cb5',
            'completed' => '#b58ab5',
            'cancelled' => '#c96f57',
            'failed'    => '#ef4444',
        ];

        $byStatus = (clone $currentQuery)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $sources = collect($statusLabels)
            ->map(function ($label, $key) use ($byStatus, $statusColors, $total) {
                $count = $byStatus[$key] ?? 0;
                $pct   = $total > 0 ? round(($count / $total) * 100, 1) : 0;
                return ['key' => $key, 'name' => $label, 'color' => $statusColors[$key], 'count' => $count, 'pct' => $pct];
            })
            ->filter(fn($s) => $s['count'] > 0)
            ->sortByDesc('count')
            ->values()
            ->toArray();

        $donutData = collect($sources)->map(fn($s) => [
            'value' => $s['count'], 'name' => $s['name'], 'hex' => $s['color'],
        ])->values()->toArray();

        $trendDays = $trendPending = $trendPaid = $trendCancel = [];
        for ($i = 6; $i >= 0; $i--) {
            $day           = Carbon::today()->subDays($i);
            $trendDays[]   = $day->isoFormat('dd D/M');
            $dq            = (clone $query)->whereDate('created_at', $day);
            $trendPending[] = (clone $dq)->where('status', 'pending')->count();
            $trendPaid[]   = (clone $dq)->whereIn('status', ['paid', 'completed'])->count();
            $trendCancel[] = (clone $dq)->where('status', 'cancelled')->count();
        }

        $barQuery = (clone $query)->where('created_at', '>=', Carbon::now()->subDays(30));
        $barData  = collect($statusLabels)
            ->map(fn($label, $key) => [
                'name' => $label, 'value' => (clone $barQuery)->where('status', $key)->count(), 'color' => $statusColors[$key],
            ])
            ->filter(fn($d) => $d['value'] > 0)
            ->sortBy('value')
            ->values()
            ->toArray();

        $roomCards      = static::getRoomCardsData();
        $roomRevenue    = static::getRoomRevenueData();
        $monthlyRevenue = static::getMonthlyRevenueData();
        $housekeeping   = static::getHousekeepingData();
        $commissionUser = auth()->user();
        $commissionIsSuperAdmin = $commissionUser?->isSuperAdmin() ?? false;

        if ($commissionIsSuperAdmin) {
            // super_admin: thấy hoa hồng của TẤT CẢ đối tác.
            $commission = $this->getCommissionData($this->commissionMonth, $this->commissionYear);
        } elseif ($commissionUser?->isPartnerOwner() && $commissionUser->partner_id) {
            // Chủ đối tác: chỉ thấy đúng số tiền đối tác MÌNH phải trả, không thấy của ai khác.
            $commission = CommissionSummaryService::getDataForPartner(
                $commissionUser->partner_id,
                $this->commissionMonth,
                $this->commissionYear
            );
        } else {
            // Nhân viên: không liên quan tới tài chính đối tác.
            $commission = null;
        }

        $user            = auth()->user();
        $branchesQuery   = \Modules\Category\Entities\Category::whereNull('parent_id')
            ->where('category_type', 'product')
            ->orderBy('name');
        if ($user && ! $user->isSuperAdmin()) {
            $allowedIds = $user->allowedCategoryIds() ?? [];
            if (! empty($allowedIds)) {
                $branchesQuery->whereIn('id', $allowedIds)
                    ->orWhereIn('id', \Modules\Category\Entities\Category::whereIn('id', $allowedIds)->pluck('parent_id')->filter()->toArray());
            }
        }
        $branches = $branchesQuery->get(['id', 'name']);

        return compact(
            'total', 'totalDelta',
            'revenue', 'revenueDelta',
            'revenueOriginal', 'revenueOriginalDelta', 'revenueExtraCharge',
            'revenuePayos', 'revenuePayosDelta',
            'revenueCod', 'revenueCodDelta',
            'revenueDepositPayos', 'revenueDepositPayosDelta',
            'revenueDepositCod', 'revenueDepositCodDelta',
            'paidCount', 'paidDelta',
            'sources', 'donutData',
            'trendDays', 'trendPending', 'trendPaid', 'trendCancel',
            'barData', 'dateRange', 'prevDateRange',
            'roomCards', 'roomRevenue', 'monthlyRevenue',
            'branches', 'housekeeping', 'commission', 'commissionIsSuperAdmin'
        );
    }

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

        // Kỳ so sánh: tháng này → tháng trước cùng kỳ; tháng trước / năm trước → cùng kỳ năm trước
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

    /** Proxy — dùng bởi routes/web.php (JSON polling) và Livewire SSR (Blade lần đầu) — GẮN LUÔN
     *  url menu thao tác nhanh ở đây để CẢ 2 nơi (Blade render lần đầu + JS tự làm mới định kỳ,
     *  xem pollRoomCards()/renderRoomCards() trong _scripts.blade.php) dùng chung 1 nguồn, tránh
     *  lặp lại logic build URL 2 nơi rồi lệch nhau. */
    public static function getRoomCardsData($user = null): array
    {
        $data = RoomCardsService::getData($user);

        $data['rooms'] = array_map(function (array $room) {
            $room['edit_url']     = \Modules\Product\App\Filament\Resources\ProductResource::getUrl('edit', ['record' => $room['product_id']]);
            $room['timeslot_url'] = \Modules\Book\App\Filament\Resources\BookResource\Pages\SettingBook::getUrl(['product_id' => $room['product_id']]);
            $room['book_url']     = \Modules\Payment\App\Filament\Resources\OrderResource::getUrl('create', ['product_id' => $room['product_id']]);

            return $room;
        }, $data['rooms']);

        return $data;
    }

    /** Tóm tắt phòng đang "đang dọn vệ sinh" — hiện ngay đầu Dashboard */
    public static function getHousekeepingData($user = null): array
    {
        return HousekeepingSummaryService::getData($user);
    }

    // Xác nhận đã dọn xong 1 phòng NGAY từ menu thao tác nhanh trên thẻ phòng (Dashboard), không
    // cần điều hướng sang trang Housekeeping — tái sử dụng ĐÚNG logic quyền hạn +
    // Product::confirmCleaned() mà RoomCleaningAction::confirmCleaning() (trang quản lý phòng)
    // đang dùng, tránh 2 nơi có 2 luật khác nhau. Livewire action bình thường — panel menu tuy
    // do JS dựng động (innerHTML) nhưng Livewire v3 (chạy trên Alpine) tự quan sát DOM bằng
    // MutationObserver nên directive wire:click gắn trong HTML chèn sau vẫn được nhận diện bình
    // thường, không cần route JSON riêng.
    public function confirmRoomCleaning(string $productId): void
    {
        $product = Product::find($productId);

        if (! $product) {
            return;
        }

        if ($product->housekeeping_status !== 'cleaning') {
            Notification::make()->warning()->title('Phòng không ở trạng thái cần dọn')->send();
            return;
        }

        if (! RoomCleaningAction::canManageCleaning($product)) {
            Notification::make()->danger()->title('Bạn không có quyền xác nhận dọn phòng này')->send();
            return;
        }

        $employee = auth()->user()?->employee;

        if (! $employee) {
            Notification::make()->danger()->title('Tài khoản này chưa có hồ sơ nhân viên liên kết — không thể xác nhận')->send();
            return;
        }

        $product->confirmCleaned($employee);

        Notification::make()->success()->title('Đã xác nhận dọn xong — phòng sẵn sàng nhận đặt mới')->send();
    }

    // Xác nhận hoàn tiền NGAY từ menu thao tác nhanh trên thẻ phòng — tái sử dụng ĐÚNG
    // ExtraChargeService::markRefundAsDone() + cách ghi AuditLogger mà
    // EditOrder::quickMarkRefundTransfer()/quickMarkRefundCash() (nút hoàn tiền ở trang sửa đơn)
    // đang dùng, chỉ khác nơi gọi — không viết luật hoàn tiền riêng ở đây.
    public function confirmRoomRefund(int $orderId, string $method): void
    {
        $order = Order::find($orderId);

        if (! $order) {
            return;
        }

        if (! auth()->user()?->can('update', $order)) {
            Notification::make()->danger()->title('Bạn không có quyền xử lý đơn này')->send();
            return;
        }

        $amount = (int) ($order->extra_refund_amount ?? 0);

        if ($amount <= 0 || ! is_null($order->extra_refund_paid_at)) {
            Notification::make()->warning()->title('Không có khoản hoàn tiền chờ xử lý')->send();
            return;
        }

        $method = $method === 'bank_transfer' ? 'bank_transfer' : 'cash';

        try {
            app(ExtraChargeService::class)->markRefundAsDone($order, $amount, $method);

            AuditLogger::log(
                'update', 'Order', $order, [],
                ['Đã hoàn tiền (' . ($method === 'cash' ? 'tiền mặt' : 'chuyển khoản') . ')' => number_format($amount, 0, ',', '.') . 'đ'],
                'Đơn #' . $order->order_code,
            );

            Notification::make()->success()->title('Đã ghi nhận hoàn tiền')->body(number_format($amount, 0, ',', '.') . 'đ')->send();
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('Lỗi: ' . $e->getMessage())->send();
        }
    }

    /** Hoa hồng super_admin nhận từ các đối tác theo tháng/năm chọn — chỉ super_admin xem được */
    public static function getCommissionData(?int $month = null, ?int $year = null): array
    {
        return CommissionSummaryService::getData($month, $year);
    }

    /** Proxy — dùng bởi routes/web.php */
    public static function getKpiData(string $period, $user = null, ?string $customStart = null, ?string $customEnd = null): array
    {
        return KpiService::getData($period, $user, $customStart, $customEnd);
    }

    /** Doanh thu từng phòng trong năm chỉ định (top 10, status=paid, PayOS+cod) */
    public static function getRoomRevenueData($user = null, ?int $year = null, ?array $branchCategoryIds = null): array
    {
        if ($user === null) {
            $user = auth()->user();
        }
        $year      = $year ?? Carbon::now()->year;
        $itemTable = (new OrderItem)->getTable();
        $ordTable  = (new Order)->getTable();
        $prodTable = (new Product)->getTable();
        // Subquery: lấy cặp (order_id, product_id) duy nhất kèm orders.amount thực tế.
        // Dùng orders.amount thay vì order_items.price*quantity vì slot bookings có thể lưu base price = 0
        // trong khi orders.amount luôn là số tiền thực tế đã thanh toán.
        $prefix = DB::getTablePrefix();
        $inner = DB::table("{$itemTable} as oi")
            ->join("{$ordTable} as o", 'oi.order_id', '=', 'o.id')
            ->join("{$prodTable} as p", 'oi.product_id', '=', 'p.id')
            ->where('o.status', 'paid')
            ->whereIn('o.payment_method', ['PayOS', 'cod'])
            ->where('o.exclude_from_stats', false)
            ->whereYear('o.created_at', $year)
            ->whereNotNull('oi.product_id')
            // 'amount' nay là tổng thực thu cuối cùng (gồm phụ phí phát sinh sau cọc nếu có),
            // 'full_amount' chỉ là tổng giá gốc cố định lúc đặt — ưu tiên amount, fallback full_amount.
            ->selectRaw("{$prefix}oi.product_id, {$prefix}p.name as product_name, {$prefix}o.id as order_id, COALESCE({$prefix}o.amount, {$prefix}o.full_amount) as order_amount")
            ->distinct();

        if ($user && ! $user->isSuperAdmin()) {
            // Đây là raw query builder (DB::table), KHÔNG đi qua Eloquent nên global scope
            // BelongsToPartner của Order không tự áp dụng — phải lọc partner_id thủ công ở đây.
            $inner->where('o.partner_id', $user->partner_id);

            $categoryIds = $user->allowedCategoryIds() ?? [];
            if (! empty($categoryIds)) {
                $allowedProductIds = Product::whereHas('categories', function ($q) use ($categoryIds) {
                    $q->whereIn('categories.id', $categoryIds);
                })->pluck('id')->toArray();
                $inner->whereIn('oi.product_id', $allowedProductIds);
            }
        }

        if ($branchCategoryIds !== null) {
            $inner->whereIn('o.category_id', $branchCategoryIds);
        }

        $rooms = DB::table(DB::raw("({$inner->toSql()}) as sub"))
            ->mergeBindings($inner)
            ->selectRaw('product_id, product_name, SUM(order_amount) as revenue, COUNT(*) as order_count')
            ->groupBy('product_id', 'product_name')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        $total = $rooms->sum('revenue');

        return [
            'rooms' => $rooms->map(fn ($r) => [
                'product_id'  => $r->product_id,
                'name'        => $r->product_name,
                'revenue'     => (int) $r->revenue,
                'order_count' => (int) $r->order_count,
                'pct'         => $total > 0 ? round(($r->revenue / $total) * 100, 1) : 0,
            ])->values()->toArray(),
            'total'           => (int) $total,
            'year'            => $year,
            'available_years' => static::getAvailableYears($user),
        ];
    }

    /** Doanh thu từng tháng trong năm chỉ định (mảng 12 phần tử, status=paid, PayOS+cod) */
    public static function getMonthlyRevenueData($user = null, ?int $year = null, ?array $branchCategoryIds = null): array
    {
        if ($user === null) {
            $user = auth()->user();
        }
        $year  = $year ?? Carbon::now()->year;
        $query = Order::query()
            ->where('exclude_from_stats', false)
            ->where('status', 'paid')
            ->whereIn('payment_method', ['PayOS', 'cod'])
            ->whereYear('created_at', $year);

        if ($user && ! $user->isSuperAdmin()) {
            // Order (Eloquent) đã tự lọc theo partner_id (BelongsToPartner); allowedCategoryIds
            // chỉ thu hẹp thêm, không dùng để chặn hết khi rỗng.
            $categoryIds = $user->allowedCategoryIds() ?? [];
            if (! empty($categoryIds)) {
                $allowedProductIds = Product::whereHas('categories', function ($q) use ($categoryIds) {
                    $q->whereIn('categories.id', $categoryIds);
                })->pluck('id')->toArray();
                $query->whereHas('items', function ($q) use ($allowedProductIds) {
                    $q->whereIn('product_id', $allowedProductIds);
                });
            }
        }

        if ($branchCategoryIds !== null) {
            $query->whereIn('category_id', $branchCategoryIds);
        }

        $data = $query
            ->selectRaw('MONTH(created_at) as month, SUM(COALESCE(amount, full_amount)) as revenue')
            ->groupByRaw('MONTH(created_at)')
            ->pluck('revenue', 'month');

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[] = (int) ($data[$m] ?? 0);
        }

        return [
            'months'          => $months,
            'year'            => $year,
            'available_years' => static::getAvailableYears($user),
        ];
    }

    /** Các năm có đơn hàng paid (PayOS + cod), mới nhất trước */
    public static function getAvailableYears($user = null): array
    {
        if ($user === null) {
            $user = auth()->user();
        }
        $query = Order::query()
            ->where('exclude_from_stats', false)
            ->where('status', 'paid')
            ->whereIn('payment_method', ['PayOS', 'cod'])
            ->selectRaw('YEAR(created_at) as year')
            ->distinct()
            ->orderByRaw('YEAR(created_at) DESC');

        if ($user && ! $user->isSuperAdmin()) {
            // Order (Eloquent) đã tự lọc theo partner_id (BelongsToPartner); allowedCategoryIds
            // chỉ thu hẹp thêm, không dùng để chặn hết khi rỗng.
            $categoryIds = $user->allowedCategoryIds() ?? [];
            if (! empty($categoryIds)) {
                $allowedProductIds = Product::whereHas('categories', function ($q) use ($categoryIds) {
                    $q->whereIn('categories.id', $categoryIds);
                })->pluck('id')->toArray();
                $query->whereHas('items', function ($q) use ($allowedProductIds) {
                    $q->whereIn('product_id', $allowedProductIds);
                });
            }
        }

        $years = $query->pluck('year')->map(fn ($y) => (int) $y)->toArray();

        return empty($years) ? [Carbon::now()->year] : $years;
    }

    private function baseQuery(): Builder
    {
        $query = Order::query()->where('exclude_from_stats', false);
        $user  = auth()->user();
        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }
        $allCategoryIds = $user->allowedCategoryIds();
        // Order đã tự lọc theo partner_id (BelongsToPartner); allowedCategoryIds chỉ thu hẹp thêm.
        if (empty($allCategoryIds)) {
            return $query;
        }
        return $query->whereIn('category_id', $allCategoryIds);
    }

    /** Tổng tiền đơn đã thanh toán: ưu tiên amount (tổng thực thu cuối cùng), fallback full_amount */
    private static function sumOrderAmount($query): int
    {
        return (int) ($query->selectRaw('SUM(COALESCE(amount, full_amount)) as total')->value('total') ?? 0);
    }
}
