<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Partner;
use App\Settings\Pay2SCardSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\Pay2SCardPayment;
use Modules\Payment\Http\Controllers\PaymentController;
use RuntimeException;

/**
 * Thanh toán THẺ qua Pay2S (bộ "Thanh toán thẻ popup", chế độ hosted) — chỉ cho chi nhánh của 365home, tiền về tài khoản Pay2S của 365home.
 *
 *  1. Khách bấm "Thanh toán thẻ" → createSession(): tạo phiên (đúng số tiền đang cần thu) → trả payment_url để mở trang thanh toán của Pay2S.
 *  2. Pay2S gọi webhook (handleWebhook) / hệ thống tự hỏi trạng thái (sync) → applyPaid(): đối chiếu đơn, số tiền, môi trường rồi mới xác nhận đơn,
 *     dùng CHUNG nhánh xử lý với PayOS/SePay (PaymentController::processVerifiedSePayLink): đổi trạng thái, gán mã cổng, báo khách, Telegram.
 *  3. Khoản Pay2S đã thu nhưng đơn không còn nhận được (đã huỷ / hết hạn / đã trả bằng cách khác) → phiên "review" để hoàn tiền thủ công
 *     (bộ thẻ của Pay2S KHÔNG có API hoàn tiền).
 * Tài liệu: https://developer.pay2s.vn/docs/card-popup/overview/
 */
class Pay2SCardService
{
    public const BRANDS = ['visa', 'mastercard', 'jcb', 'amex', 'unionpay'];

    public const MIN_AMOUNT = 2000;

    private const DEFAULT_API = 'https://payment.pay2s.vn/api/card-popup-session.php';

    private const SESSION_MINUTES = 15;

    public function settings(): ?Pay2SCardSettings
    {
        try {
            return app(Pay2SCardSettings::class);
        } catch (\Throwable) {
            return null; // chưa chạy migration cài đặt khi vừa deploy → coi như tắt, không làm hỏng luồng thanh toán khác
        }
    }

    public function configured(): bool
    {
        $s = $this->settings();

        return $s && $s->enabled && filled($s->partner_code) && filled($s->access_key) && filled($s->secret_key);
    }

    // ───────────────────────── Cấu hình (CMS + API admin dùng chung) ─────────────────────────

    /** Trạng thái cấu hình để hiển thị. Khoá bí mật KHÔNG bao giờ trả ra — chỉ báo đã có hay chưa. */
    public function status(): array
    {
        $s = $this->settings();

        return [
            'enabled' => (bool) $s?->enabled,
            'configured' => $this->configured(),
            'partner_code' => $s?->partner_code,
            'access_key' => ['configured' => filled($s?->access_key)],
            'secret_key' => ['configured' => filled($s?->secret_key)],
            'connection_id' => $s?->connection_id,
            'sandbox_only' => (bool) ($s?->sandbox_only ?? true),
            'merchant_origin' => $s?->merchant_origin ?: $this->merchantOrigin(),
            'api_url' => $s?->api_url ?: self::DEFAULT_API,
            'category_ids' => array_map('intval', (array) ($s?->category_ids ?? [])),
            'webhook_url' => route('webhook.pay2s-card'),
            'brands' => self::BRANDS,
        ];
    }

    /**
     * Lưu cấu hình: khoá bí mật để trống = giữ nguyên; khoá không gửi = giữ nguyên giá trị đang lưu.
     *
     * @param array<string,mixed> $input
     */
    public function update(array $input): array
    {
        $s = $this->apply(app(Pay2SCardSettings::class), $input);
        $s->save();
        app()->forgetInstance(Pay2SCardSettings::class);

        return $this->status();
    }

    /** Gán giá trị lên đối tượng cài đặt (KHÔNG lưu) — tách riêng để test kiểm tra quy tắc mà không ghi vào bảng settings. */
    public function apply(Pay2SCardSettings $s, array $input): Pay2SCardSettings
    {
        foreach (['access_key', 'secret_key'] as $key) {
            if (filled($input[$key] ?? null)) {
                $s->{$key} = trim((string) $input[$key]);
            }
        }
        foreach (['partner_code', 'connection_id'] as $key) {
            if (array_key_exists($key, $input)) {
                $s->{$key} = filled($input[$key]) ? trim((string) $input[$key]) : null;
            }
        }
        foreach (['merchant_origin', 'api_url'] as $key) {
            if (array_key_exists($key, $input)) {
                $s->{$key} = filled($input[$key]) ? rtrim(trim((string) $input[$key]), '/') : null;
            }
        }
        foreach (['enabled', 'sandbox_only'] as $key) {
            if (array_key_exists($key, $input)) {
                $s->{$key} = (bool) $input[$key];
            }
        }
        if (array_key_exists('category_ids', $input)) {
            $s->category_ids = array_values(array_unique(array_map('intval', (array) $input['category_ids'])));
        }

        return $s;
    }

    /** Bật/tắt thanh toán thẻ. Tắt = khách và admin không thấy gì về thẻ nữa (xem eligibleForCategory); phiên đã tạo trước đó vẫn được xác nhận nếu tiền về. */
    public function setEnabled(bool $enabled): array
    {
        return $this->update(['enabled' => $enabled]);
    }

    /**
     * Nhãn "Thẻ" cho đơn ĐÃ trả bằng thẻ — chỉ có khoá khi đơn thật sự có phiên thẻ đã thanh toán, đơn khác giữ nguyên phản hồi cũ.
     *
     * @return array{paid_by_card?: bool, payment_label?: string}
     */
    public function labelFields(Order $order, ?bool $paidByCard = null): array
    {
        $paidByCard ??= Pay2SCardPayment::query()->where('order_id', $order->id)->where('status', Pay2SCardPayment::PAID)->exists();

        return $paidByCard ? ['paid_by_card' => true, 'payment_label' => 'Thẻ'] : [];
    }

    /** Khoản "review" đã hoàn tiền / xử lý xong thủ công. false nếu khoản không ở trạng thái review. */
    public function resolve(Pay2SCardPayment $payment, ?string $by, ?string $note): bool
    {
        if ($payment->status !== Pay2SCardPayment::REVIEW) {
            return false;
        }

        $payment->update([
            'status' => Pay2SCardPayment::RESOLVED, 'resolved_at' => now(), 'resolved_by' => $by ? Str::limit($by, 60, '') : null,
            'resolution_note' => filled($note) ? Str::limit(trim((string) $note), 490, '') : null,
        ]);

        return true;
    }

    // ───────────────────────── Điều kiện áp dụng ─────────────────────────

    /** Chi nhánh (hoặc chi nhánh cha) của đơn có trong danh sách bật thẻ, và đơn KHÔNG thuộc đối tác thường. */
    public function eligible(Order $order): bool
    {
        // Thẻ đang tắt (mặc định) ⇒ thoát ngay, không tốn truy vấn tìm chi nhánh/đối tác — hàm này nằm trong mọi phản hồi chi tiết đơn.
        if (! $this->configured()) {
            return false;
        }

        return $this->eligibleForCategory($order->category_id ? (int) $order->category_id : null, PayOsAccountResolver::partnerIdForOrder($order));
    }

    /** Như eligible() nhưng theo chi nhánh — dùng ở bước đặt phòng, khi chưa có đơn, để biết có hiện lựa chọn "Thẻ quốc tế" không. */
    public function eligibleForCategory(?int $categoryId, ?string $partnerId = null): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $allowed = array_map('intval', (array) $this->settings()->category_ids);
        if (! $allowed || ! $categoryId) {
            return false;
        }

        $partnerId ??= PayOsAccountResolver::partnerIdForCategory($categoryId);
        if ($partnerId) {
            $partner = Partner::withTrashed()->find($partnerId);
            if ($partner && ! $partner->isPlatformPartner() && ! $partner->isSystemPartner()) {
                return false;
            }
        }

        for ($depth = 0; $categoryId && $depth < 5; $depth++) {
            if (in_array($categoryId, $allowed, true)) {
                return true;
            }
            $categoryId = (int) Category::withoutGlobalScopes()->whereKey($categoryId)->value('parent_id');
        }

        return false;
    }

    /** Khoản cần thu lúc này của đơn: kind (initial | remaining | extra) + số tiền; null nếu đơn không còn khoản nào chờ thanh toán. */
    public function due(Order $order): ?array
    {
        $extra = (int) ($order->extra_charge_amount ?? 0);
        $status = (string) $order->status;

        $due = match (true) {
            $status === 'pending' => ['initial', $order->depositDueAmount()],
            $status === 'deposit' => ['remaining', max(0, ((int) $order->full_amount - $order->depositDueAmount()) + $extra)],
            $status === 'paid' && $extra > 0 && $order->extra_charge_paid_at === null => ['extra', $extra],
            default => null,
        };

        return $due && $due[1] >= self::MIN_AMOUNT ? ['kind' => $due[0], 'amount' => (int) $due[1]] : null;
    }

    /** Thông tin cho app biết có hiện nút "Thanh toán thẻ" không (gộp vào paymentFields của đơn). */
    public function availability(Order $order): array
    {
        $due = $this->eligible($order) ? $this->due($order) : null;

        return $due
            ? ['available' => true, 'brands' => self::BRANDS, 'kind' => $due['kind'], 'amount' => $due['amount'], 'sandbox' => (bool) $this->settings()->sandbox_only]
            : ['available' => false];
    }

    // ───────────────────────── Tạo phiên ─────────────────────────

    /**
     * Tạo (hoặc dùng lại phiên còn hạn, cùng khoản) phiên thanh toán thẻ. @throws ValidationException khi đơn không đủ điều kiện
     */
    public function createSession(Order $order, string $cardBrand, ?string $returnUrl = null): Pay2SCardPayment
    {
        $cardBrand = strtolower($cardBrand);
        if (! in_array($cardBrand, self::BRANDS, true)) {
            throw ValidationException::withMessages(['card_brand' => 'Loại thẻ không hợp lệ.']);
        }

        if (! $this->eligible($order)) {
            throw ValidationException::withMessages(['order' => 'Đơn này chưa hỗ trợ thanh toán bằng thẻ.']);
        }

        $due = $this->due($order);
        if (! $due) {
            throw ValidationException::withMessages(['order' => 'Đơn không còn khoản nào cần thanh toán.']);
        }

        $existing = Pay2SCardPayment::query()->where('order_id', $order->id)->where('status', Pay2SCardPayment::PENDING)->latest('id')->get();
        $reuse = $existing->first(fn (Pay2SCardPayment $p) => $p->isOpen() && $p->kind === $due['kind'] && $p->amount === $due['amount'] && $p->card_brand === $cardBrand && $p->payment_url);
        if ($reuse) {
            return $reuse;
        }

        $s = $this->settings();
        $payment = Pay2SCardPayment::query()->create([
            'order_id' => $order->id, 'kind' => $due['kind'], 'amount' => $due['amount'], 'card_brand' => $cardBrand,
            'pay2s_order_id' => 'C' . $order->order_code . '-' . Str::upper(Str::random(8)), 'status' => Pay2SCardPayment::PENDING,
        ]);

        $name = trim((string) ($order->buyer_name ?: $order->customer?->name ?: 'Khach hang'));
        $parts = preg_split('/\s+/u', $name) ?: [$name];
        $lastName = array_shift($parts) ?: 'Khach';
        $firstName = $parts ? implode(' ', $parts) : $lastName;

        $body = [
            'action' => 'create',
            'orderId' => $payment->pay2s_order_id,
            'amount' => $due['amount'],
            'cardBrand' => $cardBrand,
            'buyer' => ['firstName' => $firstName, 'lastName' => $lastName, 'email' => $this->buyerEmail($order)],
            'merchantOrigin' => $this->merchantOrigin(),
            'webhookUrl' => route('webhook.pay2s-card'),
            'checkoutMode' => 'hosted',
            'returnUrl' => $this->alignToMerchantOrigin($returnUrl ?: route('payment.success') . '?orderCode=' . $order->order_code . '&card=1'),
            'sandboxOnly' => (bool) $s->sandbox_only,
        ] + (filled($s->connection_id) ? ['connectionId' => $s->connection_id] : []);

        try {
            $data = $this->call($body);
        } catch (\Throwable $e) {
            $payment->update(['status' => Pay2SCardPayment::FAILED, 'failure_reason' => Str::limit($e->getMessage(), 480, '')]);
            Log::error('Pay2S thẻ: tạo phiên lỗi', ['order_id' => $order->id, 'error' => $e->getMessage()]);

            throw new RuntimeException('Không tạo được phiên thanh toán thẻ. Vui lòng thử lại sau.', 0, $e);
        }

        $payment->update([
            'session_id' => $data['sessionId'] ?? null,
            'environment' => $data['environment'] ?? null,
            'payment_url' => $data['paymentUrl'] ?? null,
            'expires_at' => isset($data['expiresAt']) ? now()->setTimestamp((int) $data['expiresAt']) : now()->addMinutes(self::SESSION_MINUTES),
        ]);

        if (! $payment->payment_url) {
            $payment->update(['status' => Pay2SCardPayment::FAILED, 'failure_reason' => 'Pay2S không trả paymentUrl.']);

            throw new RuntimeException('Không tạo được phiên thanh toán thẻ. Vui lòng thử lại sau.');
        }

        // Phiên cũ chưa trả của đơn không còn dùng nữa (Pay2S không có API huỷ — tự hết hạn); đơn không bị tự huỷ khi khách đang nhập thẻ.
        $existing->each(fn (Pay2SCardPayment $p) => $p->update(['status' => Pay2SCardPayment::CANCELLED, 'failure_reason' => 'Thay bằng phiên mới.']));

        // expired_at đọc từ DB là chuỗi (cột không cast) ⇒ parse trước khi so.
        $holdUntil = $payment->expires_at->copy()->addMinutes(5);
        if ($due['kind'] === 'initial' && $order->expired_at && \Illuminate\Support\Carbon::parse($order->expired_at)->lt($holdUntil)) {
            $order->forceFill(['expired_at' => $holdUntil])->saveQuietly();
        }

        return $payment->fresh();
    }

    // ───────────────────────── Webhook + tra trạng thái ─────────────────────────

    /**
     * Pay2S gọi webhook (JSON). Trả mã: 'ok' (đã xử lý, kể cả bỏ qua), 'bad_signature' (401), 'error' (500 → Pay2S gửi lại).
     *
     * @return array{result: string, status?: string}
     */
    public function handleWebhook(array $payload): array
    {
        if (! $this->verifyWebhookSignature($payload)) {
            return ['result' => 'bad_signature'];
        }

        $payment = Pay2SCardPayment::query()->where('pay2s_order_id', (string) ($payload['orderId'] ?? ''))->first();
        if (! $payment) {
            Log::warning('Pay2S thẻ: webhook không khớp phiên nào', ['orderId' => $payload['orderId'] ?? null]);

            return ['result' => 'ok', 'status' => 'unknown'];
        }

        try {
            $resultCode = (int) ($payload['resultCode'] ?? -1);
            $stored = \Illuminate\Support\Arr::except($payload, ['accessKey', 'm2signature']); // không lưu khoá truy cập và chữ ký

            if ($resultCode === 0) {
                $status = $this->applyPaid($payment, (int) ($payload['amount'] ?? 0), null, $stored, (string) ($payload['transId'] ?? ''));
            } elseif ($resultCode === 2) {
                $status = $this->closeUnpaid($payment, Pay2SCardPayment::CANCELLED, 'Khách huỷ thanh toán thẻ.', $stored);
            } else {
                $status = $this->closeUnpaid($payment, Pay2SCardPayment::FAILED, 'Pay2S báo thanh toán không thành công (mã ' . $resultCode . ').', $stored);
            }
        } catch (\Throwable $e) {
            Log::error('Pay2S thẻ: xử lý webhook lỗi', ['orderId' => $payment->pay2s_order_id, 'error' => $e->getMessage()]);

            return ['result' => 'error'];
        }

        return ['result' => 'ok', 'status' => $status];
    }

    /** Chữ ký webhook: HMAC-SHA256(secretKey) trên "key=value" nối bằng & theo thứ tự cố định của Pay2S (không URL-encode, giữ chuỗi rỗng). */
    public function verifyWebhookSignature(array $p): bool
    {
        $s = $this->settings();
        $signature = (string) ($p['m2signature'] ?? '');

        if (! $s || ! filled($s->secret_key) || $signature === '') {
            return false;
        }

        if (($p['partnerCode'] ?? null) !== $s->partner_code || ($p['accessKey'] ?? null) !== $s->access_key) {
            return false;
        }

        $raw = collect(['accessKey', 'amount', 'extraData', 'message', 'orderId', 'orderInfo', 'orderType', 'partnerCode', 'payType', 'requestId', 'responseTime', 'resultCode', 'transId'])
            ->map(fn (string $k) => $k . '=' . (is_scalar($p[$k] ?? null) ? (string) $p[$k] : ''))
            ->implode('&');

        return hash_equals(hash_hmac('sha256', $raw, (string) $s->secret_key), strtolower($signature));
    }

    /**
     * Hỏi Pay2S trạng thái phiên (webhook chậm/lỡ, hoặc khách vừa quay lại từ trang thanh toán). Pay2S giới hạn 30 yêu cầu/phút/IP nên chỉ hỏi phiên còn mở.
     */
    public function sync(Pay2SCardPayment $payment): Pay2SCardPayment
    {
        if ($payment->status !== Pay2SCardPayment::PENDING) {
            return $payment;
        }

        try {
            $data = $this->call(['action' => 'status', 'orderId' => $payment->pay2s_order_id]);
        } catch (\Throwable $e) {
            Log::warning('Pay2S thẻ: tra trạng thái lỗi', ['orderId' => $payment->pay2s_order_id, 'error' => $e->getMessage()]);

            return $payment;
        }

        $status = (string) ($data['status'] ?? '');

        if ($status === 'paid') {
            $this->applyPaid($payment, (int) ($data['amount'] ?? 0), (string) ($data['environment'] ?? ''), $data, '');
        } elseif ($status === 'cancelled') {
            $this->closeUnpaid($payment, Pay2SCardPayment::CANCELLED, 'Khách huỷ thanh toán thẻ.', $data);
        } elseif ($status === 'failed') {
            $this->closeUnpaid($payment, Pay2SCardPayment::FAILED, 'Thanh toán thẻ không thành công.', $data);
        } elseif ($status === 'expired') {
            $this->closeUnpaid($payment, Pay2SCardPayment::EXPIRED, 'Phiên thanh toán đã hết hạn.', $data);
        } elseif ($status === 'review') {
            $payment->update(['status' => Pay2SCardPayment::REVIEW, 'failure_reason' => 'Pay2S báo cần đối soát (thành công muộn) — kiểm tra trên Pay2S.', 'payload' => $data]);
        }

        return $payment->fresh();
    }

    /**
     * Pay2S báo ĐÃ THU: đối chiếu số tiền + môi trường + trạng thái đơn trong khoá rồi xác nhận đơn. Lý do từ chối → phiên "review" (đã thu tiền, cần hoàn thủ công).
     * Gọi lặp (webhook gửi lại + tra trạng thái) vẫn chỉ xử lý một lần.
     */
    private function applyPaid(Pay2SCardPayment $payment, int $amount, ?string $environment, array $raw, string $transId): string
    {
        return DB::transaction(function () use ($payment, $amount, $environment, $raw, $transId): string {
            $locked = Pay2SCardPayment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status === Pay2SCardPayment::PAID) {
                return Pay2SCardPayment::PAID; // đã xử lý
            }

            $order = Order::withoutGlobalScopes()->with('items')->lockForUpdate()->find($locked->order_id);
            $environment ??= $locked->environment;
            $failure = $this->rejectReason($locked, $order, $amount, $environment);

            if ($failure) {
                $locked->update(['status' => Pay2SCardPayment::REVIEW, 'failure_reason' => Str::limit($failure, 480, ''), 'trans_id' => $transId ?: $locked->trans_id, 'payload' => $raw]);
                Log::warning('Pay2S thẻ: khoản đã thu bị từ chối — cần hoàn tiền thủ công', ['payment_id' => $locked->id, 'reason' => $failure]);

                return Pay2SCardPayment::REVIEW;
            }

            if (! app(PaymentController::class)->processVerifiedSePayLink($order, $locked->kind)) {
                throw new RuntimeException('Không cập nhật được đơn sau khi nhận tiền thẻ (xem log).');
            }

            if ($locked->kind === 'extra') {
                $order->update(['extra_charge_payment_method' => 'card']);
            }

            $locked->update(['status' => Pay2SCardPayment::PAID, 'paid_at' => now(), 'trans_id' => $transId ?: $locked->trans_id, 'failure_reason' => null, 'payload' => $raw]);

            return Pay2SCardPayment::PAID;
        });
    }

    private function rejectReason(Pay2SCardPayment $payment, ?Order $order, int $amount, ?string $environment): ?string
    {
        if (! $order) {
            return 'Không tìm thấy đơn của phiên thanh toán.';
        }
        if ($amount !== (int) $payment->amount) {
            return "Số tiền không khớp; cần {$payment->amount}, Pay2S báo {$amount}.";
        }
        if (strtolower((string) $environment) === 'sandbox' && ! (bool) $this->settings()?->sandbox_only) {
            return 'Giao dịch thử (sandbox) nhưng hệ thống đang ở chế độ thật — không xác nhận đơn.';
        }

        $extra = (int) ($order->extra_charge_amount ?? 0);
        $status = (string) $order->status;

        return match ($payment->kind) {
            'initial' => $status === 'pending' ? null : "Đơn đang ở trạng thái \"{$status}\", không nhận thanh toán đầu — hoàn tiền thủ công.",
            'remaining' => $status === 'deposit' ? null : "Đơn đang ở trạng thái \"{$status}\", không nhận phần còn lại — hoàn tiền thủ công.",
            'extra' => $extra > 0 && $order->extra_charge_paid_at === null ? null : 'Khoản phát sinh không còn chờ thanh toán — hoàn tiền thủ công.',
            default => 'Loại khoản thu không hợp lệ.',
        };
    }

    private function closeUnpaid(Pay2SCardPayment $payment, string $status, string $reason, array $raw): string
    {
        if (in_array($payment->status, [Pay2SCardPayment::PAID, Pay2SCardPayment::REVIEW, Pay2SCardPayment::RESOLVED], true)) {
            return $payment->status;
        }

        $payment->update(['status' => $status, 'failure_reason' => $reason, 'payload' => $raw]);

        return $status;
    }

    // ───────────────────────── Gọi API Pay2S ─────────────────────────

    /**
     * Gọi API phiên thẻ (chỉ từ backend). Chữ ký: HMAC-SHA256(secretKey) trên 3 dòng "pay2s-card-popup-v1" / timestamp / SHA256(body) — body gửi ĐÚNG chuỗi đã ký.
     *
     * @return array<string,mixed> khối "data" của phản hồi
     */
    private function call(array $body): array
    {
        $s = $this->settings();
        if (! $this->configured()) {
            throw new RuntimeException('Thanh toán thẻ Pay2S chưa được cấu hình.');
        }

        // Hay nhầm: màn "Thông tin tích hợp" của Pay2S hiện địa chỉ cổng CHUYỂN KHOẢN (/v1/gateway/api/create) — địa chỉ đó không phải của cổng thẻ và trả lỗi "Invalid partnerCode".
        if (filled($s->api_url) && ! str_contains((string) $s->api_url, 'card-popup-session')) {
            throw new RuntimeException('API URL không phải địa chỉ cổng thẻ (phải là .../api/card-popup-session.php). Xoá trống ô API URL để dùng địa chỉ chính thức.');
        }

        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', implode("\n", ['pay2s-card-popup-v1', $timestamp, hash('sha256', $json)]), (string) $s->secret_key);

        $response = Http::timeout(20)->withHeaders([
            'X-Pay2S-Partner' => (string) $s->partner_code,
            'X-Pay2S-Access-Key' => (string) $s->access_key,
            'X-Pay2S-Timestamp' => $timestamp,
            'X-Pay2S-Signature' => $signature,
            'Accept' => 'application/json',
        ])->withBody($json, 'application/json')->post(filled($s->api_url) ? $s->api_url : self::DEFAULT_API);

        $result = $response->json();

        if (! $response->successful() || ! is_array($result) || ($result['status'] ?? false) !== true) {
            throw new RuntimeException('Pay2S trả lỗi (' . $response->status() . '): ' . Str::limit((string) ($result['message'] ?? $response->body()), 300, ''));
        }

        return (array) ($result['data'] ?? []);
    }

    /** Thử gọi API bằng khoá đang lưu (nút "Kiểm tra kết nối" ở CMS): hỏi trạng thái một mã không tồn tại — chữ ký sai/khoá sai sẽ báo lỗi xác thực. */
    public function testConnection(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'Chưa bật hoặc chưa nhập đủ Partner Code / Access Key / Secret Key.'];
        }

        try {
            $this->call(['action' => 'status', 'orderId' => 'TEST-' . Str::upper(Str::random(8))]);

            return ['ok' => true, 'message' => 'Kết nối Pay2S thanh toán thẻ thành công.'];
        } catch (\Throwable $e) {
            // "không tìm thấy phiên" nghĩa là khoá và chữ ký đã được Pay2S chấp nhận.
            $authFailed = (bool) preg_match('/\b(401|403)\b|signature|unauthor|access.?key|partner|API URL/i', $e->getMessage());

            return $authFailed
                ? ['ok' => false, 'message' => 'Pay2S từ chối khoá: ' . Str::limit($e->getMessage(), 200, '')]
                : ['ok' => true, 'message' => 'Khoá được Pay2S chấp nhận (' . Str::limit($e->getMessage(), 120, '') . ').'];
        }
    }

    /**
     * Pay2S bắt returnUrl PHẢI cùng origin với merchantOrigin (409 nếu lệch). Địa chỉ quay về được dựng từ domain đang mở web (vd 365home-cms.test) nên có thể lệch
     * Origin đã đăng ký ⇒ giữ nguyên đường dẫn + tham số, đổi gốc theo merchantOrigin.
     */
    private function alignToMerchantOrigin(string $url): string
    {
        $parts = parse_url($url);

        return $this->merchantOrigin() . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private function merchantOrigin(): string
    {
        $origin = (string) ($this->settings()?->merchant_origin ?: config('app.url'));
        $parts = parse_url($origin);

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function buyerEmail(Order $order): string
    {
        $email = trim((string) ($order->buyer_email ?: $order->customer?->email));

        // Pay2S bắt buộc có email: đơn không có thì dùng hộp thư chung của web (không bịa địa chỉ của khách).
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'no-reply@' . (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'example.com');
    }
}
