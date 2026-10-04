<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Partner;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

// Điều khoản dịch vụ có phiên bản + lịch sử đồng ý khi đăng ký (Homestay đăng ký đối tác, MiniHouse mua gói).
class TermsService
{
    public const ACCEPT_MESSAGES = [
        'accept_terms.required' => 'Bạn cần đọc và đồng ý Điều khoản dịch vụ để đăng ký.',
        'accept_terms.accepted' => 'Bạn cần đọc và đồng ý Điều khoản dịch vụ để đăng ký.',
    ];

    // Rule dùng chung cho các API đăng ký: tick đồng ý bắt buộc; terms_version_id (phiên bản khách đã xem) tuỳ chọn nhưng nếu gửi phải còn là bản hiệu lực.
    public static function acceptRules(): array
    {
        return ['accept_terms' => ['required', 'accepted'], 'terms_version_id' => ['nullable', 'integer']];
    }

    // Rule theo công tắc: loại đang TẮT thì không đòi tick (đăng ký chạy như trước, không ghi lịch sử).
    public static function rulesFor(string $type): array
    {
        return app(self::class)->required($type) ? self::acceptRules() : ['accept_terms' => ['nullable'], 'terms_version_id' => ['nullable', 'integer']];
    }

    // Loại Điều khoản này có đang BẮT BUỘC đồng ý khi đăng ký không (công tắc trong Filament, bảng terms_switches).
    // Chưa có dòng/chưa migrate: Homestay tắt, MiniHouse bật.
    public function required(string $type): bool
    {
        try {
            $row = \Illuminate\Support\Facades\DB::table('terms_switches')->where('type', $type)->value('required');
        } catch (\Throwable) {
            $row = null;
        }

        return $row === null ? $type === TermsVersion::TYPE_MINIHOUSE : (bool) $row;
    }

    public function setRequired(string $type, bool $value): void
    {
        if (! array_key_exists($type, TermsVersion::TYPES)) {
            throw ValidationException::withMessages(['type' => 'Loại Điều khoản không hợp lệ.']);
        }
        \Illuminate\Support\Facades\DB::table('terms_switches')->updateOrInsert(['type' => $type], ['required' => $value, 'updated_at' => now(), 'created_at' => now()]);
    }

    public static function typeForPartner(string $partnerType): string
    {
        return $partnerType === Partner::TYPE_MINIHOUSE ? TermsVersion::TYPE_MINIHOUSE : TermsVersion::TYPE_HOMESTAY;
    }

    // Bản hiệu lực: bản mới nhất đã đến ngày hiệu lực.
    public function current(string $type): ?TermsVersion
    {
        return TermsVersion::query()->where('type', $type)->where('effective_at', '<=', now())->orderByDesc('effective_at')->orderByDesc('id')->first();
    }

    // Trả bản hiệu lực; khách gửi kèm terms_version_id (bản đã xem) mà không còn là bản hiệu lực → bắt đọc lại bản mới.
    public function currentOrFail(string $type, ?int $seenVersionId = null): TermsVersion
    {
        $current = $this->current($type);
        if (! $current) {
            throw ValidationException::withMessages(['accept_terms' => 'Chưa có Điều khoản dịch vụ. Vui lòng liên hệ 365 Home.']);
        }
        if ($seenVersionId !== null && $seenVersionId !== $current->id) {
            throw ValidationException::withMessages(['terms_version_id' => 'Điều khoản đã được cập nhật lên phiên bản ' . $current->version . '. Vui lòng đọc lại và đồng ý trước khi đăng ký.']);
        }

        return $current;
    }

    /**
     * Ghi nhận khách đồng ý. $ctx: plan (SubscriptionPlan), periods, amount_vnd, payment (SubscriptionPayment), source, meta.
     */
    public function record(TermsVersion $terms, ?Partner $partner, array $customer, Request $request, array $ctx = []): TermsAcceptance
    {
        /** @var SubscriptionPlan|null $plan */
        $plan = $ctx['plan'] ?? null;
        /** @var SubscriptionPayment|null $payment */
        $payment = $ctx['payment'] ?? null;

        return TermsAcceptance::create([
            'terms_version_id' => $terms->id, 'type' => $terms->type, 'partner_id' => $partner?->id, 'accepted' => true, 'accepted_at' => now(),
            'terms_version_label' => $terms->version, 'terms_content_hash' => $terms->content_hash,
            'full_name' => $customer['full_name'] ?? null, 'phone' => $customer['phone'] ?? null, 'email' => $customer['email'] ?? null, 'business_name' => $customer['business_name'] ?? null,
            'plan_id' => $plan?->id, 'plan_name' => $plan?->name, 'periods' => $ctx['periods'] ?? null, 'amount_vnd' => $payment?->amount_vnd ?? ($ctx['amount_vnd'] ?? null),
            'payment_id' => $payment?->id, 'order_code' => $payment?->payos_order_code !== null ? (string) $payment->payos_order_code : null, 'transaction_ref' => $payment?->transaction_code,
            'ip' => $request->ip(), 'user_agent' => (string) $request->userAgent(), 'source' => $ctx['source'] ?? 'web', 'meta' => $ctx['meta'] ?? null,
        ]);
    }

    // Đơn thanh toán phát sinh SAU lúc đồng ý (vd MiniHouse được duyệt dùng thử rồi mới mua gói): bổ sung mã đơn/mã giao dịch vào lần đồng ý gần nhất còn trống.
    public function attachPayment(SubscriptionPayment $payment): void
    {
        $row = TermsAcceptance::query()->where('partner_id', $payment->partner_id)->whereNull('payment_id')->latest('id')->first();
        $row?->update([
            'payment_id' => $payment->id, 'amount_vnd' => $payment->amount_vnd,
            'order_code' => $payment->payos_order_code !== null ? (string) $payment->payos_order_code : null, 'transaction_ref' => $payment->transaction_code,
        ]);
    }

    // Tạo phiên bản MỚI (không bao giờ sửa/ghi đè bản cũ). $version null → tự tăng (1.0 → 2.0…).
    public function createVersion(string $type, string $title, string $content, ?string $version = null, ?\DateTimeInterface $effectiveAt = null, ?User $by = null): TermsVersion
    {
        if (! array_key_exists($type, TermsVersion::TYPES)) {
            throw ValidationException::withMessages(['type' => 'Loại Điều khoản không hợp lệ.']);
        }
        $version = filled($version) ? trim($version) : $this->nextVersionLabel($type);
        if (TermsVersion::query()->where('type', $type)->where('version', $version)->exists()) {
            throw ValidationException::withMessages(['version' => "Phiên bản {$version} đã tồn tại — dùng số phiên bản mới."]);
        }

        return TermsVersion::create([
            'type' => $type, 'version' => $version, 'title' => trim($title), 'content' => $content, 'content_hash' => hash('sha256', $content),
            'effective_at' => $effectiveAt ?? now(), 'created_by' => $by?->id,
        ]);
    }

    public function nextVersionLabel(string $type): string
    {
        $max = TermsVersion::query()->where('type', $type)->pluck('version')->map(fn ($v) => (int) $v)->max() ?? 0;

        return ($max + 1) . '.0';
    }
}
