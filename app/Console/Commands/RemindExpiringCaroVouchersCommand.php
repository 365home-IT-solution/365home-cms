<?php

namespace App\Console\Commands;

use App\Services\CaroService;
use Illuminate\Console\Command;

/**
 * Nhắc khách các voucher caro chưa dùng sắp hết hạn (trong reminder_hours_before giờ tới) — mỗi voucher chỉ nhắc 1 lần.
 */
class RemindExpiringCaroVouchersCommand extends Command
{
    protected $signature   = 'caro:remind-expiring';
    protected $description = 'Gửi thông báo nhắc voucher caro sắp hết hạn';

    public function handle(CaroService $service): int
    {
        $this->info('Đã nhắc ' . $service->remindExpiring() . ' khách.');

        return self::SUCCESS;
    }
}
