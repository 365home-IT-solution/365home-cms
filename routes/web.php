<?php

use Illuminate\Support\Facades\Route;

// Fallback cho route tên "login" — KHÔNG có trang đăng nhập thật ở đây. App này không có route
// tên "login" nào khác (chỉ có "api.auth.login" — tên khác, không tính); Laravel mặc định fallback
// về route('login') bất cứ khi nào 1 AuthenticationException không tự xác định được URL redirect
// (Illuminate\Foundation\Exceptions\Handler::unauthenticated(): redirect()->guest($exception->
// redirectTo() ?? route('login'))). Thiếu route này khiến bất kỳ request nào tới /admin/api/* (dùng
// middleware 'auth' thường, không phải middleware riêng của Filament) bị mất phiên đăng nhập giữa
// chừng sẽ crash 500 "Route [login] not defined" thay vì chuyển hướng nhẹ nhàng về trang đăng nhập
// admin — thay vì phải sửa từng route/middleware, chặn đứng lỗi tại điểm chung này.
Route::get('/login', fn () => redirect(\Filament\Facades\Filament::getLoginUrl()))->name('login');

// "Chuyển đổi tài khoản" — bước commit thật sự, xem giải thích trong AccountSwitchController.
Route::middleware('web')->group(function () {
    Route::get('/admin/account-switch/commit', [\App\Http\Controllers\AccountSwitchController::class, 'commit'])
        ->name('admin.account-switch.commit');
    Route::get('/admin/account-switch/back', [\App\Http\Controllers\AccountSwitchController::class, 'back'])
        ->name('admin.account-switch.back');
});

// Admin: unread notification count (for tab-title polling)
// Cached per-user for 2s — supports many concurrent admins without hammering the DB
//
// QUAN TRỌNG: phải có \App\Http\Middleware\MarkAdminPanelContext::class ở đây — nhóm route này là
// route Laravel thường (không đi qua panel Filament), nên nếu thiếu middleware này,
// App\Support\AdminPanelContext::isActive() sẽ luôn false trong suốt request, khiến scope
// 'partner' của App\Models\Concerns\BelongsToPartner tự động BỎ QUA lọc partner_id — mọi query
// Order/Product/... ở các route bên dưới (monthly-revenue, room-cards, kpi-stats, branch-monthly,
// branch-revenue, top-customers, room-revenue...) sẽ trả về dữ liệu CỦA TẤT CẢ đối tác thay vì chỉ
// đối tác của user đang đăng nhập — vừa sai số liệu dashboard (khác với lần load trang đầu tiên đi
// qua panel Filament, có middleware này), vừa lộ dữ liệu chéo đối tác cho user không phải
// super_admin.
Route::middleware(['auth', 'web', 'throttle:120,1', \App\Http\Middleware\MarkAdminPanelContext::class])->prefix('admin/api')->group(function () {
    Route::get('notifications/unread-count', function () {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['count' => 0, 'latest_type' => null]);
        }

        $count = \Illuminate\Support\Facades\Cache::remember(
            'admin_unread_notif_' . $user->id,
            2, // seconds
            fn () => $user->unreadNotifications()->count()
        );

        // 'type' của thông báo CHƯA ĐỌC mới nhất — vd 'booking_confirmation'|'payment'|
        // 'order_update'|'extra_charge'|'message'|'checkin'|'checkout' (xem
        // App\Services\AdminNotificationService, field lưu trong viewData) — dùng để JS polling ở
        // AdminPanelProvider chọn ĐÚNG file âm thanh (tin nhắn khách phát âm thanh khác với đơn
        // hàng), không cần gọi thêm API nào khác.
        $latestType = \Illuminate\Support\Facades\Cache::remember(
            'admin_unread_notif_type_' . $user->id,
            2,
            function () use ($user) {
                $latest = $user->unreadNotifications()->latest()->first();

                return $latest?->data['viewData']['type'] ?? null;
            }
        );

        return response()->json(['count' => $count, 'latest_type' => $latestType]);
    })->name('admin.notifications.unread-count');

    // Room cards polling — trả về JSON để JS cập nhật section phòng không reload trang.
    // ?days= — số ngày hiển thị ở view "Lịch" (select 5/10/15 cạnh carousel chi nhánh,
    // rcCalApplyDaysRange() trong _scripts.blade.php) — mặc định 10, chặn tối đa 15 (đã chặn phía
    // client, chặn lại ở đây phòng khi request bị sửa tay/gọi trực tiếp ngoài ý muốn).
    Route::get('room-cards', function (\Illuminate\Http\Request $request) {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['branches' => [], 'rooms' => [], 'total_rooms' => 0, 'total_orders' => 0]);
        }

        $days = (int) $request->query('days', 10);
        $days = max(1, min(15, $days));

        return response()->json(
            \Modules\Dashboard\App\Filament\Pages\Dashboard::getRoomCardsData($user, $days)
        );
    })->name('admin.room-cards');

    // KPI stats polling — trả về 4 chỉ số theo period
    Route::get('kpi-stats', function (\Illuminate\Http\Request $request) {
        $user = auth()->user();
        if (! $user) {
            return response()->json([]);
        }
        $period = in_array($request->query('period'), ['today', 'yesterday', '7d', '30d', '90d', 'this_month', 'last_month', 'ytd', 'last_year', 'custom'])
            ? $request->query('period')
            : '30d';
        $customStart = $period === 'custom' ? $request->query('custom_start') : null;
        $customEnd   = $period === 'custom' ? $request->query('custom_end')   : null;

        $branchCategoryIds = null;
        $branchId = $request->query('branch_id');
        if ($branchId && ctype_digit((string) $branchId)) {
            $branchId = (int) $branchId;
            $childIds = \Modules\Category\Entities\Category::where('parent_id', $branchId)->pluck('id')->toArray();
            $branchCategoryIds = array_merge([$branchId], $childIds);
        }

        return response()->json(
            \Modules\Dashboard\App\Services\KpiService::getData($period, $user, $customStart, $customEnd, $branchCategoryIds)
        );
    })->name('admin.kpi-stats');

    // Branch monthly revenue — doanh thu từng chi nhánh (category parent_id IS NULL, type=product) theo 12 tháng
    Route::get('branch-monthly', function (\Illuminate\Http\Request $request) {
        $user = auth()->user();
        if (! $user) return response()->json(['branches' => [], 'months' => [], 'year' => date('Y')]);

        $year = (int) $request->query('year', now()->year);

        $branchesQuery = \Modules\Category\Entities\Category::whereNull('parent_id')
            ->where('category_type', 'product')
            ->orderBy('name');

        // Category không có global scope partner_id riêng — mặc định chỉ lấy chi nhánh của
        // đối tác mình (super_admin xem hết).
        if ($user && ! $user->isSuperAdmin()) {
            $branchesQuery->where('partner_id', $user->partner_id);
        }

        $filterBranchId = $request->query('branch_id');
        if ($filterBranchId && ctype_digit((string) $filterBranchId)) {
            $branchesQuery->where('id', (int) $filterBranchId);
        }

        $branches = $branchesQuery->get();

        if ($user && ! $user->isSuperAdmin()) {
            $allowedIds = $user->allowedCategoryIds() ?? [];
            // allowedCategoryIds chỉ thu hẹp thêm (giới hạn nhân viên vào 1 vài chi nhánh cụ
            // thể) — không áp dụng khi rỗng, vì đã lọc theo partner_id ở trên rồi.
            if (! empty($allowedIds)) {
                $branches = $branches->filter(function ($b) use ($allowedIds) {
                    $childIds = \Modules\Category\Entities\Category::where('parent_id', $b->id)->pluck('id')->toArray();
                    return count(array_intersect(array_merge([$b->id], $childIds), $allowedIds)) > 0;
                })->values();
            }
        }

        $months = array_map(fn($m) => str_pad((string)$m, 2, '0', STR_PAD_LEFT), range(1, 12));
        $result = [];

        foreach ($branches as $branch) {
            $childIds  = \Modules\Category\Entities\Category::where('parent_id', $branch->id)->pluck('id')->toArray();
            $allCatIds = array_merge([$branch->id], $childIds);

            $rows = \Modules\Payment\Entities\Order::selectRaw('MONTH(created_at) as m, SUM(amount) as rev_paid, SUM(money_deposit) as rev_dep')
                ->whereYear('created_at', $year)
                ->whereIn('category_id', $allCatIds)
                ->where(function ($q) {
                    $q->where('status', 'paid')
                      ->orWhere(function ($q2) { $q2->where('status', 'deposit')->whereNotNull('money_deposit'); });
                })
                ->groupByRaw('MONTH(created_at)')
                ->get()
                ->keyBy('m');

            $monthlyData = [];
            for ($m = 1; $m <= 12; $m++) {
                $row = $rows->get($m);
                $monthlyData[] = $row
                    ? (int)(($row->rev_paid ?? 0) + ($row->rev_dep ?? 0))
                    : 0;
            }

            $result[] = ['name' => $branch->name, 'data' => $monthlyData];
        }

        return response()->json(['branches' => $result, 'year' => $year]);
    })->name('admin.branch-monthly');

    // Branch revenue — doanh thu từng chi nhánh theo period hiện tại
    Route::get('branch-revenue', function (\Illuminate\Http\Request $request) {
        $user = auth()->user();
        if (! $user) return response()->json(['branches' => [], 'total' => 0, 'dateRange' => '']);

        $period = in_array($request->query('period'), ['today','yesterday','7d','30d','90d','this_month','last_month','ytd','last_year','custom'])
            ? $request->query('period') : '30d';
        $customStart = $period === 'custom' ? $request->query('custom_start') : null;
        $customEnd   = $period === 'custom' ? $request->query('custom_end')   : null;

        $end = \Carbon\Carbon::now()->endOfDay();
        if ($period === 'custom') {
            $start = $customStart ? \Carbon\Carbon::parse($customStart)->startOfDay() : \Carbon\Carbon::now()->subDays(29)->startOfDay();
            $end   = $customEnd   ? \Carbon\Carbon::parse($customEnd)->endOfDay()     : \Carbon\Carbon::now()->endOfDay();
            if ($start->gt($end)) [$start, $end] = [$end, $start];
        } elseif ($period === 'today') {
            $start = \Carbon\Carbon::today()->startOfDay(); $end = \Carbon\Carbon::today()->endOfDay();
        } elseif ($period === 'yesterday') {
            $start = \Carbon\Carbon::yesterday()->startOfDay(); $end = \Carbon\Carbon::yesterday()->endOfDay();
        } elseif ($period === 'this_month') {
            $start = \Carbon\Carbon::now()->startOfMonth()->startOfDay();
        } elseif ($period === 'last_month') {
            $start = \Carbon\Carbon::now()->subMonthNoOverflow()->startOfMonth()->startOfDay();
            $end   = \Carbon\Carbon::now()->subMonthNoOverflow()->endOfMonth()->endOfDay();
        } elseif ($period === 'last_year') {
            $start = \Carbon\Carbon::now()->subYear()->startOfYear()->startOfDay();
            $end   = \Carbon\Carbon::now()->subYear()->endOfYear()->endOfDay();
        } elseif ($period === 'ytd') {
            $start = \Carbon\Carbon::today()->startOfYear()->startOfDay();
        } else {
            $days  = match ($period) { '7d' => 7, '90d' => 90, default => 30 };
            $start = \Carbon\Carbon::now()->subDays($days - 1)->startOfDay();
        }

        $branchQuery = \Modules\Category\Entities\Category::whereNull('parent_id')
            ->where('category_type', 'product')
            ->orderBy('name');

        if ($user && ! $user->isSuperAdmin()) {
            // Category không có global scope partner_id riêng — mặc định chỉ lấy chi nhánh của
            // đối tác mình; allowedCategoryIds chỉ thu hẹp thêm nếu có.
            $branchQuery->where('partner_id', $user->partner_id);

            $allowedIds = $user->allowedCategoryIds() ?? [];
            if (! empty($allowedIds)) {
                $branchQuery->where(function ($q) use ($allowedIds) {
                    $q->whereIn('id', $allowedIds)
                      ->orWhereIn('id', \Modules\Category\Entities\Category::whereIn('id', $allowedIds)->pluck('parent_id')->filter()->toArray());
                });
            }
        }

        $branches = $branchQuery->get();
        $result   = [];

        foreach ($branches as $branch) {
            $childIds  = \Modules\Category\Entities\Category::where('parent_id', $branch->id)->pluck('id')->toArray();
            $allCatIds = array_merge([$branch->id], $childIds);

            $q = \Modules\Payment\Entities\Order::whereBetween('created_at', [$start, $end])
                ->whereIn('category_id', $allCatIds)
                ->where('exclude_from_stats', false);

            $count   = (clone $q)->count();
            $revenue = (clone $q)->where('status', 'paid')->sum('amount')
                     + (clone $q)->where('status', 'deposit')->whereNotNull('money_deposit')->sum('money_deposit');

            $result[] = ['name' => $branch->name, 'count' => (int) $count, 'revenue' => (int) $revenue];
        }

        usort($result, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        $total = array_sum(array_column($result, 'revenue'));

        return response()->json([
            'branches'  => $result,
            'total'     => $total,
            'dateRange' => $start->format('d/m') . ' – ' . $end->format('d/m'),
        ]);
    })->name('admin.branch-revenue');

    // Top customers — top điện thoại đặt phòng nhiều nhất trong năm
    Route::get('top-customers', function (\Illuminate\Http\Request $request) {
        $user = auth()->user();
        if (! $user) return response()->json(['customers' => [], 'year' => date('Y')]);

        $year      = (int) $request->query('year', now()->year);
        $limit     = min((int) $request->query('limit', 5), 50);
        $sortParam = in_array($request->query('sort', 'count'), ['count', 'revenue']) ? $request->query('sort') : 'count';
        $minOrders = max(1, (int) $request->query('min_orders', 1));

        $sortCol = $sortParam === 'revenue' ? 'total_revenue' : 'booking_count';

        $query = \Modules\Payment\Entities\Order::selectRaw(
                'buyer_phone,
                 MAX(buyer_name) as buyer_name,
                 COUNT(*) as booking_count,
                 SUM(CASE WHEN status IN (\'paid\',\'confirmed\') THEN amount ELSE 0 END)
                 + SUM(CASE WHEN status = \'deposit\' AND money_deposit IS NOT NULL THEN money_deposit ELSE 0 END)
                 AS total_revenue,
                 TIMESTAMPDIFF(MONTH, MIN(created_at), MAX(created_at)) + 1 AS active_months'
            )
            ->where('exclude_from_stats', false)
            ->whereNotNull('buyer_phone')
            ->where('buyer_phone', '!=', '')
            ->whereYear('created_at', $year)
            ->whereIn('status', ['paid', 'confirmed', 'deposit'])
            ->groupBy('buyer_phone')
            ->having('booking_count', '>=', $minOrders)
            ->orderByDesc($sortCol)
            ->limit($limit);

        if (! $user->isSuperAdmin()) {
            $allowedIds = $user->allowedCategoryIds() ?? [];
            if (! empty($allowedIds)) {
                $query->whereIn('category_id', $allowedIds);
            }
        }

        $branchId = $request->query('branch_id');
        if ($branchId && ctype_digit((string) $branchId)) {
            $branchId = (int) $branchId;
            $childIds = \Modules\Category\Entities\Category::where('parent_id', $branchId)->pluck('id')->toArray();
            $query->whereIn('category_id', array_merge([$branchId], $childIds));
        }

        $customers = $query->get()->map(fn ($c) => [
            'phone'         => $c->buyer_phone,
            'name'          => $c->buyer_name ?: $c->buyer_phone,
            'count'         => (int) $c->booking_count,
            'revenue'       => (int) $c->total_revenue,
            'avg_per_month' => round($c->booking_count / max((int) $c->active_months, 1), 1),
        ])->values();

        return response()->json(['customers' => $customers, 'year' => $year]);
    })->name('admin.top-customers');

    // Order pulse — trả về 2 timestamps tách biệt:
    //   created_ts → đơn mới tạo  → JS kêu chuông + refresh
    //   updated_ts → đổi trạng thái (paid/cancelled) → JS chỉ refresh, không kêu chuông
    Route::get('orders/latest-ts', function () {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['created_ts' => 0, 'updated_ts' => 0]);
        }

        $data = \Illuminate\Support\Facades\Cache::remember(
            'admin_orders_latest_ts_' . $user->id,
            5,
            function () use ($user) {
                $query = \Modules\Payment\Entities\Order::query();
                if (! $user->isSuperAdmin()) {
                    // Order đã tự lọc theo partner_id (BelongsToPartner); allowedCategoryIds chỉ
                    // thu hẹp thêm, không dùng để chặn hết khi rỗng.
                    $categoryIds = $user->allowedCategoryIds() ?? [];
                    if (! empty($categoryIds)) {
                        $query->whereIn('category_id', $categoryIds);
                    }
                }
                $latestCreated = $query->max('created_at');
                $latestUpdated = $query->max('updated_at');
                return [
                    'created_ts' => $latestCreated ? \Carbon\Carbon::parse($latestCreated)->timestamp : 0,
                    'updated_ts' => $latestUpdated ? \Carbon\Carbon::parse($latestUpdated)->timestamp : 0,
                ];
            }
        );

        return response()->json($data);
    })->name('admin.orders.latest-ts');

    // Room revenue ranking — top 10 phòng theo doanh thu trong năm chỉ định
    Route::get('room-revenue', function (\Illuminate\Http\Request $request) {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['rooms' => [], 'total' => 0]);
        }

        $year = (int) $request->query('year', now()->year);

        $branchCategoryIds = null;
        $branchId = $request->query('branch_id');
        if ($branchId && ctype_digit((string) $branchId)) {
            $branchId = (int) $branchId;
            $childIds = \Modules\Category\Entities\Category::where('parent_id', $branchId)->pluck('id')->toArray();
            $branchCategoryIds = array_merge([$branchId], $childIds);
        }

        return response()->json(
            \Modules\Dashboard\App\Filament\Pages\Dashboard::getRoomRevenueData($user, $year, $branchCategoryIds)
        );
    })->name('admin.room-revenue');

    // FCM token — lưu/cập nhật device token cho user hiện tại
    Route::post('fcm-token', function (\Illuminate\Http\Request $request) {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['ok' => false], 401);
        }

        $token = $request->input('token');
        if (! $token || strlen($token) < 20) {
            return response()->json(['ok' => false], 422);
        }

        \App\Models\FcmToken::upsertForUser($user->id, $token);

        return response()->json(['ok' => true]);
    })->name('admin.fcm-token');

    // Quick info popup — bấm 1 ô đã đặt trong view "Lịch" (Dashboard::_room-cards.blade.php,
    // rcCalOpenOrderPopup()) gọi route này để lấy nhanh thông tin khách + tổng thanh toán mà
    // KHÔNG cần điều hướng sang orderform ngay — nút "Xem chi tiết đơn" trong popup mới điều
    // hướng. 'amount_html' render THẲNG component 'payment::components.total-amount-card' (ĐÚNG
    // component orderform dùng ở khối "Tổng thanh toán") từ order_items ĐÃ LƯU trong DB — các
    // dòng order_items lưu sẵn đúng shape component này cần (checkin_date/checkout_date/price/
    // discount — xem OrderForm::expandOrderItemsForPersistence(), 'price' = giá sau khuyến mãi,
    // 'discount' = giá gốc slot, khớp luôn 2 cột price/discount của OrderItem), nên không cần
    // dựng lại state form/gọi lại engine tính giá riêng — dùng lại NGUYÊN component thật.
    Route::get('orders/{id}/quick-info', function ($id) {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $query = \Modules\Payment\Entities\Order::with(['items.product', 'services', 'accessCodes']);
        if (! $user->isSuperAdmin()) {
            $allowedIds = $user->allowedCategoryIds() ?? [];
            if (! empty($allowedIds)) {
                $query->whereIn('category_id', $allowedIds);
            }
        }
        $order = $query->find($id);

        if (! $order) {
            return response()->json(['message' => 'Không tìm thấy đơn.'], 404);
        }

        $statusLabels = [
            'pending'   => 'Chờ xác nhận',
            'deposit'   => 'Đặt cọc',
            'paid'      => 'Đã thanh toán',
            'shipping'  => 'Đang xử lý',
            'completed' => 'Hoàn thành',
            'cancelled' => 'Đã huỷ',
            'failed'    => 'Thất bại',
        ];

        $items = $order->items->map(fn ($item) => [
            'checkin_date'  => $item->checkin_date?->format('Y-m-d H:i:s'),
            'checkout_date' => $item->checkout_date?->format('Y-m-d H:i:s'),
            'price'         => (float) $item->price,
            'discount'      => (float) $item->discount,
            'product_id'    => $item->product_id,
            'name'          => $item->name,
            'guest_count'   => $item->guest_count,
            'extra_fee'     => (float) $item->extra_fee,
        ])->values()->all();

        $amountHtml = view('payment::components.total-amount-card', [
            'items'            => $items,
            'record'           => $order,
            'surcharge'        => $order->surcharge,
            'displayTotal'     => (int) $order->amount,
            // Ẩn khối "Chi phí phát sinh" + nút "Phát sinh thêm.../Hoàn lại..." — nút đó gọi
            // wire:click="save", không hoạt động khi card bị render tĩnh ra popup xem nhanh (xem
            // ghi chú $hideAdjustments trong chính component).
            'hideAdjustments' => true,
        ])->render();

        // Mã cổng — ĐÚNG luật hiển thị của OrderForm::hasAccessCodeSection() (chỉ đơn đã 'paid' —
        // access code/mật khẩu thủ công chỉ thật sự được gán lúc đơn chuyển paid): phòng
        // has_manual_lock -> mật khẩu thủ công (ManualLockPassword, mốc paid_at/deposit_paid_at/
        // created_at — KHÔNG dùng checkin_date/now(), xem docblock getForProductAndDate()); nếu
        // không thì chi nhánh có tài khoản TTLock đang active (TTLockService::hasAccountForCategory())
        // -> mã cổng TTLock/pool đã gán (accessCodes, load sẵn ở trên). Chi nhánh không thuộc cả 2
        // trường hợp -> 'access_code' = null, JS không hiện khối này.
        //
        // Trả DỮ LIỆU THÔ (không render sẵn access-code-info.blade.php/manual-lock-info.blade.php
        // như 'amount_html' ở trên) — 2 component đó thiết kế cho khung RỘNG của orderform (chữ
        // mã cỡ 3xl/4xl, lưới hiệu lực 2 cột, padding lớn), nhồi vào cột trái hẹp của popup này
        // (~230px) sẽ vỡ layout — JS (rcCalOpenOrderPopup()) tự dựng bản GỌN riêng từ dữ liệu này.
        $accessCode = null;
        $product = $order->items->sortBy('checkin_date')->first()?->product;

        if ($order->status === 'paid' && $product) {
            if ($product->has_manual_lock) {
                $pwdAnchorDate = $order->paid_at ?? $order->deposit_paid_at ?? $order->created_at;
                $manualPwd = \Modules\Product\App\Models\ManualLockPassword::getForProductAndDate($product, $pwdAnchorDate);

                if ($manualPwd && ($manualPwd->gate_password || $manualPwd->room_password)) {
                    $accessCode = [
                        'type'          => 'manual',
                        'gate_password' => $manualPwd->gate_password,
                        'room_password' => $manualPwd->room_password,
                        'status_label'  => $manualPwd->status_label,
                        'status_color'  => $manualPwd->status_color,
                        'valid_from'    => $manualPwd->valid_from?->format('d/m/Y H:i'),
                        'valid_until'   => $manualPwd->valid_until?->format('d/m/Y H:i'),
                    ];
                }
            } elseif (\Modules\TTLock\App\Services\TTLockService::hasAccountForCategory($order->category_id)) {
                $code = $order->accessCodes->first();

                $accessCode = [
                    'type'          => 'ttlock',
                    'code'          => $code?->code,
                    'status_label'  => $code ? ($code->status === 'active' ? 'Hoạt động' : ($code->status === 'expired' ? 'Hết hạn' : 'Vô hiệu hoá')) : null,
                    'status_color'  => $code ? ($code->status === 'active' ? 'success' : ($code->status === 'expired' ? 'danger' : 'gray')) : 'gray',
                    'valid_from'    => $code?->valid_from?->format('d/m/Y H:i'),
                    'valid_until'   => $code?->valid_until?->format('d/m/Y H:i'),
                    'gate_location' => $code?->gate_location,
                ];
            }
        }

        return response()->json([
            'order_id'         => $order->id,
            'order_code'       => $order->order_code,
            'buyer_name'       => $order->buyer_name,
            'buyer_phone'      => $order->buyer_phone,
            'description'      => $order->description,
            'status'           => $order->status,
            'status_label'     => $statusLabels[$order->status] ?? $order->status,
            'amount_html'      => $amountHtml,
            'access_code'      => $accessCode,
            'edit_url'         => '/admin/orders/' . $order->id . '/edit',
        ]);
    })->name('admin.orders.quick-info');

    // Lưu ghi chú đơn (Order.description) — sửa trực tiếp trong popup thông tin nhanh của view
    // "Lịch" (rcCalSaveOrderNote() trong _scripts.blade.php), KHÔNG cần mở orderform. Trả lại
    // 'has_note' để JS tự cập nhật badge trên MỌI ô khung giờ thuộc đơn này (window.__rcRoomsData)
    // mà không cần load lại toàn bộ dữ liệu phòng.
    Route::post('orders/{id}/description', function (\Illuminate\Http\Request $request, $id) {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['ok' => false], 401);
        }
        $query = \Modules\Payment\Entities\Order::query();
        if (! $user->isSuperAdmin()) {
            $allowedIds = $user->allowedCategoryIds() ?? [];
            if (! empty($allowedIds)) {
                $query->whereIn('category_id', $allowedIds);
            }
        }
        $order = $query->find($id);
        if (! $order) {
            return response()->json(['ok' => false], 404);
        }

        $data = $request->validate([
            'description' => 'nullable|string|max:500',
        ]);

        $order->description = $data['description'] !== null ? trim($data['description']) : null;
        $order->save();

        return response()->json([
            'ok'          => true,
            'description' => $order->description,
            'has_note'    => filled($order->description),
        ]);
    })->name('admin.orders.description');

    // Thống kê nhanh 1 phòng — popup "Xem phòng" ở view "Lịch" (rcCalOpenViewPopup() trong
    // _scripts.blade.php): tổng doanh thu + số đơn thành công (ALL-TIME), doanh thu 12 tháng
    // (T1-T12) của NĂM đang lọc (?year=, mặc định năm hiện tại — ĐÚNG cấu trúc
    // Dashboard::getMonthlyRevenueData(), panel "04/Theo tháng" ở Tổng quan, nhưng scope theo 1
    // phòng). Định nghĩa "thành công" ĐÚNG như Dashboard::getRoomRevenueData() (panel "Doanh thu
    // phòng") — status=paid, payment_method PayOS/cod, exclude_from_stats=false — để số liệu khớp
    // nhau giữa các panel, không định nghĩa lại.
    Route::get('rooms/{id}/stats-popup', function (\Illuminate\Http\Request $request, $id) {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $query = \Modules\Product\App\Models\Product::query();
        if (! $user->isSuperAdmin()) {
            $allowedCategoryIds = $user->allowedCategoryIds() ?? [];
            if (! empty($allowedCategoryIds)) {
                $query->where(function ($q) use ($allowedCategoryIds) {
                    $q->whereHas('categories', function ($q2) use ($allowedCategoryIds) {
                        $q2->whereIn('categories.id', $allowedCategoryIds);
                    })->orWhereDoesntHave('categories');
                });
            }
        }
        $product = $query->find($id);

        if (! $product) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        $year = (int) $request->query('year', now()->year);

        $baseQuery = fn () => \Modules\Payment\Entities\Order::query()
            ->where('exclude_from_stats', false)
            ->where('status', 'paid')
            ->whereIn('payment_method', ['PayOS', 'cod'])
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->id));

        $totalRevenue = (int) $baseQuery()->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(amount, full_amount)'));
        $totalOrders  = $baseQuery()->count();

        // Doanh thu theo tháng (T1-T12) của NĂM đang chọn.
        $monthlyRows = $baseQuery()
            ->whereYear('created_at', $year)
            ->selectRaw('MONTH(created_at) as m, SUM(COALESCE(amount, full_amount)) as revenue')
            ->groupByRaw('MONTH(created_at)')
            ->pluck('revenue', 'm');

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[] = (int) ($monthlyRows[$m] ?? 0);
        }

        return response()->json([
            'room_name'       => $product->name,
            'total_revenue'   => $totalRevenue,
            'total_orders'    => $totalOrders,
            'year'            => $year,
            'available_years' => \Modules\Dashboard\App\Filament\Pages\Dashboard::getAvailableYears($user),
            'monthly'         => $months,
        ]);
    })->name('admin.rooms.stats-popup');

    // Giá phòng — popup "Giá phòng" ở view "Lịch" (Dashboard::_room-cards.blade.php,
    // rcCalOpenPricePopup()) đọc nhanh cấu hình giá của 1 phòng theo khung giờ (styles=1) mà
    // không cần mở SettingBook đầy đủ: giảm giá full phòng, số khách miễn phí/phụ thu, giảm giá
    // theo số khung giờ, và từng khung giờ + giá + khuyến mãi đang gắn — ĐÚNG field
    // RoomCardsService::computeSlotAmount()/SettingBook.php đang đọc, không tính lại công thức
    // riêng. 'available_promotions' — ĐÚNG luật allowedPromotionOptions() của SettingBook.php
    // (is_active=true, super_admin thấy hết, nhân viên chỉ thấy khuyến mãi không gán riêng ai
    // (created_by null) + khuyến mãi của đồng nghiệp CÙNG chi nhánh) — để popup gắn/gỡ khuyến mãi
    // đúng phạm vi được phép, không lộ khuyến mãi ngoài phạm vi.
    Route::get('rooms/{id}/pricing-info', function ($id) {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Product tự lọc theo partner_id qua global scope BelongsToPartner — allowedCategoryIds
        // chỉ thu hẹp thêm theo chi nhánh được phép, cùng cách RoomCardsService::getData() lọc
        // danh sách phòng cho tài khoản không phải super_admin.
        $query = \Modules\Product\App\Models\Product::query();
        if (! $user->isSuperAdmin()) {
            $allowedCategoryIds = $user->allowedCategoryIds() ?? [];
            if (! empty($allowedCategoryIds)) {
                $query->where(function ($q) use ($allowedCategoryIds) {
                    $q->whereHas('categories', function ($q2) use ($allowedCategoryIds) {
                        $q2->whereIn('categories.id', $allowedCategoryIds);
                    })->orWhereDoesntHave('categories');
                });
            }
        }
        $product = $query->find($id);

        if (! $product) {
            return response()->json(['message' => 'Không tìm thấy phòng.'], 404);
        }

        $cfg = $product->room_config ?? [];

        $bulkRules = collect($product->bulk_discount_rules ?? [])
            ->map(fn ($r) => ['slots' => (int) ($r['slots'] ?? 0), 'discount' => (float) ($r['discount'] ?? 0)])
            ->filter(fn ($r) => $r['slots'] > 0)
            ->sortBy('slots')
            ->values();

        $promoTypeLabels = [
            'fixed'               => 'Giảm số tiền cố định',
            'percentage'          => 'Giảm theo %',
            'increase_fixed'      => 'Tăng số tiền cố định',
            'increase_percentage' => 'Tăng theo %',
        ];

        $slots = \Modules\Product\App\Models\RoomTimeSlot::where('room_id', $product->id)
            ->whereNull('date')
            ->with(['timeSlot', 'promotions'])
            ->get()
            ->filter(fn ($slot) => $slot->timeSlot !== null)
            ->sortBy(fn ($slot) => $slot->timeSlot->start_time)
            ->map(fn ($slot) => [
                'id'         => $slot->id,
                'label'      => $slot->timeSlot->label ?: ($slot->timeSlot->start_time . ' - ' . $slot->timeSlot->end_time),
                'price'      => (int) $slot->price,
                'over_night' => (bool) ($slot->timeSlot->over_night ?? $slot->over_night ?? false),
                'promotions' => $slot->promotions->map(fn ($p) => [
                    'id'        => $p->id,
                    'name'      => $p->name,
                    'type'      => $p->type,
                    'type_label'=> $promoTypeLabels[$p->type] ?? $p->type,
                    'value'     => (float) $p->value,
                    'is_active' => (bool) $p->is_active,
                    'start_at'  => $p->start_at?->format('d/m/Y'),
                    'end_at'    => $p->end_at?->format('d/m/Y'),
                ])->values(),
            ])
            ->values();

        $promoQuery = \Modules\Promotion\App\Models\Promotion::where('is_active', true);
        if (! $user->isSuperAdmin()) {
            $branchIds          = \Modules\DataPermission\Entities\UserBranchPermission::where('user_id', $user->id)->pluck('category_id');
            $overlappingUserIds = \Modules\DataPermission\Entities\UserBranchPermission::whereIn('category_id', $branchIds)->pluck('user_id');
            $promoQuery->where(function ($q) use ($overlappingUserIds) {
                $q->whereNull('created_by')->orWhereIn('created_by', $overlappingUserIds);
            });
        }
        $availablePromotions = $promoQuery->orderBy('name')->get(['id', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])
            ->values();

        return response()->json([
            'room_name'             => $product->name,
            'full_booking_discount' => $product->full_booking_discount,
            'max_free_guests'       => (int) ($cfg['max_free_guests'] ?? 2),
            'extra_guest_fee'       => (int) ($cfg['extra_guest_fee'] ?? 0),
            'bulk_discount_rules'   => $bulkRules,
            'slots'                 => $slots,
            'available_promotions'  => $availablePromotions,
            'timeslot_url'          => \Modules\Book\App\Filament\Resources\BookResource\Pages\SettingBook::getUrl(['product_id' => $product->id]),
        ]);
    })->name('admin.rooms.pricing-info');

    // Lưu nhanh giá phòng ngay trong popup "Giá phòng" (rcCalSavePricing() trong
    // _scripts.blade.php) — full_booking_discount/max_free_guests/extra_guest_fee/giá + giảm theo
    // số khung (bulk_discount_rules) + khuyến mãi gắn từng khung giờ (chỉ GẮN/GỠ khuyến mãi CÓ
    // SẴN qua 'available_promotions' của GET — KHÔNG tạo mới khuyến mãi ở đây, tạo mới vẫn phải
    // qua PromotionResource). Cùng đúng cách lưu với SettingBook::save() (bulk rules
    // filter+sort+null-if-empty, promotions()->sync() không kèm pivot phụ).
    Route::post('rooms/{id}/pricing-info', function (\Illuminate\Http\Request $request, $id) {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['ok' => false], 401);
        }

        $query = \Modules\Product\App\Models\Product::query();
        if (! $user->isSuperAdmin()) {
            $allowedCategoryIds = $user->allowedCategoryIds() ?? [];
            if (! empty($allowedCategoryIds)) {
                $query->where(function ($q) use ($allowedCategoryIds) {
                    $q->whereHas('categories', function ($q2) use ($allowedCategoryIds) {
                        $q2->whereIn('categories.id', $allowedCategoryIds);
                    })->orWhereDoesntHave('categories');
                });
            }
        }
        $product = $query->find($id);

        if (! $product) {
            return response()->json(['ok' => false], 404);
        }

        $data = $request->validate([
            'full_booking_discount'      => 'nullable|string|max:50',
            'max_free_guests'            => 'required|integer|min:0',
            'extra_guest_fee'            => 'required|integer|min:0',
            'bulk_discount_rules'        => 'sometimes|array',
            'bulk_discount_rules.*.slots'    => 'required_with:bulk_discount_rules|integer|min:2',
            'bulk_discount_rules.*.discount' => 'required_with:bulk_discount_rules|numeric|min:0|max:100',
            'slots'                      => 'sometimes|array',
            'slots.*.id'                 => 'required_with:slots|integer',
            'slots.*.price'              => 'required_with:slots|integer|min:0',
            'slots.*.promotion_ids'      => 'sometimes|array',
            'slots.*.promotion_ids.*'    => 'integer',
        ]);

        $cfg = $product->room_config ?? [];
        $cfg['max_free_guests'] = $data['max_free_guests'];
        $cfg['extra_guest_fee'] = $data['extra_guest_fee'];

        // Cùng cách chuẩn hoá với SettingBook::save() — sắp theo 'slots' tăng dần, rỗng thì lưu
        // null (không lưu mảng rỗng []).
        $bulkRules = collect($data['bulk_discount_rules'] ?? [])
            ->map(fn ($r) => ['slots' => (int) $r['slots'], 'discount' => (float) $r['discount']])
            ->sortBy('slots')
            ->values()
            ->toArray();

        $product->full_booking_discount = $data['full_booking_discount'] !== null ? trim($data['full_booking_discount']) : null;
        $product->bulk_discount_rules   = $bulkRules ?: null;
        $product->room_config           = $cfg;
        $product->save();

        // Chỉ update ĐÚNG khung giờ THUỘC phòng này và là slot "gốc" (date IS NULL) — chặn sửa
        // nhầm slot của phòng khác dù lỡ gửi kèm id lạ. Khuyến mãi gắn theo khung giờ chỉ được
        // GẮN/GỠ trong phạm vi 'available_promotions' đã trả ở GET (allowedPromotionOptions()) —
        // lọc lại promotion_ids gửi lên theo ĐÚNG phạm vi đó phòng khi bị sửa tay ngoài ý muốn.
        $promoQuery = \Modules\Promotion\App\Models\Promotion::where('is_active', true);
        if (! $user->isSuperAdmin()) {
            $branchIds          = \Modules\DataPermission\Entities\UserBranchPermission::where('user_id', $user->id)->pluck('category_id');
            $overlappingUserIds = \Modules\DataPermission\Entities\UserBranchPermission::whereIn('category_id', $branchIds)->pluck('user_id');
            $promoQuery->where(function ($q) use ($overlappingUserIds) {
                $q->whereNull('created_by')->orWhereIn('created_by', $overlappingUserIds);
            });
        }
        $allowedPromotionIds = $promoQuery->pluck('id')->all();

        // Trả lại has_discount MỚI của từng khung giờ vừa lưu — để popup "Giá phòng" tự cập nhật
        // NGAY hiệu ứng viền cầu vồng (.is-discounted) trên view "Lịch" sau khi lưu (rcCalSavePricing()
        // trong _scripts.blade.php), không cần F5 lại trang mới thấy. Tính ĐÚNG công thức
        // RoomCardsService::buildTimeslotGrid() — is_active=true VÀ hôm nay nằm trong
        // [start_at, end_at] (null = không giới hạn phía đó).
        $today          = now()->startOfDay();
        $updatedSlots   = [];

        foreach ($data['slots'] ?? [] as $s) {
            $slotModel = \Modules\Product\App\Models\RoomTimeSlot::where('id', $s['id'])
                ->where('room_id', $product->id)
                ->whereNull('date')
                ->first();

            if (! $slotModel) {
                continue;
            }

            $slotModel->update(['price' => $s['price']]);

            if (array_key_exists('promotion_ids', $s)) {
                $slotModel->promotions()->sync(array_intersect($s['promotion_ids'], $allowedPromotionIds));
            }

            $hasDiscount = $slotModel->promotions()->get()->contains(function ($p) use ($today) {
                if (! $p->is_active) return false;
                if ($p->start_at && $today->lt($p->start_at)) return false;
                if ($p->end_at && $today->gt($p->end_at)) return false;
                return true;
            });

            $updatedSlots[] = ['id' => (string) $slotModel->id, 'has_discount' => $hasDiscount];
        }

        return response()->json(['ok' => true, 'slots' => $updatedSlots]);
    })->name('admin.rooms.pricing-info.update');

    // Giữ chỗ real-time cho 1 ô (room_time_slot_id + date) — bấm chọn/bỏ chọn TRỰC TIẾP trên
    // view "Lịch" (rcCalToggleSlot() trong _scripts.blade.php), CÙNG cơ chế với
    // app/Livewire/RoomLockGrid.php::selectTimeslot() (TimeslotHoldService), chỉ khác gọi qua
    // AJAX thường thay vì 1 Livewire component riêng. Lịch phía khách
    // (book/_slot-cell.blade.php) đã tự đọc TimeslotHold nên giữ/nhả ở đây LẬP TỨC ảnh hưởng
    // lịch phía khách, không cần thêm việc gì khác — đây chính là phần "realtime" được yêu cầu.
    Route::post('rooms/{id}/timeslot-hold', function (\Illuminate\Http\Request $request, $id) {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['ok' => false], 401);
        }

        $query = \Modules\Product\App\Models\Product::query();
        if (! $user->isSuperAdmin()) {
            $allowedCategoryIds = $user->allowedCategoryIds() ?? [];
            if (! empty($allowedCategoryIds)) {
                $query->where(function ($q) use ($allowedCategoryIds) {
                    $q->whereHas('categories', function ($q2) use ($allowedCategoryIds) {
                        $q2->whereIn('categories.id', $allowedCategoryIds);
                    })->orWhereDoesntHave('categories');
                });
            }
        }
        $product = $query->find($id);
        if (! $product) {
            return response()->json(['ok' => false], 404);
        }

        $data = $request->validate([
            'room_time_slot_id' => 'required|integer',
            'date'               => 'required|date_format:Y-m-d',
            'action'             => 'required|in:hold,release',
        ]);

        // Chặn giữ/nhả nhầm khung giờ của phòng khác dù id gửi lên bị sửa tay.
        $slot = \Modules\Product\App\Models\RoomTimeSlot::where('id', $data['room_time_slot_id'])
            ->where('room_id', $product->id)
            ->whereNull('date')
            ->first();
        if (! $slot) {
            return response()->json(['ok' => false, 'message' => 'Không tìm thấy khung giờ.'], 404);
        }

        $service = app(\App\Services\TimeslotHoldService::class);

        if ($data['action'] === 'release') {
            $service->release($slot->id, $data['date'], $user, (string) $product->id, $slot->timeslot_id);
            return response()->json(['ok' => true]);
        }

        $conflict = $service->hold($slot->id, $data['date'], $user, (string) $product->id, $slot->timeslot_id);
        if ($conflict) {
            return response()->json([
                'ok'      => false,
                'held_by' => $conflict->user->fullname ?? $conflict->user->email ?? 'Admin khác',
            ]);
        }

        return response()->json(['ok' => true]);
    })->name('admin.rooms.timeslot-hold');

    // Khoá dài hạn 1 hoặc nhiều khung giờ NGAY từ lựa chọn hiện có trên view "Lịch" — ĐÚNG cách
    // lưu của BlockTimeslotModal::saveBlock() (style=1): merge ngày vào
    // RoomTimeSlot.settings['blocked_dates'] của từng khung giờ, KHÔNG qua modal chọn khoảng
    // ngày/phòng thủ công nữa (theo đúng yêu cầu — không hiển thị modal tô đen/khoá lịch).
    // Giải phóng hold tạm (nếu có) của các ô vừa khoá — đã khoá dài hạn thì không cần giữ tạm nữa.
    Route::post('rooms/{id}/block-slots', function (\Illuminate\Http\Request $request, $id) {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['ok' => false], 401);
        }

        $query = \Modules\Product\App\Models\Product::query();
        if (! $user->isSuperAdmin()) {
            $allowedCategoryIds = $user->allowedCategoryIds() ?? [];
            if (! empty($allowedCategoryIds)) {
                $query->where(function ($q) use ($allowedCategoryIds) {
                    $q->whereHas('categories', function ($q2) use ($allowedCategoryIds) {
                        $q2->whereIn('categories.id', $allowedCategoryIds);
                    })->orWhereDoesntHave('categories');
                });
            }
        }
        $product = $query->find($id);
        if (! $product) {
            return response()->json(['ok' => false], 404);
        }

        $data = $request->validate([
            'slots'                     => 'required|array|min:1',
            'slots.*.room_time_slot_id' => 'required|integer',
            'slots.*.date'              => 'required|date_format:Y-m-d',
        ]);

        $holdService = app(\App\Services\TimeslotHoldService::class);
        $realtime    = app(\App\Services\SlotRealtimeService::class);

        // Gộp theo room_time_slot_id — 1 khung giờ có thể được chọn nhiều ngày cùng lúc, chỉ
        // update model đúng 1 lần/khung thay vì lặp update() nhiều lần.
        $byRts           = collect($data['slots'])->groupBy('room_time_slot_id');
        $blockedDatesAll = [];

        foreach ($byRts as $rtsId => $items) {
            $slot = \Modules\Product\App\Models\RoomTimeSlot::where('id', $rtsId)
                ->where('room_id', $product->id)
                ->whereNull('date')
                ->first();
            if (! $slot) {
                continue;
            }

            $dates = $items->pluck('date')->unique()->values()->all();

            $settings = $slot->settings ?? [];
            $existing = $settings['blocked_dates'] ?? [];
            $merged   = array_values(array_unique(array_merge($existing, $dates)));
            sort($merged);
            $settings['blocked_dates'] = $merged;
            $slot->update(['settings' => $settings]);

            foreach ($dates as $d) {
                $holdService->release($slot->id, $d, $user, (string) $product->id, $slot->timeslot_id);
                $blockedDatesAll[] = $d;
            }
        }

        if (! empty($blockedDatesAll)) {
            $realtime->broadcastBlockedRange(
                (string) $product->id,
                array_values(array_unique($blockedDatesAll)),
                array_map('intval', $byRts->keys()->all()),
                'blocked'
            );
        }

        return response()->json(['ok' => true]);
    })->name('admin.rooms.block-slots');

    // Mở khoá HÀNG LOẠT — bấm chọn nhiều ô đã khoá (chấm ✓, xem rcCalToggleBlockedSlot() trong
    // _scripts.blade.php) rồi bấm "Mở khoá (N)" 1 lần, thay vì gọi server riêng cho từng ô. Vẫn
    // hoạt động bình thường khi chỉ chọn đúng 1 ô. ĐÚNG cách gỡ của BlockTimeslotModal (array_diff
    // khỏi settings['blocked_dates'], broadcast status 'available').
    Route::post('rooms/{id}/unblock-slots', function (\Illuminate\Http\Request $request, $id) {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['ok' => false], 401);
        }

        $query = \Modules\Product\App\Models\Product::query();
        if (! $user->isSuperAdmin()) {
            $allowedCategoryIds = $user->allowedCategoryIds() ?? [];
            if (! empty($allowedCategoryIds)) {
                $query->where(function ($q) use ($allowedCategoryIds) {
                    $q->whereHas('categories', function ($q2) use ($allowedCategoryIds) {
                        $q2->whereIn('categories.id', $allowedCategoryIds);
                    })->orWhereDoesntHave('categories');
                });
            }
        }
        $product = $query->find($id);
        if (! $product) {
            return response()->json(['ok' => false], 404);
        }

        $data = $request->validate([
            'slots'                     => 'required|array|min:1',
            'slots.*.room_time_slot_id' => 'required|integer',
            'slots.*.date'              => 'required|date_format:Y-m-d',
        ]);

        $realtime = app(\App\Services\SlotRealtimeService::class);
        $byRts    = collect($data['slots'])->groupBy('room_time_slot_id');

        foreach ($byRts as $rtsId => $items) {
            $slot = \Modules\Product\App\Models\RoomTimeSlot::where('id', $rtsId)
                ->where('room_id', $product->id)
                ->whereNull('date')
                ->first();
            if (! $slot) {
                continue;
            }

            $dates = $items->pluck('date')->unique()->values()->all();

            $settings = $slot->settings ?? [];
            $settings['blocked_dates'] = array_values(array_diff($settings['blocked_dates'] ?? [], $dates));
            $slot->update(['settings' => $settings]);

            $realtime->broadcastBlockedRange((string) $product->id, $dates, [(int) $rtsId], 'available');
        }

        return response()->json(['ok' => true]);
    })->name('admin.rooms.unblock-slots');

    // Deposit room note — lưu/xoá ghi chú cọc phòng cho đơn hàng
    Route::post('orders/{id}/deposit-room', function (\Illuminate\Http\Request $request, $id) {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['ok' => false], 401);
        }
        // Gộp ownership check vào query để tránh leak sự tồn tại của đơn (403 vs 404)
        // Order đã tự lọc theo partner_id (BelongsToPartner); allowedCategoryIds chỉ thu hẹp thêm.
        $query = \Modules\Payment\Entities\Order::query();
        if (! $user->isSuperAdmin()) {
            $allowedIds = $user->allowedCategoryIds() ?? [];
            if (! empty($allowedIds)) {
                $query->whereIn('category_id', $allowedIds);
            }
        }
        $order = $query->findOrFail($id);

        $order->deposit_room = trim(substr($request->input('deposit_room', ''), 0, 500));
        $order->save();
        return response()->json(['ok' => true, 'deposit_room' => $order->deposit_room]);
    })->name('admin.orders.deposit-room');

    // Monthly revenue — doanh thu từng tháng trong năm chỉ định
    Route::get('monthly-revenue', function (\Illuminate\Http\Request $request) {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['months' => array_fill(0, 12, 0), 'year' => now()->year]);
        }

        $year = (int) $request->query('year', now()->year);

        $branchCategoryIds = null;
        $branchId = $request->query('branch_id');
        if ($branchId && ctype_digit((string) $branchId)) {
            $branchId = (int) $branchId;
            $childIds = \Modules\Category\Entities\Category::where('parent_id', $branchId)->pluck('id')->toArray();
            $branchCategoryIds = array_merge([$branchId], $childIds);
        }

        return response()->json(
            \Modules\Dashboard\App\Filament\Pages\Dashboard::getMonthlyRevenueData($user, $year, $branchCategoryIds)
        );
    })->name('admin.monthly-revenue');
});

// Ký hợp đồng điện tử — trang CÔNG KHAI (không cần đăng nhập CMS), đối tác nhận link qua email
// từ super_admin (xem PartnerForm::contractTab()). Rate-limit để chặn brute-force token/OTP.
Route::middleware(['web', 'throttle:30,1'])->prefix('hop-dong')->group(function () {
    Route::get('ky/{token}', [\App\Http\Controllers\ContractSignController::class, 'show'])
        ->name('contract.sign.show');
    Route::post('ky/{token}/gui-ma', [\App\Http\Controllers\ContractSignController::class, 'sendOtp'])
        ->name('contract.sign.send-otp');
    Route::post('ky/{token}/xac-nhan', [\App\Http\Controllers\ContractSignController::class, 'sign'])
        ->name('contract.sign.submit');
});

// Xoá tài khoản — trang CÔNG KHAI theo yêu cầu Data Safety của Google Play (xem
// DeleteAccountController). Đăng ký ở đây (routes/web.php gốc) để route này được resolve TRƯỚC
// route catch-all '/{type}/{location?}' của module BladeThemeV1 (đăng ký sau, xem
// Modules\BladeThemeV1\Routes\web.php) — nếu không sẽ bị route đó nuốt mất và trả 404.
Route::get('/delete-account', [\App\Http\Controllers\DeleteAccountController::class, 'show'])
    ->name('delete-account');

// Block paths that should not be accessible
Route::get('/local', fn() => abort(404));
Route::get('/local/{any}', fn() => abort(404))->where('any', '.*');
Route::get('/cdn-cgi/{any}', fn() => abort(404))->where('any', '.*');

