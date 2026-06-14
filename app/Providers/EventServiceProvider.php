<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\User;
use App\Observers\BranchObserver;
use App\Observers\CouponObserver;
use App\Observers\CustomerObserver;
use App\Observers\OrderObserver;
use App\Observers\PostObserver;
use App\Observers\ProductObserver;
use App\Observers\PromotionObserver;
use App\Observers\RoleObserver;
use App\Observers\UserBranchPermissionObserver;
use App\Observers\UserObserver;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\DataPermission\Entities\UserBranchPermission;
use Modules\Payment\Entities\Order;
use Modules\Post\Entities\Post;
use Modules\Product\App\Models\Product;
use Modules\Promotion\App\Models\Coupon;
use Modules\Promotion\App\Models\Promotion;
use Modules\SettingCompany\Entities\Branch;
use App\Models\Role;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        Customer::observe(CustomerObserver::class);
        Order::observe(OrderObserver::class);
        Role::observe(RoleObserver::class);
        User::observe(UserObserver::class);
        UserBranchPermission::observe(UserBranchPermissionObserver::class);
        Post::observe(PostObserver::class);
        Product::observe(ProductObserver::class);
        Branch::observe(BranchObserver::class);
        Coupon::observe(CouponObserver::class);
        Promotion::observe(PromotionObserver::class);
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}

