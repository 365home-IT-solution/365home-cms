<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentGatewaySettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Cấu hình CHUNG của SePay / Pay2S qua API — cùng chức năng và cùng bộ chuẩn hoá với hai trang CMS "SePay — cấu hình chung", "Pay2S — cấu hình chung".
// Chỉ Super Admin. Khoá bí mật và token chỉ GHI, không bao giờ trả ra (chỉ báo đã có hay chưa và nguồn: cms | env | none).
class PaymentGatewayController extends Controller
{
    public function __construct(private readonly PaymentGatewaySettingsService $gateways)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeSuperAdmin($request);

        return response()->json(['data' => array_values($this->gateways->all())]);
    }

    public function show(Request $request, string $provider): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $this->provider($provider);

        return response()->json(['data' => $this->gateways->status($provider)]);
    }

    public function update(Request $request, string $provider): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $this->provider($provider);

        $rules = ['enabled' => ['sometimes', 'boolean'], 'api_token' => ['nullable', 'string', 'max:' . ($provider === 'pay2s' ? 1000 : 500)]];

        if ($provider === 'sepay') {
            $rules += [
                'webhook_secret' => ['nullable', 'string', 'min:16', 'max:200'],
                'generate_webhook_secret' => ['sometimes', 'boolean'],
                'code_prefix' => ['nullable', 'string', 'min:2', 'max:5', 'regex:/^[A-Za-z0-9]+$/'],
                'min_amount' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            ];
        }

        $input = $request->validate($rules);
        $result = $this->gateways->update($provider, $input);
        $label = $provider === 'pay2s' ? 'Pay2S' : 'SePay';
        $status = $this->gateways->status($provider);

        return response()->json([
            'message' => "Đã lưu cấu hình {$label} — {$label} đang " . ($status['enabled'] ? 'BẬT' : 'TẮT') . '.',
            'data' => $status,
            // Khoá vừa tạo (generate_webhook_secret): chỉ trả MỘT lần để chép sang SePay, sau đó không xem lại được.
            'generated_webhook_secret' => $result['generated_webhook_secret'],
        ]);
    }

    public function testConnection(Request $request, string $provider): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $this->provider($provider);

        $result = $this->gateways->testConnection($provider);

        return response()->json(['ok' => $result['ok'], 'message' => $result['message']]);
    }

    private function provider(string $provider): void
    {
        abort_unless(in_array($provider, PaymentGatewaySettingsService::PROVIDERS, true), 404);
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403, 'Chỉ Super Admin được xem hoặc sửa cấu hình cổng thanh toán.');
    }
}
