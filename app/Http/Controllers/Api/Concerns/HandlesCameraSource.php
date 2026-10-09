<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Camera;
use App\Services\Camera\Cloud\CloudProviderRegistry;
use Illuminate\Http\JsonResponse;

// Dùng chung cho CameraController của Home và MiniHouse: kiểm tra nguồn camera theo loại kết nối
// (cloud cần hãng + serial thiết bị, các loại khác cần địa chỉ nguồn) và các trường gateway trả về app.
trait HandlesCameraSource
{
    protected function sourceError(?string $sourceType, ?string $provider, ?string $deviceId, ?string $sourceUrl): ?JsonResponse
    {
        if ($sourceType === 'cloud') {
            $registry = app(CloudProviderRegistry::class);
            $errors = [];

            if (! in_array($provider, $registry->keys(), true)) {
                $errors['provider'] = ['Hãng phải là một trong: '.implode(', ', $registry->keys()).' khi chọn kết nối cloud.'];
            }

            if (blank($deviceId)) {
                $errors['external_device_id'] = ['Phải nhập mã/serial thiết bị cho camera cloud.'];
            }

            return $errors === [] ? null : response()->json(['message' => 'Dữ liệu không hợp lệ.', 'errors' => $errors], 422);
        }

        if ($sourceType !== 'existing' && blank($sourceUrl)) {
            return response()->json(['message' => 'Dữ liệu không hợp lệ.', 'errors' => ['source_url' => ['Phải nhập địa chỉ nguồn cho phương thức đã chọn.']]], 422);
        }

        return null;
    }

    /** @return array<string, mixed> */
    protected function gatewayFields(Camera $camera): array
    {
        return [
            'external_channel' => $camera->external_channel,
            'gateway_type' => $camera->gatewayType(),
            'capabilities' => $camera->gatewayCapabilities(),
        ];
    }

    protected function unsupportedResponse(Camera $camera, string $capability): JsonResponse
    {
        return response()->json([
            'message' => 'Camera này ('.$camera->gatewayType().') không hỗ trợ tính năng "'.$capability.'".',
            'code' => 'capability_not_supported',
            'capability' => $capability,
            'gateway_type' => $camera->gatewayType(),
        ], 422);
    }
}
