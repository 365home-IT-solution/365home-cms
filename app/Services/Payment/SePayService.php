<?php

namespace App\Services\Payment;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\PartnerSePayAccount;
use Modules\Payment\Entities\SePayPaymentLink;
use Modules\Payment\Entities\SePayTransaction;
use Modules\Payment\Http\Controllers\PaymentController;
use App\Support\Pay2SConfig;
use App\Support\SePayConfig;
use RuntimeException;

// Thu tiền đặt phòng qua SePay vào tài khoản ngân hàng của đối tác. Cách dùng: đối tác bật tài khoản SePay (CMS → "SePay theo đối tác")
// thì PayOsAccountResolver::forOrder() trả SePayGateway thay cho cổng PayOS — mọi luồng đang tạo "link PayOS" (đặt phòng, cọc,
// phần còn lại, phát sinh...) tự tạo yêu cầu thanh toán SePay với đúng số tiền.
//
// Xác nhận tiền vào có HAI đường, cùng đi qua handleIncoming() nên cùng kiểm tra và không bao giờ xử lý một giao dịch hai lần (sepay_id unique):
//   1. Webhook SePay (SePayWebhookController) — tức thì, cần máy chủ có địa chỉ công khai;
//   2. Kéo giao dịch từ API SePay (pull(), lệnh sepay:sync, trang QR) — không cần webhook, dùng được ở local và làm dự phòng khi webhook lỡ.
class SePayService
{
    public function verifySignature(Request $request): bool
    {
        $secret = (string) SePayConfig::get('webhook_secret');
        $signature = (string) $request->header('X-SePay-Signature');
        $timestamp = (int) $request->header('X-SePay-Timestamp');

        if ($secret === '' || $signature === '' || $timestamp <= 0) {
            return false;
        }

        if (abs(now()->timestamp - $timestamp) > SePayConfig::get('timestamp_tolerance', 300)) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    public function prefix(): string
    {
        return Str::upper((string) SePayConfig::get('code_prefix', 'DH'));
    }

    /** Mã thanh toán trong nội dung chuyển khoản → mã yêu cầu thanh toán (số). Ưu tiên trường `code` do SePay tách sẵn. */
    public function extractCode(array $payload): ?int
    {
        $prefix = preg_quote($this->prefix(), '/');

        foreach ([(string) ($payload['code'] ?? ''), (string) ($payload['content'] ?? '')] as $source) {
            // Mã hệ thống sinh luôn đúng 8 chữ số (ưu tiên, tách được cả khi ngân hàng dán mã giao dịch liền sau); mã cũ dài hơn (yêu cầu tạo trước đây) lấy cả dãy số.
            if (preg_match('/' . $prefix . '(\d{8})(?!\d)/i', $source, $match) || preg_match('/' . $prefix . '(\d{4,18})/i', $source, $match)) {
                return (int) $match[1];
            }
        }

        return null;
    }

    /**
     * Tài khoản nhận tiền (SePay / Pay2S / xác nhận thủ công) đang dùng cho đơn: đối tác được phép dùng kênh riêng (đã có hợp đồng mẫu mới), đơn không
     * thuộc chi nhánh có PayOS riêng, và có tài khoản đang bật áp dụng cho chi nhánh của đơn (xem accountForCategory).
     */
    public function accountFor(Order $order): ?PartnerSePayAccount
    {
        if (PayOsAccountResolver::branchAccountFor($order->category_id ? (int) $order->category_id : null)) {
            return null;
        }

        $partnerId = PayOsAccountResolver::partnerIdForOrder($order);

        if (! $partnerId || ! PayOsAccountResolver::partnerFlowAllowsOwnChannel($partnerId)) {
            return null;
        }

        $account = $this->accountForCategory($partnerId, $order->category_id ? (int) $order->category_id : null);

        return $account && $this->providerEnabled((string) $account->provider) ? $account : null;
    }

    /**
     * Tài khoản nhận tiền của đối tác áp dụng cho một chi nhánh: tài khoản riêng của chi nhánh (hoặc của chi nhánh cha gần nhất — khu vực con dùng chung
     * với chi nhánh), không có thì tài khoản mặc định của cả đối tác (category_id trống). $activeOnly = false: lấy cả tài khoản đang tắt (tra link cũ).
     */
    public function accountForCategory(?string $partnerId, ?int $categoryId, bool $activeOnly = true): ?PartnerSePayAccount
    {
        if (! $partnerId) {
            return null;
        }

        $accounts = PartnerSePayAccount::query()->where('partner_id', $partnerId)->when($activeOnly, fn ($q) => $q->where('is_active', true))->get();

        if ($accounts->isEmpty()) {
            return null;
        }

        $depth = 0;

        while ($categoryId && $depth++ < 5) {
            if ($account = $accounts->first(fn (PartnerSePayAccount $a) => (int) $a->category_id === $categoryId)) {
                return $account;
            }

            $categoryId = Category::whereKey($categoryId)->value('parent_id');
        }

        return $accounts->first(fn (PartnerSePayAccount $a) => $a->category_id === null);
    }

    /** Tài khoản của đơn kể cả đang tắt / cổng đang tắt — dùng để tra lại yêu cầu thanh toán đã phát hành. */
    private function accountOfOrder(Order $order): ?PartnerSePayAccount
    {
        return $this->accountForCategory(PayOsAccountResolver::partnerIdForOrder($order), $order->category_id ? (int) $order->category_id : null, false);
    }

    /** Công tắc tổng của từng cổng (CMS → Cấu hình web): tắt = mọi đối tác của cổng đó quay về PayOS. */
    public function providerEnabled(string $provider): bool
    {
        return match ($provider) {
            PartnerSePayAccount::PROVIDER_MANUAL => true, // không phụ thuộc cổng nào
            PartnerSePayAccount::PROVIDER_PAY2S => Pay2SConfig::enabled(),
            default => SePayConfig::enabled(),
        };
    }

    public function providerLabel(string $provider): string
    {
        return PartnerSePayAccount::PROVIDERS[$provider] ?? ucfirst($provider);
    }

    /** Cổng (sepay | pay2s | manual) của tài khoản đã phát hành yêu cầu thanh toán — dùng chọn đường hỏi giao dịch. */
    private function providerOfLink(SePayPaymentLink $link): ?string
    {
        if ($link->account) {
            return $link->account->provider;
        }

        $order = $link->order ?: Order::withoutGlobalScopes()->find($link->order_id);

        return $order ? $this->accountOfOrder($order)?->provider : null;
    }

    /** Số tiền tối thiểu của 1 lần thu: PayOS không tạo được link dưới 2.000đ; chuyển khoản SePay không có mức đó (mặc định 1.000đ, đổi bằng SEPAY_MIN_AMOUNT). */
    public function minAmount(Order $order): int
    {
        return $this->usesSePay($order) ? max(1, (int) SePayConfig::get('min_amount', 1000)) : 2000;
    }

    /**
     * Trường thêm vào phản hồi đơn cho app/web: kênh thu tiền của đơn và (nếu SePay) yêu cầu thanh toán đang chờ — số tiền, nội dung
     * chuyển khoản, ngân hàng, ảnh QR, trang QR. PayOS trả qr_code như cũ nên sepay = null.
     *
     * Đối tác dùng Pay2S: payment_provider = 'pay2s', cùng nội dung nằm ở khoá `pay2s` (khoá `sepay` = null).
     *
     * @return array{payment_provider: string, sepay: ?array, pay2s?: ?array}
     */
    public function paymentFields(Order $order): array
    {
        $account = $this->accountFor($order);

        if (! $account) {
            return ['payment_provider' => 'payos', 'sepay' => null];
        }

        $link = SePayPaymentLink::query()->where('order_id', $order->id)->where('status', SePayPaymentLink::PENDING)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest('id')->first();
        // Yêu cầu đang chờ hiện đúng tài khoản đã phát hành cho khách (kể cả khi cấu hình đổi sau đó).
        $account = $link?->account ?: $account;
        $data = $link ? $this->payloadFor($link, $account) + ['checkout_url' => route('sepay.checkout', ['code' => $link->code])] : null;

        return match ($account->provider) {
            PartnerSePayAccount::PROVIDER_PAY2S => ['payment_provider' => 'pay2s', 'sepay' => null, 'pay2s' => $data],
            // Đối tác tự xác nhận: app hiện QR/nội dung chuyển khoản như SePay và báo khách "chờ chủ nhà xác nhận".
            PartnerSePayAccount::PROVIDER_MANUAL => ['payment_provider' => 'manual', 'sepay' => null, 'manual' => $data],
            default => ['payment_provider' => 'sepay', 'sepay' => $data],
        };
    }

    public function usesSePay(Order $order): bool
    {
        return $this->accountFor($order) !== null;
    }

    public function gatewayFor(Order $order): ?SePayGateway
    {
        $account = $this->accountFor($order);

        return $account ? new SePayGateway($order, $account, $this) : null;
    }

    /**
     * Tạo (hoặc làm mới, nếu chưa trả) yêu cầu thanh toán cho khoản thu $refCode của đơn. Mã khách ghi khi chuyển khoản (code) là mã NGẮN 8 chữ số riêng,
     * không dùng mã đơn dài (SePay chỉ nhận hậu tố trong giới hạn đã cấu hình, mặc định 6–8 ký tự). Khoản đã thanh toán không bao giờ tạo lại.
     */
    public function createLink(Order $order, int $refCode, int $amount, ?Carbon $expiresAt, ?PartnerSePayAccount $account = null): SePayPaymentLink
    {
        if ($refCode <= 0 || $amount <= 0) {
            throw new RuntimeException('Mã hoặc số tiền thanh toán SePay không hợp lệ.');
        }

        $account ??= $this->accountFor($order);
        $link = SePayPaymentLink::query()->where('order_id', $order->id)->where('ref_code', $refCode)->latest('id')->first();

        if ($link && $link->status === SePayPaymentLink::PAID) {
            throw new RuntimeException("Khoản thu {$refCode} đã được thanh toán.");
        }

        if ($link) {
            // Làm mới yêu cầu (đổi hạn/số tiền): gắn lại tài khoản hiện áp dụng cho chi nhánh của đơn.
            $link->update(['amount' => $amount, 'status' => SePayPaymentLink::PENDING, 'expires_at' => $expiresAt, 'paid_at' => null, 'partner_sepay_account_id' => $account?->id ?? $link->partner_sepay_account_id]);

            return $link->fresh();
        }

        return SePayPaymentLink::query()->create([
            'order_id' => $order->id, 'partner_sepay_account_id' => $account?->id, 'ref_code' => $refCode, 'code' => $this->newShortCode(), 'amount' => $amount,
            'status' => SePayPaymentLink::PENDING, 'expires_at' => $expiresAt,
        ]);
    }

    private function newShortCode(): int
    {
        do {
            $code = random_int(10000000, 99999999);
        } while (SePayPaymentLink::query()->where('code', $code)->exists());

        return $code;
    }

    /** Loại khoản thu của 1 yêu cầu thanh toán, suy từ mã luồng đặt phòng (ref_code) so với các cột mã của đơn (giống webhook PayOS). */
    public function kindOf(Order $order, SePayPaymentLink $link): ?string
    {
        $code = (string) ($link->ref_code ?? $link->code);

        return match (true) {
            $code === (string) $order->order_code, $code === (string) $order->current_payos_code => 'initial',
            $code === (string) $order->remaining_payos_code => 'remaining',
            $code === (string) $order->deposit_retry_payos_code => 'deposit_retry',
            $code === (string) $order->extra_charge_payos_code => 'extra',
            default => null,
        };
    }

    /**
     * Nội dung khách phải ghi khi chuyển khoản. VietinBank (tài khoản cá nhân/hộ kinh doanh) chỉ báo biến động số dư cho SePay khi nội dung có "SEVQR",
     * nên với VietinBank thêm tiền tố này; mã DH vẫn nằm ngay sau nên hệ thống nhận diện bình thường.
     */
    public function transferContent(PartnerSePayAccount $account, string $paymentCode): string
    {
        // SEVQR là quy ước riêng của SePay; Pay2S nhận biến động VietinBank theo cách khác nên giữ nguyên nội dung.
        $needsSevqr = ($account->provider ?: PartnerSePayAccount::PROVIDER_SEPAY) === PartnerSePayAccount::PROVIDER_SEPAY
            && ($account->bank_bin === '970415' || in_array(strtoupper((string) $account->bank_code), ['CTG', 'ICB'], true));

        return $needsSevqr ? 'SEVQR ' . $paymentCode : $paymentCode;
    }

    public function qrUrl(PartnerSePayAccount $account, int $amount, string $paymentCode): string
    {
        $query = http_build_query(['acc' => $account->account_number, 'bank' => $account->bank_bin ?: $account->bank_code, 'amount' => $amount, 'des' => $this->transferContent($account, $paymentCode)], '', '&', PHP_QUERY_RFC3986);

        return 'https://vietqr.app/img?' . $query;
    }

    /** Dữ liệu hiển thị cho khách / app: số tiền, nội dung chuyển khoản, ngân hàng, ảnh QR, hạn thanh toán. */
    public function payloadFor(SePayPaymentLink $link, ?PartnerSePayAccount $account = null): array
    {
        $order = $link->order ?: Order::withoutGlobalScopes()->findOrFail($link->order_id);
        // Tài khoản đã phát hành cho yêu cầu này được ưu tiên: QR luôn trỏ đúng tài khoản chi nhánh đã báo khách, kể cả khi sau đó cấu hình đổi.
        $account ??= $link->account ?: $this->accountFor($order) ?: $this->accountOfOrder($order) ?? throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(PartnerSePayAccount::class);
        $code = $link->paymentCode();

        return [
            'provider' => $account->provider ?: 'sepay',
            'status' => $link->status,
            'order_code' => (string) $order->order_code,
            'amount' => (int) $link->amount,
            'payment_code' => $code,
            // Nội dung đầy đủ khách ghi khi chuyển khoản (= payment_code, riêng VietinBank có thêm tiền tố SEVQR) — hiển thị đúng nội dung này, không tự ghép.
            'transfer_content' => $this->transferContent($account, $code),
            'qr_url' => $this->qrUrl($account, (int) $link->amount, $code),
            'expires_at' => $link->expires_at?->toIso8601String(),
            'bank' => ['code' => $account->bank_code, 'bin' => $account->bank_bin, 'account_number' => $account->account_number, 'account_holder' => $account->account_holder],
        ] + ($account->isManual() ? [
            // Xác nhận thủ công: khách bấm "Tôi đã chuyển khoản" (POST claim_url) để báo chủ nhà; customer_claimed_at = lúc đã báo (null = chưa báo).
            'customer_claimed_at' => $link->customer_claimed_at?->toIso8601String(),
            'claim_url' => route('api.sepay.payments.claim', ['code' => $link->code]),
        ] : []);
    }

    // ───────────────────────── Xác nhận tiền vào (dùng chung webhook + kéo API) ─────────────────────────

    /**
     * Xử lý 1 giao dịch TIỀN VÀO của SePay: đối chiếu tài khoản, mã, số tiền, trạng thái đơn → xác nhận đơn, hoặc lưu "rejected" kèm lý do.
     *
     * @param array{id:int|string, accountNumber:string, transferAmount:int|string, transactionDate?:?string, referenceCode?:?string, code?:?string, content?:?string} $payload
     * @param array<string,mixed> $raw  dữ liệu gốc lưu để đối soát
     * @return array{status: string, reason: ?string, order: ?Order}  status: processed | rejected | duplicate | failed
     */
    public function handleIncoming(array $payload, array $raw, string $provider = PartnerSePayAccount::PROVIDER_SEPAY): array
    {
        $amount = (int) $payload['transferAmount'];
        $account = $this->findAccount((string) $payload['accountNumber'], (string) ($payload['subAccount'] ?? ''));
        $code = $this->extractCode($payload);
        // Tiền vào KHÔNG mang mã thanh toán của hệ thống, đến từ cổng mà tài khoản không dùng (vd SePay vẫn còn gửi webhook cho tài khoản đã chuyển sang
        // Pay2S / xác nhận thủ công) hoặc vào số tài khoản chưa khai báo: là biến động số dư không liên quan — bỏ qua, không ghi nhật ký đối soát.
        // Khoản có mã, hoặc không mã nhưng đến đúng cổng của tài khoản (khách ghi sai nội dung), vẫn được ghi "rejected" để đối soát / hoàn tiền.
        if ($code === null && (! $account || ($account->provider ?: PartnerSePayAccount::PROVIDER_SEPAY) !== $provider)) {
            return ['status' => 'ignored', 'reason' => null, 'order' => null];
        }

        $link = $code ? SePayPaymentLink::query()->where('code', $code)->first() : null;
        $order = $link ? Order::withoutGlobalScopes()->with('items')->find($link->order_id) : null;
        $failure = $this->validatePayment($account, $link, $order, $amount, $provider);

        try {
            $transaction = SePayTransaction::query()->create([
                'provider' => $provider, 'sepay_id' => $payload['id'], 'partner_sepay_account_id' => $account?->id,
                'order_id' => $order?->id, 'payment_code' => ($payload['code'] ?? null) ?: ($code ? $this->prefix() . $code : null),
                'amount' => $amount, 'reference_code' => $payload['referenceCode'] ?? null,
                'transaction_at' => $payload['transactionDate'] ?? null, 'status' => $failure ? 'rejected' : 'received',
                'failure_reason' => $failure, 'payload' => $raw,
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062 && ! str_contains(strtolower($e->getMessage()), 'unique')) {
                throw $e;
            }

            $transaction = SePayTransaction::query()->where('provider', $provider)->where('sepay_id', $payload['id'])->first();

            // Đã xử lý hoặc đã từ chối: gửi lại / kéo lại cùng giao dịch thì bỏ qua. Chỉ giao dịch lỗi xử lý (failed) mới chạy lại.
            if (! $transaction || $transaction->status !== 'failed') {
                return ['status' => 'duplicate', 'reason' => null, 'order' => $order];
            }
        }

        if ($failure) {
            Log::warning('SePay giao dịch bị từ chối', ['sepay_id' => $payload['id'], 'reason' => $failure]);

            return ['status' => 'rejected', 'reason' => $failure, 'order' => $order];
        }

        try {
            $rejected = DB::transaction(fn (): ?string => $this->applyVerifiedPayment($transaction, $link, $order));
        } catch (\Throwable $e) {
            $transaction->update(['status' => 'failed', 'failure_reason' => $e->getMessage()]);
            Log::error('SePay xử lý giao dịch lỗi', ['sepay_id' => $payload['id'], 'error' => $e->getMessage()]);

            return ['status' => 'failed', 'reason' => $e->getMessage(), 'order' => $order];
        }

        return $rejected
            ? ['status' => 'rejected', 'reason' => $rejected, 'order' => $order]
            : ['status' => 'processed', 'reason' => null, 'order' => $order->fresh()];
    }

    /**
     * Trong khoá: kiểm tra lại trạng thái, xác nhận đơn rồi đánh dấu yêu cầu thanh toán đã trả. Trả lý do từ chối (null = đã xác nhận).
     * Kiểm tra lại trong khoá để 2 xác nhận cùng lúc (webhook + kéo API, hoặc bấm 2 lần) chỉ 1 cái được xử lý.
     */
    private function applyVerifiedPayment(SePayTransaction $transaction, SePayPaymentLink $link, Order $order): ?string
    {
        $lockedLink = SePayPaymentLink::query()->lockForUpdate()->findOrFail($link->id);
        $locked = Order::withoutGlobalScopes()->with('items')->lockForUpdate()->findOrFail($order->id);

        if ($recheck = $this->validateState($lockedLink, $locked)) {
            $transaction->update(['status' => 'rejected', 'failure_reason' => $recheck]);

            return $recheck;
        }

        if (! app(PaymentController::class)->processVerifiedSePayLink($locked, (string) $this->kindOf($locked, $lockedLink))) {
            throw new RuntimeException('Không cập nhật được đơn sau khi nhận tiền (xem log).');
        }

        $lockedLink->update(['status' => SePayPaymentLink::PAID, 'paid_at' => now(), 'sepay_transaction_id' => $transaction->id]);
        $transaction->update(['status' => 'processed', 'failure_reason' => null]);

        return null;
    }

    // ───────────────────────── Đối tác tự xác nhận (không qua SePay / Pay2S) ─────────────────────────

    /**
     * Hạn muộn nhất của các yêu cầu thanh toán "xác nhận thủ công" còn chờ của đơn (null nếu không có). Đơn không được tự huỷ trước hạn này:
     * đối tác cần thời gian đối chiếu app ngân hàng, và khách đã báo "đã chuyển khoản" thì được giữ lâu hơn (xem claim()).
     */
    public static function manualHoldUntil(int|string $orderId): ?Carbon
    {
        $until = SePayPaymentLink::query()->where('order_id', $orderId)->where('status', SePayPaymentLink::PENDING)
            ->whereHas('account', fn ($q) => $q->where('provider', PartnerSePayAccount::PROVIDER_MANUAL))
            ->max('expires_at');

        return $until ? Carbon::parse($until) : null;
    }

    /**
     * Khách bấm "Tôi đã chuyển khoản" ở yêu cầu của đối tác xác nhận thủ công: ghi lúc báo (một lần), giữ đơn thêm sepay.manual_claimed_hold_minutes phút
     * và báo cho đối tác đối chiếu. Yêu cầu không phải thủ công / đã trả / đã huỷ thì không làm gì. @return bool đã ghi nhận
     */
    public function claim(SePayPaymentLink $link): bool
    {
        $account = $link->account;
        $order = Order::withoutGlobalScopes()->find($link->order_id);

        if (! $account || ! $account->isManual() || $link->status !== SePayPaymentLink::PENDING || ! $order || ! in_array($order->status, ['pending', 'deposit', 'paid'], true)) {
            return false;
        }

        if ($link->customer_claimed_at) {
            return true;
        }

        $hold = now()->addMinutes(max(15, (int) \App\Support\ManualPaymentConfig::get('claimed_hold_minutes', 240)));
        $link->update(['customer_claimed_at' => now(), 'expires_at' => $link->expires_at && $link->expires_at->gt($hold) ? $link->expires_at : $hold]);

        if ($order->status === 'pending') {
            $order->forceFill(['expired_at' => $link->expires_at])->saveQuietly();
        }

        if ($partner = \App\Models\Partner::withTrashed()->find($account->partner_id)) {
            app(\App\Services\PartnerNotifier::class)->send($partner, 'manual_payment_claimed', 'Khách báo đã chuyển khoản — chờ bạn xác nhận',
                'Đơn #' . $order->order_code . ': khách báo đã chuyển ' . number_format((int) $link->amount, 0, ',', '.') . 'đ, nội dung ' . $link->paymentCode()
                . ' vào tài khoản ' . $account->bank_code . ' ' . $account->account_number . '. Kiểm tra app ngân hàng rồi bấm "Xác nhận đã nhận tiền" ở mục Chờ xác nhận chuyển khoản.',
                ['order_code' => $order->order_code, 'link_id' => $link->id], 'warning', false);
        }

        return true;
    }

    /** Tài khoản "xác nhận thủ công" đang bật đã phát hành yêu cầu thanh toán của đơn (chi nhánh nào thì tài khoản của chi nhánh đó). */
    public function manualAccountForOrder(Order $order, ?SePayPaymentLink $link = null): ?PartnerSePayAccount
    {
        $account = $link?->account ?: $this->accountForCategory(PayOsAccountResolver::partnerIdForOrder($order), $order->category_id ? (int) $order->category_id : null);

        return $account && $account->isManual() && $account->is_active ? $account : null;
    }

    /**
     * Yêu cầu thanh toán đang chờ đối tác xác nhận (tài khoản phát hành là "xác nhận thủ công" đang bật, chưa trả/chưa huỷ). $partnerId null = mọi đối tác (Super Admin).
     * Yêu cầu tạo trước khi có tài khoản theo chi nhánh (chưa ghi tài khoản) tính theo tài khoản mặc định của đối tác.
     */
    public function pendingManualLinks(?string $partnerId = null)
    {
        $manual = PartnerSePayAccount::query()->where('provider', PartnerSePayAccount::PROVIDER_MANUAL)->where('is_active', true)
            ->when($partnerId, fn ($q) => $q->where('partner_id', $partnerId))->get(['id', 'partner_id', 'category_id']);
        $legacyPartnerIds = $manual->whereNull('category_id')->pluck('partner_id');

        // Chỉ khoản còn nhận được tiền: chưa hết hạn và đơn còn ở trạng thái nhận thanh toán (đơn đã tự huỷ / bị huỷ thì không hiện để xác nhận nhầm).
        return SePayPaymentLink::query()->where('status', SePayPaymentLink::PENDING)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('order', fn ($o) => $o->withoutGlobalScopes()->whereIn('status', ['pending', 'deposit', 'paid']))
            ->where(fn ($q) => $q->whereIn('partner_sepay_account_id', $manual->pluck('id'))
                ->orWhere(fn ($legacy) => $legacy->whereNull('partner_sepay_account_id')
                    ->whereHas('order', fn ($o) => $o->withoutGlobalScopes()->whereIn('partner_id', $legacyPartnerIds))));
    }

    /**
     * Đối tác xác nhận đã nhận đủ tiền chuyển khoản cho 1 yêu cầu thanh toán → xác nhận đơn (cùng luồng với webhook: cọc / thanh toán đủ / phần còn lại /
     * phát sinh, gán mã cổng, thông báo khách). Lưu 1 dòng nhật ký (provider = manual) ghi ai xác nhận, lúc nào, ghi chú.
     *
     * @return array{status: string, reason: ?string, order: ?Order}  status: processed | rejected | failed
     */
    public function confirmManually(SePayPaymentLink $link, ?string $confirmedBy, ?string $note = null): array
    {
        $order = Order::withoutGlobalScopes()->with('items')->find($link->order_id);
        $account = $order ? $this->manualAccountForOrder($order, $link) : null;

        $failure = match (true) {
            ! $order => 'Không tìm thấy đơn của yêu cầu thanh toán.',
            ! $account => 'Đối tác của đơn không dùng hình thức xác nhận thủ công.',
            default => null,
        };

        if ($failure) {
            return ['status' => 'rejected', 'reason' => $failure, 'order' => $order];
        }

        try {
            $reason = DB::transaction(function () use ($link, $order, $account, $confirmedBy, $note): ?string {
                $transaction = SePayTransaction::query()->create([
                    'provider' => PartnerSePayAccount::PROVIDER_MANUAL, 'sepay_id' => $link->id, 'partner_sepay_account_id' => $account->id,
                    'order_id' => $order->id, 'payment_code' => $link->paymentCode(), 'amount' => (int) $link->amount, 'transaction_at' => now(),
                    'status' => 'received', 'payload' => ['source' => 'manual', 'confirmed_by' => $confirmedBy, 'note' => $note],
                ]);

                $rejected = $this->applyVerifiedPayment($transaction, $link, $order);

                // Bị từ chối thì không giữ dòng nhật ký (giữ unique (provider, sepay_id) cho lần xác nhận đúng sau này).
                if ($rejected) {
                    $transaction->delete();
                }

                return $rejected;
            });
        } catch (QueryException $e) {
            return ['status' => 'rejected', 'reason' => 'Yêu cầu thanh toán này đã được xác nhận trước đó.', 'order' => $order];
        } catch (\Throwable $e) {
            Log::error('Đối tác xác nhận thủ công lỗi', ['link_id' => $link->id, 'error' => $e->getMessage()]);

            return ['status' => 'failed', 'reason' => $e->getMessage(), 'order' => $order];
        }

        return $reason
            ? ['status' => 'rejected', 'reason' => $reason, 'order' => $order]
            : ['status' => 'processed', 'reason' => null, 'order' => $order->fresh()];
    }

    /**
     * Tài khoản SePay của đối tác theo giao dịch tiền vào. Tiền có thể về tài khoản chính (accountNumber) HOẶC tài khoản ảo/VA (subAccount): ngân hàng
     * chỉ nhận qua VA (BIDV, OCB, MSB, KienLongBank...) và nhiều tài khoản doanh nghiệp báo SePay số chính ở accountNumber và số VA ở subAccount.
     * CMS lưu số nào (số chính hoặc số VA khách chuyển tới) cũng nhận đúng; ưu tiên số VA khi có.
     */
    private function findAccount(string $accountNumber, string $subAccount): ?PartnerSePayAccount
    {
        $numbers = array_values(array_unique(array_filter([trim($subAccount), trim($accountNumber)])));

        foreach ($numbers as $number) {
            if ($account = PartnerSePayAccount::query()->where('account_number', $number)->first()) {
                return $account;
            }
        }

        return null;
    }

    private function validatePayment(?PartnerSePayAccount $account, ?SePayPaymentLink $link, ?Order $order, int $amount, string $provider = PartnerSePayAccount::PROVIDER_SEPAY): ?string
    {
        if (! $account) {
            return 'Số tài khoản nhận tiền chưa được khai báo ở CMS → SePay / Pay2S theo đối tác.';
        }
        if (! $account->is_active) {
            return 'Tài khoản ' . $this->providerLabel($provider) . ' của đối tác đang tắt.';
        }
        if (($account->provider ?: PartnerSePayAccount::PROVIDER_SEPAY) !== $provider) {
            return 'Tài khoản này đang khai báo cổng ' . $this->providerLabel((string) $account->provider) . ', nhưng giao dịch đến từ ' . $this->providerLabel($provider) . ' — đối soát thủ công.';
        }
        if (! $link) {
            return 'Không tìm thấy yêu cầu thanh toán theo mã trong nội dung chuyển khoản.';
        }
        if (! $order) {
            return 'Không tìm thấy đơn của yêu cầu thanh toán.';
        }
        if (PayOsAccountResolver::partnerIdForOrder($order) !== (string) $account->partner_id) {
            return 'Tài khoản nhận tiền không thuộc đối tác của đơn.';
        }
        // Đối tác có nhiều tài khoản (mỗi chi nhánh một): tiền phải về ĐÚNG tài khoản đã phát hành cho yêu cầu này, không phải tài khoản chi nhánh khác.
        if ($link->partner_sepay_account_id && (int) $link->partner_sepay_account_id !== (int) $account->id) {
            return 'Tiền chuyển vào tài khoản ' . $account->account_number . ' nhưng yêu cầu thanh toán này thuộc tài khoản khác của đối tác (chi nhánh khác) — đối soát/hoàn tiền thủ công.';
        }
        if ($amount !== (int) $link->amount) {
            return "Số tiền không khớp; cần {$link->amount}, nhận {$amount}.";
        }

        return $this->validateState($link, $order);
    }

    /** Trạng thái yêu cầu thanh toán + đơn có còn cho phép nhận khoản này không. null = hợp lệ. */
    private function validateState(SePayPaymentLink $link, Order $order): ?string
    {
        if ($link->status === SePayPaymentLink::PAID) {
            return 'Yêu cầu thanh toán này đã được thanh toán trước đó (chuyển trùng) — cần hoàn tiền thủ công.';
        }
        if ($link->status === SePayPaymentLink::CANCELLED) {
            return 'Yêu cầu thanh toán đã bị huỷ (đơn đổi giá hoặc tạo lại mã) — đối soát/hoàn tiền thủ công.';
        }

        $kind = $this->kindOf($order, $link);
        $status = (string) $order->status;

        return match ($kind) {
            'initial' => $status === 'pending' ? null : "Đơn đang ở trạng thái \"{$status}\", không nhận thanh toán đầu — đối soát/hoàn tiền thủ công.",
            'deposit_retry' => in_array($status, ['pending', 'deposit'], true) ? null : "Đơn đang ở trạng thái \"{$status}\", không nhận cọc lại — đối soát/hoàn tiền thủ công.",
            'remaining' => $status === 'deposit' ? null : "Đơn đang ở trạng thái \"{$status}\", không nhận phần còn lại — đối soát/hoàn tiền thủ công.",
            'extra' => (int) ($order->extra_charge_amount ?? 0) > 0 && $order->extra_charge_paid_at === null ? null : 'Khoản phát sinh không còn chờ thanh toán — đối soát/hoàn tiền thủ công.',
            default => 'Mã thanh toán không còn gắn với đơn (đơn đã tạo mã mới) — đối soát thủ công.',
        };
    }

    // ───────────────────────── Kéo giao dịch từ API SePay (không cần webhook) ─────────────────────────

    /**
     * Thử gọi API SePay bằng token đang dùng (nút "Kiểm tra kết nối" ở CMS).
     *
     * @return array{ok: bool, message: string}
     */
    public function testApi(): array
    {
        if (! $this->canPull()) {
            return ['ok' => false, 'message' => 'Chưa nhập API Token SePay.'];
        }

        try {
            $response = Http::withToken((string) SePayConfig::get('api_token'))->acceptJson()->timeout(10)
                ->get(rtrim((string) SePayConfig::get('api_url', 'https://my.sepay.vn'), '/') . '/userapi/transactions/list', ['limit' => 1]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Không kết nối được tới SePay: ' . $e->getMessage()];
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return ['ok' => false, 'message' => 'SePay từ chối API Token (sai hoặc đã bị thu hồi). Tạo token mới ở SePay → Cấu hình Công ty → API Access.'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => 'SePay trả lỗi HTTP ' . $response->status() . ': ' . Str::limit($response->body(), 200)];
        }

        return ['ok' => true, 'message' => 'Kết nối API SePay thành công.'];
    }

    public function canPull(): bool
    {
        return filled(SePayConfig::get('api_token'));
    }

    /**
     * Hỏi SePay các giao dịch tiền vào gần đây của tài khoản đối tác rồi xử lý như webhook. $link != null: chỉ tài khoản của đơn đó, đúng số tiền
     * của yêu cầu, và chỉ gọi tối đa 1 lần / vài giây cho mỗi yêu cầu (trang QR hỏi liên tục). Trả số giao dịch đã xác nhận đơn.
     */
    public function pull(?SePayPaymentLink $link = null, int $hours = 24): int
    {
        // Đối tác tự xác nhận: không có giao dịch nào để hỏi cổng.
        if ($link && $this->providerOfLink($link) === PartnerSePayAccount::PROVIDER_MANUAL) {
            return 0;
        }

        // Yêu cầu của đối tác dùng Pay2S: hỏi API Pay2S (cùng bộ kiểm tra handleIncoming).
        if ($link && $this->providerOfLink($link) === PartnerSePayAccount::PROVIDER_PAY2S) {
            return app(Pay2SService::class)->pull($link, $hours);
        }

        if (! $this->canPull()) {
            return 0;
        }

        // MỘT lần gọi cho cả công ty SePay, KHÔNG lọc theo số tài khoản: tiền qua tài khoản ảo (VA) mang số tài khoản chính của ngân hàng nên lọc theo số VA
        // sẽ không thấy. Việc khớp đúng tài khoản đối tác do handleIncoming() làm (số chính hoặc số VA).
        $query = ['transaction_date_min' => now()->subHours($hours)->format('Y-m-d H:i:s'), 'limit' => 1000];

        if ($link) {
            if (! Cache::add('sepay:pull:' . $link->code, 1, now()->addSeconds((int) SePayConfig::get('pull_throttle_seconds', 3)))) {
                return 0;
            }

            $query['amount_in'] = (int) $link->amount;
        }

        try {
            $response = Http::withToken((string) SePayConfig::get('api_token'))->acceptJson()->timeout(10)
                ->get(rtrim((string) SePayConfig::get('api_url', 'https://my.sepay.vn'), '/') . '/userapi/transactions/list', $query);
        } catch (\Throwable $e) {
            Log::warning('SePay kéo giao dịch lỗi mạng', ['error' => $e->getMessage()]);

            return 0;
        }

        if (! $response->successful()) {
            Log::warning('SePay kéo giao dịch thất bại', ['status' => $response->status(), 'body' => Str::limit($response->body(), 300)]);

            return 0;
        }

        $confirmed = 0;

        foreach ((array) $response->json('transactions', []) as $tx) {
            if ((float) ($tx['amount_in'] ?? 0) <= 0) {
                continue;
            }

            $payload = [
                'id' => (int) $tx['id'], 'accountNumber' => (string) ($tx['account_number'] ?? ''), 'subAccount' => $tx['sub_account'] ?? null,
                'transferAmount' => (int) round((float) $tx['amount_in']),
                'transactionDate' => $tx['transaction_date'] ?? null, 'referenceCode' => $tx['reference_number'] ?? null,
                'code' => $tx['code'] ?? null, 'content' => $tx['transaction_content'] ?? null,
            ];

            // Giao dịch không có mã thanh toán của hệ thống là tiền không liên quan (không ghi vào nhật ký đối soát).
            if ($this->extractCode($payload) === null) {
                continue;
            }

            if ($this->handleIncoming($payload, ['source' => 'sepay_api'] + $tx)['status'] === 'processed') {
                $confirmed++;
            }
        }

        return $confirmed;
    }
}
