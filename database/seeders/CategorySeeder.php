<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        DB::table('categories')->insertOrIgnore([
            // Location parent categories (product)
            ['id' => 1, 'name' => '254 XuÃ¢n Thá»§y, An BÃ¬nh, Cáº§n ThÆ¡', 'slug' => '254-xuan-thuy-an-binh-can-tho', 'description' => null, 'status' => 1, 'category_type' => 'product', 'image' => null, 'parent_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => '252 XuÃ¢n Thá»§y, An BÃ¬nh, Cáº§n ThÆ¡', 'slug' => '252-xuan-thuy-an-binh-can-tho', 'description' => null, 'status' => 1, 'category_type' => 'product', 'image' => null, 'parent_id' => null, 'created_at' => now(), 'updated_at' => now()],

            // Room categories under 254
            ['id' => 3, 'name' => 'VELVET',  'slug' => 'phong-velvet',  'description' => null, 'status' => 1, 'category_type' => 'product', 'image' => null, 'parent_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'GALLERY', 'slug' => 'phong-gallery', 'description' => null, 'status' => 1, 'category_type' => 'product', 'image' => null, 'parent_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'name' => 'LUMEN',   'slug' => 'phong-lumen',   'description' => null, 'status' => 1, 'category_type' => 'product', 'image' => null, 'parent_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'name' => 'MIDNIGHT','slug' => 'phong-midnight','description' => null, 'status' => 1, 'category_type' => 'product', 'image' => null, 'parent_id' => 1, 'created_at' => now(), 'updated_at' => now()],

            // Room categories under 252
            ['id' => 7, 'name' => 'PIXEL',   'slug' => 'phong-pixel',   'description' => 'PhÃ²ng Pixel', 'status' => 1, 'category_type' => 'product', 'image' => null, 'parent_id' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 8, 'name' => 'NEON',    'slug' => 'phong-neon',    'description' => null, 'status' => 1, 'category_type' => 'product', 'image' => null, 'parent_id' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 9, 'name' => 'CRIMSON', 'slug' => 'phong-crimson', 'description' => null, 'status' => 1, 'category_type' => 'product', 'image' => null, 'parent_id' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 10,'name' => 'PINKY',   'slug' => 'phong-pinky',   'description' => null, 'status' => 1, 'category_type' => 'product', 'image' => null, 'parent_id' => 2, 'created_at' => now(), 'updated_at' => now()],

            // Post categories
            ['id' => 11,'name' => 'Há»“ng PhÃ¡t, An BÃ¬nh, Cáº§n ThÆ¡', 'slug' => 'hong-phat-an-binh-can-tho', 'description' => null, 'status' => 1, 'category_type' => 'post', 'image' => null, 'parent_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}

