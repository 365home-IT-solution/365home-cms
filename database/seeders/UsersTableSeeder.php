<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\Artisan;

class UsersTableSeeder extends Seeder
{
    public function run()
    {
        // Seeder này chỉ dành cho database RỖNG (cài mới) — vừa tạo tài khoản super_admin mặc
        // định, vừa sinh thêm user giả (fake) để có dữ liệu demo cho từng role. Nếu chạy lại trên
        // 1 database ĐÃ CÓ dữ liệu thật (phục hồi từ bản backup cũ), 2 việc này đều sai: insert
        // trùng email admin sẽ lỗi unique constraint, và tạo thêm user giả sẽ làm bẩn dữ liệu
        // thật. Coi "đã tồn tại email admin mặc định" là dấu hiệu database đã có dữ liệu thật —
        // bỏ qua toàn bộ seeder này, để "php artisan db:seed" chạy được 1 lệnh duy nhất an toàn ở
        // cả 2 trường hợp (cài mới lẫn phục hồi database cũ).
        if (DB::table('users')->where('email', 'support@goldenbeeltd.vn')->exists()) {
            $this->command?->info('UsersTableSeeder: đã có tài khoản admin mặc định — bỏ qua (database không rỗng).');

            return;
        }

        $faker = Faker::create();

        $sid = Str::uuid();
        DB::table('users')->insert([
            'id' => $sid,
            'fullname' => 'Admin',
            'email' => 'support@goldenbeeltd.vn',
            'email_verified_at' => now(),
            'password' => Hash::make('supportgoldenbeeltd'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('shield:super-admin', ['--user' => $sid]);

        $roles = DB::table('roles')->whereNot('name', 'super_admin')->get();
        foreach ($roles as $role) {
            for ($i = 0; $i < 10; $i++) {
                $userId = Str::uuid();
                DB::table('users')->insert([
                    'id' => $userId,
                    'fullname' => $faker->firstName,
                    'email' => $faker->unique()->safeEmail,
                    'email_verified_at' => now(),
                    'password' => Hash::make('password'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('model_has_roles')->insert([
                    'role_id' => $role->id,
                    'model_type' => 'App\Models\User',
                    'model_id' => $userId,
                ]);
            }
        }
    }
}

