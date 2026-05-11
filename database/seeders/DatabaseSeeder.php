<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Core: users, roles, permissions
        $this->call([
            RolesAndPermissionsSeeder::class,
            UsersTableSeeder::class,
        ]);

        Artisan::call('shield:generate --all');

        // Dữ liệu cần thiết để website hoạt động
        $this->call([
            BusinessSeeder::class,
            CategorySeeder::class,
            TimeSlotSeeder::class,
            ComponentSeeder::class,
            SettingsSeeder::class,
            PaymentConfigSeeder::class,
        ]);
    }
}
