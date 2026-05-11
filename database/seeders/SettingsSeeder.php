<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['group' => 'general', 'name' => 'brand_name',              'payload' => '"365 Home - Homestay tá»± check in"'],
            ['group' => 'general', 'name' => 'brand_logo',              'payload' => 'null'],
            ['group' => 'general', 'name' => 'brand_logo_light_version','payload' => 'null'],
            ['group' => 'general', 'name' => 'brand_logoHeight',        'payload' => '"20"'],
            ['group' => 'general', 'name' => 'site_active',             'payload' => 'true'],
            ['group' => 'general', 'name' => 'site_favicon',            'payload' => 'null'],
            ['group' => 'general', 'name' => 'site_theme', 'payload' => json_encode([
                'primary'     => '#2B5257',
                'secondary'   => '#00ffdd',
                'gray'        => '#485173',
                'background'  => '#222831',
                'bg_dark'     => '#1a1e25',
                'text_dark'   => '#212529',
                'red_9c'      => '#9c273d',
                'border_gray' => '#343a40',
                'tick_green'  => '#56f956',
                'tick_yellow' => '#ffff00',
                'tick_gray'   => '#808080',
                'Secondary'   => '#00ffdd',
                'success'     => '#1DCB8A',
                'danger'      => '#ff001e',
                'info'        => '#6E6DD7',
                'warning'     => '#f5de8d',
            ])],
            ['group' => 'general', 'name' => 'header_scripts', 'payload' => '""'],
            ['group' => 'general', 'name' => 'footer_scripts', 'payload' => '""'],
        ];

        foreach ($settings as $setting) {
            DB::table('settings')->updateOrInsert(
                ['group' => $setting['group'], 'name' => $setting['name']],
                array_merge($setting, ['locked' => 0, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}

