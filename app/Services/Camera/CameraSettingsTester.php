<?php

declare(strict_types=1);

namespace App\Services\Camera;

use App\Models\CameraSetting;
use App\Services\Camera\Cloud\CloudProviderException;
use App\Services\Camera\Cloud\CloudProviderRegistry;
use App\Services\FrigateSessionClient;
use App\Services\Go2RtcClient;

// Kiểm tra cấu hình đã lưu của 1 đối tác/toà nhà có kết nối được không — mỗi gateway kiểm tra khác nhau.
// Dùng cho nút "Kiểm tra kết nối" ở trang cấu hình và API camera-settings/test.
class CameraSettingsTester
{
    public function __construct(private readonly CloudProviderRegistry $registry) {}

    /**
     * @return array{success: bool, gateway_type: string, checks: list<array{name: string, ok: bool, message: string}>}
     */
    public function run(CameraSetting $settings): array
    {
        $gateway = $settings->gatewayType();
        $checks = [];

        if ($gateway === CameraSetting::GATEWAY_FRIGATE) {
            $checks[] = $this->frigateLogin($settings);

            if ($settings->isGo2RtcConfigured()) {
                $checks[] = $this->go2rtc($settings);
            }
        } elseif ($gateway === CameraSetting::GATEWAY_GO2RTC) {
            $checks[] = $this->go2rtc($settings);
        } else {
            array_push($checks, ...$this->cloudProviders($settings));
        }

        return [
            'success' => $checks !== [] && collect($checks)->every(fn (array $check): bool => $check['ok']),
            'gateway_type' => $gateway,
            'checks' => $checks,
        ];
    }

    /** @return array{name: string, ok: bool, message: string} */
    private function frigateLogin(CameraSetting $settings): array
    {
        if (! $settings->isConfigured() || ! $settings->hasFrigateCredentials()) {
            return ['name' => 'frigate_login', 'ok' => false, 'message' => 'Chưa nhập địa chỉ server và tài khoản/mật khẩu Frigate.'];
        }

        $cookie = (new FrigateSessionClient($settings))->getSessionCookie(forceRelogin: true, error: $error);

        return $cookie !== null
            ? ['name' => 'frigate_login', 'ok' => true, 'message' => 'Đăng nhập Frigate thành công.']
            : ['name' => 'frigate_login', 'ok' => false, 'message' => (string) $error];
    }

    /** @return array{name: string, ok: bool, message: string} */
    private function go2rtc(CameraSetting $settings): array
    {
        $result = (new Go2RtcClient($settings))->ping();

        return ['name' => 'go2rtc', 'ok' => $result['ok'], 'message' => $result['message']];
    }

    /** @return list<array{name: string, ok: bool, message: string}> */
    private function cloudProviders(CameraSetting $settings): array
    {
        $checks = [];

        foreach ($this->registry->all() as $key => $provider) {
            $credentials = $settings->providerCredentials($key);

            if ($credentials === []) {
                continue;
            }

            $name = 'cloud_'.$key;

            if (! $this->registry->isComplete($provider, $credentials)) {
                $checks[] = ['name' => $name, 'ok' => false, 'message' => "Chưa nhập đủ thông tin tài khoản {$provider->label()}."];

                continue;
            }

            try {
                $provider->verifyCredentials($credentials);
                $checks[] = ['name' => $name, 'ok' => true, 'message' => "Đăng nhập {$provider->label()} thành công."];
            } catch (CloudProviderException $e) {
                $checks[] = ['name' => $name, 'ok' => false, 'message' => $e->getMessage()];
            }
        }

        return $checks === []
            ? [['name' => 'cloud', 'ok' => false, 'message' => 'Chưa cấu hình tài khoản developer của hãng nào.']]
            : $checks;
    }
}
