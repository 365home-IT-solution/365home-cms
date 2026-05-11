<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('businesses')->insertOrIgnore([
            'id' => 1,
            'name' => '365Home',
            'slug' => 'cau-hinh-doanh-nghiep',
            'address' => '252 - 254 Ä‘Æ°á»ng XuÃ¢n Thuá»· â€“ KDC Há»“ng PhÃ¡t â€“ An BÃ¬nh â€“ Cáº§n ThÆ¡',
            'phone' => '0939174365',
            'email' => '365home.cantho@gmail.com',
            'website' => 'https://365home.vn/',
            'tax_code' => '1801709047',
            'description' => '365Home lÃ  homestay tá»± check-in hiá»‡n Ä‘áº¡i, nÆ¡i báº¡n chá»§ Ä‘á»™ng Ä‘áº·t vÃ  nháº­n phÃ²ng hoÃ n toÃ n khÃ´ng cáº§n lá»… tÃ¢n.',
            'image' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('branches')->insertOrIgnore([
            [
                'id' => 1,
                'business_id' => 1,
                'name' => 'Home - Há»“ng PhÃ¡t, Ninh Kiá»u, CT',
                'address' => 'Cáº§n ThÆ¡',
                'phone' => '0939174365',
                'email' => '365home.cantho@gmail.com',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}

