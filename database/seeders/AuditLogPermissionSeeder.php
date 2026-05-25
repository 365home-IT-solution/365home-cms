<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class AuditLogPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate([
            'name'       => 'view_audit_logs',
            'guard_name' => 'web',
        ]);

        $this->command->info('Permission [view_audit_logs] đã được tạo.');
    }
}
