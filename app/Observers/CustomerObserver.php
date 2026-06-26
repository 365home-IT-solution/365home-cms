<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Customer;
use App\Services\MembershipService;

class CustomerObserver
{
    public function created(Customer $customer): void
    {
        app(MembershipService::class)->assignWelcomeTier($customer);
    }
}
