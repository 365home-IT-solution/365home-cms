<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class RatingPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name'       => 'manage_ratings',
            'guard_name' => 'web',
        ]);

        $this->command->info('Permission [manage_ratings] đã được tạo.');
    }
}
