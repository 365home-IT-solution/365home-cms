<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentConfigSeeder extends Seeder
{
    public function run(): void
    {
        // Thay báº±ng thÃ´ng tin PayOS tháº­t cá»§a báº¡n táº¡i dashboard.payos.vn
        DB::table('payment_configurations')->insertOrIgnore([
            'id' => 1,
            'client_id' => 'YOUR_PAYOS_CLIENT_ID',
            'api_key' => 'YOUR_PAYOS_API_KEY',
            'checksum_key' => 'YOUR_PAYOS_CHECKSUM_KEY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

