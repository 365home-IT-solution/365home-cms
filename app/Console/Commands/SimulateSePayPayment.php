<?php

namespace App\Console\Commands;

use App\Services\Payment\SePayService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\PartnerSePayAccount;
use Modules\Payment\Entities\SePayPaymentLink;

/**
 * Giả lập SePay báo "tiền vào" để thử luồng tự xác nhận đơn ngay trên máy local (không cần SePay thật / đường hầm công khai).
 * Gọi đúng route webhook thật với chữ ký HMAC hợp lệ, nên đi qua toàn bộ kiểm tra như giao dịch thật.
 * Chỉ chạy khi APP_URL là máy local (.test / .local / localhost).
 */
class SimulateSePayPayment extends Command
{
    protected $signature = 'sepay:simulate
        {code : Mã đơn (order_code) hoặc mã thanh toán 8 số (số sau DH)}
        {--amount= : Số tiền chuyển (mặc định đúng số tiền yêu cầu)}
        {--account= : Số tài khoản nhận (mặc định tài khoản SePay của đối tác)}
        {--id= : sepay_id của giao dịch (mặc định ngẫu nhiên; trùng id = SePay gửi lại)}';

    protected $description = 'Giả lập webhook SePay tiền vào cho 1 yêu cầu thanh toán (chỉ local/staging)';

    public function handle(SePayService $sePay): int
    {
        // Máy local của dự án chạy APP_ENV=production nên chặn theo tên miền: chỉ chạy khi APP_URL là máy local (.test, .local, localhost).
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! preg_match('/(\.test|\.local|\.localhost|^localhost|^127\.0\.0\.1)$/i', $host)) {
            $this->error("Chỉ chạy trên máy local/staging (APP_URL hiện là {$host}). Không dùng lệnh này trên máy chủ thật.");

            return self::FAILURE;
        }

        if ((string) \App\Support\SePayConfig::get('webhook_secret') === '') {
            $this->error('Chưa có SEPAY_WEBHOOK_SECRET trong .env (thêm rồi chạy php artisan config:clear).');

            return self::FAILURE;
        }

        $input = (int) $this->argument('code');
        $link = SePayPaymentLink::query()->where('code', $input)->first();

        if (! $link) {
            $order = Order::withoutGlobalScopes()->where('order_code', (string) $input)->first();
            $link = $order ? SePayPaymentLink::query()->where('order_id', $order->id)->where('status', SePayPaymentLink::PENDING)->latest('id')->first() : null;
        }

        if (! $link) {
            $this->error("Không có yêu cầu thanh toán SePay nào cho mã {$input} (đơn phải thuộc đối tác đã bật SePay và đã tạo link thanh toán).");

            return self::FAILURE;
        }

        $order = Order::withoutGlobalScopes()->findOrFail($link->order_id);
        $account = $this->option('account') ?: PartnerSePayAccount::query()->where('partner_id', \App\Services\Payment\PayOsAccountResolver::partnerIdForOrder($order))->value('account_number');
        $amount = $this->option('amount') !== null ? (int) $this->option('amount') : (int) $link->amount;
        $id = $this->option('id') !== null ? (int) $this->option('id') : random_int(800000000, 899999999);

        $body = json_encode([
            'id' => $id, 'gateway' => 'Vietcombank', 'transactionDate' => now()->format('Y-m-d H:i:s'), 'accountNumber' => (string) $account,
            'code' => $link->paymentCode(), 'content' => 'GIA LAP ' . $link->paymentCode(), 'transferType' => 'in',
            'transferAmount' => $amount, 'accumulated' => 0, 'referenceCode' => 'SIM' . Str::upper(Str::random(8)),
        ], JSON_UNESCAPED_UNICODE);
        $timestamp = now()->timestamp;

        $request = Request::create(route('webhook.sepay', [], false), 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SEPAY_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_SEPAY_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, (string) \App\Support\SePayConfig::get('webhook_secret')),
        ], $body);

        $response = app(\Illuminate\Contracts\Http\Kernel::class)->handle($request);

        $this->line("Webhook giả lập: HTTP {$response->getStatusCode()} {$response->getContent()}");
        $this->line("  mã {$link->paymentCode()} · {$amount} đ · tài khoản {$account} · sepay_id {$id}");

        $tx = \Modules\Payment\Entities\SePayTransaction::query()->where('sepay_id', $id)->first();
        $this->line('  giao dịch: ' . ($tx?->status ?? '—') . ($tx?->failure_reason ? ' — ' . $tx->failure_reason : ''));
        $this->line('  đơn #' . $order->order_code . ' → ' . ($order->fresh()->status));

        return $response->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }
}
