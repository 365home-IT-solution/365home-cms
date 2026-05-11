<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ComponentSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('components')->insertOrIgnore([
            ['id' => 1,  'name' => 'banner',          'label' => 'Banner',              'created_at' => now(), 'updated_at' => now()],
            ['id' => 2,  'name' => 'post',             'label' => 'BÃ i viáº¿t',            'created_at' => now(), 'updated_at' => now()],
            ['id' => 3,  'name' => 'post-page',        'label' => 'Trang bÃ i viáº¿t',      'created_at' => now(), 'updated_at' => now()],
            ['id' => 4,  'name' => 'contact',          'label' => 'LiÃªn há»‡',             'created_at' => now(), 'updated_at' => now()],
            ['id' => 5,  'name' => 'gap',              'label' => 'Khoáº£ng cÃ¡ch',         'created_at' => now(), 'updated_at' => now()],
            ['id' => 6,  'name' => 'slide-component',  'label' => 'Slide Component',     'created_at' => now(), 'updated_at' => now()],
            ['id' => 7,  'name' => 'book',             'label' => 'Äáº·t phÃ²ng',           'created_at' => now(), 'updated_at' => now()],
            ['id' => 8,  'name' => 'search-booking',   'label' => 'TÃ¬m Ä‘Æ¡n Ä‘áº·t phÃ²ng',  'created_at' => now(), 'updated_at' => now()],
            ['id' => 9,  'name' => 'content-component','label' => 'Ná»™i dung',            'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}

