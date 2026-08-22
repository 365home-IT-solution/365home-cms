<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\NotificationFcm;
use App\Services\NotificationFcmService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gửi push notification thủ công cho khách hàng (bảng notification_fcm/notification_fcm_recipients)
 * — API tương đương form "Push Notification" ở Filament (App\Filament\Resources\NotificationFcmResource),
 * dùng cho admin app riêng (Bearer token) thay vì CMS.
 *
 * Gửi ngay nếu không có scheduled_at hoặc scheduled_at đã ở quá khứ/hiện tại; nếu scheduled_at ở
 * tương lai thì chỉ lưu record kèm recipient_ids, App\Console\Commands\SendScheduledNotificationsCommand
 * (chạy mỗi phút qua scheduler) sẽ gửi khi đến giờ.
 */
class PushNotificationController extends Controller
{
    /**
     * POST /api/admin/push-notification
     * Body: title, body, sent_for (all|users), customer_ids[] (bắt buộc nếu sent_for=users),
     * scheduled_at (tuỳ chọn — để trống hoặc quá khứ = gửi ngay).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'           => 'required|string|max:255',
            'body'            => 'required|string|max:1000',
            'sent_for'        => 'required|in:all,users',
            'customer_ids'    => 'required_if:sent_for,users|array|min:1',
            'customer_ids.*'  => 'string|exists:customers,id',
            'scheduled_at'    => 'nullable|date',
        ]);

        $customerIds = $data['sent_for'] === 'all'
            ? Customer::whereNotNull('token_device')
                ->where('status', Customer::STATUS_ACTIVE)
                ->pluck('id')
                ->toArray()
            : $data['customer_ids'];

        $scheduledAt = isset($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null;
        $isScheduled = $scheduledAt !== null && $scheduledAt->isFuture();

        $notification = NotificationFcm::create([
            'title'         => $data['title'],
            'body'          => $data['body'],
            'type'          => 'manual',
            'sent_for'      => $data['sent_for'],
            'scheduled_at'  => $scheduledAt,
            'created_by'    => $request->user()->id,
            'recipient_ids' => $isScheduled ? $customerIds : null,
        ]);

        if (! $isScheduled) {
            $customers = Customer::whereIn('id', $customerIds)
                ->where('status', Customer::STATUS_ACTIVE)
                ->get();

            app(NotificationFcmService::class)->sendToExisting($notification, $customers);
        }

        return response()->json(['data' => $this->toItem($notification->fresh())], 201);
    }

    private function toItem(NotificationFcm $n): array
    {
        return [
            'id'            => $n->id,
            'title'         => $n->title,
            'body'          => $n->body,
            'sent_for'      => $n->sent_for,
            'is_scheduled'  => $n->isPending(),
            'scheduled_at'  => optional($n->scheduled_at)->toIso8601String(),
            'sent_at'       => optional($n->sent_at)->toIso8601String(),
            'sent_count'    => $n->sent_count,
            'fail_count'    => $n->fail_count,
            'created_at'    => $n->created_at->toIso8601String(),
        ];
    }
}
