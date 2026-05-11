<?php

declare(strict_types=1);

namespace Modules\Dashboard\App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Modules\Post\Entities\Post;
use Modules\Form\Entities\FormSubmission;
use Modules\Payment\Entities\Order;
use Carbon\Carbon;
use Illuminate\Support\Carbon as SupportCarbon;
use Modules\Product\App\Models\Product;

class ATotalWidgets extends BaseWidget
{
    public function mount(): void
    {
        SupportCarbon::setLocale('vi');
    }

    public function getHeading(): string
    {
        return __('dashboard::dashboard.widgets.a_total.heading');
    }

    public static function canViewWidget(): bool
    {
        return class_exists(Post::class) ||
               class_exists(Product::class) ||
               class_exists(FormSubmission::class);
    }

    protected function getColumns(): int
    {
        $count = 0;
        if (class_exists(Post::class)) $count++;
        if (class_exists(Product::class)) $count++;
        if (class_exists(FormSubmission::class)) $count++;

        return $count > 0 ? $count : 3;
    }

    protected function getStats(): array
    {
        $now = Carbon::now();
        $lastWeek = $now->copy()->subWeek();

        $stats = [];

        if (class_exists(Post::class)) {
            $stats[] = $this->getPostStats($now, $lastWeek);
        }

//        if (class_exists(Product::class)) {
//            $stats[] = $this->getProductStats($now, $lastWeek);
//        }

        if (class_exists(\Modules\Payment\Entities\Order::class)) {
            $stats[] = $this->getOrderStats($now, $lastWeek);
        }

//        if (class_exists(\Modules\Payment\Entities\OrderItem::class)) {
//            $stats[] = $this->getOrderItemStats($now, $lastWeek);
//        }

        if (class_exists(FormSubmission::class)) {
            $stats[] = $this->getFormSubmissionStats($now, $lastWeek);
        }

        return $stats;
    }

    private function getPostStats(Carbon $now, Carbon $lastWeek): Stat
    {
        try {
            $base = $this->branchFilteredPostQuery();

            $totalPosts  = (clone $base)->count();
            $recentPosts = (clone $base)->where('created_at', '>=', $lastWeek)->count();
            $latestPost  = (clone $base)->latest('created_at')->first();

            $chartData = (clone $base)
                ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->whereDate('created_at', '>=', now()->subDays(7))
                ->groupBy('date')
                ->orderBy('date')
                ->pluck('count')
                ->toArray();

            return Stat::make(__('dashboard::dashboard.widgets.total.posts'), $totalPosts)
                ->description(__('dashboard::dashboard.widgets.total.new_posts', ['count' => $recentPosts]))
                ->descriptionIcon('heroicon-m-document-text')
                ->chart($chartData)
                ->color('primary')
                ->extraAttributes([
                    'tooltip' => $latestPost ? __('dashboard::dashboard.widgets.total.latest_post', ['title' => $latestPost->title]) : null,
                ]);
        } catch (\Throwable $e) {
            return $this->getEmptyStats('posts', 'document-text', 'primary');
        }
    }

    private function branchFilteredPostQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = Post::query();
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        $allowedPostCategoryIds = $user->allowedPostCategoryIds();

        if (empty($allowedPostCategoryIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('categories', function ($q) use ($allowedPostCategoryIds) {
            $q->whereIn('categories.id', $allowedPostCategoryIds);
        });
    }

    private function getProductStats(Carbon $now, Carbon $lastWeek): Stat
    {
        try {
            $totalProducts = Product::count();
            $recentProducts = Product::where('created_at', '>=', $lastWeek)->count();
            $latestProduct = Product::latest('created_at')->first();

            return Stat::make(__('Tổng số Phòng'), $totalProducts)
//                ->description(__('Số phòng được tạo trong 1 tuần', ['count' => $recentProducts]))
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->chart($this->getChartData(Product::class))
                ->color('success')
                ->extraAttributes([
                    'tooltip' => $latestProduct ? __('dashboard::dashboard.widgets.total.latest_product', ['name' => $latestProduct->name]) : null,
                ]);
        } catch (\Throwable $e) {
            return $this->getEmptyStats('products', 'shopping-bag', 'success');
        }
    }

    private function getFormSubmissionStats(Carbon $now, Carbon $lastWeek): Stat
    {
        try {
            $totalSubmissions = FormSubmission::count();
            $recentSubmissions = FormSubmission::where('created_at', '>=', $lastWeek)->count();

            return Stat::make(__('dashboard::dashboard.widgets.total.submissions'), $totalSubmissions)
                ->description(__('dashboard::dashboard.widgets.total.new_submissions', ['count' => $recentSubmissions]))
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->chart($this->getChartData(FormSubmission::class))
                ->color('warning');
        } catch (\Throwable $e) {
            return $this->getEmptyStats('submissions', 'chat-bubble-left-right', 'warning');
        }
    }

    private function getChartData($model): array
    {
        try {
            return $model::selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->whereDate('created_at', '>=', now()->subDays(7))
                ->groupBy('date')
                ->orderBy('date')
                ->pluck('count')
                ->toArray();
        } catch (\Throwable $e) {
            return array_fill(0, 7, 0);
        }
    }

    private function getOrderStats(Carbon $now, Carbon $lastWeek): Stat
    {
        try {
            $base = $this->branchFilteredOrderQuery();

            $totalOrders  = (clone $base)->count();
            $recentOrders = (clone $base)->where('created_at', '>=', $lastWeek)->count();
            $latestOrder  = (clone $base)->latest('created_at')->first();

            $chartData = (clone $base)
                ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->whereDate('created_at', '>=', now()->subDays(7))
                ->groupBy('date')
                ->orderBy('date')
                ->pluck('count')
                ->toArray();

            return Stat::make(__('Tổng Booking'), $totalOrders)
                ->description(__('BOOKING mới trong 7 ngày qua: :count', ['count' => $recentOrders]))
                ->descriptionIcon('heroicon-m-clipboard-document-list')
                ->chart($chartData)
                ->color('success')
                ->extraAttributes([
                    'tooltip' => $latestOrder ? __('Đơn gần nhất: :code', ['code' => $latestOrder->order_code]) : null,
                ]);
        } catch (\Throwable $e) {
            return $this->getEmptyStats('orders', 'clipboard-document-list', 'success');
        }
    }

    private function branchFilteredOrderQuery(): Builder
    {
        $query = Order::query();
        $user  = auth()->user();

        if (! $user || $user->isSuperAdmin()) {
            return $query;
        }

        $allCategoryIds = $user->allowedCategoryIds();

        if (empty($allCategoryIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('category_id', $allCategoryIds);
    }

    private function getOrderItemStats(Carbon $now, Carbon $lastWeek): Stat
    {
        try {
            $totalOrderItems = \Modules\Payment\Entities\OrderItem::count();
            $recentOrderItems = \Modules\Payment\Entities\OrderItem::where('created_at', '>=', $lastWeek)->count();
            $latestItem = \Modules\Payment\Entities\OrderItem::latest('created_at')->first();

            return Stat::make(__('Tổng mục đơn hàng'), $totalOrderItems)
                ->description(__('Mục mới tuần này: :count', ['count' => $recentOrderItems]))
                ->descriptionIcon('heroicon-m-list-bullet')
                ->chart($this->getChartData(\Modules\Payment\Entities\OrderItem::class))
                ->color('warning')
                ->extraAttributes([
                    'tooltip' => $latestItem ? __('Sản phẩm gần nhất: :name', ['name' => $latestItem->name]) : null,
                ]);
        } catch (\Throwable $e) {
            return $this->getEmptyStats('order_items', 'list-bullet', 'warning');
        }
    }


    private function getEmptyStats(string $type, string $icon, string $color): Stat
    {
        return Stat::make(__("dashboard::dashboard.widgets.total.{$type}"), 0)
            ->description(__('dashboard::dashboard.widgets.total.module_not_available'))
            ->descriptionIcon("heroicon-m-{$icon}")
            ->chart([0, 0, 0, 0, 0, 0, 0])
            ->color('gray');
    }
}