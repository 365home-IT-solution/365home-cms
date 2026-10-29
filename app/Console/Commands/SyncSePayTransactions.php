<?php

namespace App\Console\Commands;

use App\Services\Payment\SePayService;
use Illuminate\Console\Command;

/**
 * Hỏi SePay các giao dịch tiền vào gần đây của MỌI tài khoản SePay đang bật rồi xác nhận đơn tương ứng — dự phòng khi webhook chậm/lỡ
 * (cùng bộ kiểm tra và cùng nhật ký "Giao dịch SePay" với webhook; giao dịch đã xử lý không bị xử lý lại). Cần SEPAY_API_TOKEN.
 * Chạy tự động mỗi phút (xem App\Console\Kernel), cũng chạy tay được.
 */
class SyncSePayTransactions extends Command
{
    protected $signature = 'sepay:sync {--hours=24 : Lấy giao dịch trong bao nhiêu giờ gần đây}';

    protected $description = 'Kéo giao dịch tiền vào từ API SePay và tự xác nhận đơn (dự phòng webhook)';

    public function handle(SePayService $sePay): int
    {
        if (! \App\Support\SePayConfig::enabled()) {
            $this->warn('SEPAY_ENABLED=false — bỏ qua.');

            return self::SUCCESS;
        }

        if (! $sePay->canPull()) {
            $this->error('Chưa có API Token SePay (nhập ở CMS → Cấu hình web → SePay — cấu hình chung, hoặc SEPAY_API_TOKEN trong .env; tạo token ở SePay → Cấu hình Công ty → API Access).');

            return self::FAILURE;
        }

        $confirmed = $sePay->pull(null, max(1, (int) $this->option('hours')));
        $this->info("Đã xác nhận {$confirmed} đơn từ giao dịch SePay.");

        return self::SUCCESS;
    }
}
