<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class CustomerVoucherUsagePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name'       => 'view_customer_voucher_usage',
            'guard_name' => 'web',
        ]);

        $this->command->info('Permission [view_customer_voucher_usage] đã được tạo.');
    }
}
