<?php

namespace App\Console\Commands;

use App\Services\Payment\Pay2SCardService;
use Illuminate\Console\Command;
use Modules\Payment\Entities\Pay2SCardPayment;

/**
 * Hỏi Pay2S trạng thái các phiên thanh toán THẺ còn chờ rồi tự xác nhận đơn — dự phòng khi webhook chậm/lỡ hoặc không tới được (máy local), và khi khách đã trả tiền
 * nhưng đóng trình duyệt trước khi trang kịp hỏi. Giống sepay:sync của chuyển khoản: cùng bộ đối chiếu với webhook, phiên đã xử lý không bị xử lý lại.
 * Chạy tự động mỗi phút (xem App\Console\Kernel), cũng chạy tay được. Pay2S giới hạn 30 yêu cầu/phút/IP nên mỗi lượt chỉ hỏi tối đa --limit phiên.
 */
class SyncPay2SCardPayments extends Command
{
    protected $signature = 'pay2s-card:sync {--minutes=120 : Chỉ hỏi phiên tạo trong bao nhiêu phút gần đây} {--limit=20 : Số phiên tối đa mỗi lượt}';

    protected $description = 'Hỏi Pay2S trạng thái các phiên thanh toán thẻ đang chờ và tự xác nhận đơn (dự phòng webhook)';

    public function handle(Pay2SCardService $card): int
    {
        if (! $card->configured()) {
            $this->line('Thanh toán thẻ Pay2S chưa bật hoặc chưa đủ khoá — bỏ qua.');

            return self::SUCCESS;
        }

        $pending = Pay2SCardPayment::query()
            ->where('status', Pay2SCardPayment::PENDING)
            ->whereNotNull('payment_url')
            ->where('created_at', '>=', now()->subMinutes(max(1, (int) $this->option('minutes'))))
            ->oldest('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $paid = 0;
        foreach ($pending as $payment) {
            if ($card->sync($payment)->status === Pay2SCardPayment::PAID) {
                $paid++;
            }
        }

        $this->info("Đã hỏi {$pending->count()} phiên thẻ, xác nhận {$paid} đơn.");

        return self::SUCCESS;
    }
}
