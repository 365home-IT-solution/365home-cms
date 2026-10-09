<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Models\CameraSetting;
use App\Services\Camera\Cloud\CloudProviderRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Dùng chung cho CameraSettingsController của Home và MiniHouse: kiểu gateway + tài khoản developer của
// hãng camera cloud. Bí mật (App Secret) chỉ ghi, không bao giờ trả về qua API.
trait HandlesCameraSettings
{
    /** @return array<string, mixed> */
    protected function gatewayRules(): array
    {
        $registry = app(CloudProviderRegistry::class);

        $rules = [
            'gateway_type' => ['sometimes', Rule::in(CameraSetting::GATEWAYS)],
            'provider_credentials' => [
                'sometimes', 'nullable', 'array',
                function (string $attribute, mixed $value, \Closure $fail) use ($registry): void {
                    $unknown = array_diff(array_keys((array) $value), $registry->keys());

                    if ($unknown !== []) {
                        $fail('Hãng không hỗ trợ: '.implode(', ', $unknown).'.');
                    }
                },
            ],
        ];

        foreach ($registry->all() as $key => $provider) {
            $rules["provider_credentials.{$key}"] = ['nullable', 'array'];

            foreach ($provider->credentialFields() as $field) {
                $fieldRules = ['nullable', 'string', 'max:255'];

                if ($field['options'] !== null) {
                    $fieldRules[] = Rule::in($field['options']);
                }

                $rules["provider_credentials.{$key}.{$field['key']}"] = $fieldRules;
            }
        }

        return $rules;
    }

    /** @param array<string, mixed> $data dữ liệu đã validate bằng gatewayRules() */
    protected function applyGatewaySettings(CameraSetting $settings, Request $request, array $data): void
    {
        if ($request->has('gateway_type')) {
            $settings->gateway_type = $data['gateway_type'];
        }

        if (! $request->has('provider_credentials')) {
            return;
        }

        $registry = app(CloudProviderRegistry::class);
        $credentials = $data['provider_credentials'] ?? null;

        if ($credentials === null) {
            $settings->provider_credentials = null;

            return;
        }

        foreach ($credentials as $key => $values) {
            $provider = $registry->get((string) $key);

            if ($provider !== null) {
                $settings->mergeProviderCredentials($key, $values, $registry->secretKeys($provider));
            }
        }
    }

    /** @return array<string, mixed> */
    protected function gatewaySettingsFields(CameraSetting $settings): array
    {
        $registry = app(CloudProviderRegistry::class);
        $providers = [];

        foreach ($registry->all() as $key => $provider) {
            $stored = $settings->providerCredentials($key);
            $values = [];
            $hasSecrets = [];

            foreach ($provider->credentialFields() as $field) {
                if ($field['secret']) {
                    $hasSecrets[$field['key']] = filled($stored[$field['key']] ?? null);
                } else {
                    $values[$field['key']] = $stored[$field['key']] ?? $field['default'];
                }
            }

            $providers[$key] = [
                'configured' => $stored !== [] && $registry->isComplete($provider, $stored),
                'values' => $values,
                'has_secrets' => $hasSecrets,
            ];
        }

        return [
            'gateway_type' => $settings->gatewayType(),
            'providers' => $providers,
        ];
    }
}
