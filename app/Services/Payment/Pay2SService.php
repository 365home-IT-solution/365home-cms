<?php

namespace App\Services\Payment;

use App\Support\Pay2SConfig;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Payment\Entities\PartnerSePayAccount;
use Modules\Payment\Entities\SePayPaymentLink;

// Cổng Pay2S: cùng luồng với SePay (SePayService::handleIncoming kiểm tra tài khoản, mã DH{code}, số tiền, trạng thái đơn rồi xác nhận đơn). Chỉ khác cách nhận tiền vào:
//   1. Webhook Pay2S (Pay2SWebhookController) — xác thực bằng token Bearer của TỪNG webhook (Pay2S sinh token riêng cho mỗi tài khoản ngân hàng);
//   2. Kéo giao dịch từ API Pay2S (pull(), lệnh sepay:sync, trang QR) — dự phòng vì Pay2S chỉ gửi lại webhook 5 lần cách nhau 60 giây.
class Pay2SService
{
    public function __construct(private readonly SePayService $sePay)
    {
    }

    /** Token Bearer của webhook có khớp một tài khoản Pay2S đang khai báo ở CMS không (so sánh thời gian hằng). */
    public function verifyToken(Request $request): bool
    {
        if (! preg_match('/^Bearer\s+(\S+)$/i', trim((string) $request->header('Authorization')), $match)) {
            return false;
        }

        $given = $match[1];
        $valid = false;

        PartnerSePayAccount::query()->where('provider', PartnerSePayAccount::PROVIDER_PAY2S)->whereNotNull('webhook_token')->get()
            ->each(function (PartnerSePayAccount $account) use ($given, &$valid) {
                $token = (string) $account->webhook_token;

                if ($token !== '' && hash_equals($token, $given)) {
                    $valid = true;
                }
            });

        return $valid;
    }

    /** Giao dịch Pay2S (webhook) → định dạng chung của SePayService::handleIncoming. */
    public function webhookPayload(array $tx): array
    {
        return [
            'id' => (int) ($tx['id'] ?? 0),
            'accountNumber' => (string) ($tx['accountNumber'] ?? ''),
            'subAccount' => ($tx['vaNumber'] ?? null) ?: null,
            'transferAmount' => (int) round((float) ($tx['transferAmount'] ?? 0)),
            'transactionDate' => $tx['transactionDate'] ?? null,
            'referenceCode' => isset($tx['transactionNumber']) ? (string) $tx['transactionNumber'] : null,
            'code' => null,
            'content' => (string) ($tx['content'] ?? ''),
        ];
    }

    /**
     * Xử lý các giao dịch tiền vào của 1 lần gọi webhook.
     *
     * @return array{processed: int, failed: int}
     */
    public function handleWebhook(array $transactions): array
    {
        $processed = 0;
        $failed = 0;

        foreach ($transactions as $tx) {
            if (! is_array($tx) || Str::upper((string) ($tx['transferType'] ?? '')) !== 'IN' || (int) ($tx['id'] ?? 0) <= 0) {
                continue;
            }

            $result = $this->sePay->handleIncoming($this->webhookPayload($tx), ['source' => 'pay2s_webhook'] + $tx, PartnerSePayAccount::PROVIDER_PAY2S);

            match ($result['status']) {
                'processed' => $processed++,
                'failed' => $failed++,
                default => null,
            };
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    // ───────────────────────── Kéo giao dịch từ API Pay2S (không cần webhook) ─────────────────────────

    public function canPull(): bool
    {
        return filled(Pay2SConfig::get('api_token'));
    }

    private function apiUrl(): string
    {
        return rtrim((string) Pay2SConfig::get('api_url', 'https://api.pay2s.vn'), '/') . '/userapi/transactions';
    }

    /**
     * Thử gọi API Pay2S bằng token đang dùng (nút "Kiểm tra kết nối" ở CMS).
     *
     * @return array{ok: bool, message: string}
     */
    public function testApi(): array
    {
        if (! $this->canPull()) {
            return ['ok' => false, 'message' => 'Chưa nhập API Token Pay2S.'];
        }

        try {
            $response = $this->request(now(), now());
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Không kết nối được tới Pay2S: ' . $e->getMessage()];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return ['ok' => false, 'message' => 'Pay2S từ chối API Token (sai hoặc đã bị thu hồi). Lấy token ở my.pay2s.vn → Tích hợp Web/App.'];
        }

        if ($response->status() === 429) {
            return ['ok' => false, 'message' => 'Pay2S giới hạn 60 lần gọi mỗi phút — thử lại sau ít phút.'];
        }

        if (! $response->successful() || $response->json('status') === false) {
            return ['ok' => false, 'message' => 'Pay2S trả lỗi: ' . Str::limit((string) ($response->json('messages') ?: $response->body()), 200)];
        }

        return ['ok' => true, 'message' => 'Kết nối API Pay2S thành công.'];
    }

    private function request(Carbon $from, Carbon $to)
    {
        return Http::withHeaders(['pay2s-token' => (string) Pay2SConfig::get('api_token')])->acceptJson()->asJson()->timeout(10)
            ->post($this->apiUrl(), ['begin' => $from->format('d/m/Y'), 'end' => $to->format('d/m/Y')]);
    }

    /**
     * Hỏi Pay2S giao dịch tiền vào gần đây (một lần gọi cho mọi tài khoản đã liên kết) rồi xử lý như webhook. Có $link (trang QR / app hỏi liên tục):
     * mọi lần hỏi dùng chung một nhịp tối thiểu giữa 2 lần gọi để không vượt giới hạn 60 lần/phút của Pay2S. Trả số giao dịch đã xác nhận đơn.
     * API Pay2S lọc theo NGÀY nên giao dịch cũ hơn $hours giờ được bỏ qua ở phía mình.
     */
    public function pull(?SePayPaymentLink $link = null, int $hours = 24): int
    {
        if (! $this->canPull()) {
            return 0;
        }

        if ($link && ! Cache::add('pay2s:pull', 1, now()->addSeconds((int) Pay2SConfig::get('pull_throttle_seconds', 3)))) {
            return 0;
        }

        $since = now()->subHours($hours);

        try {
            $response = $this->request($since, now());
        } catch (\Throwable $e) {
            Log::warning('Pay2S kéo giao dịch lỗi mạng', ['error' => $e->getMessage()]);

            return 0;
        }

        if (! $response->successful() || $response->json('status') === false) {
            Log::warning('Pay2S kéo giao dịch thất bại', ['status' => $response->status(), 'body' => Str::limit($response->body(), 300)]);

            return 0;
        }

        $confirmed = 0;

        foreach ((array) $response->json('transactions', []) as $tx) {
            if (Str::upper((string) ($tx['type'] ?? '')) !== 'IN' || (float) ($tx['amount'] ?? 0) <= 0 || (int) ($tx['id'] ?? 0) <= 0) {
                continue;
            }

            $at = isset($tx['transaction_date']) ? Carbon::parse($tx['transaction_date']) : null;

            if ($at && $at->lt($since)) {
                continue;
            }

            $payload = [
                'id' => (int) $tx['id'], 'accountNumber' => (string) ($tx['account_number'] ?? ''), 'subAccount' => null,
                'transferAmount' => (int) round((float) $tx['amount']),
                'transactionDate' => $tx['transaction_date'] ?? null, 'referenceCode' => isset($tx['transaction_id']) ? (string) $tx['transaction_id'] : null,
                'code' => null, 'content' => (string) ($tx['description'] ?? ''),
            ];

            // Giao dịch không có mã thanh toán của hệ thống là tiền không liên quan (không ghi vào nhật ký đối soát).
            if ($this->sePay->extractCode($payload) === null) {
                continue;
            }

            if ($this->sePay->handleIncoming($payload, ['source' => 'pay2s_api'] + $tx, PartnerSePayAccount::PROVIDER_PAY2S)['status'] === 'processed') {
                $confirmed++;
            }
        }

        return $confirmed;
    }
}
