<?php

namespace Database\Seeders;

use App\Filament\Resources\Shield\RoleResource;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // findOrCreate thay vì create() — seeder này phải chạy an toàn CẢ trên database rỗng (cài
        // mới) LẪN database đã phục hồi từ bản backup cũ (đã có sẵn permission/role này), để
        // "php artisan db:seed" luôn dùng được 1 lệnh duy nhất, không cần biết trước database đang
        // ở trạng thái nào.
        Permission::findOrCreate('access_log_viewer', 'web');

        $roles = ["super_admin", "admin", "partner", "employee", "content_manager", "editor", "seo_manager"];

        foreach ($roles as $key => $role) {
            $roleModel = RoleResource::getModel();
            $roleCreated = $roleModel::findOrCreate($role, 'web');

            if ($role == 'super_admin') {
                $roleCreated->givePermissionTo('access_log_viewer');
            }
        }
    }
}
