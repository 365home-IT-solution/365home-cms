<?php

namespace App\Console\Commands\Minihouse;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\Minihouse\App\Services\InvoiceGenerationService;

// Lập hoá đơn hàng loạt cho tất cả hợp đồng "Đang hiệu lực" của 1 tháng — xem
// InvoiceGenerationService. Chạy tay: php artisan minihouse:generate-invoices, hoặc lên lịch chạy
// đầu mỗi tháng (xem app/Console/Kernel.php).
class GenerateInvoicesCommand extends Command
{
    protected $signature = 'minihouse:generate-invoices {--month= : Tháng cần lập, định dạng YYYY-MM — mặc định tháng hiện tại}';

    protected $description = 'Lập hoá đơn hàng loạt cho mọi hợp đồng MiniHouse đang hiệu lực trong tháng chỉ định';

    public function handle(): int
    {
        $monthOption = $this->option('month');
        $month       = $monthOption ? Carbon::createFromFormat('Y-m', $monthOption)->startOfMonth() : now()->startOfMonth();

        $result = InvoiceGenerationService::generateForMonth($month);

        $this->info("Tháng {$month->format('m/Y')}: đã tạo {$result['created']->count()} hoá đơn, bỏ qua {$result['skipped']->count()} hợp đồng đã có hoá đơn tháng này.");

        return self::SUCCESS;
    }
}
