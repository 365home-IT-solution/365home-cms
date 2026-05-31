<?php

use Illuminate\Support\Facades\Route;

// Admin: unread notification count (for tab-title polling)
// Cached per-user for 2s — supports many concurrent admins without hammering the DB
Route::middleware(['auth', 'web', 'throttle:120,1'])->prefix('admin/api')->group(function () {
    Route::get('notifications/unread-count', function () {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['count' => 0]);
        }

        $count = \Illuminate\Support\Facades\Cache::remember(
            'admin_unread_notif_' . $user->id,
            2, // seconds
            fn () => $user->unreadNotifications()->count()
        );

        return response()->json(['count' => $count]);
    })->name('admin.notifications.unread-count');

    // Room cards polling — trả về JSON để JS cập nhật section phòng không reload trang
    Route::get('room-cards', function () {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['branches' => [], 'rooms' => [], 'total_rooms' => 0, 'total_orders' => 0]);
        }

        return response()->json(
            \Modules\Dashboard\App\Filament\Pages\Dashboard::getRoomCardsData($user)
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

        return response()->json(
            \Modules\Dashboard\App\Filament\Pages\Dashboard::getKpiData($period, $user, $customStart, $customEnd)
        );
    })->name('admin.kpi-stats');

    // Branch monthly revenue — doanh thu từng chi nhánh (category parent_id IS NULL, type=product) theo 12 tháng
    Route::get('branch-monthly', function (\Illuminate\Http\Request $request) {
        $user = auth()->user();
        if (! $user) return response()->json(['branches' => [], 'months' => [], 'year' => date('Y')]);

        $year = (int) $request->query('year', now()->year);

        $branches = \Modules\Category\Entities\Category::whereNull('parent_id')
            ->where('category_type', 'product')
            ->orderBy('name')
            ->get();

        if ($user && ! $user->isSuperAdmin()) {
            $allowedIds = $user->allowedCategoryIds() ?? [];
            $branches = $branches->filter(function ($b) use ($allowedIds) {
                $childIds = \Modules\Category\Entities\Category::where('parent_id', $b->id)->pluck('id')->toArray();
                return count(array_intersect(array_merge([$b->id], $childIds), $allowedIds)) > 0;
            })->values();
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
                    $categoryIds = $user->allowedCategoryIds() ?? [];
                    if (empty($categoryIds)) {
                        return ['created_ts' => 0, 'updated_ts' => 0];
                    }
                    $query->whereIn('category_id', $categoryIds);
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

        return response()->json(
            \Modules\Dashboard\App\Filament\Pages\Dashboard::getRoomRevenueData($user, $year)
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

    // Deposit room note — lưu/xoá ghi chú cọc phòng cho đơn hàng
    Route::post('orders/{id}/deposit-room', function (\Illuminate\Http\Request $request, $id) {
        $user = auth()->user();
        if (! $user) {
            return response()->json(['ok' => false], 401);
        }
        // Gộp ownership check vào query để tránh leak sự tồn tại của đơn (403 vs 404)
        $query = \Modules\Payment\Entities\Order::query();
        if (! $user->isSuperAdmin()) {
            $allowedIds = $user->allowedCategoryIds() ?? [];
            $query->whereIn('category_id', $allowedIds);
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

        return response()->json(
            \Modules\Dashboard\App\Filament\Pages\Dashboard::getMonthlyRevenueData($user, $year)
        );
    })->name('admin.monthly-revenue');
});

// Block paths that should not be accessible
Route::get('/local', fn() => abort(404));
Route::get('/local/{any}', fn() => abort(404))->where('any', '.*');
Route::get('/cdn-cgi/{any}', fn() => abort(404))->where('any', '.*');

