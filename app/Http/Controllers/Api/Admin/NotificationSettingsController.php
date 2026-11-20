<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Support\AdminNotificationCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Cài đặt nhận thông báo THEO TÀI KHOẢN (cột users.notification_settings):
 *   { enabled: bool, types: { order, payment, chat, stay, approval, partner, subscription, app_error } }
 * `enabled` là công tắc tổng; tắt 1 nhóm trong `types` = không nhận push FCM của nhóm đó (xem
 * AdminNotificationService::notify()). Thông báo VẪN được lưu để xem lại trong
 * GET /api/admin/notifications — FE muốn ẩn khỏi danh sách thì lọc bằng ?exclude_type=.
 */
class NotificationSettingsController extends Controller
{
    /** GET /api/admin/notification-settings */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->notificationSettings()]);
    }

    /**
     * PUT /api/admin/notification-settings
     * Body { enabled?, types?: { <nhóm>: bool } } — field nào không gửi thì giữ nguyên giá trị hiện tại.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'types'   => ['sometimes', 'array'],
            'types.*' => ['boolean'],
        ]);

        // Từ chối key lạ thay vì lặng lẽ bỏ qua — FE gõ sai tên nhóm sẽ biết ngay.
        $unknown = array_diff(array_keys($data['types'] ?? []), AdminNotificationCategory::KEYS);
        if ($unknown !== []) {
            return response()->json([
                'message' => 'Loại thông báo không hợp lệ.',
                'errors'  => ['types' => ['Không hỗ trợ: ' . implode(', ', $unknown)]],
            ], 422);
        }

        $user    = $request->user();
        $current = $user->notificationSettings();

        $merged = [
            'enabled' => array_key_exists('enabled', $data) ? (bool) $data['enabled'] : $current['enabled'],
            'types'   => array_map('boolval', array_merge($current['types'], $data['types'] ?? [])),
        ];

        $user->forceFill(['notification_settings' => $merged])->save();

        return response()->json(['data' => $user->notificationSettings()]);
    }
}
