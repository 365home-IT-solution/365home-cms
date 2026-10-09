<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\CameraSetting;
use App\Services\Camera\CameraOptions;
use App\Services\Camera\CameraSettingsTester;
use App\Services\Camera\Cloud\CloudProviderRegistry;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;

// Form cấu hình camera dùng chung cho trang Home (theo đối tác) và MiniHouse (theo toà nhà): chọn gateway
// rồi chỉ hiện đúng các ô của gateway đó (Frigate, go2rtc trần, hoặc tài khoản developer của hãng cloud).
class CameraGatewayForm
{
    /** @return list<Forms\Components\Component> */
    public static function schema(): array
    {
        $isGateway = fn (string $type): \Closure => fn (Get $get): bool => ($get('gateway_type') ?: CameraSetting::GATEWAY_FRIGATE) === $type;
        $isGo2RtcBacked = fn (Get $get): bool => in_array($get('gateway_type') ?: CameraSetting::GATEWAY_FRIGATE, [CameraSetting::GATEWAY_FRIGATE, CameraSetting::GATEWAY_GO2RTC], true);

        return [
            Forms\Components\Select::make('gateway_type')
                ->label('Cách lấy luồng camera')
                ->options(CameraOptions::GATEWAY_LABELS)
                ->default(CameraSetting::GATEWAY_FRIGATE)
                ->live()
                ->required()
                ->native(false),

            Forms\Components\Section::make('Server go2rtc / Frigate')
                ->schema([
                    Forms\Components\TextInput::make('base_url')
                        ->label('Địa chỉ Frigate')
                        ->placeholder('https://192.168.1.10:8971')
                        ->url()
                        ->visible($isGateway(CameraSetting::GATEWAY_FRIGATE))
                        ->required($isGateway(CameraSetting::GATEWAY_FRIGATE))
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('go2rtc_url')
                        ->label('go2rtc URL')
                        ->placeholder('http://192.168.1.10:1984')
                        ->url()
                        ->required($isGateway(CameraSetting::GATEWAY_GO2RTC))
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('api_key')
                        ->label('API key go2rtc')
                        ->password()
                        ->revealable()
                        ->columnSpanFull(),
                ])
                ->visible($isGo2RtcBacked),

            Forms\Components\Section::make('Tài khoản đăng nhập Frigate')
                ->schema([
                    Forms\Components\TextInput::make('username')->label('Tài khoản Frigate'),
                    Forms\Components\TextInput::make('password')->label('Mật khẩu Frigate')->password()->revealable(),
                ])
                ->columns(2)
                ->visible($isGateway(CameraSetting::GATEWAY_FRIGATE)),

            // Luôn hiện (thu gọn khi gateway không phải cloud): đối tác dùng Frigate vẫn có thể có vài
            // camera cloud riêng lẻ (source_type = cloud) cần tài khoản developer của hãng.
            Forms\Components\Section::make('Tài khoản developer của hãng')
                ->schema(self::providerFieldsets())
                ->collapsible()
                ->collapsed(fn (Get $get): bool => ! $isGateway(CameraSetting::GATEWAY_CLOUD)($get)),
        ];
    }

    /** @return list<Forms\Components\Fieldset> */
    private static function providerFieldsets(): array
    {
        $fieldsets = [];

        foreach (app(CloudProviderRegistry::class)->all() as $key => $provider) {
            $inputs = [];

            foreach ($provider->credentialFields() as $field) {
                $name = "provider_credentials.{$key}.{$field['key']}";

                if ($field['options'] !== null) {
                    $input = Forms\Components\Select::make($name)->options(array_combine($field['options'], $field['options']))->native(false);
                } else {
                    $input = Forms\Components\TextInput::make($name);
                }

                $input->label($field['label']);

                if ($field['secret']) {
                    $input->password()->revealable()->placeholder('Để trống để giữ giá trị đã lưu');
                }

                if ($field['default'] !== null) {
                    $input->default($field['default']);
                }

                $inputs[] = $input;
            }

            $fieldsets[] = Forms\Components\Fieldset::make($provider->label())->schema($inputs)->columns(3);
        }

        return $fieldsets;
    }

    /**
     * Dữ liệu nạp vào form: trường Frigate/go2rtc như cũ, tài khoản hãng chỉ nạp phần không bí mật.
     *
     * @return array<string, mixed>
     */
    public static function fill(CameraSetting $settings): array
    {
        $providers = [];

        foreach (app(CloudProviderRegistry::class)->all() as $key => $provider) {
            $stored = $settings->providerCredentials($key);

            foreach ($provider->credentialFields() as $field) {
                if (! $field['secret'] && isset($stored[$field['key']])) {
                    $providers[$key][$field['key']] = $stored[$field['key']];
                }
            }
        }

        return [
            ...$settings->only(['base_url', 'go2rtc_url', 'api_key', 'username', 'password']),
            'gateway_type' => $settings->gatewayType(),
            'provider_credentials' => $providers,
        ];
    }

    /**
     * Ghi dữ liệu form vào $settings (chưa save). Trường bí mật của hãng để trống = giữ giá trị cũ.
     *
     * @param  array<string, mixed>  $data
     */
    public static function apply(CameraSetting $settings, array $data): void
    {
        $credentials = $data['provider_credentials'] ?? [];
        unset($data['provider_credentials']);

        $settings->fill($data);

        $registry = app(CloudProviderRegistry::class);

        foreach ((array) $credentials as $key => $values) {
            $provider = $registry->get((string) $key);

            if ($provider !== null && is_array($values)) {
                $settings->mergeProviderCredentials($key, $values, $registry->secretKeys($provider));
            }
        }
    }

    public static function notifyTestResult(CameraSetting $settings): void
    {
        $result = app(CameraSettingsTester::class)->run($settings);

        Notification::make()
            ->title($result['success'] ? 'Kết nối thành công' : 'Kết nối chưa đạt')
            ->body(collect($result['checks'])->map(fn (array $check): string => ($check['ok'] ? '✓ ' : '✗ ').$check['message'])->implode("\n"))
            ->status($result['success'] ? 'success' : 'warning')
            ->send();
    }
}
