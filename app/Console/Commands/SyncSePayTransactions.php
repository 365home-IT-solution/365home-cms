<?php

namespace App\Console\Commands;

use App\Services\Payment\Pay2SService;
use App\Services\Payment\SePayService;
use App\Support\Pay2SConfig;
use App\Support\SePayConfig;
use Illuminate\Console\Command;

/**
 * Hỏi SePay và Pay2S các giao dịch tiền vào gần đây của MỌI tài khoản đang bật rồi xác nhận đơn tương ứng — dự phòng khi webhook chậm/lỡ
 * (cùng bộ kiểm tra và cùng nhật ký "Giao dịch SePay / Pay2S" với webhook; giao dịch đã xử lý không bị xử lý lại). Cổng nào chưa có API Token thì bỏ qua.
 * Chạy tự động mỗi phút (xem App\Console\Kernel), cũng chạy tay được.
 */
class SyncSePayTransactions extends Command
{
    protected $signature = 'sepay:sync {--hours=24 : Lấy giao dịch trong bao nhiêu giờ gần đây}';

    protected $description = 'Kéo giao dịch tiền vào từ API SePay / Pay2S và tự xác nhận đơn (dự phòng webhook)';

    public function handle(SePayService $sePay, Pay2SService $pay2s): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $ran = false;

        if (SePayConfig::enabled() && $sePay->canPull()) {
            $ran = true;
            $this->info('Đã xác nhận ' . $sePay->pull(null, $hours) . ' đơn từ giao dịch SePay.');
        }

        if (Pay2SConfig::enabled() && $pay2s->canPull()) {
            $ran = true;
            $this->info('Đã xác nhận ' . $pay2s->pull(null, $hours) . ' đơn từ giao dịch Pay2S.');
        }

        if (! $ran) {
            $this->error('Chưa có cổng nào đủ điều kiện kéo giao dịch: nhập API Token SePay (CMS → Cấu hình web → SePay — cấu hình chung, hoặc SEPAY_API_TOKEN) hoặc API Token Pay2S (CMS → Pay2S — cấu hình chung, hoặc PAY2S_API_TOKEN), và bật cổng đó.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
