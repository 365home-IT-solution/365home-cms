<?php

namespace App\Services\Payment;

use App\Settings\Pay2SSettings;
use App\Settings\SePaySettings;
use App\Support\Pay2SConfig;
use App\Support\SePayConfig;
use Illuminate\Support\Str;
use Modules\Payment\Entities\PartnerSePayAccount;
use Spatie\LaravelSettings\Settings;

// Cấu hình CHUNG của hai cổng chuyển khoản tự động (SePay, Pay2S). Một nguồn duy nhất cho trang CMS ("SePay — cấu hình chung", "Pay2S — cấu hình chung")
// và API admin (/api/admin/payment-gateways): cùng cách chuẩn hoá dữ liệu, cùng quy tắc "khoá bí mật để trống = giữ nguyên", cùng cách báo nguồn giá trị.
// Khoá bí mật và token KHÔNG bao giờ được trả ra ngoài — chỉ báo đã có hay chưa.
class PaymentGatewaySettingsService
{
    public const PROVIDERS = [PartnerSePayAccount::PROVIDER_SEPAY, PartnerSePayAccount::PROVIDER_PAY2S];

    public function settings(string $provider): Settings
    {
        return $provider === PartnerSePayAccount::PROVIDER_PAY2S ? app(Pay2SSettings::class) : app(SePaySettings::class);
    }

    private function forget(string $provider): void
    {
        app()->forgetInstance($provider === PartnerSePayAccount::PROVIDER_PAY2S ? Pay2SSettings::class : SePaySettings::class);
    }

    /**
     * Dữ liệu form/API → giá trị ghi vào cài đặt. Khoá bí mật và token để trống = giữ nguyên giá trị đang lưu (giá trị đã lưu không hiện lại nên không thể "lưu lại nguyên").
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function normalize(string $provider, array $data, Settings $current): array
    {
        $data['enabled'] = (bool) ($data['enabled'] ?? false);
        $data['api_token'] = filled($data['api_token'] ?? null) ? trim((string) $data['api_token']) : $current->api_token;

        if ($provider === PartnerSePayAccount::PROVIDER_PAY2S) {
            return array_intersect_key($data, array_flip(['enabled', 'api_token']));
        }

        $data['webhook_secret'] = filled($data['webhook_secret'] ?? null) ? trim((string) $data['webhook_secret']) : $current->webhook_secret;
        $data['code_prefix'] = filled($data['code_prefix'] ?? null) ? Str::upper(trim((string) $data['code_prefix'])) : null;
        $data['min_amount'] = filled($data['min_amount'] ?? null) ? (int) $data['min_amount'] : null;

        return array_intersect_key($data, array_flip(['enabled', 'webhook_secret', 'code_prefix', 'api_token', 'min_amount']));
    }

    /**
     * Cập nhật từng phần cho API: khoá nào không gửi thì giữ nguyên. Gửi null/rỗng cho tiền tố hoặc số tiền tối thiểu = bỏ giá trị CMS, quay về .env.
     * generate_webhook_secret = true (chỉ SePay): tạo khoá ngẫu nhiên, trả về MỘT LẦN để chép sang SePay.
     *
     * @param array<string,mixed> $input
     * @return array{generated_webhook_secret: ?string}
     */
    public function update(string $provider, array $input): array
    {
        $settings = $this->settings($provider);
        $generated = null;

        if ($provider === PartnerSePayAccount::PROVIDER_SEPAY && ! empty($input['generate_webhook_secret'])) {
            $generated = Str::random(40);
            $input['webhook_secret'] = $generated;
        }

        $data = [
            'enabled' => $input['enabled'] ?? $settings->enabled,
            'api_token' => $input['api_token'] ?? null,
        ];

        if ($provider === PartnerSePayAccount::PROVIDER_SEPAY) {
            $data += [
                'webhook_secret' => $input['webhook_secret'] ?? null,
                'code_prefix' => array_key_exists('code_prefix', $input) ? $input['code_prefix'] : $settings->code_prefix,
                'min_amount' => array_key_exists('min_amount', $input) ? $input['min_amount'] : $settings->min_amount,
            ];
        }

        $normalized = $this->normalize($provider, $data, $settings);

        // Không gửi enabled = giữ nguyên giá trị đã lưu (null = chưa chỉnh ở CMS, theo .env), không ép thành false.
        if (! array_key_exists('enabled', $input)) {
            $normalized['enabled'] = $settings->enabled;
        }

        $settings->fill($normalized)->save();
        $this->forget($provider);

        return ['generated_webhook_secret' => $generated];
    }

    /** @return array<string,mixed> */
    public function status(string $provider): array
    {
        return $provider === PartnerSePayAccount::PROVIDER_PAY2S ? $this->pay2sStatus() : $this->sepayStatus();
    }

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        return ['sepay' => $this->sepayStatus(), 'pay2s' => $this->pay2sStatus()];
    }

    private function sepayStatus(): array
    {
        return [
            'provider' => 'sepay',
            'label' => 'SePay',
            'enabled' => SePayConfig::enabled(),
            'enabled_source' => SePayConfig::source('enabled'),
            'webhook_url' => SePayConfig::get('webhook_url') ?: route('webhook.sepay'),
            'webhook_secret' => ['configured' => filled(SePayConfig::get('webhook_secret')), 'source' => SePayConfig::source('webhook_secret')],
            'code_prefix' => ['value' => Str::upper((string) SePayConfig::get('code_prefix', 'DH')), 'source' => SePayConfig::source('code_prefix')],
            'api_token' => ['configured' => filled(SePayConfig::get('api_token')), 'source' => SePayConfig::source('api_token')],
            'min_amount' => ['value' => max(1, (int) SePayConfig::get('min_amount', 1000)), 'source' => SePayConfig::source('min_amount')],
            'can_pull' => app(SePayService::class)->canPull(),
            'accounts' => $this->accountCounts(PartnerSePayAccount::PROVIDER_SEPAY),
        ];
    }

    private function pay2sStatus(): array
    {
        return [
            'provider' => 'pay2s',
            'label' => 'Pay2S',
            'enabled' => Pay2SConfig::enabled(),
            'enabled_source' => Pay2SConfig::source('enabled'),
            'webhook_url' => Pay2SConfig::get('webhook_url') ?: route('webhook.pay2s'),
            'api_token' => ['configured' => filled(Pay2SConfig::get('api_token')), 'source' => Pay2SConfig::source('api_token')],
            'can_pull' => app(Pay2SService::class)->canPull(),
            'accounts' => $this->accountCounts(PartnerSePayAccount::PROVIDER_PAY2S),
        ];
    }

    /** Số tài khoản đối tác đang dùng cổng; Pay2S cần token webhook riêng cho từng tài khoản nên báo số tài khoản còn thiếu token. */
    private function accountCounts(string $provider): array
    {
        $query = PartnerSePayAccount::query()->where('provider', $provider);
        $counts = ['total' => (clone $query)->count(), 'active' => (clone $query)->where('is_active', true)->count()];

        if ($provider === PartnerSePayAccount::PROVIDER_PAY2S) {
            $counts['missing_webhook_token'] = (clone $query)->where(fn ($q) => $q->whereNull('webhook_token')->orWhere('webhook_token', ''))->count();
        }

        return $counts;
    }

    /** @return array{ok: bool, message: string} */
    public function testConnection(string $provider): array
    {
        return $provider === PartnerSePayAccount::PROVIDER_PAY2S ? app(Pay2SService::class)->testApi() : app(SePayService::class)->testApi();
    }
}
