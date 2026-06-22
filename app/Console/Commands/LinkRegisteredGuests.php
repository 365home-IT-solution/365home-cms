<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkRegisteredGuests extends Command
{
    protected $signature   = 'guests:link-registered';
    protected $description = 'Liên kết khách vãng lai đã đăng ký tài khoản dựa trên device_token = token_device của Customer';

    public function handle(): int
    {
        $affected = DB::affectingStatement('
            UPDATE `guest_customers` g
            INNER JOIN `customers` c
                ON c.token_device = g.device_token
               AND c.deleted_at IS NULL
            SET g.customer_id = c.id
            WHERE g.customer_id IS NULL
              AND g.device_token IS NOT NULL
        ');

        $this->info("Đã liên kết {$affected} khách vãng lai.");

        return self::SUCCESS;
    }
}
